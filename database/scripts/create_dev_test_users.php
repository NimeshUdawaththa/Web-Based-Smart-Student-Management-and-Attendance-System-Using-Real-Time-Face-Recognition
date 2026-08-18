<?php

declare(strict_types=1);

/**
 * DEVELOPMENT ONLY — create one test login for each role.
 *
 * This script is not part of the production application.
 * Run it from the command line. It will refuse HTTP requests and
 * refuse to run when APP_ENV=production or APP_DEBUG is false.
 *
 * Usage (from the project root):
 *   C:\xampp\php\php.exe database/scripts/create_dev_test_users.php
 *
 * To disable later:
 *   1. Delete this file (database/scripts/create_dev_test_users.php).
 *   2. Delete database/scripts/README.md if it is no longer needed.
 *   3. Remove the test users from MySQL:
 *        DELETE FROM users
 *        WHERE username IN ('admin', 'academic_staff', 'lecturer', 'student');
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This development script can only be run from the command line.');
}

define('APP_STARTED', true);

require_once dirname(__DIR__, 2) . '/web/config/env.php';
require_once dirname(__DIR__, 2) . '/web/config/config.php';
require_once dirname(__DIR__, 2) . '/web/config/database.php';

if (APP_ENV === 'production' || !APP_DEBUG) {
    fwrite(STDERR, "Refusing to create test users outside a local debug environment.\n");
    exit(1);
}

$accounts = [
    [
        'username' => 'admin',
        'email' => 'admin@localhost.test',
        'role' => 'ADMIN',
        'password' => 'Admin123!',
    ],
    [
        'username' => 'academic_staff',
        'email' => 'academic_staff@localhost.test',
        'role' => 'ACADEMIC_STAFF',
        'password' => 'Academic123!',
    ],
    [
        'username' => 'lecturer',
        'email' => 'lecturer@localhost.test',
        'role' => 'LECTURER',
        'password' => 'Lecturer123!',
    ],
    [
        'username' => 'student',
        'email' => 'student@localhost.test',
        'role' => 'STUDENT',
        'password' => 'Student123!',
    ],
];

try {
    $pdo = db();
} catch (Throwable $exception) {
    fwrite(STDERR, "Database connection failed. Check .env and that MySQL is running.\n");
    exit(1);
}

$select = $pdo->prepare(
    'SELECT user_id FROM users WHERE username = :username OR email = :email LIMIT 1'
);
$insert = $pdo->prepare(
    'INSERT INTO users (username, email, password_hash, role, status)
     VALUES (:username, :email, :password_hash, :role, :status)'
);
$update = $pdo->prepare(
    'UPDATE users
     SET email = :email,
         password_hash = :password_hash,
         role = :role,
         status = :status
     WHERE user_id = :user_id'
);

// Backward compatibility for earlier development seeds.
// This keeps a single academic staff account identity.
$pdo->prepare(
    "UPDATE users
     SET username = 'academic_staff',
         email = 'academic_staff@localhost.test'
     WHERE username = 'academic.staff'
       AND role = 'ACADEMIC_STAFF'"
)->execute();

echo "Creating development test users...\n\n";

foreach ($accounts as $account) {
    $passwordHash = password_hash($account['password'], PASSWORD_DEFAULT);

    $select->execute([
        'username' => $account['username'],
        'email' => $account['email'],
    ]);
    $existing = $select->fetch();

    if ($existing !== false) {
        $update->execute([
            'email' => $account['email'],
            'password_hash' => $passwordHash,
            'role' => $account['role'],
            'status' => 'ACTIVE',
            'user_id' => $existing['user_id'],
        ]);
        $action = 'updated';
    } else {
        $insert->execute([
            'username' => $account['username'],
            'email' => $account['email'],
            'password_hash' => $passwordHash,
            'role' => $account['role'],
            'status' => 'ACTIVE',
        ]);
        $action = 'created';
    }

    echo sprintf(
        "[%s] %-16s  username: %-16s  email: %-32s  password: %s\n",
        $action,
        $account['role'],
        $account['username'],
        $account['email'],
        $account['password']
    );
}

echo "\nThese accounts are for local development only. Remove them before production.\n";
