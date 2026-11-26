<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\CatalogueRepository;
use App\Repositories\CollectorRepository;
use App\Services\Discovery\CloudRunCollector;
use App\Services\Discovery\CollectorSourceService;
use App\Services\JobService;
use App\Services\Security\Admin;
use App\Services\Security\Validator;

final class AdminController
{
    public function index(Request $request): void
    {
        Admin::require();
        $collector = new CollectorRepository();
        $tab =
            is_string($_GET['tab'] ?? null) &&
            in_array($_GET['tab'], ['jobs', 'sources', 'runs'], true)
                ? $_GET['tab']
                : 'jobs';
        $query = Validator::text($_GET['q'] ?? '', 'search', 150);
        $editSource = isset($_GET['source']) ? $collector->source((int) $_GET['source']) : null;
        View::render('pages/admin', [
            'title' => 'Administration',
            'active' => 'admin',
            'tab' => $tab,
            'jobs' => (new CatalogueRepository())->list($query, true, (int) ($_GET['page'] ?? 1)),
            'counts' => (new CatalogueRepository())->counts(),
            'sources' => $collector->sources(),
            'runs' => $collector->runs(),
            'editingSource' => $editSource,
            'collectorConfigured' => (new CloudRunCollector())->configured(),
        ]);
    }

    public function editor(Request $request, ?int $id = null): void
    {
        Admin::require();
        View::render('pages/admin-job', [
            'title' => $id !== null ? 'Edit catalogue job' : 'Add catalogue job',
            'active' => 'admin',
            'entry' => $id !== null ? (new CatalogueRepository())->get($id) : null,
        ]);
    }

    public function saveJob(Request $request, ?int $id = null): void
    {
        Admin::require();
        $input = $request->all();
        // A public catalogue entry never imports private documents or factual approval.
        unset($input['source_id'], $input['poster_document_id'], $input['verified']);
        $job = (new JobService())->normalise($input);
        $revision =
            $id !== null
                ? (int) Validator::number($input['revision'] ?? 0, 'revision', 1, PHP_INT_MAX)
                : null;
        try {
            Database::transaction(
                fn(): array => (new CatalogueRepository())->upsert(
                    $job,
                    null,
                    true,
                    $id,
                    $revision,
                    !empty($input['published']),
                ),
            );
        } catch (\PDOException $error) {
            if (($error->errorInfo[1] ?? null) === 1062) {
                throw new HttpException(
                    'Another catalogue entry has this vacancy URL or identity.',
                    409,
                );
            }
            throw $error;
        }
        Response::json(['ok' => true, 'message' => 'Catalogue job saved.', 'redirect' => '/admin']);
    }

    public function publish(Request $request, int $id): void
    {
        Admin::require();
        (new CatalogueRepository())->publish($id, $request->string('published') === '1');
        Response::json(['ok' => true, 'message' => 'Publication updated.', 'reload' => true]);
    }

    public function saveSource(Request $request, ?int $id = null): void
    {
        Admin::require();
        (new CollectorSourceService())->save($id, $request->all());
        Response::json([
            'ok' => true,
            'message' => 'Collection source saved.',
            'redirect' => '/admin?tab=sources',
        ]);
    }

    public function collect(Request $request): void
    {
        $userId = Admin::require();
        $client = new CloudRunCollector();
        if (!$client->configured()) {
            throw new HttpException(
                'Cloud Run is not configured yet. Complete the collector deployment first.',
            );
        }
        $source = (int) $request->string('source_id', '0');
        $repository = new CollectorRepository();
        $id = $repository->request($userId, $source > 0 ? $source : null);
        try {
            $client->dispatch($id);
        } catch (\Throwable $error) {
            $repository->dispatchError($id);
            throw $error;
        }
        Response::json([
            'ok' => true,
            'message' => 'Collection requested. Progress appears in Collection runs.',
            'redirect' => '/admin?tab=runs',
        ]);
    }
}
