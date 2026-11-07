<?php
declare(strict_types=1);

if (!defined('SOLVINGKEYS')) {
    http_response_code(403);
    exit;
}

function settings_page(): void
{
    $user = current_user();
    if ($user === null) {
        redirect('login');
    }
    render_view('settings', [
        'pageTitle' => '设置 · ' . config('app.name'),
        'bodyClass' => 'page-settings',
        'user' => $user,
        'avatarUrl' => avatar_url($user, 640),
        'hasVault' => (string) ($user['keys_key'] ?? '') !== '',
        'pageEntry' => 'src/pages/settings.js',
    ]);
}

function settings_profile_post(): void
{
    $user = current_user();
    if ($user === null) {
        redirect('login');
    }
    try {
        User::updateProfile(
            (int) $user['id'],
            input_string('username'),
            input_string('email'),
            input_string('avatar', '-1'),
        );
        flash('success', '个人资料已更新。');
    } catch (HttpException $error) {
        flash('error', $error->getMessage());
    }
    redirect('settings');
}

function settings_password_post(): void
{
    $user = current_user();
    if ($user === null) {
        redirect('login');
    }
    $current = input_string('current_password');
    $new = input_string('new_password');
    $confirm = input_string('new_password_confirm');

    if (!User::verifyPassword($user, $current)) {
        flash('error', '当前密码不正确。');
        redirect('settings');
    }
    if ($new !== $confirm) {
        flash('error', '两次输入的新密码不一致。');
        redirect('settings');
    }
    try {
        User::updatePassword((int) $user['id'], $new);
        session_regenerate_id(true);
        flash('success', '登录密码已更新。');
    } catch (HttpException $error) {
        flash('error', $error->getMessage());
    }
    redirect('settings');
}
