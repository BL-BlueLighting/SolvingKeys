<?php
declare(strict_types=1);

if (!defined('SOLVINGKEYS')) {
    http_response_code(403);
    exit;
}

function notifications_page(): void
{
    $user = current_user();
    if ($user === null) {
        redirect('login');
    }
    $userId = (int) $user['id'];
    render_view('notifications', [
        'pageTitle' => '通知 · ' . config('app.name'),
        'bodyClass' => 'page-notifications',
        'notifications' => Notification::forUser($userId, 100, 0),
        'unread' => Notification::unreadCount($userId),
    ]);
}

function notifications_read_post(): void
{
    $user = current_user();
    if ($user === null) {
        redirect('login');
    }
    $userId = (int) $user['id'];
    if (input_string('all') === '1') {
        Notification::markAllRead($userId);
        flash('success', '所有通知已标为已读。');
    } else {
        $id = positive_int($_POST['id'] ?? null);
        if ($id !== null) {
            Notification::markRead($id, $userId);
        }
    }
    redirect('notifications');
}
