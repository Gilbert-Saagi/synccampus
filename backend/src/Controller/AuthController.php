<?php
declare(strict_types=1);

namespace SyncCampus\Controller;

use SyncCampus\Auth;
use SyncCampus\Codes;
use SyncCampus\Db;
use SyncCampus\Env;
use SyncCampus\HttpError;
use SyncCampus\Mailer;
use SyncCampus\Request;
use SyncCampus\Response;
use SyncCampus\Throttle;
use SyncCampus\Time;

/**
 * Signing in, devices and staff invitations.
 *
 * - The Guild cabinet signs in at /staff/ with email and password.
 * - The system admin signs in at /admin/ with a one-time link emailed to the
 *   admin address, which only works in the browser that asked for it.
 * - Accounts exist only through one-use invitation links created by the admin.
 */
final class AuthController
{
    private const INVITE_DAYS = 7;
    private const ADMIN_LINK_MINUTES = 15;

    // Only the system admin creates cabinet accounts.
    private const CAN_INVITE = [
        'admin' => ['president', 'vice_guild', 'delegate'],
    ];

    public function login(Request $request): array
    {
        $in = $request->input();
        $email = strtolower($in->text('username', 'Email', 1, 160));
        $password = (string) $in->raw('password');
        $key = 'login:' . $email;

        Throttle::check($key);
        $user = Db::one('SELECT * FROM users WHERE email = ? AND is_active = TRUE', [$email]);
        if ($user === null || !Auth::verifyPassword($password, $user['password_hash'])) {
            Throttle::fail($key);
            throw new HttpError(401, 'Incorrect email or password');
        }
        Throttle::clear($key);
        if ($user['role'] === 'admin') {
            // Admins never sign in with a password, only with an emailed link.
            throw new HttpError(403, 'The admin account signs in with an emailed link on the admin page.');
        }

        if (Auth::needsRehash($user['password_hash'])) {
            Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [Auth::hashPassword($password), $user['id']]);
        }
        Auth::audit((int) $user['id'], 'login', 'user', (int) $user['id']);

