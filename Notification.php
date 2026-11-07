<?php
declare(strict_types=1);

if (!defined('SOLVINGKEYS')) {
    http_response_code(403);
    exit;
}

final class Notification
{
    public static function create(
        int $userId,
        ?int $actorId,
        string $type,
        string $subjectType,
        ?int $subjectId,
        string $message,
        string $linkPath = '',
    ): void {
        if ($userId <= 0) {
            return;
        }
        $type = substr($type, 0, 32);
        $subjectType = substr($subjectType, 0, 32);
        $message = mb_substr(trim($message), 0, 500, 'UTF-8');
        if ($message === '') {
            return;
        }
        if ($linkPath !== '' && !str_starts_with($linkPath, '/')) {
            $linkPath = '';
        }
        $linkPath = substr($linkPath, 0, 255);

        Database::pdo()->prepare(
            'INSERT INTO notifications (user_id, actor_id, type, subject_type, subject_id, message, link_path, is_read, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)'
        )->execute([$userId, $actorId, $type, $subjectType, $subjectId, $message, $linkPath, now_utc()]);
    }

    /** @return array<int,array> */
    public static function forUser(int $userId, int $limit = 50, int $offset = 0): array
    {
        $statement = Database::pdo()->prepare(
            'SELECT n.*, u.username AS actor_username, u.avatar AS actor_avatar, u.email AS actor_email
             FROM notifications n
             LEFT JOIN users u ON u.id = n.actor_id
             WHERE n.user_id = ?
             ORDER BY n.id DESC
             LIMIT ? OFFSET ?'
        );
        $statement->bindValue(1, $userId, PDO::PARAM_INT);
        $statement->bindValue(2, max(1, $limit), PDO::PARAM_INT);
        $statement->bindValue(3, max(0, $offset), PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['user_id'] = (int) $row['user_id'];
            $row['actor_id'] = $row['actor_id'] === null ? null : (int) $row['actor_id'];
            $row['subject_id'] = $row['subject_id'] === null ? null : (int) $row['subject_id'];
            $row['is_read'] = (int) $row['is_read'];
            $row['actor'] = $row['actor_id'] === null ? null : public_user([
                'id' => $row['actor_id'],
                'username' => $row['actor_username'],
                'avatar' => $row['actor_avatar'],
                'email' => $row['actor_email'],
            ]);
            unset($row['actor_username'], $row['actor_avatar'], $row['actor_email']);
        }
        return $rows;
    }

    public static function latest(int $userId, int $limit = 5): array
    {
        return self::forUser($userId, $limit, 0);
    }

    public static function unreadCount(int $userId): int
    {
        $statement = Database::pdo()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $statement->execute([$userId]);
        return (int) $statement->fetchColumn();
    }

    public static function markRead(int $id, int $userId): void
    {
        Database::pdo()->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')
            ->execute([$id, $userId]);
    }

    public static function markAllRead(int $userId): void
    {
        Database::pdo()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$userId]);
    }

    public static function deleteForSubject(string $subjectType, int $subjectId): void
    {
        Database::pdo()->prepare('DELETE FROM notifications WHERE subject_type = ? AND subject_id = ?')
            ->execute([$subjectType, $subjectId]);
    }

    public static function deleteForUser(int $userId): void
    {
        Database::pdo()->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$userId]);
    }

    public static function nullifyActor(int $actorId): void
    {
        Database::pdo()->prepare('UPDATE notifications SET actor_id = NULL WHERE actor_id = ?')->execute([$actorId]);
    }
}
