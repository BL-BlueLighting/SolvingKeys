<?php
declare(strict_types=1);

if (!defined('SOLVINGKEYS')) {
    http_response_code(403);
    exit;
}

function keys_index_page(): void
{
    $user = current_user();
    if ($user === null) {
        redirect('login');
    }
    $keys = Keys::forOwner((int) $user['id']);
    render_view('keys/index', [
        'pageTitle' => '我的 Keys · ' . config('app.name'),
        'bodyClass' => 'page-keys',
        'keys' => $keys,
    ]);
}

function keys_new_page(): void
{
    require_write();
    render_view('keys/new', [
        'pageTitle' => '新建 Key · ' . config('app.name'),
        'bodyClass' => 'page-keys',
        'pageEntry' => 'src/pages/keys-new.js',
    ]);
}

function keys_view_page(int $id): void
{
    $user = current_user();
    if ($user === null) {
        redirect('login');
    }
    $key = Keys::findWithOwner($id);
    if ($key === null) {
        abort(404, 'Key 不存在。');
    }

    $owner = public_user([
        'id' => $key['owner'],
        'username' => $key['owner_username'],
        'avatar' => $key['owner_avatar'],
        'email' => $key['owner_email'],
    ]);

    render_view('keys/view', [
        'pageTitle' => $key['key_name'] . ' · ' . config('app.name'),
        'bodyClass' => 'page-key-view',
        'key' => $key,
        'owner' => $owner,
        'isOwner' => (int) $key['owner'] === (int) $user['id'],
        'pageEntry' => 'src/pages/keys-view.js',
    ]);
}

function keys_edit_page(int $id): void
{
    $user = require_write();
    $key = Keys::findWithOwner($id);
    if ($key === null) {
        abort(404, 'Key 不存在。');
    }
    if ((int) $key['owner'] !== (int) $user['id']) {
        abort(403, '只有所有者可以编辑此 Key。');
    }

    render_view('keys/edit', [
        'pageTitle' => '编辑 ' . $key['key_name'] . ' · ' . config('app.name'),
        'bodyClass' => 'page-key-edit',
        'key' => $key,
        'pageEntry' => 'src/pages/keys-edit.js',
    ]);
}
