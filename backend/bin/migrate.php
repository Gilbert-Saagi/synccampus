<?php
declare(strict_types=1);

/*
 * Creates any missing tables, adds the default categories and, when
 * BOOTSTRAP_ADMIN_EMAIL is set, the system admin account. Safe to run on
 * every deploy.
 *
 *   php bin/migrate.php
 */

use SyncCampus\Auth;
use SyncCampus\Db;
use SyncCampus\Env;
use SyncCampus\Time;

require dirname(__DIR__) . '/src/bootstrap.php';

$file = dirname(__DIR__) . '/database/' . (Db::isPostgres() ? 'schema.pgsql.sql' : 'schema.mysql.sql');
$sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($file));
foreach (preg_split('/;\s*$/m', $sql) as $statement) {
    if (trim($statement) !== '') {
        Db::pdo()->exec($statement);
    }
}
// Columns added after the first release. CREATE TABLE IF NOT EXISTS skips
// tables that already exist, so older databases get them here.
$timestamp = Db::isPostgres() ? 'TIMESTAMP' : 'DATETIME';
$added = [
    ['concerns', 'academic_impact', 'BOOLEAN NOT NULL DEFAULT FALSE'],
    ['concerns', 'financial_impact', 'BOOLEAN NOT NULL DEFAULT FALSE'],
    ['concerns', 'satisfaction_rating', 'INTEGER NULL'],
    ['delegates', 'campus', "VARCHAR(60) NOT NULL DEFAULT ''"],
    ['staff_invitations', 'campus', "VARCHAR(60) NOT NULL DEFAULT ''"],
    ['staff_invitations', 'invitee_name', "VARCHAR(120) NOT NULL DEFAULT ''"],
    ['assignments', 'seen_at', "$timestamp NULL"],
];
$schema = Db::isPostgres() ? 'current_schema()' : 'DATABASE()';
foreach ($added as [$table, $column, $definition]) {
    $exists = Db::value(
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = $schema AND table_name = ? AND column_name = ?",
        [$table, $column]
    );
    if (!(int) $exists) {
        Db::pdo()->exec("ALTER TABLE $table ADD COLUMN $column $definition");
        echo "Added $table.$column\n";
    }
}

echo 'Schema is up to date (' . basename($file) . ").\n";

// The admin signs in with an emailed link, so only the address is needed.
$email = strtolower(Env::get('BOOTSTRAP_ADMIN_EMAIL'));
if ($email === '') {
    exit(0);
}
$existing = Db::one('SELECT id, role FROM users WHERE email = ?', [$email]);
if ($existing === null) {
    Db::insert('users', [
        'full_name' => 'System administrator',
        'email' => $email,
        // Never used: admin password sign-in is switched off.
        'password_hash' => Auth::hashPassword(bin2hex(random_bytes(32))),
        'role' => 'admin',
        'is_active' => true,
        'created_at' => Time::now(),
    ]);
    echo "Created admin account $email (signs in with an emailed link at /admin/).
";
} elseif ($existing['role'] !== 'admin') {
    fwrite(STDERR, "$email already belongs to a non-admin account; leaving it unchanged.
");
} else {
    echo "Admin account $email already exists.
";
}
