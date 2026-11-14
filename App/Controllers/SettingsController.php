<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Env;
use App\Core\HttpException;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\ActivityRepository;
use App\Repositories\IntegrationRepository;
use App\Repositories\QueueRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\SourceRepository;
use App\Repositories\UserRepository;
use App\Services\AI\AiService;
use App\Services\Discovery\DiscoveryService;
use App\Services\Mail\GmailService;
use App\Services\Security\Auth;
use App\Services\Security\UrlGuard;
use App\Services\Security\Validator;
use App\Services\SettingsService;

final class SettingsController
{
    public function index(Request $request): void
    {
        $section = $_GET['tab'] ?? 'general';
        if (
            !in_array(
                $section,
                [
                    'general',
                    'discovery',
                    'ai',
                    'gmail',
                    'automation',
                    'documents',
                    'security',
                    'system',
                ],
                true,
            )
        ) {
            $section = 'general';
        }
        $repository = new SettingsRepository();
        $connection = (new IntegrationRepository())->connection(Auth::id());
        if ($connection) {
            unset($connection['encrypted_tokens']);
        }
        (new \App\Services\Discovery\SourcePresetService())->refreshEmailHealth(Auth::id());
        $workerFile = BASE_PATH . '/storage/worker-heartbeat';
        View::render('pages/settings', [
            'title' => 'Settings',
            'active' => 'settings',
            'tab' => $section,
            'settings' => $repository->get(Auth::id(), $section),
            'providers' => $repository->providers(Auth::id()),
            'usage' => $repository->usage(Auth::id()),
            'sources' => (new SourceRepository())->all(Auth::id()),
            'sourceCatalog' => \App\Services\Discovery\SourceCatalog::SOURCES,
            'alertCounts' => (new \App\Repositories\AlertRepository())->counts(Auth::id()),
            'connection' => $connection,
            'gmailConfigured' => (new GmailService())->configured(),
            'sessions' =>
                $section === 'security' ? (new UserRepository())->sessions(Auth::id()) : [],
            'runs' => $section === 'system' ? (new QueueRepository())->runs(Auth::id()) : [],
            'tasks' => $section === 'system' ? (new QueueRepository())->recent(Auth::id()) : [],
            'heartbeat' => is_file($workerFile) ? filemtime($workerFile) : null,
        ]);
    }

    public function save(Request $request): void
    {
        (new SettingsService())->save(Auth::id(), $request->string('section'), $request->all());
        Response::json(['ok' => true, 'message' => 'Settings saved.']);
    }

    public function provider(Request $request): void
    {
        (new SettingsService())->provider(Auth::id(), $request->all());
        Response::json([
            'ok' => true,
            'message' => 'Provider saved. Test the connection before use.',
            'reload' => true,
        ]);
    }

    public function testProvider(Request $request, int $id): void
    {
        (new AiService())->run(Auth::id(), 'test', ['request' => 'Verify the connection.'], $id);
        Response::json([
            'ok' => true,
            'message' => 'The API returned a valid response.',
            'reload' => true,
        ]);
    }

    public function sourcePresets(Request $request): void
    {
        $key = $request->string('preset');
        $keys = $key === '' ? [] : [$key];
        $ids = (new \App\Services\Discovery\SourcePresetService())->install(Auth::id(), $keys);
        $message =
            'Requested sources added. Connect Gmail and enable the official email alerts for BrighterMonday and Fuzu.';
        if (isset($ids['myjobmag'])) {
            $repository = new SourceRepository();
            $source = $repository->get(Auth::id(), $ids['myjobmag']);
            try {
                (new DiscoveryService())->adapter('myjobmag')->validate($source);
                $repository->health(Auth::id(), $source['id'], true);
                $message =
                    'MyJobMag connected and its first collection is queued. BrighterMonday and Fuzu need Gmail and official email alerts.';
                (new QueueRepository())->enqueue(Auth::id(), 'discovery');
            } catch (\Throwable $exception) {
                $error =
                    $exception instanceof HttpException
                        ? $exception->getMessage()
                        : 'The feed connection could not be verified.';
                $repository->health(Auth::id(), $source['id'], false, $error);
                $message = 'Sources added. MyJobMag needs attention: ' . $error;
            }
        }
        Response::json(['ok' => true, 'message' => $message, 'reload' => true]);
    }

