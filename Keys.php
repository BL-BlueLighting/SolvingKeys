<?php
declare(strict_types=1);

if (!defined('SOLVINGKEYS')) {
    http_response_code(403);
    exit;
}

final class Keys
{
    private static function normalize(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        foreach (['id', 'owner', 'version'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = (int) $row[$field];
            }
        }
        return $row;
    }

    public static function find(int $id): ?array
    {
        $statement = Database::pdo()->prepare('SELECT * FROM `keys` WHERE id = ? LIMIT 1');
        $statement->execute([$id]);
        return self::normalize($statement->fetch() ?: null);
    }

    public static function findWithOwner(int $id): ?array
    {
        $statement = Database::pdo()->prepare(
            'SELECT k.*, u.username AS owner_username, u.avatar AS owner_avatar, u.email AS owner_email, u.permission AS owner_permission
             FROM `keys` k JOIN users u ON u.id = k.owner
             WHERE k.id = ? LIMIT 1'
        );
        $statement->execute([$id]);
        return self::normalize($statement->fetch() ?: null);
    }

    public static function create(int $ownerId, string $name, string $content): array
    {
        $name = validate_key_name($name);
        $content = validate_ciphertext($content, (int) config('limits.keys_content_bytes', 2097152), 'Key 内容');
        $now = now_utc();
        $statement = Database::pdo()->prepare(
            'INSERT INTO `keys` (key_name, owner, content, version, created_at, updated_at) VALUES (?, ?, ?, 0, ?, ?)'
        );
        $statement->execute([$name, $ownerId, $content, $now, $now]);
        $id = (int) Database::pdo()->lastInsertId();
        $created = self::findWithOwner($id);
        if ($created === null) {
            throw new RuntimeException('创建 Key 后无法读取该 Key。');
        }
        return $created;
    }

    /** @return array<int,array> */
    public static function forOwner(int $ownerId): array
    {
        $statement = Database::pdo()->prepare(
            'SELECT id, key_name, version, created_at, updated_at FROM `keys` WHERE owner = ? ORDER BY updated_at DESC, id DESC'
        );
        $statement->execute([$ownerId]);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row = self::normalize($row);
        }
        return $rows;
    }

    public static function updateContent(int $id, int $ownerId, string $content, int $expectedVersion): array
    {
        $content = validate_ciphertext($content, (int) config('limits.keys_content_bytes', 2097152), 'Key 内容');
        $statement = Database::pdo()->prepare(
            'UPDATE `keys` SET content = ?, version = version + 1, updated_at = ? WHERE id = ? AND owner = ? AND version = ?'
        );
        $statement->execute([$content, now_utc(), $id, $ownerId, $expectedVersion]);
        if ($statement->rowCount() < 1) {
            $current = self::find($id);
            if ($current === null) {
                abort(404, 'Key 不存在。', 'not_found');
            }
            if ($current['owner'] !== $ownerId) {
                abort(403, '只有所有者可以修改此 Key。', 'forbidden');
            }
            abort(409, 'Key 内容已在其他位置被修改，请刷新后重试。', 'version_conflict');
        }
        $updated = self::findWithOwner($id);
        if ($updated === null) {
            throw new RuntimeException('更新 Key 后无法读取该 Key。');
        }
        return $updated;
    }

    public static function rename(int $id, int $ownerId, string $name): array
    {
        $name = validate_key_name($name);
        $statement = Database::pdo()->prepare('UPDATE `keys` SET key_name = ?, updated_at = ? WHERE id = ? AND owner = ?');
        $statement->execute([$name, now_utc(), $id, $ownerId]);
        if ($statement->rowCount() < 1) {
            $current = self::find($id);
            if ($current === null) {
                abort(404, 'Key 不存在。', 'not_found');
            }
            abort(403, '只有所有者可以修改此 Key。', 'forbidden');
        }
        $updated = self::findWithOwner($id);
        if ($updated === null) {
            throw new RuntimeException('重命名 Key 后无法读取该 Key。');
        }
        return $updated;
    }

    /** @return array<int,array{id:int,project_name:string}> */
    public static function messagingProjects(int $id): array
    {
        $statement = Database::pdo()->prepare('SELECT id, project_name FROM projects WHERE messaging_key_id = ? ORDER BY id ASC');
        $statement->execute([$id]);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
        }
        return $rows;
    }

    public static function delete(int $id, bool $force = false): void
    {
        Database::transaction(function () use ($id, $force): void {
            $key = self::find($id);
            if ($key === null) {
                return;
            }

            $messaging = self::messagingProjects($id);
            if ($messaging !== [] && !$force) {
                abort(409, '该 Key 正被项目用作消息密钥，无法删除。', 'key_in_use');
            }

            foreach ($messaging as $project) {
                Project::disableMessaging((int) $project['id']);
            }

            $params = [];
            $contains = json_int_array_contains_sql('project_keys', $id, $params);
            $projects = Database::pdo()->prepare('SELECT id FROM projects WHERE ' . $contains);
            $projects->execute($params);
            foreach ($projects->fetchAll(PDO::FETCH_COLUMN) as $projectId) {
                Project::removeKey((int) $projectId, $id, true);
            }

            Notification::deleteForSubject('key', $id);
            Database::pdo()->prepare('DELETE FROM `keys` WHERE id = ?')->execute([$id]);
        });
    }

    /**
     * Resolve key references by id and/or exact name.
     *
     * @return array<int,array{id:int,key_name:string}>
     */
    public static function lookup(array $ids, array $names): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        $names = array_values(array_unique(array_filter(array_map(
            static fn ($name): string => trim((string) $name),
            $names
        ), static fn (string $name): bool => $name !== '')));

        $ids = array_slice($ids, 0, 50);
        $names = array_slice($names, 0, 50);
        if ($ids === [] && $names === []) {
            return [];
        }

        $clauses = [];
        $params = [];
        if ($ids !== []) {
            $clauses[] = 'id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($params, ...$ids);
        }
        if ($names !== []) {
            $lowerNames = array_map(static fn (string $name): string => mb_strtolower($name, 'UTF-8'), $names);
            $placeholders = implode(',', array_fill(0, count($lowerNames), '?'));
            $clauses[] = '(LOWER(key_name) IN (' . $placeholders . ') OR key_name IN (' . $placeholders . '))';
            array_push($params, ...$lowerNames);
            array_push($params, ...$names);
        }

        $statement = Database::pdo()->prepare(
            'SELECT id, key_name FROM `keys` WHERE ' . implode(' OR ', $clauses) . ' ORDER BY id ASC LIMIT 100'
        );
        $statement->execute($params);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
        }
        return $rows;
    }

    public static function countForOwner(int $ownerId): int
    {
        $statement = Database::pdo()->prepare('SELECT COUNT(*) FROM `keys` WHERE owner = ?');
        $statement->execute([$ownerId]);
        return (int) $statement->fetchColumn();
    }

    public static function recentForOwner(int $ownerId, int $limit = 5): array
    {
        $statement = Database::pdo()->prepare(
            'SELECT id, key_name, version, created_at, updated_at FROM `keys` WHERE owner = ? ORDER BY updated_at DESC, id DESC LIMIT ?'
        );
        $statement->bindValue(1, $ownerId, PDO::PARAM_INT);
        $statement->bindValue(2, max(1, $limit), PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row = self::normalize($row);
        }
        return $rows;
    }
}
