<?php

declare(strict_types=1);

require dirname(__DIR__) . '/Bootstrap/App.php';

use App\Core\Database;
use App\Repositories\AccountRepository;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$accounts = new AccountRepository();
$sources = [];
foreach (
    Database::connection()
        ->query(
            "SELECT preset_key, MAX(health = 'connected' AND enabled = 1) AS connected FROM job_sources WHERE preset_key IS NOT NULL GROUP BY preset_key",
        )
        ->fetchAll()
    as $source
) {
    if ($source['connected']) {
        $sources[$source['preset_key']] = ['enabled' => true, 'health' => 'connected'];
    }
}
if ($sources) {
    $accounts->saveSite('job_sources', $sources);
    echo count($sources) . ' connected sites published to the onboarding catalogue.' . PHP_EOL;
}
foreach ($argv as $argument) {
    if (preg_match('/^--ai-provider=([0-9]+)$/', $argument, $matches)) {
        $statement = Database::connection()->prepare(
            'SELECT name, endpoint, encrypted_key, model, options_json FROM ai_providers WHERE id = ?',
        );
        $statement->execute([(int) $matches[1]]);
        $provider = $statement->fetch();
        if (!$provider) {
            throw new RuntimeException('Provider not found.');
        }
        $accounts->saveSite('ai_provider', $provider);
        echo 'AI connection copied to private site configuration.' . PHP_EOL;
    }
}
echo 'Site AI can also be configured using GEMINI_API_KEY, GEMINI_MODEL and model prices in the server environment.' .
    PHP_EOL;
