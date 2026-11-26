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
 * Staff sign-in and the invitation chain:
 * system admin -> Guild President / Vice -> ministers and GRC delegates.
 * There is no open registration; every staff account starts from a one-use link.
 */
final class AuthController
{
    private const INVITE_DAYS = 7;

    // Which roles each account type may invite.
    private const CAN_INVITE = [
        'admin' => ['president', 'vice_guild', 'delegate'],
        'president' => ['vice_guild', 'delegate'],
        'vice_guild' => ['delegate'],
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

        if (Auth::needsRehash($user['password_hash'])) {
            Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [Auth::hashPassword($password), $user['id']]);
        }
        Auth::audit((int) $user['id'], 'login', 'user', (int) $user['id']);

        return $this->session($user);
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

        return Response::created($this->session($user));
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

    private function session(array $user): array
    {
        return [
            'access_token' => Auth::issueToken($user),
            'token_type' => 'bearer',
            'user' => ['id' => (int) $user['id'], 'name' => $user['full_name'], 'role' => $user['role']],
        ];
    }

    private function assertEmail(string $email, string $message): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpError(422, $message);
        }
    }
}
