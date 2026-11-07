<?php
declare(strict_types=1);

if (!defined('SOLVINGKEYS')) {
    http_response_code(403);
    exit;
}

if (!defined('SK_ROOT')) {
    define('SK_ROOT', dirname(__DIR__));
}

$config = require SK_ROOT . '/config.php';
$localConfig = SK_ROOT . '/config.local.php';
if (is_file($localConfig)) {
    $override = require $localConfig;
    if (is_array($override)) {
        $config = array_replace_recursive($config, $override);
    }
}
$GLOBALS['SK_CONFIG'] = $config;

date_default_timezone_set((string) ($config['app']['timezone'] ?? 'UTC'));

$debug = (bool) ($config['app']['debug'] ?? false);
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');

$dataDir = SK_ROOT . '/data';
if (is_dir($dataDir) && is_writable($dataDir)) {
    ini_set('error_log', $dataDir . '/error.log');
}

if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0775, true);
}

require SK_ROOT . '/include/sm3.php';
require SK_ROOT . '/include/helpers.php';
require SK_ROOT . '/Database.php';
require SK_ROOT . '/User.php';
require SK_ROOT . '/Keys.php';
require SK_ROOT . '/Project.php';
require SK_ROOT . '/Notification.php';
require SK_ROOT . '/Site.php';

if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

set_exception_handler('sk_exception_handler');

$lifetime = (int) ($config['app']['session_lifetime'] ?? 1209600);
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', (string) $lifetime);
session_name((string) ($config['app']['session_name'] ?? 'solvingkeys_sid'));
$cookiePath = base_path();
$cookiePath = $cookiePath === '' ? '/' : $cookiePath . '/';
session_set_cookie_params([
    'lifetime' => $lifetime,
    'path' => $cookiePath,
    'secure' => is_https(),
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-Permitted-Cross-Domain-Policies: none');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
if (is_https()) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

$csp = implode('; ', [
    "default-src 'self'",
    "base-uri 'none'",
    "object-src 'none'",
    "frame-ancestors 'none'",
    "form-action 'self'",
    "img-src 'self' data: blob: https:",
    "font-src 'self' data:",
    "style-src 'self' 'unsafe-inline'",
    "script-src 'self' 'wasm-unsafe-eval'",
    "connect-src 'self'",
    "worker-src 'self' blob:",
    "child-src 'self' blob:",
]);
header('Content-Security-Policy: ' . $csp);

try {
    Database::pdo();

    // Site branding is stored in the database and can be edited by admins.
    // Applying the name to the runtime config makes page titles and the layout
    // follow the configured site name without touching every controller.
    $configuredSiteName = trim(Site::get('site_name', ''));
    if ($configuredSiteName !== '') {
        $GLOBALS['SK_CONFIG']['app']['name'] = $configuredSiteName;
    }
} catch (Throwable $error) {
    sk_log($error);
    if (is_api_request()) {
        json_error(500, $debug ? $error->getMessage() : '数据库连接失败。', 'database_error');
    }
    render_error_page(500, $debug ? $error->getMessage() : '数据库连接失败，请检查配置。');
    exit;
}
