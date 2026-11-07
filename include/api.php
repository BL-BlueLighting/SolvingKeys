<?php
declare(strict_types=1);

if (!defined('SOLVINGKEYS')) {
    http_response_code(403);
    exit;
}

function api_allow(string $method, string ...$allowed): void
{
    if (!in_array($method, $allowed, true)) {
        json_error(405, '请求方法不受支持。', 'method_not_allowed');
    }
}

function api_version(mixed $value): int
{
    if (is_int($value) && $value >= 0) {
        return $value;
    }
    if (is_string($value) && ctype_digit($value)) {
        return (int) $value;
    }
    abort(422, '版本号无效。', 'invalid_version');
}

function api_key_payload(array $key, int $currentUserId): array
{
    $owner = public_user([
        'id' => $key['owner'],
        'username' => $key['owner_username'],
        'avatar' => $key['owner_avatar'],
        'email' => $key['owner_email'],
    ]);

    $isOwner = (int) $key['owner'] === $currentUserId;
    $payload = [
        'id' => (int) $key['id'],
        'key_name' => $key['key_name'],
        'owner' => $owner,
        'content' => $key['content'],
        'version' => (int) $key['version'],
        'created_at' => iso_time($key['created_at']),
        'updated_at' => iso_time($key['updated_at']),
        'is_owner' => $isOwner,
    ];
    if ($isOwner) {
        $payload['messaging_projects'] = Keys::messagingProjects((int) $key['id']);
    }
    return $payload;
}

function api_project_payload(array $project, array $currentUser): array
{
    $userId = (int) $currentUser['id'];
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
        $members[] = $memberData;
    }

    $keys = [];
    foreach ($project['key_ids'] as $keyId) {
        $key = Keys::findWithOwner($keyId);
        if ($key === null) {
            continue;
        }
        $keys[] = [
            'id' => (int) $key['id'],
            'key_name' => $key['key_name'],
            'owner' => public_user([
                'id' => $key['owner'],
                'username' => $key['owner_username'],
                'avatar' => $key['owner_avatar'],
                'email' => $key['owner_email'],
            ]),
        ];
    }

    $owner = User::find((int) $project['owner']);
    $messagingKeyName = null;
    if (!empty($project['messaging_key_id'])) {
        $messagingKey = Keys::find((int) $project['messaging_key_id']);
        $messagingKeyName = $messagingKey['key_name'] ?? null;
    }

    $activeVote = null;
    $myBallot = null;
    if ($isMember) {
        $activeVote = Project::activeVisibilityRequest((int) $project['id']);
        if ($activeVote !== null) {
            foreach ($activeVote['ballots'] as $ballot) {
                if ((int) $ballot['user_id'] === $userId) {
                    $myBallot = $ballot['vote'];
                    break;
                }
            }
        }
    }

    return [
        'id' => (int) $project['id'],
        'project_name' => $project['project_name'],
        'owner' => $owner === null ? null : public_user($owner),
        'visibility' => (int) $project['visibility'],
        'members' => $members,
        'keys' => $keys,
        'is_member' => $isMember,
        'is_owner' => $isOwner,
        'can_write' => $isMember && user_can_write($currentUser),
        'messaging_key_id' => $project['messaging_key_id'],
        'messaging_key_name' => $messagingKeyName,
        'messaging_record' => $project['messaging_record'],
        'messaging_version' => (int) $project['messaging_version'],
        'active_visibility_vote' => $activeVote,
        'my_ballot' => $myBallot,
        'created_at' => iso_time($project['created_at']),
        'updated_at' => iso_time($project['updated_at']),
    ];
}

