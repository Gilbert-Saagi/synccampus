<?php
declare(strict_types=1);

namespace SyncCampus;

/**
 * Staff authentication. People sign in with an emailed, device-bound link
 * (see AuthController). Every sign-in creates a row in `sessions` (one per
 * device) and a signed HS256 token that names it, so any device can be
 * signed out from another one. Students never sign in; they use a reference
 * and access code instead (see Codes).
 */
final class Auth
{
    public const LEADERS = ['president', 'vice_guild'];
    public const STAFF = ['president', 'vice_guild', 'delegate'];
    public const ROLES = ['admin', 'president', 'vice_guild', 'delegate'];

    private const SESSION_HOURS = 8;
    private const TOUCH_SECONDS = 300; // how often last_seen_at is refreshed

    private static ?array $user = null;

    /** Filler for the password column: nobody signs in with a password. */
    public static function unusablePassword(): string
    {
        return password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    }

    /** Signs a user in on this device and returns the response body the frontend expects. */
    public static function startSession(array $user): array
    {
        $now = time();
        $sessionId = Db::insert('sessions', [
            'user_id' => (int) $user['id'],
            'label' => self::deviceLabel(),
            'created_at' => Time::now(),
            'last_seen_at' => Time::now(),
            'expires_at' => Time::now(self::SESSION_HOURS * 3600),
            'revoked_at' => null,
        ]);

        $header = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $claims = self::b64(json_encode([
            'sub' => (string) $user['id'],
            'sid' => $sessionId,
            'role' => $user['role'],
            'iat' => $now,
            'exp' => $now + self::SESSION_HOURS * 3600,
        ]));

        return [
            'access_token' => "$header.$claims." . self::sign("$header.$claims"),
            'token_type' => 'bearer',
            'user' => ['id' => (int) $user['id'], 'name' => $user['full_name'], 'role' => $user['role']],
        ];
    }

    /** The signed-in staff account (with its session id as `session_id`), or a 401. */
    public static function user(Request $request): array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $token = $request->bearerToken();
        if ($token === null) {
            throw new HttpError(401, 'Not authenticated');
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3 || !hash_equals(self::sign("$parts[0].$parts[1]"), $parts[2])) {
            throw new HttpError(401, 'Invalid or expired token');
        }
        $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (!is_array($claims) || ($claims['exp'] ?? 0) < time() || empty($claims['sid'])) {
            throw new HttpError(401, 'Invalid or expired token');
        }

        $session = Db::one(
            'SELECT id, last_seen_at FROM sessions WHERE id = ? AND user_id = ? AND revoked_at IS NULL AND expires_at > ?',
            [(int) $claims['sid'], (int) $claims['sub'], Time::now()]
        );
        if ($session === null) {
            throw new HttpError(401, 'You have been signed out on this device');
        }
        $user = Db::one('SELECT * FROM users WHERE id = ? AND is_active = TRUE', [(int) $claims['sub']]);
        if ($user === null) {
            throw new HttpError(401, 'Account disabled');
        }
        if (strtotime($session['last_seen_at'] . ' UTC') < time() - self::TOUCH_SECONDS) {
            Db::run('UPDATE sessions SET last_seen_at = ? WHERE id = ?', [Time::now(), $session['id']]);
        }

        $user['id'] = (int) $user['id'];
        $user['session_id'] = (int) $session['id'];
        return self::$user = $user;
    }

    public static function requireRole(Request $request, array $roles): array
    {
        $user = self::user($request);
        if (!in_array($user['role'], $roles, true)) {
            throw new HttpError(403, 'You do not have access to this resource');
        }
        return $user;
    }

    public static function audit(?int $userId, string $action, string $entity, ?int $entityId = null): void
    {
        Db::insert('audit_log', [
            'user_id' => $userId,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'created_at' => Time::now(),
        ]);
    }

    /** "Chrome on Windows" style name for the current browser, shown in the device list. */
    public static function deviceLabel(): string
    {
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') || str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Browser',
        };
        $system = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iPhone/iPad',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS') => 'Mac',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'unknown device',
        };
        return "$browser on $system";
    }

    private static function sign(string $data): string
    {
        $secret = Env::get('SECRET_KEY', 'dev-secret-change-me');
        return self::b64(hash_hmac('sha256', $data, $secret, true));
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
