<?php
declare(strict_types=1);

if (!defined('SOLVINGKEYS')) {
    http_response_code(403);
    exit;
}

final class HttpException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly string $errorCode = '',
    ) {
        parent::__construct($message);
    }
}

function abort(int $status, string $message, string $errorCode = ''): never
{
    throw new HttpException($status, $message, $errorCode);
}

function config(string $key, mixed $default = null): mixed
{
    $values = $GLOBALS['SK_CONFIG'] ?? [];
    $current = $values;
    foreach (explode('.', $key) as $segment) {
        if (!is_array($current) || !array_key_exists($segment, $current)) {
            return $default;
        }
        $current = $current[$segment];
    }
    return $current;
}

function sk_root(string $path = ''): string
{
    $root = defined('SK_ROOT') ? SK_ROOT : dirname(__DIR__);
    return $path === '' ? $root : $root . '/' . ltrim($path, '/');
}

function db(): PDO
{
    return Database::pdo();
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    return $proto === 'https';
}

function base_path(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $configured = config('app.base_path');
    if (is_string($configured)) {
        $cached = $configured === '' ? '' : '/' . trim($configured, '/');
        return $cached;
    }

    // The PHP built-in server serves the application from its own root.
    if (PHP_SAPI === 'cli-server') {
        $cached = '';
        return $cached;
    }

    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = str_replace('\\', '/', dirname($script));
    if ($dir === '.' || $dir === '/' || $dir === '') {
        $cached = '';
    } else {
        $cached = rtrim($dir, '/');
    }
    return $cached;
}

function url(string $path = ''): string
{
    $base = base_path();
    $path = '/' . ltrim($path, '/');
    if ($path === '/') {
        return $base === '' ? '/' : $base . '/';
    }
    return $base . $path;
}

function request_path(): string
{
    if (isset($_GET['r']) && is_string($_GET['r'])) {
        return trim($_GET['r'], '/');
    }

    $uri = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $uri = is_string($uri) ? $uri : '/';
    $base = base_path();
    if ($base !== '' && str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base));
    }
    return trim(rawurldecode($uri), '/');
}

function request_method(): string
{
    return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

function is_api_request(): bool
{
    $path = request_path();
    return $path === 'api' || str_starts_with($path, 'api/');
}

function client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return substr($ip, 0, 64);
}

function run_form_action(callable $action, string $redirectPath, string $successMessage = ''): never
{
    try {
        $action();
        if ($successMessage !== '') {
            flash('success', $successMessage);
        }
    } catch (HttpException $error) {
        flash('error', $error->getMessage());
    }
    redirect($redirectPath);
}

/**
 * Build a cross-driver LIKE condition for a JSON array of integers stored as
 * e.g. [1,2,3]. The parameters are appended to $params in order.
 */
function json_int_array_contains_sql(string $column, int $value, array &$params): string
{
    $needle = (string) $value;
    $patterns = ['[' . $needle . ']', '[' . $needle . ',%', '%,' . $needle . ']', '%,' . $needle . ',%'];
    foreach ($patterns as $pattern) {
        $params[] = $pattern;
    }
    return '(' . $column . ' = ? OR ' . $column . ' LIKE ? OR ' . $column . ' LIKE ? OR ' . $column . ' LIKE ?)';
}

function query_array(string $name): array
{
    $value = $_GET[$name] ?? [];
    if (is_string($value)) {
        $value = $value === '' ? [] : explode(',', $value);
    }
    if (!is_array($value)) {
        return [];
    }
    $result = [];
    foreach ($value as $item) {
        if (is_string($item) || is_int($item)) {
            $item = trim((string) $item);
            if ($item !== '') {
                $result[$item] = $item;
            }
        }
    }
    return array_values($result);
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path, int $status = 303): never
{
    header('Location: ' . url($path), true, $status);
    exit;
}

