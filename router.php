<?php
declare(strict_types=1);

/**
 * Router script for PHP's built-in server:
 *   php -S 127.0.0.1:8000 router.php
 *
 * Only static files under /assets/ and /uploads/ are served directly.
 * Everything else, including direct requests to model/config PHP files,
 * goes through the front controller.
 */

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($path) ? rawurldecode($path) : '/';

// Never allow traversal outside the static asset roots.
if (str_contains($path, "\0") || preg_match('#(^|/)\.\.(/|$)#', $path)) {
    require __DIR__ . '/index.php';
    return true;
}

$staticAllowed = str_starts_with($path, '/assets/')
    || str_starts_with($path, '/uploads/')
    || $path === '/favicon.ico'
    || $path === '/robots.txt';

if ($staticAllowed) {
    $file = realpath(__DIR__ . $path);
    if ($file !== false && is_file($file)) {
        if (str_starts_with($path, '/assets/') || str_starts_with($path, '/uploads/')) {
            $root = realpath(__DIR__ . (str_starts_with($path, '/assets/') ? '/assets' : '/uploads'));
            if ($root !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
                if (str_starts_with($path, '/uploads/')) {
                    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    if (!in_array($extension, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'ico'], true)) {
                        require __DIR__ . '/index.php';
                        return true;
                    }
                }
                return false;
            }
        } else {
            return false;
        }
    }
}

require __DIR__ . '/index.php';
return true;
