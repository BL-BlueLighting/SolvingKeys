<?php
declare(strict_types=1);

if (!defined('SOLVINGKEYS')) {
    http_response_code(403);
    exit;
}

final class User
{
    public const PERMISSION_ADMIN = 'Administrator';
    public const PERMISSION_USER = 'User';

    private static function normalize(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        foreach (['id', 'ban', 'cloud_vault'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = (int) $row[$field];
            }
        }
        return $row;
    }

    public static function find(int $id): ?array
    {
        $statement = Database::pdo()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $statement->execute([$id]);
        return self::normalize($statement->fetch() ?: null);
    }

    public static function findByUsername(string $username): ?array
    {
        $statement = Database::pdo()->prepare('SELECT * FROM users WHERE username_norm = ? LIMIT 1');
        $statement->execute([normalize_username($username)]);
        return self::normalize($statement->fetch() ?: null);
    }

    public static function findByEmail(string $email): ?array
    {
        $statement = Database::pdo()->prepare('SELECT * FROM users WHERE email_norm = ? LIMIT 1');
        $statement->execute([mb_strtolower(trim($email), 'UTF-8')]);
        return self::normalize($statement->fetch() ?: null);
    }

    public static function count(): int
    {
        return (int) Database::pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    public static function isAdmin(array $user): bool
    {
        return ($user['permission'] ?? '') === self::PERMISSION_ADMIN;
    }

    public static function isBanned(array $user): bool
    {
        return (int) ($user['ban'] ?? 0) === 1;
    }

    /** @return array{0:string,1:string} [hash, salt] */
    public static function hashPassword(string $password): array
    {
        $salt = bin2hex(random_bytes(16));
        return [Sm3::hex($salt . ':' . $password), $salt];
    }

    public static function verifyPassword(array $user, string $password): bool
    {
        $expected = (string) ($user['password'] ?? '');
        if ($expected === '') {
            return false;
        }
        $actual = Sm3::hex((string) ($user['password_salt'] ?? '') . ':' . $password);
        return hash_equals($expected, $actual);
    }

    /**
     * @param array{username:string,email:string,password:string,avatar?:string,keys_key?:string,cloud_vault?:bool} $data
     */
    public static function create(array $data): array
    {
        $username = validate_username($data['username'] ?? '');
        $email = validate_email($data['email'] ?? '');
        $password = validate_password($data['password'] ?? '');
        $avatar = validate_avatar((string) ($data['avatar'] ?? '-1'));
        $usernameNorm = normalize_username($username);
        $emailNorm = mb_strtolower($email, 'UTF-8');
        $cloudVault = (bool) ($data['cloud_vault'] ?? false);
        $keysKey = (string) ($data['keys_key'] ?? '');
        if ($cloudVault) {
            $keysKey = validate_ciphertext($keysKey, (int) config('limits.vault_bytes', 2097152), '钥匙库');
            if ($keysKey === '') {
                abort(422, '开启云端钥匙库时必须提供加密后的钥匙库。', 'vault_required');
            }
        } else {
            $keysKey = '';
        }

        return Database::transaction(function (PDO $pdo) use ($username, $usernameNorm, $email, $emailNorm, $password, $avatar, $cloudVault, $keysKey): array {
            $check = $pdo->prepare('SELECT id, username_norm, email_norm FROM users WHERE username_norm = ? OR email_norm = ? LIMIT 1');
            $check->execute([$usernameNorm, $emailNorm]);
            $existing = $check->fetch();
            if ($existing !== false) {
                if (($existing['username_norm'] ?? '') === $usernameNorm) {
                    abort(409, '该用户名已被使用。', 'username_taken');
                }
                abort(409, '该邮箱已被使用。', 'email_taken');
            }

            $isFirst = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
            $permission = ($isFirst && (bool) config('app.first_user_admin', true))
                ? self::PERMISSION_ADMIN
                : self::PERMISSION_USER;

            [$hash, $salt] = self::hashPassword($password);
            $now = now_utc();
            $statement = $pdo->prepare(
                'INSERT INTO users (username, username_norm, password, password_salt, avatar, email, email_norm, permission, keys_key, ban, cloud_vault, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)'
            );
            try {
                $statement->execute([
                    $username, $usernameNorm, $hash, $salt, $avatar, $email, $emailNorm,
                    $permission, $keysKey, $cloudVault ? 1 : 0, $now, $now,
                ]);
            } catch (PDOException $error) {
                if ($error->getCode() === '23000') {
                    abort(409, '用户名或邮箱已被使用。', 'duplicate_user');
                }
                throw $error;
            }

            $id = (int) $pdo->lastInsertId();
            $created = self::find($id);
            if ($created === null) {
                throw new RuntimeException('创建用户后无法读取该用户。');
            }
            return $created;
        });
    }

    public static function updateProfile(int $id, string $username, string $email, string $avatar): array
    {
        $username = validate_username($username);
        $email = validate_email($email);
        $avatar = validate_avatar($avatar);
        $usernameNorm = normalize_username($username);
        $emailNorm = mb_strtolower($email, 'UTF-8');

        return Database::transaction(function (PDO $pdo) use ($id, $username, $usernameNorm, $email, $emailNorm, $avatar): array {
            $check = $pdo->prepare('SELECT id, username_norm, email_norm FROM users WHERE (username_norm = ? OR email_norm = ?) AND id <> ? LIMIT 1');
            $check->execute([$usernameNorm, $emailNorm, $id]);
            $existing = $check->fetch();
            if ($existing !== false) {
                if (($existing['username_norm'] ?? '') === $usernameNorm) {
                    abort(409, '该用户名已被使用。', 'username_taken');
                }
                abort(409, '该邮箱已被使用。', 'email_taken');
            }
            $statement = $pdo->prepare('UPDATE users SET username = ?, username_norm = ?, email = ?, email_norm = ?, avatar = ?, updated_at = ? WHERE id = ?');
            $statement->execute([$username, $usernameNorm, $email, $emailNorm, $avatar, now_utc(), $id]);
            $updated = self::find($id);
            if ($updated === null) {
                abort(404, '用户不存在。');
            }
            return $updated;
        });
    }

    public static function updatePassword(int $id, string $password): void
    {
        $password = validate_password($password);
        [$hash, $salt] = self::hashPassword($password);
        $statement = Database::pdo()->prepare('UPDATE users SET password = ?, password_salt = ?, updated_at = ? WHERE id = ?');
        $statement->execute([$hash, $salt, now_utc(), $id]);
    }

    public static function setVault(int $id, string $envelope): void
    {
        $envelope = validate_ciphertext($envelope, (int) config('limits.vault_bytes', 2097152), '钥匙库');
        $statement = Database::pdo()->prepare('UPDATE users SET keys_key = ?, updated_at = ? WHERE id = ?');
        $statement->execute([$envelope, now_utc(), $id]);
    }

    public static function setCloudPreference(int $id, bool $cloud, string $envelope = ''): void
    {
        if ($cloud) {
            $envelope = validate_ciphertext($envelope, (int) config('limits.vault_bytes', 2097152), '钥匙库');
            if ($envelope === '') {
                abort(422, '开启云端钥匙库时必须提供加密后的钥匙库。', 'vault_required');
            }
            $statement = Database::pdo()->prepare('UPDATE users SET cloud_vault = 1, keys_key = ?, updated_at = ? WHERE id = ?');
            $statement->execute([$envelope, now_utc(), $id]);
            return;
        }

        $statement = Database::pdo()->prepare("UPDATE users SET cloud_vault = 0, keys_key = '', updated_at = ? WHERE id = ?");
        $statement->execute([now_utc(), $id]);
    }

    public static function setBan(int $id, bool $banned): void
    {
        $statement = Database::pdo()->prepare('UPDATE users SET ban = ?, updated_at = ? WHERE id = ?');
        $statement->execute([$banned ? 1 : 0, now_utc(), $id]);
    }

    /** @return array<int,array> */
    public static function paginate(int $limit, int $offset, string $search = ''): array
    {
        $pdo = Database::pdo();
        $sql = 'SELECT u.id, u.username, u.email, u.avatar, u.permission, u.ban, u.cloud_vault, u.created_at, u.updated_at,
                    (SELECT COUNT(*) FROM `keys` k WHERE k.owner = u.id) AS key_count,
                    (SELECT COUNT(*) FROM projects p WHERE p.owner = u.id) AS project_count
                FROM users u';
        $params = [];
        if ($search !== '') {
            $sql .= ' WHERE u.username LIKE ? OR u.email LIKE ?';
            $params = ['%' . $search . '%', '%' . $search . '%'];
        }
        $sql .= ' ORDER BY u.id ASC LIMIT ? OFFSET ?';
        $statement = $pdo->prepare($sql);
        $index = 1;
        foreach ($params as $param) {
            $statement->bindValue($index++, $param, PDO::PARAM_STR);
        }
        $statement->bindValue($index++, max(1, $limit), PDO::PARAM_INT);
        $statement->bindValue($index, max(0, $offset), PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['ban'] = (int) $row['ban'];
            $row['cloud_vault'] = (int) $row['cloud_vault'];
            $row['key_count'] = (int) $row['key_count'];
            $row['project_count'] = (int) $row['project_count'];
            $row['avatar_url'] = avatar_url($row, 64);
        }
        return $rows;
    }

    public static function countSearch(string $search = ''): int
    {
        $pdo = Database::pdo();
        if ($search === '') {
            return (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        }
        $statement = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username LIKE ? OR email LIKE ?');
        $statement->execute(['%' . $search . '%', '%' . $search . '%']);
        return (int) $statement->fetchColumn();
    }

    /**
     * Resolve user mentions by numeric id or username.
     *
     * @return array<int,array> public user rows
     */
    public static function lookup(array $ids, array $names): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        $names = array_values(array_unique(array_filter(array_map(
            static fn ($name): string => normalize_username((string) $name),
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
            $clauses[] = 'username_norm IN (' . implode(',', array_fill(0, count($names), '?')) . ')';
            array_push($params, ...$names);
        }
        $statement = Database::pdo()->prepare('SELECT * FROM users WHERE ' . implode(' OR ', $clauses) . ' LIMIT 100');
        $statement->execute($params);
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $row = self::normalize($row);
            $result[] = public_user($row);
        }
        return $result;
    }

    public static function tooManyLoginAttempts(string $identifier, string $ip, int $max, int $window): bool
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $window);
        $statement = Database::pdo()->prepare('SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND ip = ? AND attempted_at >= ?');
        $statement->execute([$identifier, $ip, $cutoff]);
        return (int) $statement->fetchColumn() >= $max;
    }

    public static function recordFailedLogin(string $identifier, string $ip, int $window): void
    {
        $pdo = Database::pdo();
        $cutoff = gmdate('Y-m-d H:i:s', time() - $window);
        $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')->execute([$cutoff]);
        $pdo->prepare('INSERT INTO login_attempts (identifier, ip, attempted_at) VALUES (?, ?, ?)')
            ->execute([$identifier, $ip, now_utc()]);
    }

    public static function clearLoginAttempts(string $identifier, string $ip): void
    {
        Database::pdo()->prepare('DELETE FROM login_attempts WHERE identifier = ? AND ip = ?')
            ->execute([$identifier, $ip]);
    }

    /**
     * Permanently remove a user and their owned content.
     * Administrator accounts are protected and cannot be deleted this way.
     */
    public static function deleteAccount(int $id): void
    {
        Database::transaction(function (PDO $pdo) use ($id): void {
            $user = self::find($id);
            if ($user === null) {
                abort(404, '用户不存在。');
            }
            if (self::isAdmin($user)) {
                abort(403, '不能删除管理员账号。', 'admin_protected');
            }

            $keys = $pdo->prepare('SELECT id FROM `keys` WHERE owner = ?');
            $keys->execute([$id]);
            foreach ($keys->fetchAll(PDO::FETCH_COLUMN) as $keyId) {
                Keys::delete((int) $keyId, true);
            }

            $ownedProjects = $pdo->prepare('SELECT * FROM projects WHERE owner = ?');
            $ownedProjects->execute([$id]);
            foreach ($ownedProjects->fetchAll() as $project) {
                $members = Project::decodeMembers($project);
                $members = array_values(array_filter($members, static fn (int $member): bool => $member !== $id));
                if ($members === []) {
                    Project::delete((int) $project['id']);
                    continue;
                }
                Project::transferOwnership((int) $project['id'], $members[0]);
                Project::updateMembers((int) $project['id'], $members);
                Project::cancelPendingVisibility((int) $project['id']);
            }

            $memberParams = [];
            $memberCondition = json_int_array_contains_sql('members', $id, $memberParams);
            $memberProjects = $pdo->prepare('SELECT * FROM projects WHERE ' . $memberCondition);
            $memberProjects->execute($memberParams);
            foreach ($memberProjects->fetchAll() as $project) {
                if ((int) $project['owner'] === $id) {
                    continue;
                }
                Project::removeMember((int) $project['id'], $id, true);
            }

            Project::deleteUserInvitations($id);
            Project::deleteUserBallots($id);
            Project::cancelUserVisibilityRequests($id);

            Notification::deleteForUser($id);
            Notification::nullifyActor($id);
            $pdo->prepare('DELETE FROM login_attempts WHERE identifier = ?')->execute([normalize_username((string) $user['username'])]);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        });
    }

    public static function countAdmins(int $excludeId = 0): int
    {
        $sql = 'SELECT COUNT(*) FROM users WHERE permission = ?';
        $params = [self::PERMISSION_ADMIN];
        if ($excludeId > 0) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);
        return (int) $statement->fetchColumn();
    }
}