function flash(string $key, ?string $value = null): ?string
{
    if ($value !== null) {
        $_SESSION['_flash'][$key] = $value;
        return null;
    }
    if (!isset($_SESSION['_flash'][$key])) {
        return null;
    }
    $message = (string) $_SESSION['_flash'][$key];
    unset($_SESSION['_flash'][$key]);
    return $message;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function request_csrf_token(): ?string
{
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (is_string($header) && $header !== '') {
        return $header;
    }
    $posted = $_POST['_csrf'] ?? null;
    return is_string($posted) ? $posted : null;
}

function verify_csrf(): bool
{
    $token = request_csrf_token();
    return is_string($token) && $token !== '' && hash_equals(csrf_token(), $token);
}

function require_csrf(): void
{
    if (!in_array(request_method(), ['GET', 'HEAD', 'OPTIONS'], true) && !verify_csrf()) {
        abort(419, '安全令牌已失效，请刷新页面后重试。', 'csrf');
    }
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function json_ok(mixed $data = null, int $status = 200): never
{
    json_response(['ok' => true, 'data' => $data], $status);
}

function json_error(int $status, string $message, string $code = '', mixed $extra = null): never
{
    $error = ['code' => $code !== '' ? $code : 'error', 'message' => $message];
    if ($extra !== null) {
        $error['extra'] = $extra;
    }
    json_response(['ok' => false, 'error' => $error], $status);
}

function api_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        abort(400, '请求体必须是有效的 JSON 对象。', 'invalid_json');
    }
    return $decoded;
}

function input_string(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? $value : $default;
}

function api_string(array $input, string $key, string $default = ''): string
{
    $value = $input[$key] ?? $default;
    return is_string($value) ? $value : $default;
}

function api_bool(array $input, string $key, bool $default = false): bool
{
    if (!array_key_exists($key, $input)) {
        return $default;
    }
    $value = $input[$key];
    if (is_bool($value)) {
        return $value;
    }
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function current_user(bool $refresh = false): ?array
{
    static $cached = null;
    static $loaded = false;
    if ($refresh) {
        $cached = null;
        $loaded = false;
    }
    if ($loaded) {
        return $cached;
    }
    $loaded = true;
    $id = $_SESSION['user_id'] ?? null;
    if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
        return null;
    }
    $user = User::find((int) $id);
    if ($user === null) {
        unset($_SESSION['user_id']);
        return null;
    }
    $cached = $user;
    return $cached;
}

function require_guest(): void
{
    if (current_user() !== null) {
        redirect('');
    }
}

function require_login(): void
{
    if (current_user() !== null) {
        return;
    }
    if (is_api_request()) {
        json_error(401, '请先登录。', 'unauthenticated');
    }
    redirect('login');
}

function require_admin(): array
{
    $user = current_user();
    if ($user === null) {
        if (is_api_request()) {
            json_error(401, '请先登录。', 'unauthenticated');
        }
        redirect('login');
    }
    if (!User::isAdmin($user)) {
        abort(403, '需要管理员权限。');
    }
    return $user;
}

function is_banned(?array $user = null): bool
{
    $user ??= current_user();
    return $user !== null && (int) ($user['ban'] ?? 0) === 1;
}

function user_can_write(?array $user = null): bool
{
    $user ??= current_user();
    return $user !== null && !is_banned($user);
}

function require_write(): array
{
    $user = current_user();
    if ($user === null) {
        if (is_api_request()) {
            json_error(401, '请先登录。', 'unauthenticated');
        }
        redirect('login');
    }
    if (is_banned($user)) {
        if (is_api_request()) {
            json_error(403, '账号当前为只读状态，无法修改内容。', 'banned');
        }
        abort(403, '账号当前为只读状态，无法修改内容。');
    }
    return $user;
}

function public_user(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'username' => (string) $user['username'],
        'avatar_url' => avatar_url($user, 96),
    ];
}

function gravatar_url(string $email, int $size = 96): string
{
    $hash = md5(strtolower(trim($email)));
    return 'https://www.gravatar.com/avatar/' . $hash . '?d=identicon&s=' . max(16, min($size, 640));
}

