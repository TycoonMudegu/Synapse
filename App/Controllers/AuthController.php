<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\ActivityRepository;
use App\Services\Security\Auth;
use App\Services\Security\Validator;

final class AuthController
{
    public function show(Request $request): void
    {
        if (Auth::user()) {
            Response::redirect('/');
        }
        View::render('auth/login', ['title' => 'Sign in'], 'guest');
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
            Response::json(
                ['ok' => false, 'message' => 'The email address or password is incorrect.'],
                422,
            );
        }
        (new ActivityRepository())->log(Auth::id(), 'security.login', 'Signed in to JobFlow.');
        Response::json(['ok' => true, 'redirect' => '/']);
    }

    public function logout(Request $request): void
    {
        Auth::logout();
        Response::redirect('/login');
    }
}
