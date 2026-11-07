<?php
declare(strict_types=1);

if (!defined('SOLVINGKEYS')) {
    http_response_code(403);
    exit;
}

function home_page(): void
{
    $user = current_user();
    if ($user === null) {
        if (User::count() === 0) {
            redirect('register');
        }
        redirect('login');
    }

    $userId = (int) $user['id'];
    $keys = Keys::recentForOwner($userId, 5);
    $projects = Project::recentForUser($userId, 5);
    $publicProjects = Project::publicList(6, 0);
    $invitations = Project::pendingInvitationsForUser($userId);
    $notifications = Notification::latest($userId, 5);

    render_view('home', [
        'pageTitle' => config('app.name'),
        'bodyClass' => 'page-home',
        'user' => $user,
        'keys' => $keys,
        'projects' => $projects,
        'publicProjects' => $publicProjects,
        'invitations' => $invitations,
        'notifications' => $notifications,
        'keyCount' => Keys::countForOwner($userId),
        'projectCount' => Project::countForUser($userId),
    ]);
}
