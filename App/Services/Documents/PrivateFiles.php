<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Core\Env;

final class PrivateFiles
{
    public static function protect(string $path): void
    {
        // Shared worker/web installations may grant read access through a private
        // group or named ACL. World-readable uploads are never permitted.
        $mode = Env::get('PRIVATE_FILE_MODE', '0600');
        if (!in_array($mode, ['0600', '0640'], true)) {
            throw new \RuntimeException('PRIVATE_FILE_MODE must be 0600 or 0640.');
        }
        if (!chmod($path, octdec($mode))) {
            throw new \RuntimeException('Could not protect the private document.');
        }
    }
}
