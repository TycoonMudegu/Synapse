<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;
use App\Core\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\ActivityRepository;
use App\Services\Security\Auth;
use App\Services\Security\Csrf;
use App\Services\Security\Validator;

final class AuthController
{
    public function csrf(Request $request): void
    {
        Response::json(['ok' => true, 'csrf_token' => Csrf::token()]);
    }

    public function show(Request $request): void
    {
        if (Auth::user()) {
            Response::redirect(empty(Auth::user()['onboarded_at']) ? '/onboarding' : '/');
        }
        unset($_SESSION['auth_flow']);
        View::render('auth/login', ['title' => 'Welcome to Synapse'], 'guest');
    }

    public function advance(Request $request): void
    {
        if (Auth::user()) {
            $this->finished($request);
        }
        try {
            if ($request->string('stage', 'email') === 'email') {
                $users = new \App\Repositories\UserRepository();
                $key = hash('sha256', 'identify:' . ($_SERVER['REMOTE_ADDR'] ?? 'local'));
                if ($users->attempts($key) >= 30) {
                    throw new HttpException('Too many attempts. Try again in 15 minutes.', 429);
                }
                $users->recordAttempt($key);
                $email = mb_strtolower(Validator::email($request->string('email'), true));
                $stage = $users->byEmail($email) ? 'password' : 'register';
                $_SESSION['auth_flow'] = [
                    'email' => $email,
                    'stage' => $stage,
                    'expires_at' => time() + 900,
                ];
                if ($request->wantsJson()) {
                    Response::json(['ok' => true, 'auth_stage' => $stage, 'email' => $email]);
                }
                View::render(
                    'auth/login',
                    [
                        'title' => 'Continue to Synapse',
                        'authStage' => $stage,
                        'authEmail' => $email,
                    ],
                    'guest',
                );
                return;
            }
            $flow = $_SESSION['auth_flow'] ?? [];
            if (
                ($flow['expires_at'] ?? 0) < time() ||
                !in_array($flow['stage'] ?? '', ['password', 'register'], true) ||
                !hash_equals($flow['email'] ?? '', mb_strtolower($request->string('email')))
            ) {
                unset($_SESSION['auth_flow']);
                if ($request->wantsJson()) {
                    Response::json(
                        [
                            'ok' => false,
                            'auth_stage' => 'email',
                            'message' => 'Start with your email again to continue.',
                        ],
                        409,
                    );
                }
                throw new HttpException('Start with your email again to continue.', 409);
            }
            if ($flow['stage'] === 'password') {
                $this->login($request);
            } else {
                $this->register($request);
            }
        } catch (HttpException $exception) {
            if ($request->wantsJson()) {
                throw $exception;
            }
            http_response_code($exception->status);
            $flow = $_SESSION['auth_flow'] ?? [];
            View::render(
                'auth/login',
                [
                    'title' => 'Continue to Synapse',
                    'authStage' => $flow['stage'] ?? 'email',
                    'authEmail' => $flow['email'] ?? $request->string('email'),
                    'authError' => $exception->getMessage(),
                ],
                'guest',
            );
        }
    }

    private function finished(Request $request): never
    {
        unset($_SESSION['auth_flow']);
        $redirect = empty(Auth::user()['onboarded_at']) ? '/onboarding' : '/';
        if ($request->wantsJson()) {
            Response::json(['ok' => true, 'redirect' => $redirect]);
        }
        Response::redirect($redirect);
    }

    public function login(Request $request): void
    {
        $email = Validator::email($request->string('email'), true);
        if (
            !Auth::login(
                $email,
                $request->password('password'),
                !empty($request->all()['remember']),
            )
        ) {
            throw new HttpException('The email address or password is incorrect.');
        }
        (new ActivityRepository())->log(Auth::id(), 'security.login', 'Signed in to Synapse.');
        $this->finished($request);
    }

    public function showRegister(Request $request): void
    {
        if (Auth::user()) {
            Response::redirect(empty(Auth::user()['onboarded_at']) ? '/onboarding' : '/');
        }
        Response::redirect('/login');
    }

    public function register(Request $request): void
    {
        if (Auth::user()) {
            $this->finished($request);
        }
        $users = new \App\Repositories\UserRepository();
        $key = hash('sha256', 'register:' . ($_SERVER['REMOTE_ADDR'] ?? 'local'));
        if ($users->attempts($key) >= 8) {
            throw new \App\Core\HttpException(
                'Too many registration attempts. Try again in 15 minutes.',
                429,
            );
        }
        $users->recordAttempt($key);
        $name = Validator::text($request->string('name'), 'name', 150, true);
        $email = Validator::email($request->string('email'), true);
        $password = $request->password('password');
        if (
            strlen($password) < 12 ||
            strlen($password) > 72 ||
            $password !== $request->password('password_confirmation')
        ) {
            throw new \App\Core\HttpException(
                'Use a password of 12–72 characters and make sure both passwords match.',
            );
        }
        try {
            $id = \App\Core\Database::transaction(fn() => $users->create($name, $email, $password));
        } catch (\PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new \App\Core\HttpException(
                    'An account with this email already exists. Please sign in.',
                );
            }
            throw $exception;
        }
        Auth::establish($id);
        $this->finished($request);
    }

    public function google(Request $request): void
    {
        Response::redirect((new \App\Services\Security\GoogleSignIn())->authorizationUrl());
    }

    public function googleCallback(Request $request): void
    {
        // Google sign-in and Gmail may share the one registered callback URL.
        // Only the matching, server-side Gmail state can select the Gmail flow.
        $state = $_GET['state'] ?? '';
        $gmailState = $_SESSION['google_oauth']['state'] ?? null;
        if (
            is_string($state) &&
            $state !== '' &&
            is_string($gmailState) &&
            hash_equals($gmailState, $state)
        ) {
            (new InboxController())->callback($request);
            return;
        }
        try {
            $id = (new \App\Services\Security\GoogleSignIn())->callback($_GET);
            Auth::establish($id);
            Response::redirect(empty(Auth::user()['onboarded_at']) ? '/onboarding' : '/');
        } catch (\App\Core\HttpException $exception) {
            View::render(
                'auth/login',
                ['title' => 'Sign in', 'authError' => $exception->getMessage()],
                'guest',
            );
        }
    }

    public function logout(Request $request): void
    {
        Auth::logout();
        Response::redirect('/login');
    }
}