function qq_avatar_url(string $qq, int $size = 96): string
{
    // q1.qlogo.cn only serves a few discrete sizes. 64/96/128 return HTTP 400.
    // Request the nearest supported size and let CSS scale it down/up.
    if ($size <= 48) {
        $qqSize = 40;
    } elseif ($size <= 160) {
        $qqSize = 100;
    } else {
        $qqSize = 640;
    }
    return 'https://q1.qlogo.cn/g?b=qq&nk=' . rawurlencode($qq) . '&s=' . $qqSize;
}

function avatar_url(array $user, int $size = 96): string
{
    $avatar = trim((string) ($user['avatar'] ?? '-1'));
    $email = (string) ($user['email'] ?? '');

    if ($avatar === '' || $avatar === '-1') {
        return gravatar_url($email, $size);
    }

    if (preg_match('/^-2:(\d{5,12})$/', $avatar, $match)) {
        return qq_avatar_url($match[1], $size);
    }

    if ($avatar === '-2') {
        if (preg_match('/^(\d{5,12})@qq\.com$/i', $email, $match)) {
            return qq_avatar_url($match[1], $size);
        }
        return gravatar_url($email, $size);
    }

    if (preg_match('#^https://#i', $avatar)) {
        return $avatar;
    }

    // The CSP only permits HTTPS images; use a local fallback for legacy
    // HTTP avatar URLs instead of sending a request that is guaranteed to be blocked.
    if (preg_match('#^http://#i', $avatar)) {
        return url('assets/default-avatar.svg');
    }

    if (str_starts_with($avatar, '/') && !str_starts_with($avatar, '//')) {
        return url(ltrim($avatar, '/'));
    }

    return gravatar_url($email, $size);
}

function site_name(): string
{
    $name = trim(Site::get('site_name', ''));
    return $name !== '' ? $name : (string) config('app.name', 'SolvingKeys');
}

function site_logo_url(): string
{
    $logo = trim(Site::get('site_logo', ''));
    if ($logo === '') {
        return '';
    }
    if (preg_match('#^https://#i', $logo)) {
        return $logo;
    }
    if (str_starts_with($logo, '/') && !str_starts_with($logo, '//')) {
        return url(ltrim($logo, '/'));
    }
    return '';
}

function site_description(): string
{
    $value = trim(Site::get('site_description', ''));
    return $value !== '' ? $value : '用于存放、共享与协作整理解谜线索的端到端加密平台。';
}

function site_footer_text(): string
{
    $value = trim(Site::get('footer_text', ''));
    return $value !== '' ? $value : 'Keys 内容与消息在浏览器端加密；服务端不保存明文密钥。';
}

function format_time(?string $utcTime): string
{
    if ($utcTime === null || $utcTime === '') {
        return '';
    }
    try {
        $date = new DateTimeImmutable($utcTime, new DateTimeZone('UTC'));
        $date = $date->setTimezone(new DateTimeZone((string) config('app.timezone', 'UTC')));
        return $date->format('Y-m-d H:i');
    } catch (Throwable) {
        return (string) $utcTime;
    }
}

