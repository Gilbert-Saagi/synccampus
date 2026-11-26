<?php
declare(strict_types=1);

namespace SyncCampus\Service;

use SyncCampus\Db;
use SyncCampus\Engine\Recommender;

/**
 * Delegates together with their current workload and track record, loaded
 * once per request so the whole priority queue can be scored without a query
 * per concern.
 */
final class DelegatePool
{
    private array $delegates = [];
    private array $openLoad = [];
    private array $experience = [];

    public function __construct()
    {
        $this->delegates = Db::all(
            "SELECT d.id, d.user_id, d.portfolio, d.categories_handled, d.campus, d.max_open_load, d.is_available,
                    u.full_name AS name
               FROM delegates d
               JOIN users u ON u.id = d.user_id
              WHERE u.role = 'delegate' AND u.is_active = TRUE
              ORDER BY u.full_name"
        );

        $rows = Db::all(
            "SELECT a.delegate_id, COUNT(*) AS n
               FROM assignments a
               JOIN concerns c ON c.id = a.concern_id
              WHERE a.status = 'active' AND c.status IN ('new', 'assigned', 'in_progress')
              GROUP BY a.delegate_id"
        );
        foreach ($rows as $row) {
            $this->openLoad[(int) $row['delegate_id']] = (int) $row['n'];
        }

        $rows = Db::all(
            "SELECT a.delegate_id, c.category_id, COUNT(*) AS n
               FROM assignments a
               JOIN concerns c ON c.id = a.concern_id
              WHERE a.status = 'completed'
              GROUP BY a.delegate_id, c.category_id"
        );
        foreach ($rows as $row) {
            $this->experience[(int) $row['delegate_id']][(int) $row['category_id']] = (int) $row['n'];
        }
    }

    /** Ranked suggestions for one concern, best first. */
    public function recommend(array $concern): array
    {
        $candidates = [];
        foreach ($this->delegates as $d) {
            $id = (int) $d['id'];
            $candidates[] = [
                'id' => $id,
                'name' => $d['name'],
                'portfolio' => $d['portfolio'],
                'categories_handled' => $d['categories_handled'],
                'campus' => $d['campus'],
                'open_load' => $this->openLoad[$id] ?? 0,
                'max_open_load' => (int) $d['max_open_load'],
                'resolved_similar' => $this->experience[$id][(int) $concern['category_id']] ?? 0,
                'is_available' => (bool) $d['is_available'],
            ];
        }
        return Recommender::rank($candidates, $concern['category'], (string) $concern['campus']);
    }

    public function workload(): array
    {
        return array_map(fn ($d) => [
            'delegate_id' => (int) $d['id'],
            'user_id' => (int) $d['user_id'],
            'name' => $d['name'],
            'portfolio' => $d['portfolio'],
            'campus' => $d['campus'],
            'available' => (bool) $d['is_available'],
            'open' => $this->openLoad[(int) $d['id']] ?? 0,
            'max' => (int) $d['max_open_load'],
        ], $this->delegates);
    }
}