function dispatch_api(array $segments, string $method): void
{
    $first = $segments[0] ?? '';

    if ($first === 'register') {
        if (count($segments) !== 1) {
            json_error(404, '接口不存在。', 'not_found');
        }
        api_allow($method, 'POST');
        auth_register_api();
    }

    $user = current_user();
    if ($user === null) {
        json_error(401, '请先登录。', 'unauthenticated');
    }
    $userId = (int) $user['id'];

    if ($first === 'vault') {
        if (count($segments) === 1) {
            if ($method === 'GET') {
                json_ok([
                    'envelope' => (string) ($user['keys_key'] ?? ''),
                    'cloud_vault' => (int) ($user['cloud_vault'] ?? 0) === 1,
                    'has_vault' => (string) ($user['keys_key'] ?? '') !== '',
                ]);
            }
            if ($method === 'PUT') {
                if ((int) ($user['cloud_vault'] ?? 0) !== 1) {
                    json_error(409, '云端钥匙库未开启。', 'cloud_disabled');
                }
                $input = api_input();
                $envelope = api_string($input, 'envelope', '');
                if ($envelope === '') {
                    json_error(422, '钥匙库内容不能为空。', 'empty_vault');
                }
                User::setVault($userId, $envelope);
                json_ok(['saved' => true]);
            }
            api_allow($method, 'GET', 'PUT');
        }

        if (($segments[1] ?? '') === 'preference' && count($segments) === 2) {
            api_allow($method, 'POST');
            $input = api_input();
            $cloud = api_bool($input, 'cloud_vault', false);
            $envelope = api_string($input, 'envelope', '');
            User::setCloudPreference($userId, $cloud, $envelope);
            json_ok(['cloud_vault' => $cloud]);
        }

        json_error(404, '接口不存在。', 'not_found');
    }

    if ($first === 'keys') {
        if (count($segments) === 1) {
            api_allow($method, 'POST');
            $actor = require_write();
            $input = api_input();
            $key = Keys::create(
                (int) $actor['id'],
                api_string($input, 'key_name', ''),
                api_string($input, 'content', ''),
            );
            json_ok([
                'id' => (int) $key['id'],
                'key_name' => $key['key_name'],
                'version' => (int) $key['version'],
                'created_at' => iso_time($key['created_at']),
                'updated_at' => iso_time($key['updated_at']),
            ], 201);
        }

        if (($segments[1] ?? '') === 'lookup' && count($segments) === 2) {
            api_allow($method, 'GET');
            json_ok(Keys::lookup(query_array('ids'), query_array('names')));
        }

        $keyId = positive_int($segments[1] ?? null);
        if ($keyId === null || count($segments) !== 2) {
            json_error(404, '接口不存在。', 'not_found');
        }

        $key = Keys::findWithOwner($keyId);
        if ($key === null) {
            json_error(404, 'Key 不存在。', 'not_found');
        }

        if ($method === 'GET') {
            json_ok(api_key_payload($key, $userId));
        }

        if ($method === 'PUT') {
            $actor = require_write();
            if ((int) $key['owner'] !== (int) $actor['id']) {
                json_error(403, '只有所有者可以修改此 Key。', 'forbidden');
            }
            $input = api_input();
            $updated = Keys::updateContent(
                $keyId,
                (int) $actor['id'],
                api_string($input, 'content', ''),
                api_version($input['version'] ?? null),
            );
            json_ok([
                'version' => (int) $updated['version'],
                'updated_at' => iso_time($updated['updated_at']),
            ]);
        }

        if ($method === 'PATCH') {
            $actor = require_write();
            if ((int) $key['owner'] !== (int) $actor['id']) {
                json_error(403, '只有所有者可以修改此 Key。', 'forbidden');
            }
            $input = api_input();
            $updated = Keys::rename($keyId, (int) $actor['id'], api_string($input, 'key_name', ''));
            json_ok([
                'id' => (int) $updated['id'],
                'key_name' => $updated['key_name'],
                'updated_at' => iso_time($updated['updated_at']),
            ]);
        }

        if ($method === 'DELETE') {
            $actor = require_write();
            if ((int) $key['owner'] !== (int) $actor['id']) {
                json_error(403, '只有所有者可以删除此 Key。', 'forbidden');
            }
            $messaging = Keys::messagingProjects($keyId);
            if ($messaging !== []) {
                json_error(409, '该 Key 正被项目用作消息密钥，无法删除。', 'key_in_use', [
                    'projects' => $messaging,
                ]);
            }
            Keys::delete($keyId);
            json_ok(['deleted' => true]);
        }

        api_allow($method, 'GET', 'PUT', 'PATCH', 'DELETE');
    }

    if ($first === 'projects') {
        $projectId = positive_int($segments[1] ?? null);
        if ($projectId === null) {
            json_error(404, '接口不存在。', 'not_found');
        }
        $project = Project::find($projectId);
        if ($project === null) {
            json_error(404, '项目不存在。', 'not_found');
        }
        if (!Project::canView($project, $userId)) {
            json_error(403, '你没有访问此项目的权限。', 'forbidden');
        }

        if (count($segments) === 2) {
            api_allow($method, 'GET');
            json_ok(api_project_payload($project, $user));
        }

        if (($segments[2] ?? '') === 'messages' && count($segments) === 3) {
            api_allow($method, 'PUT');
            $actor = require_write();
            if (!Project::isMember($project, (int) $actor['id'])) {
                json_error(403, '只有项目成员可以发送消息。', 'forbidden');
            }
            if (empty($project['messaging_key_id'])) {
                json_error(409, '项目尚未设置消息密钥。', 'no_messaging_key');
            }
            $input = api_input();
            $result = Project::updateMessages(
                $projectId,
                api_string($input, 'record', ''),
                api_version($input['version'] ?? null),
            );
            if ($result['conflict']) {
                $latest = $result['project'];
                json_error(409, '消息已在其他位置更新，请合并后重试。', 'version_conflict', [
                    'messaging_record' => $latest['messaging_record'] ?? '',
                    'messaging_version' => (int) ($latest['messaging_version'] ?? 0),
                    'messaging_key_id' => $latest['messaging_key_id'] ?? null,
                ]);
            }
            $latest = $result['project'];
            json_ok([
                'messaging_version' => (int) $latest['messaging_version'],
                'updated_at' => iso_time($latest['updated_at']),
            ]);
        }

        if (($segments[2] ?? '') === 'messaging-key' && count($segments) === 3) {
            api_allow($method, 'POST');
            $actor = require_write();
            $input = api_input();
            $keyId = positive_int($input['key_id'] ?? null);
            if ($keyId === null) {
                json_error(422, '消息密钥无效。', 'invalid_messaging_key');
            }
            $result = Project::setMessagingKey(
                $projectId,
                (int) $actor['id'],
                $keyId,
                api_string($input, 'record', ''),
                api_version($input['version'] ?? null),
            );
            if ($result['conflict']) {
                $latest = $result['project'];
                json_error(409, '消息已在其他位置更新，请刷新后重试。', 'version_conflict', [
                    'messaging_record' => $latest['messaging_record'] ?? '',
                    'messaging_version' => (int) ($latest['messaging_version'] ?? 0),
                    'messaging_key_id' => $latest['messaging_key_id'] ?? null,
                ]);
            }
            $latest = $result['project'];
            json_ok([
                'messaging_key_id' => (int) $latest['messaging_key_id'],
                'messaging_version' => (int) $latest['messaging_version'],
                'updated_at' => iso_time($latest['updated_at']),
            ]);
        }

        json_error(404, '接口不存在。', 'not_found');
    }

    if ($first === 'users' && ($segments[1] ?? '') === 'lookup' && count($segments) === 2) {
        api_allow($method, 'GET');
        json_ok(User::lookup(query_array('ids'), query_array('names')));
    }

    if ($first === 'notifications') {
        if (($segments[1] ?? '') === 'count' && count($segments) === 2) {
            api_allow($method, 'GET');
            json_ok(['unread' => Notification::unreadCount($userId)]);
        }

        if (($segments[1] ?? '') === 'mentions' && count($segments) === 2) {
            api_allow($method, 'POST');
            $actor = require_write();
            $input = api_input();
            $subjectType = api_string($input, 'subject_type', '');
            $subjectId = positive_int($input['subject_id'] ?? null);
            $mentions = validate_mentions($input['mentions'] ?? []);
            if ($subjectId === null || !in_array($subjectType, ['key', 'project'], true)) {
                json_error(422, '提醒目标无效。', 'invalid_subject');
            }
            if ($mentions === []) {
                json_ok(['notified' => 0]);
            }

            $ids = [];
            $names = [];
            foreach ($mentions as $mention) {
                if (ctype_digit((string) $mention)) {
                    $ids[] = (int) $mention;
                } else {
                    $names[] = (string) $mention;
                }
            }
            $resolved = User::lookup($ids, $names);

            $memberIds = [];
            $isPublicProject = true;
            if ($subjectType === 'key') {
                $subject = Keys::find($subjectId);
                if ($subject === null) {
                    json_error(404, 'Key 不存在。', 'not_found');
                }
                if ((int) $subject['owner'] !== (int) $actor['id']) {
                    json_error(403, '只有所有者可以触发此 Key 的提醒。', 'forbidden');
                }
                $subjectName = $subject['key_name'];
                $message = '在 Key《' . $subjectName . '》中提到了你。';
                $link = '/keys/' . $subjectId;
            } else {
                $subject = Project::find($subjectId);
                if ($subject === null) {
                    json_error(404, '项目不存在。', 'not_found');
                }
                if (!Project::isMember($subject, (int) $actor['id'])) {
                    json_error(403, '只有项目成员可以触发项目提醒。', 'forbidden');
                }
                $isPublicProject = (int) $subject['visibility'] === 1;
                $memberIds = Project::decodeMembers($subject);
                $message = '在项目《' . $subject['project_name'] . '》的聊天中提到了你。';
                $link = '/projects/' . $subjectId;
            }

            $notified = 0;
            $seen = [];
            foreach ($resolved as $target) {
                $targetId = (int) $target['id'];
                if ($targetId === (int) $actor['id'] || isset($seen[$targetId])) {
                    continue;
                }
                if ($subjectType === 'project' && !$isPublicProject && !in_array($targetId, $memberIds, true)) {
                    continue;
                }
                $seen[$targetId] = true;
                Notification::create(
                    $targetId,
                    (int) $actor['id'],
                    'mention',
                    $subjectType,
                    $subjectId,
                    $message,
                    $link
                );
                $notified++;
            }
            json_ok(['notified' => $notified]);
        }

        json_error(404, '接口不存在。', 'not_found');
    }

    json_error(404, '接口不存在。', 'not_found');
}
