<?php
declare(strict_types=1);

namespace SyncCampus\Controller;

use SyncCampus\Auth;
use SyncCampus\Db;
use SyncCampus\HttpError;
use SyncCampus\Request;
use SyncCampus\Response;
use SyncCampus\Time;

/**
 * Supporting Guild workspace: projects and tasks, announcements, events,
 * media links, surveys, the student suggestion inbox and internal staff chat.
 */
final class WorkspaceController
{
    public const ITEM_KINDS = ['project', 'announcement', 'event', 'media'];
    private const TASK_STATES = ['open', 'in_progress', 'completed'];
    private const SUGGESTION_STATES = ['new', 'reviewing', 'accepted', 'declined', 'implemented'];

    public function items(Request $request): array
    {
        Auth::requireRole($request, Auth::STAFF);
        return self::formatItems(Db::all('SELECT * FROM workspace_items ORDER BY kind, id DESC LIMIT 200'));
    }

    public function createItem(Request $request): Response
    {
        $user = Auth::requireRole($request, Auth::LEADERS);
        $in = $request->input();

        $url = $in->text('url', 'Link', 0, 1000);
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            throw new HttpError(422, 'Links must start with https:// or http://');
        }
        $kind = $in->choice('kind', self::ITEM_KINDS, null, 'Choose a supported item type');
        if ($kind === null) {
            throw new HttpError(422, 'Choose a supported item type');
        }

