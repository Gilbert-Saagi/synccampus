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

$fired = Concerns::runEscalationChecks();

echo "Demo data loaded: " . count($staff) . " staff accounts, " . count($concerns) . " concerns, "
    . count($fired) . " reminders/escalations.\n";
echo "Sign in at /staff/ as president@demo.local (or any demo account), or at /admin/ as admin@demo.local,\n";
echo "with the password " . DEMO_PASSWORD . "\n";