    public function source(Request $request): void
    {
        $id = (int) $request->string('id', '0');
        if ($id > 0 && (new SourceRepository())->get(Auth::id(), $id)['preset_key']) {
            throw new HttpException(
                'Built-in sources use their verified endpoints. Manage their connection or subscription instead.',
            );
        }
        $url = Validator::text($request->string('url'), 'source URL', 2048, true);
        UrlGuard::validate($url);
        (new SourceRepository())->save(
            Auth::id(),
            [
                'name' => Validator::text($request->string('name'), 'source name', 200, true),
                'adapter' => Validator::choice($request->string('adapter'), 'source type', [
                    'rss',
                    'json',
                ]),
                'url' => $url,
                'permission_confirmed' => !empty($request->all()['permission_confirmed']),
            ],
            (int) $request->string('id', '0'),
        );
        Response::json([
            'ok' => true,
            'message' => 'Source saved. Test it to activate collection.',
            'reload' => true,
        ]);
    }

    public function sourceAction(Request $request, int $id): void
    {
        $repository = new SourceRepository();
        $source = $repository->get(Auth::id(), $id);
        $action = $request->string('action');
        if ($action === 'test') {
            if (!$source['permission_confirmed']) {
                throw new HttpException(
                    'Confirm permitted automated access before testing this source.',
                );
            }
            try {
                if ($source['adapter'] === 'email_alert') {
                    (new GmailService())->sync(Auth::id(), $id);
                }
                (new DiscoveryService())->adapter($source['adapter'])->validate($source);
                $repository->health(Auth::id(), $id, true);
            } catch (\Throwable $exception) {
                $error =
                    $exception instanceof HttpException
                        ? $exception->getMessage()
                        : 'Connection validation failed.';
                if ($source['adapter'] === 'email_alert') {
                    $repository->emailHealth(Auth::id(), 'error', $error);
                    (new \App\Services\Discovery\SourcePresetService())->refreshEmailHealth(
                        Auth::id(),
                    );
                } else {
                    $repository->health(Auth::id(), $id, false, $error);
                }
                throw $exception;
            }
        } elseif ($action === 'retry_alerts' && $source['adapter'] === 'email_alert') {
            (new \App\Repositories\AlertRepository())->retry(Auth::id(), $id);
            (new QueueRepository())->enqueue(Auth::id(), 'discovery');
        } elseif ($action === 'delete') {
            $repository->delete(Auth::id(), $id);
        } elseif ($action === 'toggle') {
            $repository->toggle(Auth::id(), $id, !$source['enabled']);
        } else {
            throw new HttpException('Unknown source action.');
        }
        Response::json(['ok' => true, 'message' => 'Source updated.', 'reload' => true]);
    }

    public function password(Request $request): void
    {
        $repository = new UserRepository();
        $user = $repository->byEmail(Auth::user()['email']);
        if (!password_verify($request->password('current_password'), $user['password_hash'])) {
            throw new HttpException('The current password is incorrect.');
        }
        $password = $request->password('password');
        if (
            strlen($password) < 12 ||
            strlen($password) > 72 ||
            $password !== $request->password('password_confirmation')
        ) {
            throw new HttpException('Use 12–72 characters and ensure the new passwords match.');
        }
        $repository->password(Auth::id(), password_hash($password, PASSWORD_DEFAULT));
        $repository->revokeSessions(Auth::id(), hash('sha256', session_id()));
        (new ActivityRepository())->log(
            Auth::id(),
            'security.password',
            'Changed the account password and revoked other sessions.',
        );
        Response::json([
            'ok' => true,
            'message' => 'Password changed. Other sessions have been signed out.',
        ]);
    }

    public function revokeSessions(Request $request): void
    {
        (new UserRepository())->revokeSessions(Auth::id(), hash('sha256', session_id()));
        Response::json([
            'ok' => true,
            'message' => 'Other sessions and remembered sign-ins revoked.',
            'reload' => true,
        ]);
    }
}
