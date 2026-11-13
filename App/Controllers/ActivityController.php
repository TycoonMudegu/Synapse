<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\ActivityRepository;
use App\Services\Security\Auth;

final class ActivityController
{
    public function index(Request $request): void
    {
        View::render('pages/activity', [
            'title' => 'Activity centre',
            'active' => 'activity',
            'notifications' => (new ActivityRepository())->notifications(Auth::id()),
            'activity' => (new ActivityRepository())->recent(Auth::id(), 100),
        ]);
    }

    public function notifications(Request $request): void
    {
        Response::json([
            'ok' => true,
            'data' => (new ActivityRepository())->notifications(Auth::id()),
        ]);
    }

    public function read(Request $request, int $id): void
    {
        (new ActivityRepository())->markRead(Auth::id(), $id);
        Response::json(['ok' => true, 'message' => 'Marked as read.', 'reload' => true]);
    }
}
