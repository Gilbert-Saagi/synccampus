<?php
declare(strict_types=1);

namespace SyncCampus\Controller;

use SyncCampus\Auth;
use SyncCampus\Codes;
use SyncCampus\Db;
use SyncCampus\HttpError;
use SyncCampus\Request;
use SyncCampus\Response;
use SyncCampus\Throttle;
use SyncCampus\Time;

/**
 * Signing in, linked devices and staff invitations.
 *
 * - The first device signs in with email and password at /staff/ (Guild
 *   cabinet) or /admin/ (system admin). It becomes the account's primary device.
 * - From the primary device a person can link another gadget: it shows a
 *   one-time code and link, valid for 10 minutes. Opening the link (or typing
 *   the code) on the new gadget signs it in to the same account.
 * - The primary device lists every connected gadget and can sign any of them out.
 * - Accounts exist only through one-use invitation links created by the admin.
 */
final class AuthController
{
    private const INVITE_DAYS = 7;
    private const LINK_MINUTES = 10;

    // Who may sign in through each door.
    private const PORTALS = [
        'admin' => ['admin'],
        'staff' => Auth::STAFF,
    ];

    // The admin sets up the whole cabinet; the Guild President and Vice invite their delegates.
    private const CAN_INVITE = [
        'admin' => ['president', 'vice_guild', 'delegate'],
        'president' => ['delegate'],
        'vice_guild' => ['delegate'],
    ];

    public function login(Request $request): array
    {
        $in = $request->input();
        $portal = $in->choice('portal', array_keys(self::PORTALS), 'staff', 'Unknown sign-in page');
        $email = strtolower($in->text('username', 'Email', 1, 160));
        $password = (string) $in->raw('password');
        $key = 'login:' . $email;

        Throttle::check($key);
        $user = Db::one('SELECT * FROM users WHERE email = ? AND is_active = TRUE', [$email]);
        // Wrong door counts as wrong details, so the page reveals nothing about other accounts.
        if ($user === null || !in_array($user['role'], self::PORTALS[$portal], true)
            || !Auth::verifyPassword($password, $user['password_hash'])) {
            Throttle::fail($key);
            throw new HttpError(401, 'Incorrect email or password');
        }
        Throttle::clear($key);

        if (Auth::needsRehash($user['password_hash'])) {
            Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [Auth::hashPassword($password), $user['id']]);
        }
        Auth::audit((int) $user['id'], 'login', 'user', (int) $user['id']);
        return Auth::startSession($user);
    }

    /** On the primary device: a one-time code to sign another gadget in. */
    public function createDeviceLink(Request $request): Response
    {
        $user = Auth::user($request);
        if (!$user['is_primary_device']) {
            throw new HttpError(403, 'New devices can only be linked from your primary device');
        }
        // One live code at a time.
        Db::run('UPDATE device_links SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [Time::now(), $user['id']]);

        $code = Codes::accessCode();
        $expires = Time::now(self::LINK_MINUTES * 60);
        Db::insert('device_links', [
            'user_id' => $user['id'],
            'created_by_session' => $user['session_id'],
            'code_hash' => $this->hashLinkCode($code),
            'created_at' => Time::now(),
            'expires_at' => $expires,
            'used_at' => null,
        ]);
        Auth::audit($user['id'], 'create_device_link', 'session', $user['session_id']);
        return Response::created(['code' => $code, 'expires_at' => Time::iso($expires)]);
    }

    /** On the new gadget: the code (typed, or carried in the link) signs it in. */
    public function useDeviceLink(Request $request): array
    {
        $in = $request->input();
        $portal = $in->choice('portal', array_keys(self::PORTALS), 'staff', 'Unknown sign-in page');
        $code = $in->text('code', 'Link code', 4, 40);
        // Guesses are counted per network address, so codes cannot be tried in bulk.
        $key = 'devicelink:' . ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown');

        Throttle::check($key);
        $session = Db::transaction(function () use ($code, $portal) {
            $link = Db::one(
                'SELECT l.*, s.revoked_at AS primary_revoked FROM device_links l
                   JOIN sessions s ON s.id = l.created_by_session
                  WHERE l.code_hash = ? FOR UPDATE',
                [$this->hashLinkCode($code)]
            );
            $user = $link ? Db::one('SELECT * FROM users WHERE id = ? AND is_active = TRUE', [$link['user_id']]) : null;
            $valid = $link !== null && $link['used_at'] === null && $link['primary_revoked'] === null
                && strtotime($link['expires_at'] . ' UTC') > time()
                && $user !== null && in_array($user['role'], self::PORTALS[$portal], true);
            if (!$valid) {
                return null;
            }
            Db::run('UPDATE device_links SET used_at = ? WHERE id = ?', [Time::now(), $link['id']]);
            Auth::audit((int) $user['id'], 'link_device', 'user', (int) $user['id']);
            return Auth::startSession($user, viaPassword: false);
        });

        // Recorded outside the transaction, so a failed attempt is not rolled back with it.
        if ($session === null) {
            Throttle::fail($key);
            throw new HttpError(404, 'That code is wrong, expired or already used. Make a new one on your primary device.');
        }
        Throttle::clear($key);
        return $session;
    }

    private function hashLinkCode(string $code): string
    {
        return hash('sha256', 'device-link:' . strtoupper(str_replace([' ', '-'], '', trim($code))));
    }

    /** Devices signed in to the current account. */
    public function sessions(Request $request): array
    {
        $user = Auth::user($request);
        $rows = Db::all(
            'SELECT id, label, is_primary, created_at, last_seen_at FROM sessions
              WHERE user_id = ? AND revoked_at IS NULL AND expires_at > ? ORDER BY is_primary DESC, last_seen_at DESC',
            [$user['id'], Time::now()]
        );
        return array_map(static fn ($s) => [
            'id' => (int) $s['id'],
            'label' => $s['label'],
            'signed_in_at' => Time::iso($s['created_at']),
            'last_seen_at' => Time::iso($s['last_seen_at']),
            'primary' => (bool) $s['is_primary'],
            'current' => (int) $s['id'] === $user['session_id'],
        ], $rows);
    }

    /** The primary device can sign out any device; a linked device only itself. */
    public function revokeSession(Request $request): array
    {
        $user = Auth::user($request);
        if (!$user['is_primary_device'] && $request->id() !== $user['session_id']) {
            throw new HttpError(403, 'Other devices can only be signed out from your primary device');
        }
        Db::run('UPDATE sessions SET revoked_at = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL', [Time::now(), $request->id(), $user['id']]);
        Auth::audit($user['id'], 'sign_out_device', 'session', $request->id());
        return ['ok' => true];
    }

    public function revokeOtherSessions(Request $request): array
    {
        $user = Auth::user($request);
        if (!$user['is_primary_device']) {
            throw new HttpError(403, 'Other devices can only be signed out from your primary device');
        }
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
            'email' => $user['email'],
            'role' => $user['role'],
            'primary_device' => $user['is_primary_device'],
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
        $new = $in->text('new_password', 'The new password', 8, 128);
        Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [Auth::hashPassword($new), $user['id']]);
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
        $password = $in->text('password', 'Password', 8, 128);
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
            throw new HttpError(404, 'This invitation is invalid, expired, or already used. Ask the system admin for a new link.');
        }
        return $invitation;
    }

    private function assertEmail(string $email, string $message): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpError(422, $message);
        }
    }
}
