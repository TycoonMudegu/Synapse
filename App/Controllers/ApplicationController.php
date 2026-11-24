<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\ApplicationRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\QueueRepository;
use App\Services\ApplicationService;
use App\Services\Documents\Exporter;
use App\Services\Security\Auth;
use App\Services\Security\Validator;

final class ApplicationController
{
    public function index(Request $request): void
    {
        View::render('pages/applications', [
            'title' => 'Applications',
            'active' => 'applications',
            'applications' => (new ApplicationRepository())->all(Auth::id(), $_GET),
            'statuses' => array_keys(ApplicationService::TRANSITIONS),
        ]);
    }

    public function show(Request $request, int $id): void
    {
        $repository = new ApplicationRepository();
        $application = $repository->get(Auth::id(), $id);
        $documents = (new DocumentRepository())->all(Auth::id());
        View::render('pages/application', [
            'title' => 'Application workspace',
            'active' => 'applications',
            'application' => $application,
            'manualAlert' => (new \App\Repositories\ManualJobAlertRepository())->forJob(
                Auth::id(),
                (int) $application['job_id'],
            ),
            'documents' => $documents,
            'preserveLetter' =>
                (new \App\Repositories\SettingsRepository())->get(Auth::id(), 'documents')[
                    'layout_mode'
                ] === 'preserve' &&
                (bool) array_filter(
                    $documents,
                    fn($document) => $document['kind'] === 'cover_letter_template',
                ),
            'letter' => $repository->letter(Auth::id(), $id),
            'transitions' => ApplicationService::TRANSITIONS[$application['status']] ?? [],
        ]);
    }

    public function save(Request $request, int $id): void
    {
        (new \App\Services\ApplicationDraftService())->save(Auth::id(), $id, $request->all());
        Response::json(['ok' => true, 'message' => 'Application saved.', 'reload' => true]);
    }

    public function interview(Request $request, int $id): void
    {
        (new \App\Services\ApplicationDraftService())->interview(Auth::id(), $id, $request->all());
        Response::json(['ok' => true, 'message' => 'Interview scheduled.', 'reload' => true]);
    }

    public function prepare(Request $request, int $id): void
    {
        $application = (new ApplicationRepository())->get(Auth::id(), $id);
        if (!in_array($application['status'], ['draft', 'ready_for_review'], true)) {
            throw new HttpException('Only draft or review applications can be prepared.');
        }
        $task = (new QueueRepository())->enqueue(Auth::id(), 'prepare_application', [
            'application_id' => $id,
        ]);
        Response::json([
            'ok' => true,
            'task_id' => $task,
            'message' => 'Document preparation queued. Run the worker to process it.',
        ]);
    }

    public function status(Request $request, int $id): void
    {
        (new ApplicationService())->transition(
            Auth::id(),
            $id,
            $request->string('status'),
            Validator::text($request->string('confirmation'), 'confirmation', 500),
        );
        Response::json([
            'ok' => true,
            'message' => 'Application status updated.',
            'reload' => true,
        ]);
    }

    public function send(Request $request, int $id): void
    {
        $application = (new ApplicationRepository())->get(Auth::id(), $id);
        if ($application['status'] !== 'approved') {
            throw new HttpException('Approve the application before submitting.');
        }
        $task = (new QueueRepository())->enqueue(Auth::id(), 'submit_application', [
            'application_id' => $id,
        ]);
        Response::json([
            'ok' => true,
            'task_id' => $task,
            'message' =>
                'Submission queued. Delivery will be recorded only after Gmail confirms it.',
        ]);
    }

    public function letter(Request $request, int $id): void
    {
        (new \App\Services\ApplicationDraftService())->letter(Auth::id(), $id, $request->all());
        Response::json([
            'ok' => true,
            'message' => 'New cover letter version saved. Review and verify the updated document.',
            'reload' => true,
        ]);
    }
}
