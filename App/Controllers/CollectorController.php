<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Env;
use App\Core\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\CollectorRepository;

/** Server-to-server endpoints: bearer authentication precedes any body parsing. */
final class CollectorController
{
    public static function authenticate(string $authorization): void
    {
        $token = Env::get('COLLECTOR_TOKEN');
        if (
            strlen($token) < 32 ||
            preg_match('/\s/', $token) ||
            !hash_equals('Bearer ' . $token, $authorization)
        ) {
            throw new HttpException('Collector authentication failed.', 401);
        }
    }

    private function input(): array
    {
        self::authenticate(
            $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''),
        );
        if (!str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
            throw new HttpException('Expected application/json.', 415);
        }
        $raw = file_get_contents('php://input', false, null, 0, 2097153);
        if ($raw === false || strlen($raw) > 2097152) {
            throw new HttpException('Collector request exceeds two MiB.', 413);
        }
        try {
            $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException('Invalid collector JSON.', 400);
        }
        if (!is_array($input) || array_is_list($input)) {
            throw new HttpException('Expected a JSON object.', 400);
        }
        return $input;
    }

    private function integer(array $input, string $key): int
    {
        if (!is_int($input[$key] ?? null) || $input[$key] < 1) {
            throw new HttpException('Invalid ' . $key . '.');
        }
        return $input[$key];
    }

    private function text(array $input, string $key): string
    {
        if (!is_string($input[$key] ?? null) || strlen($input[$key]) > 200) {
            throw new HttpException('Invalid ' . $key . '.');
        }
        return $input[$key];
    }

    public function claim(Request $request): void
    {
        $input = $this->input();
        $result = (new CollectorRepository())->claim(
            isset($input['run_id']) ? $this->integer($input, 'run_id') : null,
            $this->text($input, 'execution'),
        );
        Response::json(['ok' => true, ...$result]);
    }

    public function batch(Request $request): void
    {
        $input = $this->input();
        if (!is_array($input['jobs'] ?? null)) {
            throw new HttpException('Invalid vacancy batch.');
        }
        $result = (new CollectorRepository())->batch(
            $this->integer($input, 'run_id'),
            $this->text($input, 'run_token'),
            $this->integer($input, 'source_id'),
            $this->text($input, 'batch_key'),
            $input['jobs'],
        );
        Response::json(['ok' => true, ...$result]);
    }

    public function finish(Request $request): void
    {
        $input = $this->input();
        if (!is_array($input['results'] ?? null)) {
            throw new HttpException('Invalid source results.');
        }
        $result = (new CollectorRepository())->finish(
            $this->integer($input, 'run_id'),
            $this->text($input, 'run_token'),
            $input['results'],
        );
        Response::json(['ok' => true, ...$result]);
    }
}
