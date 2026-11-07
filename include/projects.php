<?php
declare(strict_types=1);

if (!defined('SOLVINGKEYS')) {
    http_response_code(403);
    exit;
}

function projects_index_page(): void
{
    $user = current_user();
    if ($user === null) {
        redirect('login');
    }
    $userId = (int) $user['id'];
    render_view('projects/index', [
        'pageTitle' => '项目 · ' . config('app.name'),
        'bodyClass' => 'page-projects',
        'projects' => Project::forUser($userId),
        'publicProjects' => Project::publicList(12, 0),
        'invitations' => Project::pendingInvitationsForUser($userId),
    ]);
}

function projects_create_page(): void
{
    require_write();
    render_view('projects/create', [
        'pageTitle' => '新建项目 · ' . config('app.name'),
        'bodyClass' => 'page-projects',
    ]);
}

function projects_create_post(): void
{
    $user = require_write();
    $name = input_string('project_name');
    try {
        $project = Project::create((int) $user['id'], $name);
        flash('success', '项目已创建。现在可以邀请成员、加入 Keys，并设置消息密钥。');
        redirect('projects/' . (int) $project['id']);
    } catch (HttpException $error) {
        flash('error', $error->getMessage());
        redirect('projects/new');
    }
}

function projects_view_page(int $id): void
{
    $user = current_user();
    if ($user === null) {
        redirect('login');
    }
    $userId = (int) $user['id'];
    $project = Project::find($id);
    if ($project === null) {
        abort(404, '项目不存在。');
    }

    if (!Project::canView($project, $userId)) {
        $invitation = Project::findPendingInvitation($id, $userId);
        if ($invitation === null) {
            abort(403, '你没有访问此项目的权限。');
        }
        $owner = User::find((int) $project['owner']);
        render_view('projects/invited', [
            'pageTitle' => '项目邀请 · ' . config('app.name'),
            'bodyClass' => 'page-projects',
            'project' => $project,
            'invitation' => $invitation,
            'owner' => $owner === null ? null : public_user($owner),
            'canWrite' => user_can_write($user),
        ]);
        return;
    }

    $isMember = Project::isMember($project, $userId);
    $isOwner = Project::isOwner($project, $userId);
    $project = Project::hydrate($project);

    $members = [];
    foreach ($project['member_ids'] as $memberId) {
        $member = User::find($memberId);
        if ($member === null) {
            continue;
        }
        $memberData = public_user($member);
        $memberData['is_owner'] = $memberId === (int) $project['owner'];
        $memberData['is_me'] = $memberId === $userId;
        $members[] = $memberData;
    }

    $projectKeys = [];
    foreach ($project['key_ids'] as $keyId) {
        $key = Keys::findWithOwner($keyId);
        if ($key === null) {
            continue;
        }
        $projectKeys[] = [
            'id' => (int) $key['id'],
            'key_name' => $key['key_name'],
            'updated_at' => $key['updated_at'],
            'owner' => public_user([
                'id' => $key['owner'],
                'username' => $key['owner_username'],
                'avatar' => $key['owner_avatar'],
                'email' => $key['owner_email'],
            ]),
            'owner_id' => (int) $key['owner'],
        ];
    }

    $ownKeys = [];
    if ($isMember) {
        foreach (Keys::forOwner($userId) as $key) {
            if (!in_array((int) $key['id'], $project['key_ids'], true)) {
                $ownKeys[] = $key;
            }
        }
    }

    $messagingKey = null;
    if (!empty($project['messaging_key_id'])) {
        $key = Keys::find((int) $project['messaging_key_id']);
        if ($key !== null) {
            $messagingKey = ['id' => (int) $key['id'], 'key_name' => $key['key_name']];
        }
    }

    $activeVote = $isMember ? Project::activeVisibilityRequest($id) : null;
    $myBallot = null;
    if ($activeVote !== null) {
        foreach ($activeVote['ballots'] as $ballot) {
            if ((int) $ballot['user_id'] === $userId) {
                $myBallot = $ballot;
                break;
            }
        }
    }

    $invitations = $isOwner ? Project::pendingInvitationsForProject($id) : [];

    render_view('projects/view', [
        'pageTitle' => $project['project_name'] . ' · ' . config('app.name'),
        'bodyClass' => 'page-project-view',
        'project' => $project,
        'members' => $members,
        'projectKeys' => $projectKeys,
        'ownKeys' => $ownKeys,
        'messagingKey' => $messagingKey,
        'activeVote' => $activeVote,
        'myBallot' => $myBallot,
        'invitations' => $invitations,
        'isMember' => $isMember,
        'isOwner' => $isOwner,
        'canWrite' => user_can_write($user),
        'pageEntry' => 'src/pages/project-view.js',
    ]);
}

