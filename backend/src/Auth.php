<?php
declare(strict_types=1);

namespace SyncCampus;

/**
 * Staff authentication: password hashing and signed bearer tokens (HS256 JWT).
 * Students never log in; they use a reference + access code instead (see Codes).
 */
final class Auth
{
    public const LEADERS = ['president', 'vice_guild'];
    public const STAFF = ['president', 'vice_guild', 'delegate'];
    public const ROLES = ['admin', 'president', 'vice_guild', 'delegate'];

    private const TOKEN_HOURS = 8;

    private static ?array $user = null;

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function verifyPassword(string $password, string $stored): bool
    {
        // Accounts created by the first (Python) version of the system used
        // "pbkdf2$<salt hex>$<hash hex>". They are re-hashed on their next login.
        if (str_starts_with($stored, 'pbkdf2$')) {
            $parts = explode('$', $stored);
            if (count($parts) !== 3 || ($salt = hex2bin($parts[1])) === false) {
                return false;
            }
            return hash_equals($parts[2], hash_pbkdf2('sha256', $password, $salt, 200000, 64));
        }
        return password_verify($password, $stored);
    }

    public static function needsRehash(string $stored): bool
    {
        return str_starts_with($stored, 'pbkdf2$') || password_needs_rehash($stored, PASSWORD_DEFAULT);
    }

    public static function issueToken(array $user): string
    {
        $now = time();
        $header = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $claims = self::b64(json_encode([
            'sub' => (string) $user['id'],
            'role' => $user['role'],
            'iat' => $now,
            'exp' => $now + self::TOKEN_HOURS * 3600,
        ]));
        return "$header.$claims." . self::sign("$header.$claims");
    }

    /** The signed-in staff account, or a 401. */
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
        if (!is_array($claims) || ($claims['exp'] ?? 0) < time()) {
            throw new HttpError(401, 'Invalid or expired token');
        }
        $user = Db::one('SELECT * FROM users WHERE id = ? AND is_active = TRUE', [(int) $claims['sub']]);
        if ($user === null) {
            throw new HttpError(401, 'Account disabled');
        }
        $user['id'] = (int) $user['id'];
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
