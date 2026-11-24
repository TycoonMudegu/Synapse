<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\AccountRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\SettingsRepository;
use App\Services\Documents\CvReviewService;
use App\Services\Documents\DocumentService;
use App\Services\Entitlements;
use App\Services\OnboardingService;
use App\Services\Security\Auth;

final class OnboardingController
{
    public function index(Request $request): void
    {
        if (!empty(Auth::user()['onboarded_at'])) {
            Response::redirect('/');
        }
        $accounts = new AccountRepository();
        $service = new OnboardingService();
        $preferences = $accounts->preferences(Auth::id());
        $step = max(
            1,
            min(
                5,
                (int) ($_GET['step'] ?? Auth::user()['onboarding_step']),
                (int) Auth::user()['onboarding_step'],
            ),
        );
        View::render(
            'pages/onboarding',
            [
                'title' => 'Make yourself at home',
                'setupFlow' => true,
                'step' => $step,
                'preferences' => $preferences,
                'cvProcessing' =>
                    $preferences['cv_document_id'] &&
                    ((new DocumentRepository())->get(
                        Auth::id(),
                        (int) $preferences['cv_document_id'],
                    )['processing_status'] ??
                        'ready') ===
                        'pending',
                'fields' => OnboardingService::FIELDS,
                'sources' => $service->sources('technology') + $service->sources('other'),
                'review' => $preferences['cv_document_id']
                    ? $accounts->review(
                        Auth::id(),
                        (int) $preferences['cv_document_id'],
                        $preferences['field_key'],
                    )
                    : null,
                'membership' => $accounts->membership(Auth::id()),
                'provider' => (new SettingsRepository())->providers(Auth::id()),
            ],
            'onboarding',
        );
    }

    public function save(Request $request): void
    {
        if (!empty(Auth::user()['onboarded_at'])) {
            Response::json(['ok' => true, 'redirect' => '/']);
        }
        $stage = $request->string('stage');
        $accounts = new AccountRepository();
        $service = new OnboardingService();
        $requiredStep =
            [
                'preferences' => 1,
                'documents' => 2,
                'review' => 3,
                'provider' => 4,
                'automation' => 5,
            ][$stage] ?? throw new HttpException('Choose a valid setup step.');
        if ((int) Auth::user()['onboarding_step'] < $requiredStep) {
            throw new HttpException('Complete the preceding setup step first.');
        }
        $skip = $request->string('skip') === '1';
        $documentTask = null;
        switch ($stage) {
            case 'preferences':
                $service->preferences(Auth::id(), $request->all());
                break;
            case 'documents':
                if (!$skip) {
                    foreach (
                        ['cv' => 'master_cv', 'letter' => 'cover_letter_template']
                        as $field => $kind
                    ) {
                        if (\App\Services\Documents\DocumentStorage::enabled()) {
                            if (!empty($_FILES[$field]['size'])) {
                                throw new HttpException('Use the direct cloud upload form.', 409);
                            }
                            $documentId = (int) $request->string($field . '_document_id');
                            if (!$documentId) {
                                continue;
                            }
                            $document = (new DocumentRepository())->get(Auth::id(), $documentId);
                            if (
                                $document['kind'] !== $kind ||
                                !\App\Services\Documents\DocumentStorage::isCloud($document)
                            ) {
                                throw new HttpException(
                                    'Choose a cloud document of the correct type.',
                                );
                            }
                            $accounts->savePreferences(Auth::id(), [
                                $field . '_document_id' => $documentId,
                            ]);
                            if ($field === 'cv') {
                                (new DocumentRepository())->master(Auth::id(), $documentId);
                                $documentTask = (new \App\Repositories\QueueRepository())->enqueue(
                                    Auth::id(),
                                    'process_document',
                                    ['document_id' => $documentId],
                                );
                            }
                            continue;
                        }
                        if (
                            ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !==
                            UPLOAD_ERR_NO_FILE
                        ) {
                            $document = (new DocumentService())->upload(
                                Auth::id(),
                                $_FILES[$field],
                                $kind,
                            );
                            $accounts->savePreferences(Auth::id(), [
                                $field === 'cv'
                                    ? 'cv_document_id'
                                    : 'letter_document_id' => $document['id'],
                            ]);
                            if ($field === 'cv') {
                                (new DocumentRepository())->master(
                                    Auth::id(),
                                    (int) $document['id'],
                                );
                                (new CvReviewService())->grade(Auth::id(), (int) $document['id']);
                            }
                        }
                    }
                }
                break;
            case 'provider':
                if (!$skip) {
                    $service->provider(Auth::id(), $request->all());
                }
                break;
            case 'automation':
                if (!$skip && $request->string('automatic') === '1') {
                    (new Entitlements())->requirePaid(Auth::id());
                    if ($request->string('confirm_automatic') !== '1') {
                        throw new HttpException(
                            'Confirm automatic email applications before enabling them.',
                        );
                    }
                    $accounts->savePreferences(Auth::id(), ['automatic_requested' => 1]);
                    $repository = new SettingsRepository();
                    $repository->save(
                        Auth::id(),
                        'automation',
                        array_replace($repository->get(Auth::id(), 'automation'), [
                            'mode' => 'automatic',
                            'analysis_enabled' => true,
                            'preparation_enabled' => true,
                            'discovery_enabled' => true,
                            'permitted_methods' => ['email'],
                        ]),
                    );
                }
                $service->finish(Auth::id());
                Response::json(['ok' => true, 'redirect' => '/']);
        }
        $accounts->step(Auth::id(), $requiredStep + 1);
        Response::json([
            'ok' => true,
            'redirect' => '/onboarding?step=' . ($requiredStep + 1),
            'task_id' => $documentTask,
        ]);
    }

    public function skip(Request $request): void
    {
        (new OnboardingService())->finish(Auth::id());
        Response::json(['ok' => true, 'redirect' => '/']);
    }

    public function review(Request $request): void
    {
        View::render('pages/cv-review', [
            'title' => 'Your CV check',
            'active' => 'profile',
            'review' => (new AccountRepository())->latestReview(Auth::id()),
            'documents' => array_filter(
                (new DocumentRepository())->all(Auth::id()),
                fn($d) => empty($d['archived_at']) &&
                    in_array($d['kind'], ['master_cv', 'tailored_cv'], true),
            ),
            'membership' => (new AccountRepository())->membership(Auth::id()),
        ]);
    }

    public function grade(Request $request): void
    {
        (new CvReviewService())->grade(Auth::id(), (int) $request->string('document_id'));
        Response::json(['ok' => true, 'message' => 'Your CV check is ready.', 'reload' => true]);
    }

    public function advice(Request $request): void
    {
        (new CvReviewService())->advice(Auth::id());
        Response::json([
            'ok' => true,
            'message' => 'Your CV suggestions are ready to review.',
            'reload' => true,
        ]);
    }
}
