<?php
declare(strict_types=1);

/*
 * Fills a development database with a small Guild cabinet and sample
 * concerns so every screen has something to show. Refuses to run when
 * APP_ENV=production.
 *
 *   php bin/seed-demo.php
 *
 * All demo accounts use the password: Demo-password-2026
 */

use SyncCampus\Auth;
use SyncCampus\Codes;
use SyncCampus\Db;
use SyncCampus\Engine\Rules;
use SyncCampus\Env;
use SyncCampus\Service\Concerns;
use SyncCampus\Time;

require dirname(__DIR__) . '/src/bootstrap.php';

if (Env::isProduction()) {
    fwrite(STDERR, "Demo data is not allowed when APP_ENV=production.\n");
    exit(1);
}
if (Db::value("SELECT COUNT(*) FROM users WHERE email LIKE '%@demo.local'") > 0) {
    echo "Demo data is already loaded.\n";
    exit(0);
}

const DEMO_PASSWORD = 'Demo-password-2026';

$staff = [
    // name, email, role, portfolio, categories handled, max load, campus ('' = all)
    ['System Admin', 'admin@demo.local', 'admin', '', '', 0, ''],
    ['Guild President', 'president@demo.local', 'president', 'Guild President', 'General', 5, ''],
    ['Guild Vice President', 'vice@demo.local', 'vice_guild', 'Guild Vice President', 'General', 5, ''],
    ['Grace Nakato', 'nakato@demo.local', 'delegate', 'Minister of Academic Affairs', 'Academic issues,Examinations,Timetables,Registration', 6, ''],
    ['Ronald Okello', 'okello@demo.local', 'delegate', 'Minister of ICT', 'ICT / Internet', 5, 'Main Campus'],
    ['Sarah Kyomuhendo', 'kyomuhendo@demo.local', 'delegate', 'Minister of Finance', 'Tuition / fees,Administration', 5, ''],
    ['Joseph Mukasa', 'mukasa@demo.local', 'delegate', 'Minister of Security and Facilities', 'Security,Campus facilities', 5, ''],
    ['Doreen Lubega', 'lubega@demo.local', 'delegate', 'Minister of Welfare', 'Student welfare,Student activities / events,Harassment / discrimination', 5, ''],
    ['Brian Ssemanda', 'ssemanda@demo.local', 'delegate', 'GRC – City Campus', 'General,Campus facilities,ICT / Internet', 4, 'City Campus'],
];

foreach ($staff as [$name, $email, $role, $portfolio, $areas, $load, $campus]) {
    $userId = Db::insert('users', [
        'full_name' => $name,
        'email' => $email,
        'password_hash' => Auth::hashPassword(DEMO_PASSWORD),
        'role' => $role,
        'is_active' => true,
        'created_at' => Time::now(),
    ]);
    if ($role !== 'admin') {
        Db::insert('delegates', [
            'user_id' => $userId,
            'portfolio' => $portfolio,
            'categories_handled' => $areas,
            'max_open_load' => $load,
            'campus' => $campus,
            'is_available' => true,
            'show_public' => true,
        ]);
    }
}

$category = static fn (string $name) => (int) Db::value('SELECT id FROM categories WHERE name = ?', [$name]);

$concerns = [
    // category, title, description, campus, location, urgency, affected, safety, public, hours ago
    // (academic / financial impact follow the category defaults the form would pre-tick)
    ['ICT / Internet', 'Wi-Fi down in the library', 'The wifi in the main library has not been working since Monday. We cannot download notes or submit coursework online.', 'Main Campus', 'Library', 3, 60, false, true, 30],
    ['ICT / Internet', 'No internet connection in the library', 'Internet connection keeps failing in the library, students cannot connect to the network for research.', 'Main Campus', 'Library', 2, 20, false, true, 20],
    ['Examinations', 'Missing coursework marks for BSc Software Engineering', 'Year 2 coursework marks for Database Systems are missing on the portal and exams start next week.', 'Main Campus', 'Block B', 3, 45, false, false, 80],
    ['Campus facilities', 'Broken chairs in lecture room C4', 'Several chairs in room C4 are broken and students stand during evening lectures.', 'City Campus', 'Room C4', 2, 30, false, true, 130],
    ['Security', 'Street light at the back gate is off', 'The light near the back gate has been off for a week. Evening students walk in the dark.', 'Main Campus', 'Back gate', 3, 100, true, true, 4],
    ['Tuition / fees', 'Payment not reflecting on student portal', 'I paid tuition through the bank two weeks ago but the portal still shows a balance and blocks my exam permit.', 'City Campus', 'Finance office', 3, 1, false, false, 50],
    ['Student welfare', 'Not enough drinking water points', 'There is only one working water dispenser for the whole of Block A.', 'Main Campus', 'Block A', 1, 200, false, true, 200],
];

foreach ($concerns as [$cat, $title, $text, $campus, $location, $urgency, $affected, $safety, $public, $hoursAgo]) {
    [$concern] = Concerns::create([
        'title' => $title,
        'description' => $text,
        'category_id' => $category($cat),
        'campus' => $campus,
        'location' => $location,
        'urgency' => $urgency,
        'students_affected' => $affected,
        'safety_flag' => $safety,
        'is_public' => $public,
        'academic_impact' => in_array($cat, Rules::ACADEMIC_CATEGORIES, true) || $cat === 'ICT / Internet',
        'financial_impact' => in_array($cat, Rules::FINANCIAL_CATEGORIES, true),
    ]);
    Db::run('UPDATE concerns SET created_at = ? WHERE id = ?', [Time::now(-$hoursAgo * 3600), $concern['id']]);
}

