<?php

declare(strict_types=1);

namespace App\Services\AI;

interface Provider
{
    public function generate(array $configuration, array $messages, array $schema): array;
}
