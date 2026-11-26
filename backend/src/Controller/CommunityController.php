<?php
declare(strict_types=1);

namespace SyncCampus\Controller;

use SyncCampus\Codes;
use SyncCampus\Db;
use SyncCampus\HttpError;
use SyncCampus\Input;
use SyncCampus\Request;
use SyncCampus\Response;
use SyncCampus\Throttle;
use SyncCampus\Time;

/** Public "Guild updates" page: announcements, open surveys and anonymous suggestions. */
final class CommunityController
{
    public function updates(): array
    {
        $rows = Db::all(
            "SELECT * FROM workspace_items
              WHERE visibility = 'public' AND kind IN ('announcement', 'event', 'media')
              ORDER BY starts_at IS NULL, starts_at, id DESC LIMIT 100"
        );
        return array_map(static function ($item) {
            unset($item['tasks']);
            return $item;
        }, WorkspaceController::formatItems($rows));
    }

    public function surveys(): array
    {
        $rows = Db::all(
            'SELECT id, title, question, options_json, closes_at FROM surveys
              WHERE is_published = TRUE AND (closes_at IS NULL OR closes_at > ?) ORDER BY id DESC',
            [Time::now()]
        );
        return array_map(static fn ($s) => [
            'id' => (int) $s['id'],
            'title' => $s['title'],
            'question' => $s['question'],
            'options' => json_decode($s['options_json'], true) ?: [],
            'closes_at' => Time::iso($s['closes_at']),
        ], $rows);
    }

    public function answerSurvey(Request $request): Response
    {
        $survey = Db::one(
            'SELECT id, options_json FROM surveys WHERE id = ? AND is_published = TRUE AND (closes_at IS NULL OR closes_at > ?)',
            [$request->id(), Time::now()]
        );
        if ($survey === null) {
            throw new HttpError(404, 'This survey is not available');
        }

        $in = $request->input();
        $answer = $in->text('answer', 'Answer', 1, 500);
        if (!in_array($answer, json_decode($survey['options_json'], true) ?: [], true)) {
            throw new HttpError(422, 'Select one of the listed answers');
        }

        // The browser keeps a random id per survey; only its hash is stored, to stop repeat votes.
        $respondent = hash('sha256', 'saagi-survey:' . $in->text('respondent_token', 'Respondent token', 20, 200));
        if (Db::value('SELECT id FROM survey_responses WHERE survey_id = ? AND respondent_hash = ?', [$survey['id'], $respondent])) {
            throw new HttpError(409, 'A response has already been recorded from this browser');
        }

        Db::insert('survey_responses', [
            'survey_id' => (int) $survey['id'],
            'respondent_hash' => $respondent,
            'answer' => $answer,
            'comment' => $in->text('comment', 'Comment', 0, 2000),
            'submitted_at' => Time::now(),
        ]);
        return Response::created(['ok' => true]);
    }

    public function submitSuggestion(Request $request): Response
    {
        $in = $request->input();
        $code = Codes::accessCode();
        $reference = Codes::reference('SG', 'student_suggestions');

        Db::insert('student_suggestions', [
            'reference' => $reference,
            'access_code_hash' => Codes::hashAccessCode($code),
            'title' => $in->text('title', 'Title', 5, 200),
            'description' => $in->text('description', 'Your idea', 10, 5000),
            'status' => 'new',
            'created_at' => Time::now(),
        ]);

        return Response::created([
            'reference' => $reference,
            'access_code' => $code,
            'note' => 'Save the access code now. It is shown once and cannot be recovered.',
        ]);
    }

    public function trackSuggestion(Request $request): array
    {
        $suggestion = $this->unlock($request->input());
        $updates = Db::all(
            'SELECT author_label, message, created_at FROM suggestion_updates WHERE suggestion_id = ? ORDER BY id',
            [$suggestion['id']]
        );
        return [
            'reference' => $suggestion['reference'],
            'title' => $suggestion['title'],
            'description' => $suggestion['description'],
            'status' => $suggestion['status'],
            'created_at' => Time::iso($suggestion['created_at']),
            'updates' => array_map(static fn ($u) => [
                'from' => $u['author_label'],
                'message' => $u['message'],
                'at' => Time::iso($u['created_at']),
            ], $updates),
        ];
    }

    public function replyToSuggestion(Request $request): array
    {
        $in = $request->input();
        $suggestion = $this->unlock($in);
        Db::insert('suggestion_updates', [
            'suggestion_id' => (int) $suggestion['id'],
            'author_user_id' => null,
            'author_label' => 'Student',
            'message' => $in->text('message', 'Reply', 2, 3000),
            'created_at' => Time::now(),
        ]);
        return ['ok' => true];
    }

    private function unlock(Input $in): array
    {
        $reference = strtoupper($in->text('reference', 'Reference', 1, 20));
        $key = 'suggestion:' . $reference;

        Throttle::check($key);
        $suggestion = Db::one('SELECT * FROM student_suggestions WHERE reference = ?', [$reference]);
        $code = $in->text('access_code', 'Access code', 1, 40);
        if ($suggestion === null || !hash_equals($suggestion['access_code_hash'], Codes::hashAccessCode($code))) {
            Throttle::fail($key);
            throw new HttpError(404, 'Reference or access code not recognised');
        }
        Throttle::clear($key);
        return $suggestion;
    }
}
