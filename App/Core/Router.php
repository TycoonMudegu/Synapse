<?php

declare(strict_types=1);

namespace App\Core;

use App\Http\Request;
use App\Http\Response;
use App\Services\Security\Auth;
use App\Services\Security\Csrf;

final class Router
{
    private array $routes = [];

    public function add(
        string $method,
        string $path,
        callable|array $handler,
        bool $private = true,
    ): void {
        $this->routes[] = [$method, $path, $handler, $private];
    }

    public function dispatch(Request $request): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        foreach ($this->routes as [$verb, $path, $handler, $private]) {
            $pattern = '#^' . preg_replace('/\{([a-z_]+)\}/', '(?P<$1>[0-9]+)', $path) . '$#';
            if ($verb !== $method || !preg_match($pattern, $request->path(), $matches)) {
                continue;
            }
            if ($private && !Auth::user()) {
                if ($request->wantsJson()) {
                    throw new HttpException('Please sign in to continue.', 401);
                }
                Response::redirect('/login');
            }
            if (
                $private &&
                empty(Auth::user()['onboarded_at']) &&
                !in_array(
                    $request->path(),
                    [
                        '/onboarding',
                        '/api/onboarding',
                        '/api/onboarding/skip',
                        '/plans',
                        '/api/billing/mpesa',
                        '/api/billing/promo',
                        '/logout',
                    ],
                    true,
                ) &&
                !preg_match('#^/api/billing/payments/[0-9]+/check$#', $request->path()) &&
                !preg_match('#^/api/documents/uploads(?:/complete)?$#', $request->path()) &&
                !preg_match('#^/api/tasks/[0-9]+$#', $request->path())
            ) {
                if ($request->wantsJson()) {
                    Response::json(
                        [
                            'ok' => false,
                            'message' => 'Finish your setup to continue.',
                            'redirect' => '/onboarding',
                        ],
                        409,
                    );
                }
                Response::redirect('/onboarding');
            }
            if ($private && $method === 'POST') {
                $limits = new \App\Repositories\RateLimitRepository();
                $limits->check(Auth::id(), 'mutations', 120);
                if (
                    preg_match(
                        '#/(extract|assist|profile|test|analyse|prepare|send|preview|export)$#',
                        $request->path(),
                    )
                ) {
                    $limits->check(Auth::id(), 'expensive_operations', 12);
                }
            }
            if (
                !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) &&
                $request->path() !== '/api/billing/mpesa/callback'
            ) {
                Csrf::validate($request->string('_csrf', $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
            }
            if (
                $private &&
                $method === 'POST' &&
                preg_match(
                    '#^/api/(jobs/[0-9]+/analyse|applications/[0-9]+/(prepare|send))$#',
                    $request->path(),
                )
            ) {
                (new \App\Services\Entitlements())->requirePaid(Auth::id());
            }
            $arguments = [$request];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $arguments[] = (int) $value;
                }
            }
            if (is_array($handler)) {
                [$class, $action] = $handler;
                (new $class())->$action(...$arguments);
            } else {
                $handler(...$arguments);
            }
            return;
        }
        throw new HttpException('This page could not be found.', 404);
    }
}
