<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;
use App\Http\Request;
use App\Repositories\ActivityRepository;
use App\Repositories\ApplicationRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\JobRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\QueueRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\SourceRepository;
use App\Services\Security\Auth;

final class DashboardController
{
    public function index(Request $request): void
    {
        $id = Auth::id();
        $hour = (int) date('G');
        View::render('pages/dashboard', [
            'title' => 'Your workspace',
            'active' => 'dashboard',
            'greeting' =>
                $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening'),
            'stats' => (new JobRepository())->dashboard($id),
            'jobs' => (new JobRepository())->list($id, ['sort' => 'match'], 4),
            'applications' => (new ApplicationRepository())->all($id, [], 4),
            'activity' => (new ActivityRepository())->recent($id, 5),
            'profile' => (new ProfileRepository())->get($id),
            'documents' => (new DocumentRepository())->all($id),
            'sources' => (new SourceRepository())->all($id),
            'providers' => (new SettingsRepository())->providers($id),
            'automation' => (new SettingsRepository())->get($id, 'automation'),
            'discovery' => (new SettingsRepository())->get($id, 'discovery'),
            'latestRun' => (new QueueRepository())->latestRun($id, 'discovery'),
        ]);
    }
}
