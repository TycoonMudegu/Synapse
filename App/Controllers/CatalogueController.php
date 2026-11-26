<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\CatalogueRepository;
use App\Services\Security\Auth;
use App\Services\Security\Validator;

final class CatalogueController
{
    public function index(Request $request): void
    {
        View::render('pages/catalogue', [
            'title' => 'Job catalogue',
            'active' => 'catalogue',
            'jobs' => (new CatalogueRepository())->list(
                Validator::text($_GET['q'] ?? '', 'search', 150),
                false,
                (int) ($_GET['page'] ?? 1),
            ),
        ]);
    }

    public function show(Request $request, int $id): void
    {
        View::render('pages/catalogue-job', [
            'title' => 'Catalogue opportunity',
            'active' => 'catalogue',
            'entry' => (new CatalogueRepository())->get($id, true),
        ]);
    }

    public function save(Request $request, int $id): void
    {
        $result = (new CatalogueRepository())->saveForUser($id, Auth::id());
        Response::json([
            'ok' => true,
            'message' => $result['duplicate']
                ? 'This opportunity is already saved.'
                : 'Saved to your jobs. Review the original instructions before applying.',
            'redirect' => '/jobs/' . $result['id'],
        ]);
    }
}