function projects_rename_post(int $id): void
{
    $user = require_write();
    run_form_action(function () use ($id, $user): void {
        $project = Project::find($id);
        if ($project === null) {
            abort(404, '项目不存在。');
        }
        if ((int) $project['owner'] !== (int) $user['id']) {
            abort(403, '只有项目所有者可以修改项目。');
        }
        Project::updateName($id, (int) $user['id'], input_string('project_name'));
    }, 'projects/' . $id, '项目名称已更新。');
}

function projects_delete_post(int $id): void
{
    $user = require_write();
    $project = Project::find($id);
    if ($project === null) {
        abort(404, '项目不存在。');
    }
    if ((int) $project['owner'] !== (int) $user['id']) {
        abort(403, '只有项目所有者可以删除项目。');
    }
    Project::delete($id);
    flash('success', '项目已删除。Key 本身不受影响。');
    redirect('projects');
}

function projects_invite_post(int $id): void
{
    $user = require_write();
    $username = input_string('username');
    try {
        $project = Project::find($id);
        if ($project === null) {
            abort(404, '项目不存在。');
        }
        if ((int) $project['owner'] !== (int) $user['id']) {
            abort(403, '只有项目所有者可以邀请成员。');
        }
        $invitee = User::findByUsername($username);
        if ($invitee === null) {
            abort(404, '找不到该用户。');
        }
        Project::invite($id, (int) $user['id'], (int) $invitee['id']);
        flash('success', '邀请已发送给 ' . $invitee['username'] . '。');
    } catch (HttpException $error) {
        flash('error', $error->getMessage());
    }
    redirect('projects/' . $id);
}

function projects_invitation_respond_post(int $projectId, int $invitationId): void
{
    $user = require_write();
    $invitation = Project::findInvitation($invitationId);
    if ($invitation === null || (int) $invitation['project_id'] !== $projectId) {
        abort(404, '邀请不存在。');
    }
    $accept = input_string('decision') === 'accept';
    try {
        $project = Project::respondInvitation($invitationId, (int) $user['id'], $accept);
        flash('success', $accept ? '已加入项目。' : '已拒绝邀请。');
        redirect($accept ? 'projects/' . $project : 'projects');
    } catch (HttpException $error) {
        flash('error', $error->getMessage());
        redirect('projects/' . $projectId);
    }
}

function projects_invitation_cancel_post(int $projectId, int $invitationId): void
{
    $user = require_write();
    $invitation = Project::findInvitation($invitationId);
    if ($invitation === null || (int) $invitation['project_id'] !== $projectId) {
        abort(404, '邀请不存在。');
    }
    try {
        Project::cancelInvitation($invitationId, (int) $user['id']);
        flash('success', '邀请已取消。');
    } catch (HttpException $error) {
        flash('error', $error->getMessage());
    }
    redirect('projects/' . $projectId);
}

function projects_leave_post(int $id): void
{
    $user = require_write();
    $project = Project::find($id);
    if ($project === null) {
        abort(404, '项目不存在。');
    }
    if (!Project::isMember($project, (int) $user['id'])) {
        abort(403, '你不是该项目成员。');
    }
    if (Project::isOwner($project, (int) $user['id'])) {
        abort(403, '项目所有者不能退出项目，请先删除项目。');
    }
    Project::removeMember($id, (int) $user['id']);
    flash('success', '已退出项目，你拥有的 Key 已从该项目移除。');
    redirect('projects');
}