        return Auth::startSession($user);
    }

    /**
     * Step 1 of admin sign-in. Always answers the same way, so nobody can use
     * it to find out which address belongs to the admin.
     */
    public function requestAdminLink(Request $request): array
    {
        $in = $request->input();
        $email = strtolower($in->text('email', 'Email', 3, 160));
        $device = $in->text('device', 'Device key', 32, 128);
        // Checked before looking the address up, so every address gets the same reply.
        if (Env::isProduction() && !Mailer::isConfigured()) {
            throw new HttpError(503, 'Email is not set up on the server yet (RESEND_API_KEY)');
        }
        $key = 'adminlink:' . $email;
        Throttle::check($key);
        Throttle::fail($key); // every request counts, so links cannot be requested in bulk

        $answer = ['ok' => true, 'message' => 'If that address belongs to an administrator, a sign-in link is on its way. Open it on this device within 15 minutes.'];
        $admin = Db::one("SELECT * FROM users WHERE email = ? AND role = 'admin' AND is_active = TRUE", [$email]);
        if ($admin === null) {
            return $answer;
        }

        $token = Codes::invitationToken();
        Db::insert('admin_login_requests', [
            'user_id' => (int) $admin['id'],
            'token_hash' => hash('sha256', 'admin-link:' . $token),
            'device_hash' => hash('sha256', 'device:' . $device),
            'device_label' => Auth::deviceLabel(),
            'created_at' => Time::now(),
            'expires_at' => Time::now(self::ADMIN_LINK_MINUTES * 60),
            'used_at' => null,
        ]);

        $link = rtrim(Env::get('APP_URL', $this->origin()), '/') . '/admin/#login=' . rawurlencode($token);
        Mailer::send($admin['email'], 'Your SyncCampus admin sign-in link', implode("
", [
            'Someone asked to sign in to the SyncCampus admin page from ' . Auth::deviceLabel() . '.',
            '',
            'If it was you, open this link on that same device within ' . self::ADMIN_LINK_MINUTES . ' minutes:',
            $link,
            '',
            'If it was not you, ignore this email. Nobody can sign in without opening the link on the device that asked for it.',
        ]));
        Auth::audit((int) $admin['id'], 'request_admin_link', 'user', (int) $admin['id']);

        // Local development without email: hand the link straight back.
        if (!Mailer::isConfigured() && !Env::isProduction()) {
            $answer['dev_link'] = $link;
        }
        return $answer;
    }

    /** Step 2: the link is opened. It must be the same browser that asked for it. */
    public function confirmAdminLink(Request $request): array
    {
        $in = $request->input();
        $token = $in->text('token', 'Sign-in link', 30, 200);
        $device = $in->text('device', 'Device key', 32, 128);

        return Db::transaction(function () use ($token, $device) {
            $login = Db::one('SELECT * FROM admin_login_requests WHERE token_hash = ? FOR UPDATE', [hash('sha256', 'admin-link:' . $token)]);
            if ($login === null || $login['used_at'] !== null || strtotime($login['expires_at'] . ' UTC') <= time()) {
                throw new HttpError(404, 'This sign-in link is invalid, expired or already used. Ask for a new one.');
            }
            if (!hash_equals($login['device_hash'], hash('sha256', 'device:' . $device))) {
                throw new HttpError(403, 'Open this link on the device and browser where you asked for it (' . $login['device_label'] . ').');
            }
            Db::run('UPDATE admin_login_requests SET used_at = ? WHERE id = ?', [Time::now(), $login['id']]);

            $admin = Db::one("SELECT * FROM users WHERE id = ? AND role = 'admin' AND is_active = TRUE", [$login['user_id']]);
            if ($admin === null) {
                throw new HttpError(403, 'This admin account is switched off');
            }
            Throttle::clear('adminlink:' . $admin['email']);
            Auth::audit((int) $admin['id'], 'login', 'user', (int) $admin['id']);
            return Auth::startSession($admin);
        });
    }

    /** Devices signed in to the current account. */
    public function sessions(Request $request): array
    {
        $user = Auth::user($request);
        $rows = Db::all(
            'SELECT id, label, created_at, last_seen_at FROM sessions
              WHERE user_id = ? AND revoked_at IS NULL AND expires_at > ? ORDER BY last_seen_at DESC',
            [$user['id'], Time::now()]
        );
        return array_map(static fn ($s) => [
            'id' => (int) $s['id'],
            'label' => $s['label'],
            'signed_in_at' => Time::iso($s['created_at']),
            'last_seen_at' => Time::iso($s['last_seen_at']),
            'current' => (int) $s['id'] === $user['session_id'],
        ], $rows);
    }

    public function revokeSession(Request $request): array
    {
        $user = Auth::user($request);
        Db::run('UPDATE sessions SET revoked_at = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL', [Time::now(), $request->id(), $user['id']]);
        Auth::audit($user['id'], 'sign_out_device', 'session', $request->id());
        return ['ok' => true];
    }

    public function revokeOtherSessions(Request $request): array
    {
        $user = Auth::user($request);
        Db::run('UPDATE sessions SET revoked_at = ? WHERE user_id = ? AND id <> ? AND revoked_at IS NULL', [Time::now(), $user['id'], $user['session_id']]);
        Auth::audit($user['id'], 'sign_out_other_devices', 'session');
        return ['ok' => true];
    }

    public function logout(Request $request): array
    {
        $user = Auth::user($request);
        Db::run('UPDATE sessions SET revoked_at = ? WHERE id = ?', [Time::now(), $user['session_id']]);
        return ['ok' => true];
    }

    public function me(Request $request): array
    {
        $user = Auth::user($request);
        $delegateId = Db::value('SELECT id FROM delegates WHERE user_id = ?', [$user['id']]);
        return [
            'id' => $user['id'],
            'name' => $user['full_name'],
            'role' => $user['role'],
            'delegate_id' => $delegateId === null ? null : (int) $delegateId,
        ];
    }

    public function changePassword(Request $request): array
    {
        $user = Auth::user($request);
        $in = $request->input();
        if (!Auth::verifyPassword((string) $in->raw('current_password'), $user['password_hash'])) {
            throw new HttpError(401, 'Current password is incorrect');
        }
        $new = $in->text('new_password', 'The new password', 12, 128);
        Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [Auth::hashPassword($new), $user['id']]);
        // A new password signs out every other device.
        Db::run('UPDATE sessions SET revoked_at = ? WHERE user_id = ? AND id <> ? AND revoked_at IS NULL', [Time::now(), $user['id'], $user['session_id']]);
        Auth::audit($user['id'], 'change_password', 'user', $user['id']);
        return ['ok' => true];
    }

    public function createInvitation(Request $request): Response
    {
        $user = Auth::requireRole($request, array_keys(self::CAN_INVITE));
        $in = $request->input();

        $role = (string) $in->raw('role');
        if (!in_array($role, self::CAN_INVITE[$user['role']], true)) {
            throw new HttpError(403, 'Your account cannot invite that role');
        }

        $portfolio = $in->text('portfolio', 'Portfolio', 0, 120);
        $portfolio = match ($role) {
            'president' => $portfolio ?: 'Guild President',
            'vice_guild' => $portfolio ?: 'Guild Vice President',
            default => $portfolio ?: throw new HttpError(422, 'Enter the minister portfolio or GRC area for this delegate'),
        };

        $email = strtolower($in->text('invitee_email', 'Email', 0, 160)) ?: null;
        if ($email !== null) {
            $this->assertEmail($email, 'Enter a valid email address or leave it blank');
            if (Db::value('SELECT id FROM users WHERE email = ?', [$email])) {
                throw new HttpError(409, 'That email already has an account');
            }
        }

        $token = Codes::invitationToken();
        $expires = Time::now(self::INVITE_DAYS * 86400);
        $id = Db::insert('staff_invitations', [
            'token_hash' => Codes::hashInvitationToken($token),
            'role' => $role,
            'invitee_email' => $email,
            'invitee_name' => $in->text('invitee_name', 'Name', 0, 120),
            'portfolio' => $portfolio,
            'categories_handled' => $in->text('categories_handled', 'Categories', 0, 300),
            'max_open_load' => $in->int('max_open_load', 'Maximum open assignments', 5, 1, 50),
            'campus' => $in->text('campus', 'Campus', 0, 60),
            'created_by' => $user['id'],
            'created_at' => Time::now(),
            'expires_at' => $expires,
            'redeemed_at' => null,
            'revoked_at' => null,
        ]);
        Auth::audit($user['id'], 'create_invitation', 'staff_invitation', $id);

        return Response::created([
            'id' => $id,
            'token' => $token,
            'role' => $role,
            'portfolio' => $portfolio,
            'invitee_email' => $email,
            'expires_at' => Time::iso($expires),
        ]);
    }

    public function inspectInvitation(Request $request): array
    {
        $invitation = $this->invitation($request->input()->text('token', 'Invitation token', 30, 200));
        return [
            'role' => $invitation['role'],
            'portfolio' => $invitation['portfolio'],
            'invitee_name' => $invitation['invitee_name'] ?? '',
            'invitee_email' => $invitation['invitee_email'],
            'expires_at' => Time::iso($invitation['expires_at']),
        ];
    }

    public function redeemInvitation(Request $request): Response
    {
        $in = $request->input();
        $token = $in->text('token', 'Invitation token', 30, 200);
        $name = $in->text('full_name', 'Full name', 2, 120);
        $email = strtolower($in->text('email', 'Email', 5, 160));
        $password = $in->text('password', 'Password', 12, 128);
        $this->assertEmail($email, 'Enter a valid email address');

        $user = Db::transaction(function () use ($token, $name, $email, $password) {
            $invitation = $this->invitation($token, lock: true);
            if ($invitation['invitee_email'] && !hash_equals($invitation['invitee_email'], $email)) {
                throw new HttpError(403, 'This invitation was issued for a different email address');
            }
            if (Db::value('SELECT id FROM users WHERE email = ?', [$email])) {
                throw new HttpError(409, 'That email already has an account');
            }

            $userId = Db::insert('users', [
                'full_name' => $name,
                'email' => $email,
                'password_hash' => Auth::hashPassword($password),
                'role' => $invitation['role'],
                'is_active' => true,
                'created_at' => Time::now(),
            ]);
            Db::insert('delegates', [
                'user_id' => $userId,
                'portfolio' => $invitation['portfolio'],
                'categories_handled' => $invitation['categories_handled'],
                'max_open_load' => (int) $invitation['max_open_load'],
                'campus' => $invitation['campus'] ?? '',
                'is_available' => true,
                'show_public' => true,
            ]);
            Db::run('UPDATE staff_invitations SET redeemed_at = ? WHERE id = ?', [Time::now(), $invitation['id']]);
            Auth::audit($userId, 'redeem_invitation', 'staff_invitation', (int) $invitation['id']);

            return Db::one('SELECT * FROM users WHERE id = ?', [$userId]);
        });

        return Response::created(Auth::startSession($user));
    }

    private function invitation(string $token, bool $lock = false): array
    {
        $sql = 'SELECT * FROM staff_invitations WHERE token_hash = ?' . ($lock ? ' FOR UPDATE' : '');
        $invitation = Db::one($sql, [Codes::hashInvitationToken($token)]);
        $usable = $invitation !== null
            && $invitation['redeemed_at'] === null
            && $invitation['revoked_at'] === null
            && strtotime($invitation['expires_at'] . ' UTC') > time();
        if (!$usable) {
            throw new HttpError(404, 'This invitation is invalid, expired, or already used. Ask a Guild leader for a new link.');
        }
        return $invitation;
    }

    /** The site address the browser used. Behind Vercel that arrives as X-Forwarded-Host. */
    private function origin(): string
    {
        $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
        $host = explode(',', $host)[0];
        $scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http');
        return explode(',', $scheme)[0] . '://' . trim($host);
    }

    private function assertEmail(string $email, string $message): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpError(422, $message);
        }
    }
}
