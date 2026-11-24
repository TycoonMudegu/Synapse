<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\QueueRepository;
use App\Repositories\SettingsRepository;
use App\Services\Security\Auth;

final class AutomationController
{
    public function search(Request $request): void
    {
        if ((new SettingsRepository())->get(Auth::id(), 'automation')['paused']) {
            throw new HttpException('Resume automation before starting a search.');
        }
        $task = (new QueueRepository())->enqueue(Auth::id(), 'discovery');
        Response::json([
            'ok' => true,
            'task_id' => $task,
            'message' => 'Your job search is queued. New opportunities will appear here when it finishes.',
        ]);
    }

    public function task(Request $request, int $id): void
    {
        $task = (new QueueRepository())->get(Auth::id(), $id);
        Response::json(['ok' => true, 'data' => $task]);
    }
}
