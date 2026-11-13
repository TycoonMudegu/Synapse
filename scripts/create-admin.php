<?php

declare(strict_types=1);

require dirname(__DIR__) . '/Bootstrap/App.php';

use App\Repositories\UserRepository;
use App\Services\Security\Validator;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$repository = new UserRepository();
if ($repository->count() > 0) {
    fwrite(STDERR, "An administrator already exists. Public registration is disabled.\n");
    exit(1);
}
$name = Validator::text(readline('Full name: '), 'full name', 150, true);
$email = Validator::email(readline('Email address: '), true);
if (function_exists('posix_isatty') && posix_isatty(STDIN)) {
    fwrite(STDOUT, 'Password (12–72 characters, hidden): ');
    system('stty -echo');
    try {
        $password = rtrim(fgets(STDIN), "\r\n");
    } finally {
        system('stty echo');
        fwrite(STDOUT, PHP_EOL);
    }
} else {
    $password = rtrim(fgets(STDIN), "\r\n");
}
if (strlen($password) < 12 || strlen($password) > 72) {
    fwrite(STDERR, "Use a password between 12 and 72 characters.\n");
    exit(1);
}
$repository->create($name, $email, $password);
echo 'Administrator created. Sign in at /login.' . PHP_EOL;
