<?php
declare(strict_types=1);

if (!defined('SOLVINGKEYS')) {
    http_response_code(403);
    exit;
}

function auth_login_page(): void
{
    require_guest();
    render_view('auth/login', [
        'pageTitle' => '登录 · ' . config('app.name'),
        'bodyClass' => 'page-auth',
        'oldUsername' => flash('login_username') ?? '',
        'registerOpen' => Site::registrationOpen() || User::count() === 0,
        'noUsers' => User::count() === 0,
    ]);
}

function auth_login_post(): void
{
    require_guest();

    $username = input_string('username');
    $password = input_string('password');
    $identifier = normalize_username($username);
    $ip = client_ip();
    $max = (int) config('security.login_max_attempts', 6);
    $window = (int) config('security.login_window', 900);

    if ($identifier === '' || $password === '') {
        flash('error', '请输入用户名和密码。');
        flash('login_username', $username);
        redirect('login');
    }

    if (User::tooManyLoginAttempts($identifier, $ip, $max, $window)) {
        flash('error', '登录尝试次数过多，请稍后再试。');
        flash('login_username', $username);
        redirect('login');
    }

    $user = User::findByUsername($username);
    if ($user === null || !User::verifyPassword($user, $password)) {
        User::recordFailedLogin($identifier, $ip, $window);
        flash('error', '用户名或密码错误。');
        flash('login_username', $username);
        redirect('login');
    }

    User::clearLoginAttempts($identifier, $ip);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    unset($_SESSION['_flash']);

    redirect('');
}

function auth_register_page(): void
{
    require_guest();
    $open = Site::registrationOpen() || User::count() === 0;
    render_view('auth/register', [
        'pageTitle' => '注册 · ' . config('app.name'),
        'bodyClass' => 'page-auth',
        'registrationOpen' => $open,
        'firstUser' => User::count() === 0,
        'pageEntry' => 'src/pages/register.js',
    ]);
}

function auth_logout(): never
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool) ($params['secure'] ?? false),
            'httponly' => (bool) ($params['httponly'] ?? true),
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
    redirect('login');
}

/**
 * POST /api/register
 * The 16-digit vault password is never sent; the browser sends only the
 * AES-256-GCM encrypted keys_key envelope.
 */
function auth_register_api(): never
{
    if (!Site::registrationOpen() && User::count() > 0) {
        json_error(403, '当前未开放注册。', 'registration_closed');
    }

    $input = api_input();
    $cloudVault = api_bool($input, 'cloud_vault', false);
    $envelope = api_string($input, 'vault_envelope', '');
    $avatar = api_string($input, 'avatar', '-1');

    try {
        $user = User::create([
            'username' => api_string($input, 'username', ''),
            'email' => api_string($input, 'email', ''),
            'password' => api_string($input, 'password', ''),
            'avatar' => $avatar,
            'cloud_vault' => $cloudVault,
            'keys_key' => $cloudVault ? $envelope : '',
        ]);
    } catch (HttpException $error) {
        json_error($error->status, $error->getMessage(), $error->errorCode);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    unset($_SESSION['_flash']);

    json_ok([
        'user' => public_user($user) + [
            'permission' => $user['permission'],
            'cloud_vault' => (int) $user['cloud_vault'] === 1,
        ],
        'redirect' => url(''),
    ], 201);
}