// Guild workspace: projects with tasks, meetings, an announcement, a survey and suggestions.
$userId = static fn (string $email) => (int) Db::value('SELECT id FROM users WHERE email = ?', [$email]);
$president = $userId('president@demo.local');
$day = static fn (float $days, int $hour = 10) => gmdate('Y-m-d H:i:s', strtotime(gmdate('Y-m-d', time() + (int) ($days * 86400))) + $hour * 3600);
$item = static function (array $row) use ($president): int {
    return Db::insert('workspace_items', $row + [
        'description' => '', 'status' => 'active', 'visibility' => 'staff', 'starts_at' => null, 'ends_at' => null,
        'due_at' => null, 'location' => '', 'url' => '', 'created_by' => $president,
        'created_at' => Time::now(), 'updated_at' => Time::now(),
    ]);
};
$task = static function (int $project, string $title, string $status, ?int $user, ?string $due) use ($president): void {
    Db::insert('project_tasks', ['project_id' => $project, 'title' => $title, 'status' => $status, 'assigned_to_user_id' => $user,
        'due_at' => $due, 'created_by' => $president, 'created_at' => Time::now()]);
};

$wifi = $item(['kind' => 'project', 'title' => 'Library Wi-Fi upgrade', 'description' => 'Work with ICT to add access points in the main library.', 'due_at' => $day(21)]);
$task($wifi, 'Survey weak spots with ICT office', 'completed', $userId('okello@demo.local'), $day(-3));
$task($wifi, 'Get a quotation for new access points', 'in_progress', $userId('okello@demo.local'), $day(4));
$task($wifi, 'Present the plan to management', 'open', $president, $day(10));
$water = $item(['kind' => 'project', 'title' => 'More drinking water points', 'description' => 'Two new dispensers for Block A and the canteen.', 'due_at' => $day(30)]);
$task($water, 'Count students per block', 'completed', $userId('lubega@demo.local'), $day(-6));
$task($water, 'Meet the estates manager', 'open', $userId('mukasa@demo.local'), $day(6));
$week = $item(['kind' => 'project', 'title' => 'Freshers welcome week', 'description' => 'Orientation activities for the new intake.', 'visibility' => 'public', 'due_at' => $day(45)]);
$task($week, 'Book the main hall', 'in_progress', $userId('lubega@demo.local'), $day(8));
$task($week, 'Design posters', 'open', null, $day(14));
$item(['kind' => 'event', 'title' => 'Cabinet weekly meeting', 'location' => 'Guild office', 'starts_at' => $day(2, 14)]);
$item(['kind' => 'event', 'title' => 'Guild town hall', 'description' => 'Open meeting for all students.', 'visibility' => 'public', 'location' => 'Main hall', 'starts_at' => $day(9, 11)]);
$item(['kind' => 'announcement', 'title' => 'Exam timetable published', 'description' => 'Check the portal for your final exam dates.', 'visibility' => 'public', 'starts_at' => $day(-1, 9)]);

$surveyId = Db::insert('surveys', ['title' => 'Library opening hours', 'question' => 'Should the library open on Sundays during exams?',
    'options_json' => json_encode(['Yes', 'No', 'Only in the afternoon']), 'is_published' => true, 'closes_at' => null,
    'created_by' => $president, 'created_at' => Time::now()]);
foreach (['Yes' => 14, 'No' => 3, 'Only in the afternoon' => 6] as $answer => $count) {
    for ($i = 0; $i < $count; $i++) {
        Db::insert('survey_responses', ['survey_id' => $surveyId, 'respondent_hash' => hash('sha256', "demo-$answer-$i"),
            'answer' => $answer, 'comment' => '', 'submitted_at' => Time::now()]);
    }
}
foreach ([['Longer library hours', 'Keep the library open until midnight during exams.', 'reviewing'],
          ['Shuttle between campuses', 'A morning shuttle from City Campus to Main Campus.', 'new'],
          ['Water dispensers in Block A', 'Only one dispenser works for the whole block.', 'accepted']] as [$t, $d, $s]) {
    Db::insert('student_suggestions', ['reference' => Codes::reference('SG', 'student_suggestions'),
        'access_code_hash' => Codes::hashAccessCode(Codes::accessCode()), 'title' => $t, 'description' => $d, 'status' => $s, 'created_at' => Time::now()]);
}
Db::insert('internal_messages', ['author_user_id' => $userId('okello@demo.local'), 'message' => 'The ICT office will share the Wi-Fi survey results on Friday.', 'created_at' => Time::now(-3600)]);
Db::insert('internal_messages', ['author_user_id' => $president, 'message' => 'Thanks. Please add them to the project before the cabinet meeting.', 'created_at' => Time::now(-1800)]);

$fired = Concerns::runEscalationChecks();

echo "Demo data loaded: " . count($staff) . " staff accounts, " . count($concerns) . " concerns, "
    . count($fired) . " reminders/escalations.\n";
echo "Sign in at /staff/ as president@demo.local (or any demo account), or at /admin/ as admin@demo.local,\n";
echo "with the password " . DEMO_PASSWORD . "\n";
