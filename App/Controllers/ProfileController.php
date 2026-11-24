<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\DocumentRepository;
use App\Repositories\ProfileRepository;
use App\Services\Documents\Exporter;
use App\Services\ProfileService;
use App\Services\Security\Auth;
use App\Services\Security\Validator;

final class ProfileController
{
    public function index(Request $request): void
    {
        View::render('pages/profile', [
            'title' => 'My CV & career profile',
            'active' => 'profile',
            'profile' => (new ProfileRepository())->get(Auth::id()),
            'documentLayout' => (new \App\Repositories\SettingsRepository())->get(
                Auth::id(),
                'documents',
            )['layout_mode'],
            'documents' => array_values(
                array_filter(
                    (new DocumentRepository())->all(Auth::id()),
                    fn($document) => in_array(
                        $document['kind'],
                        ['master_cv', 'tailored_cv'],
                        true,
                    ),
                ),
            ),
        ]);
    }

    public function save(Request $request): void
    {
        (new ProfileService())->save(Auth::id(), $request->all());
        Response::json([
            'ok' => true,
            'message' => 'Career profile saved. Previous matches now need a fresh analysis.',
        ]);
    }

    public function export(Request $request): void
    {
        $format = Validator::choice($request->string('format'), 'export format', ['pdf', 'docx']);
        $profile = (new ProfileRepository())->get(Auth::id());
        if (
            (new \App\Repositories\SettingsRepository())->get(Auth::id(), 'documents')[
                'layout_mode'
            ] === 'preserve'
        ) {
            $exporter = new \App\Services\Documents\SourceExporter();
            $id = $exporter->export(
                Auth::id(),
                $exporter->master(Auth::id()),
                $format,
                'Career CV',
                snapshot: ['profile' => $profile],
            );
        } else {
            $id = (new Exporter())->cv(Auth::id(), $profile, $format, 'Career CV');
            (new DocumentRepository())->addCv(Auth::id(), $id, ['profile' => $profile]);
        }
        Response::json([
            'ok' => true,
            'redirect' => '/documents/' . $id,
            'message' => 'CV exported.',
        ]);
    }
}
