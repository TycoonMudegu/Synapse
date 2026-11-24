<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\ApplicationRepository;
use App\Repositories\IntegrationRepository;
use App\Repositories\QueueRepository;
use App\Services\Mail\GmailService;
use App\Services\Security\Auth;

final class InboxController
{
    public function index(Request $request): void
    {
        $repository = new IntegrationRepository();
        $connection = $repository->connection(Auth::id());
        if ($connection) {
            unset($connection['encrypted_tokens']);
        }
        View::render('pages/inbox', [
            'title' => 'Application inbox',
            'active' => 'inbox',
            'connection' => $connection,
            'messages' => $repository->inbox(Auth::id(), $_GET),
            'message' => isset($_GET['message'])
                ? $repository->message(Auth::id(), (int) $_GET['message'])
                : null,
            'applications' => (new ApplicationRepository())->all(Auth::id()),
        ]);
    }

    public function connect(Request $request): void
    {
        Response::redirect((new GmailService())->authorizationUrl(Auth::id()));
    }

    public function callback(Request $request): void
    {
        (new GmailService())->callback(
            Auth::id(),
            (string) ($_GET['state'] ?? ''),
            (string) ($_GET['code'] ?? ''),
        );
        Response::redirect('/settings?tab=gmail');
    }

    public function disconnect(Request $request): void
    {
        (new GmailService())->disconnect(Auth::id());
        Response::json(['ok' => true, 'message' => 'Gmail disconnected.', 'reload' => true]);
    }

    public function sync(Request $request): void
    {
        if (!(new IntegrationRepository())->connection(Auth::id())) {
            throw new HttpException('Connect Gmail before synchronising.');
        }
        $task = (new QueueRepository())->enqueue(Auth::id(), 'gmail_sync');
        Response::json([
            'ok' => true,
            'task_id' => $task,
            'message' => 'Gmail synchronisation queued.',
        ]);
    }

    public function test(Request $request): void
    {
        $connection =
            (new IntegrationRepository())->connection(Auth::id()) ??
            throw new HttpException('Connect Gmail first.');
        (new GmailService())->send(
            Auth::id(),
            $connection['account_email'],
            'Synapse connection test',
            'Your Synapse Gmail sending connection is working.',
        );
        Response::json([
            'ok' => true,
            'message' => 'Google confirmed the test message was sent to your connected account.',
        ]);
    }
}
