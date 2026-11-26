<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require BASE_PATH . '/scripts/migrate.php';

use App\Core\Database;
use App\Repositories\UserRepository;
use App\Services\Security\Admin;

$db = Database::connection();
$users = new UserRepository();
$tag = bin2hex(random_bytes(6));
$emails = [];
$trigger = 'admin_atomic_' . $tag;
$passed = 0;

function admin_check(bool $condition, string $message): void
{
    global $passed;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $passed++;
    echo 'PASS ' . $message . PHP_EOL;
}

function admin_command(array $input): array
{
    $process = proc_open(
        [PHP_BINARY, BASE_PATH . '/scripts/create-admin.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        BASE_PATH,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the administrator command.');
    }
    fwrite($pipes[0], implode("\n", $input) . "\n");
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $output];
}

try {
    $existingEmail = $emails[] = 'admin-existing-' . $tag . '@example.test';
    $existingId = $users->create('Existing user', $existingEmail, 'existing-password-123456');
    $existingHash = $users->byEmail($existingEmail)['password_hash'];
    $newEmail = $emails[] = 'admin-new-' . $tag . '@example.test';
    $password = bin2hex(random_bytes(18));
    [$status, $output] = admin_command([
        'New administrator',
        strtoupper($newEmail),
        $password,
        $password,
    ]);
    $admin = $users->byEmail($newEmail);
    admin_check($status === 0 && $admin !== null, 'creates a new account when users already exist');
    admin_check(Admin::allowed($admin), 'the new account has administrator access');
    admin_check(
        $admin['onboarded_at'] === null && (int) $admin['onboarding_step'] === 1,
        'administrator creation leaves job-seeker setup untouched',
    );
    admin_check(
        $admin['password_hash'] !== $password &&
            password_verify($password, $admin['password_hash']),
        'the administrator password is hashed and usable for sign-in',
    );
    admin_check(
        !str_contains($output, $password),
        'the command never prints the supplied password',
    );
    $profile = $db->prepare('SELECT full_name, email FROM user_profiles WHERE user_id = ?');
    $profile->execute([$admin['id']]);
    $profile = $profile->fetch();
    admin_check(
        $profile['full_name'] === 'New administrator' &&
            strtolower($profile['email']) === $newEmail,
        'the new administrator receives a matching account profile',
    );
    admin_check(
        !Admin::allowed($users->byId($existingId)),
        'creating an administrator preserves the role of existing users',
    );
    [$status, $output] = admin_command([
        'Replacement name',
        strtoupper($existingEmail),
        $password,
        $password,
    ]);
    $existing = $users->byEmail($existingEmail);
    admin_check(
        $status === 1 &&
            str_contains($output, 'already exists') &&
            $existing['name'] === 'Existing user' &&
            $existing['password_hash'] === $existingHash &&
            !Admin::allowed($existing),
        'an existing email cannot overwrite an account or grant it administrator access',
    );

    $invalidEmail = $emails[] = 'admin-invalid-' . $tag . '@example.test';
    foreach (
        [
            'empty name' => ['', $invalidEmail, $password, $password],
            'invalid email' => ['Administrator', 'invalid-email', $password, $password],
            'short password' => ['Administrator', $invalidEmail, 'short', 'short'],
            'long password' => [
                'Administrator',
                $invalidEmail,
                str_repeat('x', 73),
                str_repeat('x', 73),
            ],
            'mismatched passwords' => [
                'Administrator',
                $invalidEmail,
                $password,
                'different-password',
            ],
            'incomplete input' => ['Administrator', $invalidEmail],
        ]
        as $case => $input
    ) {
        [$status] = admin_command($input);
        admin_check(
            $status === 1 && $users->byEmail($invalidEmail) === null,
            $case . ' leaves no partial administrator account',
        );
    }

    // Simulate a role-write failure after the account and profile have been inserted.
    $rollbackEmail = $emails[] = 'admin-rollback-' . $tag . '@example.test';
    $db->exec(
        'CREATE TRIGGER `' .
            $trigger .
            '` BEFORE UPDATE ON users FOR EACH ROW BEGIN ' .
            'IF NEW.email = ' .
            $db->quote($rollbackEmail) .
            ' AND NEW.is_admin = 1 THEN ' .
            "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fixture role write failure'; END IF; END",
    );
    [$status] = admin_command(['Rollback administrator', $rollbackEmail, $password, $password]);
    $profiles = $db->prepare('SELECT COUNT(*) FROM user_profiles WHERE email = ?');
    $profiles->execute([$rollbackEmail]);
    admin_check(
        $status === 1 &&
            $users->byEmail($rollbackEmail) === null &&
            (int) $profiles->fetchColumn() === 0,
        'a role-write failure rolls back both the account and its profile',
    );
    echo $passed . " administrator checks passed.\n";
} finally {
    $db->exec('DROP TRIGGER IF EXISTS `' . $trigger . '`');
    foreach ($emails as $email) {
        $db->prepare('DELETE FROM users WHERE email = ?')->execute([$email]);
    }
}
