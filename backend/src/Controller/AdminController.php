<?php
declare(strict_types=1);

namespace SyncCampus\Controller;

use SyncCampus\Auth;
use SyncCampus\Db;
use SyncCampus\HttpError;
use SyncCampus\Request;
use SyncCampus\Time;

/**
 * System administration: staff accounts and invitations. The admin keeps the
 * cabinet's accounts in order but, for student privacy, never sees concerns.
 */
final class AdminController
{
    private const CAMPUSES = ['', 'Main Campus', 'City Campus'];

    public function staff(Request $request): array
    {
        Auth::requireRole($request, ['admin']);
        $lastLogin = [];
        foreach (Db::all("SELECT user_id, MAX(created_at) AS at FROM audit_log WHERE action = 'login' GROUP BY user_id") as $row) {
            $lastLogin[(int) $row['user_id']] = $row['at'];
        }

        $rows = Db::all(
            'SELECT u.id, u.full_name, u.email, u.role, u.is_active, u.created_at,
                    d.portfolio, d.categories_handled, d.campus, d.max_open_load, d.is_available
               FROM users u LEFT JOIN delegates d ON d.user_id = u.id
              ORDER BY u.is_active DESC, u.role, u.full_name'
        );
        return array_map(static fn ($u) => [
            'id' => (int) $u['id'],
            'name' => $u['full_name'],
            'email' => $u['email'],
            'role' => $u['role'],
            'is_active' => (bool) $u['is_active'],
            'portfolio' => $u['portfolio'],
            'categories_handled' => $u['categories_handled'],
            'campus' => $u['campus'],
            'max_open_load' => $u['max_open_load'] === null ? null : (int) $u['max_open_load'],
            'is_available' => $u['is_available'] === null ? null : (bool) $u['is_available'],
            'created_at' => Time::iso($u['created_at']),
            'last_login' => Time::iso($lastLogin[(int) $u['id']] ?? null),
        ], $rows);
    }

    /** Update a cabinet member's portfolio and areas, or switch their account off and on. */
    public function updateStaff(Request $request): array
    {
        $admin = Auth::requireRole($request, ['admin']);
        $user = Db::one('SELECT id, role FROM users WHERE id = ?', [$request->id()]) ?? throw new HttpError(404, 'Account not found');
        $in = $request->input();

        if ($in->has('is_active') || $in->raw('is_active') === false) {
            $active = $in->bool('is_active');
            if ((int) $user['id'] === $admin['id'] && !$active) {
                throw new HttpError(400, 'You cannot switch off your own account');
            }
            Db::run('UPDATE users SET is_active = ? WHERE id = ?', [$active, $user['id']]);
            if (!$active) {
                // Work held by someone who has left goes back to the queue for reassignment.
                Db::run(
                    "UPDATE assignments SET status = 'reassigned' WHERE status = 'active'
                        AND delegate_id IN (SELECT id FROM delegates WHERE user_id = ?)",
                    [$user['id']]
                );
                Db::run(
                    "UPDATE concerns SET status = 'new' WHERE status = 'assigned'
                        AND id NOT IN (SELECT concern_id FROM assignments WHERE status = 'active')"
                );
            }
        }

        if ($user['role'] !== 'admin' && $in->has('portfolio')) {
            Db::run(
                'UPDATE delegates SET portfolio = ?, categories_handled = ?, campus = ?, max_open_load = ? WHERE user_id = ?',
                [
                    $in->text('portfolio', 'Portfolio', 1, 120),
                    $in->text('categories_handled', 'Categories', 0, 300),
                    $in->choice('campus', self::CAMPUSES, '', 'Choose a campus'),
                    $in->int('max_open_load', 'Maximum open assignments', 5, 1, 50),
                    $user['id'],
                ]
            );
        }

        Auth::audit($admin['id'], 'update_staff', 'user', (int) $user['id']);
        return ['ok' => true];
    }

    public function invitations(Request $request): array
    {
        Auth::requireRole($request, ['admin']);
        $rows = Db::all(
            'SELECT i.id, i.role, i.invitee_name, i.invitee_email, i.portfolio, i.created_at, i.expires_at,
                    i.redeemed_at, i.revoked_at, u.full_name AS created_by
               FROM staff_invitations i JOIN users u ON u.id = i.created_by
              ORDER BY i.id DESC LIMIT 100'
        );
        return array_map(static function ($i) {
            $status = $i['redeemed_at'] ? 'used' : ($i['revoked_at'] ? 'revoked'
                : (strtotime($i['expires_at'] . ' UTC') <= time() ? 'expired' : 'pending'));
            return [
                'id' => (int) $i['id'],
                'role' => $i['role'],
                'invitee_name' => $i['invitee_name'],
                'invitee_email' => $i['invitee_email'],
                'portfolio' => $i['portfolio'],
                'created_by' => $i['created_by'],
                'created_at' => Time::iso($i['created_at']),
                'expires_at' => Time::iso($i['expires_at']),
                'status' => $status,
            ];
        }, $rows);
    }

    public function revokeInvitation(Request $request): array
    {
        $admin = Auth::requireRole($request, ['admin']);
        Db::run(
            'UPDATE staff_invitations SET revoked_at = ? WHERE id = ? AND redeemed_at IS NULL AND revoked_at IS NULL',
            [Time::now(), $request->id()]
        );
        Auth::audit($admin['id'], 'revoke_invitation', 'staff_invitation', $request->id());
        return ['ok' => true];
    }
}