function iso_time(?string $utcTime): ?string
{
    if ($utcTime === null || $utcTime === '') {
        return null;
    }
    try {
        $date = new DateTimeImmutable($utcTime, new DateTimeZone('UTC'));
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    } catch (Throwable) {
        return null;
    }
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function normalize_username(string $username): string
{
    return mb_strtolower(trim($username), 'UTF-8');
}

function validate_username(string $value): string
{
    $value = trim($value);
    $length = mb_strlen($value, 'UTF-8');
    $max = (int) config('limits.username_max', 32);
    if ($length < 2 || $length > $max) {
        abort(422, '用户名需为 2 到 ' . $max . ' 个字符。', 'invalid_username');
    }
    if (!preg_match('/^[\p{L}\p{N}_-]+$/u', $value)) {
        abort(422, '用户名只能包含文字、数字、下划线和连字符。', 'invalid_username');
    }
    return $value;
}

function validate_email(string $value): string
{
    $value = trim($value);
    if ($value === '' || strlen($value) > 254 || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
        abort(422, '请输入有效的邮箱地址。', 'invalid_email');
    }
    return $value;
}

function validate_password(string $value): string
{
    $length = mb_strlen($value, 'UTF-8');
    $min = (int) config('security.password_min', 8);
    $max = (int) config('security.password_max', 200);
    if ($length < $min || $length > $max) {
        abort(422, '密码长度需为 ' . $min . ' 到 ' . $max . ' 个字符。', 'invalid_password');
    }
    return $value;
}

function validate_display_name(string $value, string $label, int $max): string
{
    $value = trim($value);
    $length = mb_strlen($value, 'UTF-8');
    if ($length < 1 || $length > $max) {
        abort(422, $label . '长度需为 1 到 ' . $max . ' 个字符。', 'invalid_name');
    }
    if (preg_match('/[\x00-\x1F\x7F]/u', $value)) {
        abort(422, $label . '不能包含控制字符。', 'invalid_name');
    }
    return $value;
}

function validate_key_name(string $value): string
{
    return validate_display_name($value, 'Key 名称', (int) config('limits.key_name_max', 120));
}

function validate_project_name(string $value): string
{
    return validate_display_name($value, '项目名称', (int) config('limits.project_name_max', 120));
}

function validate_avatar(string $value): string
{
    $value = trim($value);
    if ($value === '' || $value === '-1' || $value === '-2') {
        return $value === '' ? '-1' : $value;
    }
    if (preg_match('/^-2:(\d{5,12})$/', $value, $match)) {
        return '-2:' . $match[1];
    }
    if (preg_match('#^https://#i', $value) && strlen($value) <= 2048) {
        return $value;
    }
    if (str_starts_with($value, '/') && !str_starts_with($value, '//') && strlen($value) <= 2048) {
        return $value;
    }
    abort(422, '头像地址必须是 HTTPS 链接或站内路径。', 'invalid_avatar');
}

function validate_ciphertext(string $value, int $maxBytes, string $label): string
{
    if (strlen($value) > $maxBytes) {
        abort(413, $label . '超出大小限制。', 'too_large');
    }
    if ($value !== '' && !preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', $value)) {
        abort(422, $label . '不是有效的密文。', 'invalid_ciphertext');
    }
    return $value;
}

function validate_mentions(mixed $mentions): array
{
    if (!is_array($mentions)) {
        return [];
    }
    $result = [];
    foreach ($mentions as $mention) {
        if (!is_string($mention) && !is_int($mention)) {
            continue;
        }
        $mention = trim((string) $mention);
        if ($mention === '' || mb_strlen($mention, 'UTF-8') > 64) {
            continue;
        }
        $result[$mention] = $mention;
        if (count($result) >= 50) {
            break;
        }
    }
    return array_values($result);
}

function positive_int(mixed $value): ?int
{
    if (is_int($value)) {
        return $value > 0 ? $value : null;
    }
    if (is_string($value) && ctype_digit($value)) {
        $int = (int) $value;
        return $int > 0 ? $int : null;
    }
    return null;
}

function route_int(string $value): int
{
    $id = positive_int($value);
    if ($id === null) {
        abort(404, '页面不存在。');
    }
    return $id;
}

function is_current(string $path): bool
{
    $current = request_path();
    $path = trim($path, '/');
    if ($path === '') {
        return $current === '';
    }
    return $current === $path || str_starts_with($current, $path . '/');
}

function unread_notification_count(): int
{
    $user = current_user();
    if ($user === null) {
        return 0;
    }
    return Notification::unreadCount((int) $user['id']);
}

function vite_manifest(): array
{
    static $manifest = null;
    if ($manifest !== null) {
        return $manifest;
    }
    $path = sk_root('assets/dist/.vite/manifest.json');
    if (!is_file($path)) {
        $path = sk_root('assets/dist/manifest.json');
    }
    if (!is_file($path)) {
        $manifest = [];
        return $manifest;
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    $manifest = is_array($decoded) ? $decoded : [];
    return $manifest;
}

function vite_assets_missing(): bool
{
    return vite_manifest() === [];
}

function vite_asset_tags(string $entry): string
{
    $manifest = vite_manifest();
    if (!isset($manifest[$entry])) {
        return '';
    }

    $css = [];
    $visited = [];
    $collect = static function (string $key) use (&$collect, &$css, &$visited, $manifest): void {
        if (isset($visited[$key]) || !isset($manifest[$key])) {
            return;
        }
        $visited[$key] = true;
        foreach ($manifest[$key]['css'] ?? [] as $file) {
            $css[$file] = $file;
        }
        foreach ($manifest[$key]['imports'] ?? [] as $import) {
            $collect($import);
        }
    };
    $collect($entry);

    $html = '';
    foreach ($css as $file) {
        $html .= '<link rel="stylesheet" href="' . e(url('assets/dist/' . $file)) . '">' . "\n";
    }
    $entryFile = $manifest[$entry]['file'] ?? '';
    if ($entryFile !== '') {
        $html .= '<script type="module" src="' . e(url('assets/dist/' . $entryFile)) . '"></script>' . "\n";
    }
    return $html;
}

function render_view(string $view, array $data = [], bool $withLayout = true): void
{
    $theme = (string) config('theme', 'default');
    $viewFile = sk_root('views/' . $theme . '/' . $view . '.php');
    if (!is_file($viewFile)) {
        throw new RuntimeException('View not found: ' . $view);
    }

    $pageTitle = (string) ($data['pageTitle'] ?? config('app.name', 'SolvingKeys'));
    $pageEntry = $data['pageEntry'] ?? null;
    $bodyClass = (string) ($data['bodyClass'] ?? '');

    extract($data, EXTR_SKIP);
    ob_start();
    require $viewFile;
    $content = (string) ob_get_clean();

    if (!$withLayout) {
        echo $content;
        return;
    }

    $layoutFile = sk_root('views/' . $theme . '/layout.php');
    if (!is_file($layoutFile)) {
        throw new RuntimeException('Layout not found for theme ' . $theme);
    }
    require $layoutFile;
}

function render_error_page(int $status, string $message): void
{
    http_response_code($status);
    try {
        render_view('errors/error', [
            'pageTitle' => $status . ' · ' . config('app.name', 'SolvingKeys'),
            'status' => $status,
            'message' => $message,
            'bodyClass' => 'page-error',
        ]);
    } catch (Throwable) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><title>' . $status
            . '</title><body style="font-family:system-ui;padding:3rem"><h1>' . $status
            . '</h1><p>' . e($message) . '</p></body></html>';
    }
}

function sk_log(Throwable $error): void
{
    $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . get_class($error) . ': ' . $error->getMessage()
        . ' in ' . $error->getFile() . ':' . $error->getLine() . PHP_EOL
        . $error->getTraceAsString() . PHP_EOL . PHP_EOL;
    $dir = sk_root('data');
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir) || @file_put_contents($dir . '/error.log', $line, FILE_APPEND | LOCK_EX) === false) {
        error_log($line);
    }
}

function sk_exception_handler(Throwable $error): void
{
    $status = 500;
    $message = '服务器开小差了，请稍后再试。';
    $code = 'internal_error';

    if ($error instanceof HttpException) {
        $status = $error->status;
        $message = $error->getMessage();
        $code = $error->errorCode;
        if ($status >= 500) {
            sk_log($error);
        }
    } else {
        sk_log($error);
        if ((bool) config('app.debug', false)) {
            $message = get_class($error) . ': ' . $error->getMessage();
        }
    }

    if (is_api_request()) {
        json_error($status, $message, $code);
    }

    render_error_page($status, $message);
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function display_user(array $user): string
{
    return (string) $user['username'];
}
