<?php
declare(strict_types=1);

if (!defined('SOLVINGKEYS')) {
    http_response_code(403);
    exit;
}

final class Project
{
    private static function normalize(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        foreach (['id', 'owner', 'visibility', 'messaging_key_id', 'messaging_version'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = $row[$field] === null ? null : (int) $row[$field];
            }
        }
        return $row;
    }

    public static function find(int $id): ?array
    {
        $statement = Database::pdo()->prepare('SELECT * FROM projects WHERE id = ? LIMIT 1');
        $statement->execute([$id]);
        return self::normalize($statement->fetch() ?: null);
    }

    private static function readForUpdate(PDO $pdo, int $id): ?array
    {
        $sql = 'SELECT * FROM projects WHERE id = ? LIMIT 1';
        if (!Database::isSqlite()) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $pdo->prepare($sql);
        $statement->execute([$id]);
        return self::normalize($statement->fetch() ?: null);
    }

    public static function decodeMembers(array $project): array
    {
        $decoded = json_decode((string) ($project['members'] ?? '[]'), true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_unique(array_map('intval', $decoded)));
    }

    public static function decodeKeys(array $project): array
    {
        $decoded = json_decode((string) ($project['project_keys'] ?? '[]'), true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_unique(array_map('intval', $decoded)));
    }

    public static function hydrate(?array $project): ?array
    {
        if ($project === null) {
            return null;
        }
        $project['member_ids'] = self::decodeMembers($project);
        $project['key_ids'] = self::decodeKeys($project);
        return $project;
    }

    public static function isMember(array $project, int $userId): bool
    {
        return in_array($userId, self::decodeMembers($project), true);
    }

    public static function isOwner(array $project, int $userId): bool
    {
        return (int) $project['owner'] === $userId;
    }

    public static function canView(array $project, int $userId): bool
    {
        return self::isMember($project, $userId) || (int) $project['visibility'] === 1;
    }

