<?php

declare(strict_types=1);

require dirname(__DIR__) . '/Bootstrap/App.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$action = $argv[1] ?? '';
$email = '';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--email=')) {
        $email = substr($argument, 8);
    }
}
if (!in_array($action, ['grant', 'revoke'], true) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php scripts/admin.php grant|revoke --email=owner@example.com\n");
    exit(1);
}
$user = (new App\Repositories\UserRepository())->byEmail($email);
if (!$user) {
    fwrite(STDERR, "No existing account has that email. Register the account first.\n");
    exit(1);
}
$statement = App\Core\Database::connection()->prepare('UPDATE users SET is_admin = ? WHERE id = ?');
$statement->execute([(int) ($action === 'grant'), $user['id']]);
echo $action === 'grant' ? "Administrator access granted.\n" : "Administrator access revoked.\n";
