<?php
declare(strict_types=1);

/*
 * Creates any missing tables, adds the default categories and, when
 * BOOTSTRAP_ADMIN_EMAIL / BOOTSTRAP_ADMIN_PASSWORD are set, the system admin
 * account. Safe to run on every deploy.
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
    ['sessions', 'is_primary', 'BOOLEAN NOT NULL DEFAULT FALSE'],
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

$email = strtolower(Env::get('BOOTSTRAP_ADMIN_EMAIL'));
$password = Env::get('BOOTSTRAP_ADMIN_PASSWORD');
if ($email === '') {
    exit(0);
}
if (strlen($password) < 8) {
    fwrite(STDERR, "BOOTSTRAP_ADMIN_PASSWORD must be at least 8 characters; no admin was created.
");
    exit(1);
}
$existing = Db::one('SELECT id, role FROM users WHERE email = ?', [$email]);
if ($existing === null) {
    Db::insert('users', [
        'full_name' => 'System administrator',
        'email' => $email,
        'password_hash' => Auth::hashPassword($password),
        'role' => 'admin',
        'is_active' => true,
        'created_at' => Time::now(),
    ]);
    echo "Created admin account $email.
";
} elseif ($existing['role'] !== 'admin') {
    fwrite(STDERR, "$email already belongs to a non-admin account; leaving it unchanged.
");
} else {
    // Never overwrite a password the admin has since changed in the app.
    echo "Admin account $email already exists.
";
}
