<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\ApplicationRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\JobRepository;
use App\Repositories\QueueRepository;
use App\Repositories\SourceRepository;
use App\Services\AI\AiService;
use App\Services\Discovery\PublicContent;
use App\Services\Documents\DocumentService;
use App\Services\JobService;
use App\Services\Security\Auth;
use App\Services\Security\Validator;

final class JobController
{
    public function index(Request $request): void
    {
        $discover = $request->path() === '/discover';
        View::render('pages/jobs', [
            'title' => $discover ? 'Discover jobs' : 'My jobs',
            'active' => $discover ? 'discover' : 'jobs',
            'jobs' => (new JobRepository())->list(Auth::id(), $_GET),
            'sources' => (new SourceRepository())->all(Auth::id()),
        ]);
    }

    public function add(Request $request): void
    {
        View::render('pages/add-job', ['title' => 'Add an opportunity', 'active' => 'jobs']);
    }

    public function show(Request $request, int $id): void
    {
        View::render('pages/job', [
            'title' => 'Opportunity details',
            'active' => 'jobs',
            'job' => (new JobRepository())->get(Auth::id(), $id),
        ]);
    }

    public function edit(Request $request, int $id): void
    {
        View::render('pages/add-job', [
            'title' => 'Edit opportunity',
            'active' => 'jobs',
            'editingJob' => (new JobRepository())->get(Auth::id(), $id),
        ]);
    }

    public function update(Request $request, int $id): void
    {
        $data = (new JobService())->normalise($request->all());
        (new JobRepository())->edit(Auth::id(), $id, $data);
        Response::json([
            'ok' => true,
            'message' => 'Job updated. Previous matches and approvals require review.',
            'redirect' => '/jobs/' . $id,
        ]);
    }

    public function create(Request $request): void
    {
        $data = $request->all();
        if (!empty($data['poster_document_id'])) {
            (new DocumentRepository())->get(Auth::id(), (int) $data['poster_document_id']);
        }
        unset($data['source_id']);
        $result = (new JobService())->create(Auth::id(), $data);
        Response::json([
            'ok' => true,
            'redirect' => '/jobs/' . $result['id'],
            'message' => $result['duplicate']
                ? 'This opportunity is already saved.'
                : 'Opportunity saved.',
        ]);
    }

    public function extract(Request $request): void
    {
        $type = Validator::choice($request->string('type'), 'import method', [
            'text',
            'url',
            'poster',
        ]);
        $url = '';
        if ($type === 'poster') {
            $data = (new DocumentService())->extractPoster(
                Auth::id(),
                (int) $request->string('document_id'),
            );
        } else {
            if ($type === 'url') {
                if (empty($request->all()['permission_confirmed'])) {
                    throw new HttpException(
                        'Confirm that automated access is permitted for this page.',
                    );
                }
                $url = $request->string('url');
                $public = new PublicContent();
                $text = $public->text($public->fetch($url));
            } else {
                $text = Validator::text($request->string('text'), 'advertisement', 60000, true);
            }
            $data = (new AiService())->run(Auth::id(), 'extract_job', ['advertisement' => $text]);
        }
        Response::json([
            'ok' => true,
            'data' => [...$data, 'original_url' => $url],
            'message' => 'Details extracted. Check every field before saving.',
        ]);
    }

    public function analyse(Request $request, int $id): void
    {
        (new JobRepository())->get(Auth::id(), $id);
        $task = (new QueueRepository())->enqueue(Auth::id(), 'analyse_job', ['job_id' => $id]);
        Response::json([
            'ok' => true,
            'task_id' => $task,
            'message' => 'Analysis queued. Run the worker to process it.',
        ]);
    }

    public function apply(Request $request, int $id): void
    {
        $job = (new JobRepository())->get(Auth::id(), $id);
        $applicationId = (new ApplicationRepository())->create(Auth::id(), $job);
        Response::json(['ok' => true, 'redirect' => '/applications/' . $applicationId]);
    }

    public function verify(Request $request, int $id): void
    {
        if (empty($request->all()['verified'])) {
            throw new HttpException(
                'Confirm that the job details and application instructions are accurate.',
            );
        }
        (new JobRepository())->verify(Auth::id(), $id);
        Response::json(['ok' => true, 'message' => 'Job details verified.', 'reload' => true]);
    }

    public function assist(Request $request, int $id): void
    {
        $task = Validator::choice($request->string('task'), 'AI task', ['summary', 'questions']);
        $profile = (new \App\Repositories\ProfileRepository())->get(Auth::id());
        if ($task === 'questions' && empty($profile['verified_at'])) {
            throw new HttpException('Verify your career profile before drafting answers.');
        }
        $data = (new AiService())->run(Auth::id(), $task, [
            'job' => (new JobRepository())->get(Auth::id(), $id),
            'profile' => $task === 'questions' ? $profile : [],
            'questions' => Validator::text($request->string('questions'), 'questions', 10000),
        ]);
        Response::json([
            'ok' => true,
            'data' => $data,
            'message' => 'Draft ready. Verify all statements before use.',
        ]);
    }

    public function status(Request $request, int $id): void
    {
        $status = Validator::choice($request->string('status'), 'job status', [
            'saved',
            'shortlisted',
            'archived',
        ]);
        (new JobRepository())->status(Auth::id(), $id, $status);
        Response::json(['ok' => true, 'message' => 'Job updated.', 'reload' => true]);
    }
}
