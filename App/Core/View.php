<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\Security\Auth;

final class View
{
    public static function render(string $template, array $data = [], string $layout = 'app'): void
    {
        extract($data, EXTR_SKIP);
        $currentUser = Auth::user();
        ob_start();
        require BASE_PATH . '/Resources/Views/' . $template . '.php';
        $content = ob_get_clean();
        require BASE_PATH . '/Resources/Views/layouts/' . $layout . '.php';
    }
}