    public static function create(int $ownerId, string $name): array
    {
        $name = validate_project_name($name);
        $now = now_utc();
        $statement = Database::pdo()->prepare(
            'INSERT INTO projects (project_name, project_keys, owner, visibility, messaging_record, members, messaging_key_id, messaging_version, created_at, updated_at)
             VALUES (?, ?, ?, 0, ?, ?, NULL, 0, ?, ?)'
        );
        $statement->execute([
            $name,
            '[]',
            $ownerId,
            '',
            json_encode([$ownerId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $now,
            $now,
        ]);
        $id = (int) Database::pdo()->lastInsertId();
        $created = self::find($id);
        if ($created === null) {
            throw new RuntimeException('创建项目后无法读取该项目。');
        }
        return $created;
    }

    public static function updateName(int $id, int $ownerId, string $name): array
    {
        $name = validate_project_name($name);
        $statement = Database::pdo()->prepare('UPDATE projects SET project_name = ?, updated_at = ? WHERE id = ? AND owner = ?');
        $statement->execute([$name, now_utc(), $id, $ownerId]);
        if ($statement->rowCount() < 1) {
            $project = self::find($id);
            if ($project === null) {
                abort(404, '项目不存在。');
            }
            abort(403, '只有项目所有者可以修改项目。', 'forbidden');
        }
        $updated = self::find($id);
        if ($updated === null) {
            throw new RuntimeException('更新项目后无法读取该项目。');
        }
        return $updated;
    }

    public static function delete(int $id): void
    {
        Database::transaction(function () use ($id): void {
            $project = self::find($id);
            if ($project === null) {
                return;
            }
            Notification::deleteForSubject('project', $id);
            Database::pdo()->prepare('DELETE FROM project_visibility_ballots WHERE request_id IN (SELECT id FROM project_visibility_requests WHERE project_id = ?)')->execute([$id]);
            Database::pdo()->prepare('DELETE FROM project_visibility_requests WHERE project_id = ?')->execute([$id]);
            Database::pdo()->prepare('DELETE FROM project_invitations WHERE project_id = ?')->execute([$id]);
            Database::pdo()->prepare('DELETE FROM projects WHERE id = ?')->execute([$id]);
        });
    }

    public static function transferOwnership(int $id, int $newOwnerId): void
    {
        Database::pdo()->prepare('UPDATE projects SET owner = ?, updated_at = ? WHERE id = ?')
            ->execute([$newOwnerId, now_utc(), $id]);
    }

    public static function updateMembers(int $id, array $members): void
    {
        $members = array_values(array_unique(array_map('intval', $members)));
        Database::pdo()->prepare('UPDATE projects SET members = ?, updated_at = ? WHERE id = ?')
            ->execute([json_encode($members, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), now_utc(), $id]);
    }

    /** @return array<int,array> */
    public static function forUser(int $userId): array
    {
        $params = [];
        $contains = json_int_array_contains_sql('members', $userId, $params);
        $statement = Database::pdo()->prepare(
            'SELECT * FROM projects WHERE owner = ? OR ' . $contains . ' ORDER BY updated_at DESC, id DESC'
        );
        $statement->execute(array_merge([$userId], $params));
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row = self::hydrate(self::normalize($row));
        }
        return $rows;
    }

    /** @return array<int,array> */
    public static function publicList(int $limit = 20, int $offset = 0): array
    {
        $statement = Database::pdo()->prepare(
            'SELECT * FROM projects WHERE visibility = 1 ORDER BY updated_at DESC, id DESC LIMIT ? OFFSET ?'
        );
        $statement->bindValue(1, max(1, $limit), PDO::PARAM_INT);
        $statement->bindValue(2, max(0, $offset), PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row = self::hydrate(self::normalize($row));
        }
        return $rows;
    }

    public static function countPublic(): int
    {
        return (int) Database::pdo()->query('SELECT COUNT(*) FROM projects WHERE visibility = 1')->fetchColumn();
    }

    public static function countForUser(int $userId): int
    {
        $params = [];
        $contains = json_int_array_contains_sql('members', $userId, $params);
        $statement = Database::pdo()->prepare('SELECT COUNT(*) FROM projects WHERE owner = ? OR ' . $contains);
        $statement->execute(array_merge([$userId], $params));
        return (int) $statement->fetchColumn();
    }

    public static function recentForUser(int $userId, int $limit = 5): array
    {
        $params = [];
        $contains = json_int_array_contains_sql('members', $userId, $params);
        $statement = Database::pdo()->prepare(
            'SELECT * FROM projects WHERE owner = ? OR ' . $contains . ' ORDER BY updated_at DESC, id DESC LIMIT ?'
        );
        $index = 1;
        $statement->bindValue($index++, $userId, PDO::PARAM_INT);
        foreach ($params as $param) {
            $statement->bindValue($index++, $param, PDO::PARAM_STR);
        }
        $statement->bindValue($index, max(1, $limit), PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row = self::hydrate(self::normalize($row));
        }
        return $rows;
    }

    public static function addMember(int $id, int $userId): void
    {
        Database::transaction(function (PDO $pdo) use ($id, $userId): void {
            $project = self::readForUpdate($pdo, $id);
            if ($project === null) {
                abort(404, '项目不存在。');
            }
            $members = self::decodeMembers($project);
            if (in_array($userId, $members, true)) {
                return;
            }
            $members[] = $userId;
            $pdo->prepare('UPDATE projects SET members = ?, updated_at = ? WHERE id = ?')
                ->execute([json_encode(array_values($members), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), now_utc(), $id]);
            self::cancelPendingVisibility($id, false);
        });
    }

    public static function removeMember(int $id, int $userId, bool $force = false): void
    {
        Database::transaction(function (PDO $pdo) use ($id, $userId, $force): void {
            $project = self::readForUpdate($pdo, $id);
            if ($project === null) {
                abort(404, '项目不存在。');
            }
            if ((int) $project['owner'] === $userId && !$force) {
                abort(403, '项目所有者不能退出项目，请先删除项目。', 'owner_cannot_leave');
            }
            $members = self::decodeMembers($project);
            if (!in_array($userId, $members, true)) {
                return;
            }
            $members = array_values(array_filter($members, static fn (int $member): bool => $member !== $userId));

            $keyIds = self::decodeKeys($project);
            $owned = $pdo->prepare('SELECT id FROM `keys` WHERE owner = ?');
            $owned->execute([$userId]);
            foreach ($owned->fetchAll(PDO::FETCH_COLUMN) as $keyId) {
                $keyId = (int) $keyId;
                if (in_array($keyId, $keyIds, true)) {
                    if ((int) ($project['messaging_key_id'] ?? 0) === $keyId) {
                        self::disableMessaging($id);
                        $project = self::find($id) ?? $project;
                        $keyIds = self::decodeKeys($project);
                    }
                    $keyIds = array_values(array_filter($keyIds, static fn (int $existing): bool => $existing !== $keyId));
                }
            }

            $pdo->prepare('UPDATE projects SET members = ?, project_keys = ?, updated_at = ? WHERE id = ?')
                ->execute([
                    json_encode($members, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode($keyIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    now_utc(),
                    $id,
                ]);
            self::cancelPendingVisibility($id, false);
        });
    }

    public static function addKey(int $id, int $keyId): void
    {
        Database::transaction(function (PDO $pdo) use ($id, $keyId): void {
            $project = self::readForUpdate($pdo, $id);
            if ($project === null) {
                abort(404, '项目不存在。');
            }
            $keyIds = self::decodeKeys($project);
            if (in_array($keyId, $keyIds, true)) {
                return;
            }
            $keyIds[] = $keyId;
            $pdo->prepare('UPDATE projects SET project_keys = ?, updated_at = ? WHERE id = ?')
                ->execute([json_encode(array_values($keyIds), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), now_utc(), $id]);
        });
    }

    public static function removeKey(int $id, int $keyId, bool $force = false): void
    {
        Database::transaction(function (PDO $pdo) use ($id, $keyId, $force): void {
            $project = self::readForUpdate($pdo, $id);
            if ($project === null) {
                return;
            }
            $keyIds = self::decodeKeys($project);
            if (!in_array($keyId, $keyIds, true)) {
                return;
            }
            if ((int) ($project['messaging_key_id'] ?? 0) === $keyId && !$force) {
                abort(409, '该 Key 正用作项目消息密钥，请先在项目设置中更换消息密钥。', 'messaging_key');
            }
            if ((int) ($project['messaging_key_id'] ?? 0) === $keyId) {
                self::disableMessaging($id);
            }
            $keyIds = array_values(array_filter($keyIds, static fn (int $existing): bool => $existing !== $keyId));
            $pdo->prepare('UPDATE projects SET project_keys = ?, updated_at = ? WHERE id = ?')
                ->execute([json_encode($keyIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), now_utc(), $id]);
        });
    }

    /**
     * Store a new messaging key and (re)encrypted message payload.
     * Returns ['conflict' => bool, 'project' => array].
     */
    public static function setMessagingKey(int $id, int $ownerId, int $keyId, string $record, int $expectedVersion): array
    {
        $record = validate_ciphertext($record, (int) config('limits.messaging_bytes', 4194304), '消息记录');
        if ($record === '') {
            abort(422, '消息记录必须包含加密后的内容。', 'empty_record');
        }
        return Database::transaction(function (PDO $pdo) use ($id, $ownerId, $keyId, $record, $expectedVersion): array {
            $project = self::readForUpdate($pdo, $id);
            if ($project === null) {
                abort(404, '项目不存在。');
            }
            if ((int) $project['owner'] !== $ownerId) {
                abort(403, '只有项目所有者可以设置消息密钥。', 'forbidden');
            }
            if (!in_array($keyId, self::decodeKeys($project), true)) {
                abort(422, '消息密钥必须来自项目内的 Key。', 'invalid_messaging_key');
            }

            $statement = $pdo->prepare(
                'UPDATE projects SET messaging_key_id = ?, messaging_record = ?, messaging_version = messaging_version + 1, updated_at = ?
                 WHERE id = ? AND messaging_version = ?'
            );
            $statement->execute([$keyId, $record, now_utc(), $id, $expectedVersion]);
            if ($statement->rowCount() < 1) {
                $current = self::find($id);
                return ['conflict' => true, 'project' => $current];
            }
            $updated = self::find($id);
            if ($updated === null) {
                throw new RuntimeException('设置消息密钥后无法读取项目。');
            }
            return ['conflict' => false, 'project' => $updated];
        });
    }

    /**
     * Optimistically append/replace the encrypted message array.
     * Returns ['conflict' => bool, 'project' => array].
     */
    public static function updateMessages(int $id, string $record, int $expectedVersion): array
    {
        $record = validate_ciphertext($record, (int) config('limits.messaging_bytes', 4194304), '消息记录');
        if ($record === '') {
            abort(422, '消息记录不能为空。', 'empty_record');
        }
        $statement = Database::pdo()->prepare(
            'UPDATE projects SET messaging_record = ?, messaging_version = messaging_version + 1, updated_at = ?
             WHERE id = ? AND messaging_version = ?'
        );
        $statement->execute([$record, now_utc(), $id, $expectedVersion]);
        if ($statement->rowCount() < 1) {
            $current = self::find($id);
            if ($current === null) {
                abort(404, '项目不存在。');
            }
            return ['conflict' => true, 'project' => $current];
        }
        $updated = self::find($id);
        if ($updated === null) {
            throw new RuntimeException('更新消息后无法读取项目。');
        }
        return ['conflict' => false, 'project' => $updated];
    }

    public static function disableMessaging(int $id): void
    {
        Database::pdo()->prepare(
            "UPDATE projects SET messaging_key_id = NULL, messaging_record = '', messaging_version = messaging_version + 1, updated_at = ? WHERE id = ?"
        )->execute([now_utc(), $id]);
    }

    public static function invite(int $projectId, int $inviterId, int $inviteeId): array
    {
        return Database::transaction(function (PDO $pdo) use ($projectId, $inviterId, $inviteeId): array {
            $project = self::readForUpdate($pdo, $projectId);
            if ($project === null) {
                abort(404, '项目不存在。');
            }
            if ((int) $project['owner'] !== $inviterId) {
                abort(403, '只有项目所有者可以邀请成员。', 'forbidden');
            }
            if (in_array($inviteeId, self::decodeMembers($project), true)) {
                abort(409, '该用户已经是项目成员。', 'already_member');
            }
            $invitee = User::find($inviteeId);
            if ($invitee === null) {
                abort(404, '用户不存在。');
            }
            $inviter = User::find($inviterId);
            if ($inviter === null) {
                abort(404, '邀请者不存在。');
            }

            $existing = $pdo->prepare('SELECT * FROM project_invitations WHERE project_id = ? AND invitee_id = ? LIMIT 1');
            $existing->execute([$projectId, $inviteeId]);
            $invitation = $existing->fetch();
            $now = now_utc();
            if ($invitation !== false) {
                if (($invitation['status'] ?? '') === 'pending') {
                    abort(409, '该用户已有待处理的邀请。', 'invite_pending');
                }
                $pdo->prepare("UPDATE project_invitations SET status = 'pending', inviter_id = ?, updated_at = ? WHERE id = ?")
                    ->execute([$inviterId, $now, (int) $invitation['id']]);
                $invitationId = (int) $invitation['id'];
            } else {
                $pdo->prepare("INSERT INTO project_invitations (project_id, inviter_id, invitee_id, status, created_at, updated_at) VALUES (?, ?, ?, 'pending', ?, ?)")
                    ->execute([$projectId, $inviterId, $inviteeId, $now, $now]);
                $invitationId = (int) $pdo->lastInsertId();
            }

            Notification::create(
                $inviteeId,
                $inviterId,
                'invitation',
                'project',
                $projectId,
                $inviter['username'] . ' 邀请你加入项目《' . $project['project_name'] . '》。',
                '/projects/' . $projectId
            );

            return [
                'id' => $invitationId,
                'project_id' => $projectId,
                'invitee_id' => $inviteeId,
                'status' => 'pending',
            ];
        });
    }

    public static function findInvitation(int $id): ?array
    {
        $statement = Database::pdo()->prepare('SELECT * FROM project_invitations WHERE id = ? LIMIT 1');
        $statement->execute([$id]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }
        foreach (['id', 'project_id', 'inviter_id', 'invitee_id'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        return $row;
    }

    public static function hasPendingInvitation(int $projectId, int $userId): bool
    {
        return self::findPendingInvitation($projectId, $userId) !== null;
    }

    public static function findPendingInvitation(int $projectId, int $userId): ?array
    {
        $statement = Database::pdo()->prepare(
            "SELECT * FROM project_invitations WHERE project_id = ? AND invitee_id = ? AND status = 'pending' LIMIT 1"
        );
        $statement->execute([$projectId, $userId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }
        foreach (['id', 'project_id', 'inviter_id', 'invitee_id'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        return $row;
    }

    /** @return array<int,array> */
    public static function pendingInvitationsForUser(int $userId): array
    {
        $statement = Database::pdo()->prepare(
            "SELECT i.id, i.project_id, i.inviter_id, i.created_at, p.project_name, p.owner, p.visibility,
                    u.username AS inviter_username, u.avatar AS inviter_avatar, u.email AS inviter_email
             FROM project_invitations i
             JOIN projects p ON p.id = i.project_id
             JOIN users u ON u.id = i.inviter_id
             WHERE i.invitee_id = ? AND i.status = 'pending'
             ORDER BY i.created_at DESC"
        );
        $statement->execute([$userId]);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['project_id'] = (int) $row['project_id'];
            $row['owner'] = (int) $row['owner'];
            $row['visibility'] = (int) $row['visibility'];
            $row['inviter'] = public_user([
                'id' => $row['inviter_id'],
                'username' => $row['inviter_username'],
                'avatar' => $row['inviter_avatar'],
                'email' => $row['inviter_email'],
            ]);
        }
        return $rows;
    }

    /** @return array<int,array> */
    public static function pendingInvitationsForProject(int $projectId): array
    {
        $statement = Database::pdo()->prepare(
            "SELECT i.id, i.invitee_id, i.created_at, u.username, u.avatar, u.email
             FROM project_invitations i JOIN users u ON u.id = i.invitee_id
             WHERE i.project_id = ? AND i.status = 'pending'
             ORDER BY i.created_at ASC"
        );
        $statement->execute([$projectId]);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['invitee'] = public_user([
                'id' => $row['invitee_id'],
                'username' => $row['username'],
                'avatar' => $row['avatar'],
                'email' => $row['email'],
            ]);
        }
        return $rows;
    }

    public static function respondInvitation(int $invitationId, int $userId, bool $accept): int
    {
        return Database::transaction(function (PDO $pdo) use ($invitationId, $userId, $accept): int {
            $invitation = self::findInvitation($invitationId);
            if ($invitation === null) {
                abort(404, '邀请不存在。');
            }
            if ((int) $invitation['invitee_id'] !== $userId) {
                abort(403, '这不是发给你的邀请。', 'forbidden');
            }
            if (($invitation['status'] ?? '') !== 'pending') {
                abort(409, '该邀请已被处理。', 'invitation_handled');
            }
            $project = self::find((int) $invitation['project_id']);
            if ($project === null) {
                abort(404, '项目不存在。');
            }

            $now = now_utc();
            $pdo->prepare('UPDATE project_invitations SET status = ?, updated_at = ? WHERE id = ?')
                ->execute([$accept ? 'accepted' : 'declined', $now, $invitationId]);

            if ($accept) {
                $members = self::decodeMembers($project);
                if (!in_array($userId, $members, true)) {
                    $members[] = $userId;
                    $pdo->prepare('UPDATE projects SET members = ?, updated_at = ? WHERE id = ?')
                        ->execute([json_encode(array_values($members), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now, (int) $project['id']]);
                    self::cancelPendingVisibility((int) $project['id'], false);
                }
            }

            $actor = User::find($userId);
            if ($actor !== null) {
                Notification::create(
                    (int) $invitation['inviter_id'],
                    $userId,
                    'invitation_result',
                    'project',
                    (int) $project['id'],
                    $actor['username'] . ($accept ? ' 接受了加入项目《' : ' 拒绝了加入项目《') . $project['project_name'] . '》的邀请。',
                    '/projects/' . (int) $project['id']
                );
            }

            return (int) $project['id'];
        });
    }

    public static function cancelInvitation(int $invitationId, int $ownerId): void
    {
        Database::transaction(function () use ($invitationId, $ownerId): void {
            $invitation = self::findInvitation($invitationId);
            if ($invitation === null) {
                abort(404, '邀请不存在。');
            }
            $project = self::find((int) $invitation['project_id']);
            if ($project === null) {
                abort(404, '项目不存在。');
            }
            if ((int) $project['owner'] !== $ownerId) {
                abort(403, '只有项目所有者可以取消邀请。', 'forbidden');
            }
            if (($invitation['status'] ?? '') === 'pending') {
                Database::pdo()->prepare("UPDATE project_invitations SET status = 'cancelled', updated_at = ? WHERE id = ?")
                    ->execute([now_utc(), $invitationId]);
            }
        });
    }

    public static function activeVisibilityRequest(int $projectId): ?array
    {
        $statement = Database::pdo()->prepare(
            "SELECT * FROM project_visibility_requests WHERE project_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1"
        );
        $statement->execute([$projectId]);
        $request = $statement->fetch();
        if ($request === false) {
            return null;
        }
        $request['id'] = (int) $request['id'];
        $request['project_id'] = (int) $request['project_id'];
        $request['requested_by'] = (int) $request['requested_by'];
        $ballots = Database::pdo()->prepare(
            'SELECT b.*, u.username, u.avatar, u.email FROM project_visibility_ballots b
             JOIN users u ON u.id = b.user_id WHERE b.request_id = ? ORDER BY b.id ASC'
        );
        $ballots->execute([$request['id']]);
        $request['ballots'] = [];
        foreach ($ballots->fetchAll() as $ballot) {
            $ballot['user_id'] = (int) $ballot['user_id'];
            $ballot['user'] = public_user([
                'id' => $ballot['user_id'],
                'username' => $ballot['username'],
                'avatar' => $ballot['avatar'],
                'email' => $ballot['email'],
            ]);
            $request['ballots'][] = $ballot;
        }
        return $request;
    }

    public static function requestPublic(int $projectId, int $userId): array
    {
        return Database::transaction(function (PDO $pdo) use ($projectId, $userId): array {
            $project = self::readForUpdate($pdo, $projectId);
            if ($project === null) {
                abort(404, '项目不存在。');
            }
            if ((int) $project['owner'] !== $userId) {
                abort(403, '只有项目所有者可以发起公开化请求。', 'forbidden');
            }
            if ((int) $project['visibility'] === 1) {
                abort(409, '项目已经是公开的。', 'already_public');
            }
            if (self::activeVisibilityRequest($projectId) !== null) {
                abort(409, '已有进行中的公开化投票。', 'vote_pending');
            }

            $members = self::decodeMembers($project);
            $now = now_utc();
            $pdo->prepare("INSERT INTO project_visibility_requests (project_id, requested_by, status, created_at, updated_at) VALUES (?, ?, 'pending', ?, ?)")
                ->execute([$projectId, $userId, $now, $now]);
            $requestId = (int) $pdo->lastInsertId();

            $insert = $pdo->prepare('INSERT INTO project_visibility_ballots (request_id, user_id, vote, created_at, updated_at) VALUES (?, ?, ?, ?, ?)');
            foreach ($members as $memberId) {
                $insert->execute([$requestId, $memberId, $memberId === $userId ? 'approve' : 'pending', $now, $now]);
            }

            $owner = User::find($userId);
            $message = ($owner['username'] ?? '项目所有者') . ' 发起将项目《' . $project['project_name'] . '》公开的投票，需要全体成员同意。';
            foreach ($members as $memberId) {
                if ($memberId !== $userId) {
                    Notification::create($memberId, $userId, 'visibility', 'project', $projectId, $message, '/projects/' . $projectId);
                }
            }

            self::maybeApproveVisibility($projectId, $requestId);
            return self::find($projectId) ?? $project;
        });
    }

    public static function voteVisibility(int $projectId, int $userId, bool $approve): array
    {
        return Database::transaction(function (PDO $pdo) use ($projectId, $userId, $approve): array {
            $project = self::readForUpdate($pdo, $projectId);
            if ($project === null) {
                abort(404, '项目不存在。');
            }
            if (!self::isMember($project, $userId)) {
                abort(403, '只有项目成员可以投票。', 'forbidden');
            }
            $request = self::activeVisibilityRequest($projectId);
            if ($request === null) {
                abort(404, '没有进行中的公开化投票。', 'no_vote');
            }

            $now = now_utc();
            $pdo->prepare('UPDATE project_visibility_ballots SET vote = ?, updated_at = ? WHERE request_id = ? AND user_id = ?')
                ->execute([$approve ? 'approve' : 'reject', $now, (int) $request['id'], $userId]);

            if (!$approve) {
                $pdo->prepare("UPDATE project_visibility_requests SET status = 'rejected', updated_at = ? WHERE id = ?")
                    ->execute([$now, (int) $request['id']]);
                $actor = User::find($userId);
                foreach (self::decodeMembers($project) as $memberId) {
                    if ($memberId !== $userId) {
                        Notification::create(
                            $memberId,
                            $userId,
                            'visibility_result',
                            'project',
                            $projectId,
                            '项目《' . $project['project_name'] . '》的公开化投票被 ' . ($actor['username'] ?? '成员') . ' 拒绝，项目保持私密。',
                            '/projects/' . $projectId
                        );
                    }
                }
                return self::find($projectId) ?? $project;
            }

            self::maybeApproveVisibility($projectId, (int) $request['id']);
            return self::find($projectId) ?? $project;
        });
    }

    private static function maybeApproveVisibility(int $projectId, int $requestId): void
    {
        $pdo = Database::pdo();
        $members = $pdo->prepare('SELECT * FROM projects WHERE id = ? LIMIT 1');
        $members->execute([$projectId]);
        $project = self::normalize($members->fetch() ?: null);
        if ($project === null) {
            return;
        }
        $memberIds = self::decodeMembers($project);
        if ($memberIds === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM project_visibility_ballots WHERE request_id = ? AND vote = \'approve\' AND user_id IN (' . $placeholders . ')'
        );
        $statement->execute(array_merge([$requestId], $memberIds));
        $approved = (int) $statement->fetchColumn();
        if ($approved < count($memberIds)) {
            return;
        }

        $now = now_utc();
        $pdo->prepare('UPDATE projects SET visibility = 1, updated_at = ? WHERE id = ?')->execute([$now, $projectId]);
        $pdo->prepare("UPDATE project_visibility_requests SET status = 'approved', updated_at = ? WHERE id = ?")
            ->execute([$now, $requestId]);

        foreach ($memberIds as $memberId) {
            Notification::create(
                $memberId,
                null,
                'visibility_result',
                'project',
                $projectId,
                '项目《' . $project['project_name'] . '》已通过全体成员同意，现在对外公开。',
                '/projects/' . $projectId
            );
        }
    }

    public static function cancelVisibilityRequest(int $projectId, int $userId): void
    {
        $project = self::find($projectId);
        if ($project === null) {
            abort(404, '项目不存在。');
        }
        if ((int) $project['owner'] !== $userId) {
            abort(403, '只有项目所有者可以取消投票。', 'forbidden');
        }
        self::cancelPendingVisibility($projectId);
    }

    public static function cancelPendingVisibility(int $projectId, bool $notify = true): void
    {
        $request = self::activeVisibilityRequest($projectId);
        if ($request === null) {
            return;
        }
        Database::pdo()->prepare("UPDATE project_visibility_requests SET status = 'cancelled', updated_at = ? WHERE id = ?")
            ->execute([now_utc(), (int) $request['id']]);
    }

    public static function setPrivate(int $projectId, int $userId): array
    {
        return Database::transaction(function () use ($projectId, $userId): array {
            $project = self::find($projectId);
            if ($project === null) {
                abort(404, '项目不存在。');
            }
            if ((int) $project['owner'] !== $userId) {
                abort(403, '只有项目所有者可以将项目设为私密。', 'forbidden');
            }
            self::cancelPendingVisibility($projectId);
            Database::pdo()->prepare('UPDATE projects SET visibility = 0, updated_at = ? WHERE id = ?')
                ->execute([now_utc(), $projectId]);
            return self::find($projectId) ?? $project;
        });
    }

    public static function deleteUserInvitations(int $userId): void
    {
        Database::pdo()->prepare('DELETE FROM project_invitations WHERE inviter_id = ? OR invitee_id = ?')
            ->execute([$userId, $userId]);
    }

    public static function deleteUserBallots(int $userId): void
    {
        Database::pdo()->prepare('DELETE FROM project_visibility_ballots WHERE user_id = ?')->execute([$userId]);
    }

    public static function cancelUserVisibilityRequests(int $userId): void
    {
        Database::pdo()->prepare(
            "UPDATE project_visibility_requests SET status = 'cancelled', updated_at = ? WHERE requested_by = ? AND status = 'pending'"
        )->execute([now_utc(), $userId]);
    }
}
