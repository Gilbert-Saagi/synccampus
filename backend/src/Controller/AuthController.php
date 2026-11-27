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
 * Nobody has a password. On /admin/ (system admin) and /staff/ (Guild cabinet)
 * a person enters their email and gets a one-time link, valid for 15 minutes,
 * that only works in the browser on the device that asked for it. Each sign-in
 * is a session that can be signed out from any other device. Accounts exist
 * only through one-use invitation links created by the admin.
 */
final class AuthController
{
    private const INVITE_DAYS = 7;
    private const LINK_MINUTES = 15;

    // Who may sign in through each door.
    private const PORTALS = [
        'admin' => ['roles' => ['admin'], 'path' => '/admin/', 'name' => 'admin page'],
        'staff' => ['roles' => Auth::STAFF, 'path' => '/staff/', 'name' => 'Guild cabinet dashboard'],
    ];

    // Only the system admin creates cabinet accounts.
    private const CAN_INVITE = [
        'admin' => ['president', 'vice_guild', 'delegate'],
    ];

    /**
     * Step 1: email a sign-in link. Every address gets the same reply, so the
     * form cannot be used to find out who has an account.
     */
    public function requestLink(Request $request): array
    {
        $in = $request->input();
        $portal = $in->choice('portal', array_keys(self::PORTALS), 'staff', 'Unknown sign-in page');
        $email = strtolower($in->text('email', 'Email', 3, 160));
        $device = $in->text('device', 'Device key', 32, 128);
        // Checked before the address is looked up, so it reveals nothing about accounts.
        if (Env::isProduction() && !Mailer::isConfigured()) {
            throw new HttpError(503, 'Email is not set up on the server yet');
        }
        $key = 'signinlink:' . $email;
        Throttle::check($key);
        Throttle::fail($key); // every request counts, so links cannot be requested in bulk

        $answer = ['ok' => true, 'message' => 'If that address has an account here, a sign-in link is on its way. Open it on this device within ' . self::LINK_MINUTES . ' minutes.'];
        $roles = self::PORTALS[$portal]['roles'];
        $marks = implode(',', array_fill(0, count($roles), '?'));
        $user = Db::one("SELECT * FROM users WHERE email = ? AND is_active = TRUE AND role IN ($marks)", [$email, ...$roles]);
        if ($user === null) {
            return $answer;
        }

        $token = Codes::invitationToken();
        Db::insert('login_links', [
            'user_id' => (int) $user['id'],
            'portal' => $portal,
            'token_hash' => hash('sha256', 'sign-in-link:' . $token),
            'device_hash' => hash('sha256', 'device:' . $device),
            'device_label' => Auth::deviceLabel(),
            'created_at' => Time::now(),
            'expires_at' => Time::now(self::LINK_MINUTES * 60),
            'used_at' => null,
        ]);

        $link = $this->siteUrl() . self::PORTALS[$portal]['path'] . '#login=' . rawurlencode($token);
        Mailer::send($user['email'], 'Your SyncCampus sign-in link', implode("\n", [
            "Hello {$user['full_name']},",
            '',
            'Someone asked to sign in to the SyncCampus ' . self::PORTALS[$portal]['name'] . ' from ' . Auth::deviceLabel() . '.',
            'If it was you, open this link on that same device within ' . self::LINK_MINUTES . ' minutes:',
            '',
            $link,
            '',
            'If it was not you, ignore this email. The link does nothing on any other device.',
        ]));
        Auth::audit((int) $user['id'], 'request_sign_in_link', 'user', (int) $user['id']);

        // Local development without email: hand the link straight back.
        if (!Mailer::isConfigured() && !Env::isProduction()) {
            $answer['dev_link'] = $link;
        }
        return $answer;
    }

    /** Step 2: the link is opened. It must be the same browser that asked for it. */
    public function confirmLink(Request $request): array
    {
        $in = $request->input();
        $token = $in->text('token', 'Sign-in link', 30, 200);
        $device = $in->text('device', 'Device key', 32, 128);

        return Db::transaction(function () use ($token, $device) {
            $link = Db::one('SELECT * FROM login_links WHERE token_hash = ? FOR UPDATE', [hash('sha256', 'sign-in-link:' . $token)]);
            if ($link === null || $link['used_at'] !== null || strtotime($link['expires_at'] . ' UTC') <= time()) {
                throw new HttpError(404, 'This sign-in link is invalid, expired or already used. Ask for a new one.');
            }
            if (!hash_equals($link['device_hash'], hash('sha256', 'device:' . $device))) {
                throw new HttpError(403, 'Open this link on the device and browser where you asked for it (' . $link['device_label'] . ').');
            }
            Db::run('UPDATE login_links SET used_at = ? WHERE id = ?', [Time::now(), $link['id']]);

            $roles = self::PORTALS[$link['portal']]['roles'] ?? [];
            $user = Db::one('SELECT * FROM users WHERE id = ? AND is_active = TRUE', [$link['user_id']]);
            if ($user === null || !in_array($user['role'], $roles, true)) {
                throw new HttpError(403, 'This account is switched off');
            }
            Throttle::clear('signinlink:' . $user['email']);
            Auth::audit((int) $user['id'], 'login', 'user', (int) $user['id']);
            return Auth::startSession($user);
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
            'email' => $user['email'],
            'role' => $user['role'],
            'delegate_id' => $delegateId === null ? null : (int) $delegateId,
        ];
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

        // With an address and working email, the link also goes straight to the person.
        $emailed = false;
        if ($email !== null && Mailer::isConfigured()) {
            $name = $in->text('invitee_name', 'Name', 0, 120);
            Mailer::send($email, 'Your SyncCampus account for ' . $portfolio, implode("\n", [
                'Hello' . ($name !== '' ? " $name" : '') . ',',
                '',
                "You have been invited to the SyncCampus Guild cabinet dashboard as $portfolio.",
                'Open this link within ' . self::INVITE_DAYS . ' days to create your account (it works once):',
                '',
                $this->siteUrl() . '/staff/#invite=' . rawurlencode($token),
                '',
                'Afterwards you sign in at ' . $this->siteUrl() . '/staff/ with a link sent to this address.',
            ]));
            $emailed = true;
        }

        return Response::created([
            'id' => $id,
            'emailed' => $emailed,
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
        $this->assertEmail($email, 'Enter a valid email address');

        $user = Db::transaction(function () use ($token, $name, $email) {
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
                'password_hash' => Auth::unusablePassword(),
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

    /** APP_URL, or else the address the browser used (behind Vercel it arrives as X-Forwarded-Host). */
    private function siteUrl(): string
    {
        $configured = Env::get('APP_URL');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
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
