<?php

declare(strict_types=1);

use App\Core\HttpException;
use App\Core\Router;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;

require dirname(__DIR__) . '/Bootstrap/App.php';
$request = new Request();

try {
    $router = new Router();
    require BASE_PATH . '/Routes/web.php';
    $router->dispatch($request);
} catch (Throwable $exception) {
    $status = $exception instanceof HttpException ? $exception->status : 500;
    $message =
        $status === 500
            ? 'Synapse could not complete this request. Check the system log and database configuration.'
            : $exception->getMessage();
    if ($status === 500) {
        $reference = bin2hex(random_bytes(6));
        // Never log request bodies, provider responses, tokens, or exception messages containing secrets.
        error_log(
            '[' .
                $reference .
                '] ' .
                get_class($exception) .
                ' at ' .
                $exception->getFile() .
                ':' .
                $exception->getLine(),
        );
        $message .= ' Reference: ' . $reference;
    }
    if ($request->wantsJson()) {
        Response::json(['ok' => false, 'message' => $message], $status);
    }
    http_response_code($status);
    $title = $status === 500 ? 'Setup or service needs attention' : 'Unable to open this page';
    // Error rendering must also work when authentication or the database is unavailable.
    $content =
        '<div class="auth-card"><div class="brand">Synapse<span>↗</span></div><h1>' .
        e($title) .
        '</h1><p>' .
        e($message) .
        '</p><p>Please try again, or contact the person who shared Synapse with you.</p><a class="btn btn-primary" href="' .
        e(app_url('/login')) .
        '">Return to sign in</a></div>';
    require BASE_PATH . '/Resources/Views/layouts/guest.php';
}