function projects_member_remove_post(int $projectId, int $memberId): void
{
    $user = require_write();
    $project = Project::find($projectId);
    if ($project === null) {
        abort(404, '项目不存在。');
    }
    if ((int) $project['owner'] !== (int) $user['id']) {
        abort(403, '只有项目所有者可以移除成员。');
    }
    if ($memberId === (int) $user['id']) {
        abort(422, '不能移除自己。');
    }
    $member = User::find($memberId);
    if ($member === null) {
        abort(404, '成员不存在。');
    }
    Project::removeMember($projectId, $memberId);
    flash('success', '成员 ' . $member['username'] . ' 已被移除，其拥有的 Key 也已从项目移除。');
    redirect('projects/' . $projectId);
}

function projects_key_add_post(int $projectId): void
{
    $user = require_write();
    $keyId = positive_int($_POST['key_id'] ?? null);
    if ($keyId === null) {
        flash('error', '请选择一个 Key。');
        redirect('projects/' . $projectId);
    }
    try {
        $project = Project::find($projectId);
        if ($project === null) {
            abort(404, '项目不存在。');
        }
        if (!Project::isMember($project, (int) $user['id'])) {
            abort(403, '只有项目成员可以加入 Key。');
        }
        $key = Keys::find($keyId);
        if ($key === null) {
            abort(404, 'Key 不存在。');
        }
        if ((int) $key['owner'] !== (int) $user['id']) {
            abort(403, '只能把自己拥有的 Key 加入项目。');
        }
        Project::addKey($projectId, $keyId);
        flash('success', 'Key 已加入项目。');
    } catch (HttpException $error) {
        flash('error', $error->getMessage());
    }
    redirect('projects/' . $projectId);
}

function projects_key_remove_post(int $projectId, int $keyId): void
{
    $user = require_write();
    $project = Project::find($projectId);
    if ($project === null) {
        abort(404, '项目不存在。');
    }
    $key = Keys::find($keyId);
    if ($key === null) {
        abort(404, 'Key 不存在。');
    }
    $isProjectOwner = (int) $project['owner'] === (int) $user['id'];
    $isKeyOwner = (int) $key['owner'] === (int) $user['id'];
    if (!$isProjectOwner && !$isKeyOwner) {
        abort(403, '只有项目所有者或 Key 所有者可以移除 Key。');
    }
    try {
        Project::removeKey($projectId, $keyId);
        flash('success', 'Key 已从项目移除。');
    } catch (HttpException $error) {
        flash('error', $error->getMessage());
    }
    redirect('projects/' . $projectId);
}

function projects_visibility_request_post(int $projectId): void
{
    $user = require_write();
    try {
        $project = Project::requestPublic($projectId, (int) $user['id']);
        flash('success', (int) $project['visibility'] === 1
            ? '项目已公开。'
            : '公开化投票已发起，等待全体成员同意。');
    } catch (HttpException $error) {
        flash('error', $error->getMessage());
    }
    redirect('projects/' . $projectId);
}

function projects_visibility_vote_post(int $projectId): void
{
    $user = require_write();
    $approve = input_string('vote') === 'approve';
    try {
        $project = Project::voteVisibility($projectId, (int) $user['id'], $approve);
        flash('success', $approve
            ? ((int) $project['visibility'] === 1 ? '全体成员已同意，项目现在公开。' : '已投同意票，等待其他成员。')
            : '已投反对票，项目保持私密。');
    } catch (HttpException $error) {
        flash('error', $error->getMessage());
    }
    redirect('projects/' . $projectId);
}

function projects_visibility_cancel_post(int $projectId): void
{
    $user = require_write();
    try {
        Project::cancelVisibilityRequest($projectId, (int) $user['id']);
        flash('success', '公开化投票已取消。');
    } catch (HttpException $error) {
        flash('error', $error->getMessage());
    }
    redirect('projects/' . $projectId);
}

function projects_visibility_private_post(int $projectId): void
{
    $user = require_write();
    try {
        Project::setPrivate($projectId, (int) $user['id']);
        flash('success', '项目已设为私密。');
    } catch (HttpException $error) {
        flash('error', $error->getMessage());
    }
    redirect('projects/' . $projectId);
}