        $now = Time::now();
        $id = Db::insert('workspace_items', [
            'kind' => $kind,
            'title' => $in->text('title', 'Title', 2, 200),
            'description' => $in->text('description', 'Description', 0, 5000),
            'status' => 'active',
            'visibility' => $in->choice('visibility', ['staff', 'public'], 'staff', 'Choose who can see this item'),
            'starts_at' => Time::fromInput($in->raw('starts_at')),
            'ends_at' => Time::fromInput($in->raw('ends_at')),
            'due_at' => Time::fromInput($in->raw('due_at')),
            'location' => $in->text('location', 'Location', 0, 160),
            'url' => $url,
            'created_by' => $user['id'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Auth::audit($user['id'], 'create_workspace_item', $kind, $id);

        return Response::created(self::formatItems([Db::one('SELECT * FROM workspace_items WHERE id = ?', [$id])])[0]);
    }

    public function addTask(Request $request): Response
    {
        $user = Auth::requireRole($request, Auth::LEADERS);
        $project = Db::one("SELECT id FROM workspace_items WHERE id = ? AND kind = 'project'", [$request->id()]);
        if ($project === null) {
            throw new HttpError(404, 'Project not found');
        }
        $in = $request->input();
        $assignee = $in->optionalInt('assigned_to_user_id');
        if ($assignee !== null && !Db::value('SELECT id FROM users WHERE id = ? AND is_active = TRUE', [$assignee])) {
            throw new HttpError(404, 'Staff account not found');
        }

        $id = Db::insert('project_tasks', [
            'project_id' => (int) $project['id'],
            'title' => $in->text('title', 'Task title', 2, 200),
            'status' => 'open',
            'assigned_to_user_id' => $assignee,
            'due_at' => Time::fromInput($in->raw('due_at')),
            'created_by' => $user['id'],
            'created_at' => Time::now(),
        ]);
        Auth::audit($user['id'], 'create_project_task', 'project_task', $id);
        return Response::created(['id' => $id]);
    }

    public function updateTask(Request $request): array
    {
        $user = Auth::requireRole($request, Auth::STAFF);
        $task = Db::one('SELECT id, assigned_to_user_id FROM project_tasks WHERE id = ?', [$request->id()]);
        if ($task === null) {
            throw new HttpError(404, 'Project task not found');
        }
        if ($user['role'] === 'delegate' && (int) $task['assigned_to_user_id'] !== $user['id']) {
            throw new HttpError(403, 'You can only update tasks assigned to you');
        }
        $status = $request->input()->choice('status', self::TASK_STATES, null, 'Task status must be open, in_progress or completed');
        if ($status === null) {
            throw new HttpError(422, 'Task status must be open, in_progress or completed');
        }
        Db::run('UPDATE project_tasks SET status = ? WHERE id = ?', [$status, $task['id']]);
        Auth::audit($user['id'], 'update_project_task', 'project_task', (int) $task['id']);
        return ['id' => (int) $task['id'], 'status' => $status];
    }

    public function messages(Request $request): array
    {
        Auth::requireRole($request, Auth::STAFF);
        $rows = Db::all(
            'SELECT m.id, m.message, m.created_at, u.full_name
               FROM internal_messages m JOIN users u ON u.id = m.author_user_id
              ORDER BY m.id DESC LIMIT 100'
        );
        return array_reverse(array_map(static fn ($m) => [
            'id' => (int) $m['id'],
            'author' => $m['full_name'],
            'message' => $m['message'],
            'at' => Time::iso($m['created_at']),
        ], $rows));
    }

    public function postMessage(Request $request): Response
    {
        $user = Auth::requireRole($request, Auth::STAFF);
        Db::insert('internal_messages', [
            'author_user_id' => $user['id'],
            'message' => $request->input()->text('message', 'Message', 1, 3000),
            'created_at' => Time::now(),
        ]);
        return Response::created(['ok' => true]);
    }

    public function surveys(Request $request): array
    {
        Auth::requireRole($request, Auth::LEADERS);
        $counts = [];
        foreach (Db::all('SELECT survey_id, answer, COUNT(*) AS n FROM survey_responses GROUP BY survey_id, answer') as $r) {
            $counts[(int) $r['survey_id']][$r['answer']] = (int) $r['n'];
        }

        return array_map(static function ($s) use ($counts) {
            $options = json_decode($s['options_json'], true) ?: [];
            $results = [];
            foreach ($options as $option) {
                $results[$option] = $counts[(int) $s['id']][$option] ?? 0;
            }
            return [
                'id' => (int) $s['id'],
                'title' => $s['title'],
                'question' => $s['question'],
                'options' => $options,
                'is_published' => (bool) $s['is_published'],
                'closes_at' => Time::iso($s['closes_at']),
                'results' => (object) $results,
                'response_count' => array_sum($counts[(int) $s['id']] ?? []),
            ];
        }, Db::all('SELECT * FROM surveys ORDER BY id DESC'));
    }

    public function createSurvey(Request $request): Response
    {
        $user = Auth::requireRole($request, Auth::LEADERS);
        $in = $request->input();

        $options = array_values(array_unique(array_filter(array_map(
            static fn ($o) => mb_substr(trim((string) $o), 0, 500),
            $in->list('options')
        ), 'strlen')));
        if (count($options) < 2 || count($options) > 20) {
            throw new HttpError(422, 'Enter between two and twenty distinct answer options');
        }

        $id = Db::insert('surveys', [
            'title' => $in->text('title', 'Survey title', 2, 200),
            'question' => $in->text('question', 'Question', 2, 2000),
            'options_json' => json_encode($options, JSON_UNESCAPED_UNICODE),
            'is_published' => $in->bool('is_published'),
            'closes_at' => Time::fromInput($in->raw('closes_at')),
            'created_by' => $user['id'],
            'created_at' => Time::now(),
        ]);
        Auth::audit($user['id'], 'create_survey', 'survey', $id);
        return Response::created(['id' => $id]);
    }

    public function togglePublished(Request $request): array
    {
        $user = Auth::requireRole($request, Auth::LEADERS);
        $survey = Db::one('SELECT id, is_published FROM surveys WHERE id = ?', [$request->id()]);
        if ($survey === null) {
            throw new HttpError(404, 'Survey not found');
        }
        $published = !$survey['is_published'];
        Db::run('UPDATE surveys SET is_published = ? WHERE id = ?', [$published, $survey['id']]);
        Auth::audit($user['id'], 'publish_survey', 'survey', (int) $survey['id']);
        return ['is_published' => $published];
    }

    public function suggestions(Request $request): array
    {
        Auth::requireRole($request, Auth::LEADERS);
        $rows = Db::all('SELECT * FROM student_suggestions ORDER BY created_at DESC LIMIT 200');
        return array_map(static fn ($s) => [
            'id' => (int) $s['id'],
            'reference' => $s['reference'],
            'title' => $s['title'],
            'description' => $s['description'],
            'status' => $s['status'],
            'created_at' => Time::iso($s['created_at']),
        ], $rows);
    }

    public function updateSuggestion(Request $request): array
    {
        $user = Auth::requireRole($request, Auth::LEADERS);
        $suggestion = Db::one('SELECT id, status FROM student_suggestions WHERE id = ?', [$request->id()]);
        if ($suggestion === null) {
            throw new HttpError(404, 'Suggestion not found');
        }
        $in = $request->input();
        $status = $in->choice('status', self::SUGGESTION_STATES, $suggestion['status'], 'Invalid suggestion status');
        $message = $in->text('message', 'Reply', 0, 3000);

        Db::transaction(static function () use ($suggestion, $status, $message, $user) {
            Db::run('UPDATE student_suggestions SET status = ? WHERE id = ?', [$status, $suggestion['id']]);
            if ($message !== '') {
                Db::insert('suggestion_updates', [
                    'suggestion_id' => (int) $suggestion['id'],
                    'author_user_id' => $user['id'],
                    'author_label' => $user['full_name'],
                    'message' => $message,
                    'created_at' => Time::now(),
                ]);
            }
            Auth::audit($user['id'], 'update_suggestion', 'suggestion', (int) $suggestion['id']);
        });
        return ['status' => $status];
    }

    /** Shared with the public community page. Tasks are only attached to projects. */
    public static function formatItems(array $items): array
    {
        $tasks = [];
        $projectIds = array_column(array_filter($items, static fn ($i) => $i['kind'] === 'project'), 'id');
        if ($projectIds) {
            $marks = implode(',', array_fill(0, count($projectIds), '?'));
            $rows = Db::all(
                "SELECT t.*, u.full_name AS assigned_to FROM project_tasks t
                   LEFT JOIN users u ON u.id = t.assigned_to_user_id
                  WHERE t.project_id IN ($marks) ORDER BY t.id",
                $projectIds
            );
            foreach ($rows as $t) {
                $tasks[(int) $t['project_id']][] = [
                    'id' => (int) $t['id'],
                    'title' => $t['title'],
                    'status' => $t['status'],
                    'assigned_to_user_id' => $t['assigned_to_user_id'] === null ? null : (int) $t['assigned_to_user_id'],
                    'assigned_to' => $t['assigned_to'],
                    'due_at' => Time::iso($t['due_at']),
                ];
            }
        }

        return array_map(static fn ($i) => [
            'id' => (int) $i['id'],
            'kind' => $i['kind'],
            'title' => $i['title'],
            'description' => $i['description'],
            'status' => $i['status'],
            'visibility' => $i['visibility'],
            'starts_at' => Time::iso($i['starts_at']),
            'ends_at' => Time::iso($i['ends_at']),
            'due_at' => Time::iso($i['due_at']),
            'location' => $i['location'],
            'url' => $i['url'],
            'tasks' => $tasks[(int) $i['id']] ?? [],
        ], $items);
    }
}
