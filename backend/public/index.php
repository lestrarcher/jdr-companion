<?php

use App\Kernel;

// The Docker development server uses this file as its router script.
// Let PHP serve existing public files with their native MIME type.
if (PHP_SAPI === 'cli-server') {
    $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
    $file = realpath(__DIR__.$path);
    if ($file !== false && str_starts_with($file, __DIR__.DIRECTORY_SEPARATOR)
        && is_file($file) && $file !== __FILE__) {
        return false;
    }
}

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
