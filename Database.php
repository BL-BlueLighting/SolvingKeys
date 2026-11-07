<?php
declare(strict_types=1);

if (!defined('SOLVINGKEYS')) {
    http_response_code(403);
    exit;
}

final class Database
{
    private const SCHEMA_VERSION = 2;

    private static ?PDO $pdo = null;
    private static ?string $driver = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $driver = strtolower((string) config('db.driver', 'sqlite'));
        self::$driver = $driver;

        if ($driver === 'sqlite') {
            self::$pdo = self::connectSqlite();
        } elseif ($driver === 'mysql') {
            self::$pdo = self::connectMysql();
        } else {
            throw new RuntimeException('Unsupported database driver: ' . $driver);
        }

        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        self::migrate(self::$pdo, $driver);
        return self::$pdo;
    }

    public static function driver(): string
    {
        if (self::$driver !== null) {
            return self::$driver;
        }
        return strtolower((string) config('db.driver', 'sqlite'));
    }

    public static function isSqlite(): bool
    {
        return self::driver() === 'sqlite';
    }

    /**
     * Run a callback in a transaction. Nested calls join the outer transaction.
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $result = $callback($pdo);
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    private static function connectSqlite(): PDO
    {
        $path = (string) config('db.sqlite_path', sk_root('data/solvingkeys.sqlite'));
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('无法创建 SQLite 数据目录：' . $dir);
        }
        if (!is_writable($dir)) {
            throw new RuntimeException('SQLite 数据目录不可写：' . $dir);
        }

        $pdo = new PDO('sqlite:' . $path);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        return $pdo;
    }

    private static function connectMysql(): PDO
    {
        $mysql = (array) config('db.mysql', []);
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $mysql['host'] ?? '127.0.0.1',
            (int) ($mysql['port'] ?? 3306),
            $mysql['database'] ?? 'solvingkeys',
            $mysql['charset'] ?? 'utf8mb4'
        );
        $pdo = new PDO($dsn, (string) ($mysql['username'] ?? ''), (string) ($mysql['password'] ?? ''), [
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    }

    private static function migrate(PDO $pdo, string $driver): void
    {
        if ($driver === 'sqlite') {
            $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
            if ($version >= self::SCHEMA_VERSION) {
                return;
            }
            foreach (self::sqliteSchema() as $statement) {
                $pdo->exec($statement);
            }
            $pdo->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
            return;
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_meta ('
            . 'k VARCHAR(64) NOT NULL PRIMARY KEY, '
            . 'v VARCHAR(255) NOT NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $statement = $pdo->query("SELECT v FROM schema_meta WHERE k = 'schema_version'");
        $version = $statement === false ? 0 : (int) ($statement->fetchColumn() ?: 0);
        if ($version >= self::SCHEMA_VERSION) {
            return;
        }
        foreach (self::mysqlSchema() as $sql) {
            $pdo->exec($sql);
        }
        $pdo->prepare(
            "INSERT INTO schema_meta (k, v) VALUES ('schema_version', ?) "
            . 'ON DUPLICATE KEY UPDATE v = VALUES(v)'
        )->execute([(string) self::SCHEMA_VERSION]);
    }

    private static function sqliteSchema(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL,
                username_norm TEXT NOT NULL,
                password TEXT NOT NULL,
                password_salt TEXT NOT NULL,
                avatar TEXT NOT NULL DEFAULT \'-1\',
                email TEXT NOT NULL,
                email_norm TEXT NOT NULL,
                permission TEXT NOT NULL DEFAULT \'User\',
                keys_key TEXT NOT NULL DEFAULT \'\',
                ban INTEGER NOT NULL DEFAULT 0,
                cloud_vault INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE (username_norm),
                UNIQUE (email_norm)
            )',
            'CREATE TABLE IF NOT EXISTS keys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                key_name TEXT NOT NULL,
                owner INTEGER NOT NULL,
                content TEXT NOT NULL DEFAULT \'\',
                version INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
            'CREATE INDEX IF NOT EXISTS idx_keys_owner ON keys (owner)',
            'CREATE TABLE IF NOT EXISTS projects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_name TEXT NOT NULL,
                project_keys TEXT NOT NULL DEFAULT \'[]\',
                owner INTEGER NOT NULL,
                visibility INTEGER NOT NULL DEFAULT 0,
                messaging_record TEXT NOT NULL DEFAULT \'\',
                members TEXT NOT NULL DEFAULT \'[]\',
                messaging_key_id INTEGER NULL,
                messaging_version INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
            'CREATE INDEX IF NOT EXISTS idx_projects_owner ON projects (owner)',
            'CREATE INDEX IF NOT EXISTS idx_projects_visibility ON projects (visibility)',
            'CREATE TABLE IF NOT EXISTS notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                actor_id INTEGER NULL,
                type TEXT NOT NULL,
                subject_type TEXT NOT NULL,
                subject_id INTEGER NULL,
                message TEXT NOT NULL,
                link_path TEXT NOT NULL DEFAULT \'\',
                is_read INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )',
            'CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications (user_id, is_read, id)',
            'CREATE TABLE IF NOT EXISTS project_invitations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL,
                inviter_id INTEGER NOT NULL,
                invitee_id INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT \'pending\',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE (project_id, invitee_id)
            )',
            'CREATE INDEX IF NOT EXISTS idx_invitations_invitee ON project_invitations (invitee_id, status)',
            'CREATE INDEX IF NOT EXISTS idx_invitations_project ON project_invitations (project_id, status)',
            'CREATE TABLE IF NOT EXISTS project_visibility_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL,
                requested_by INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT \'pending\',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
            'CREATE INDEX IF NOT EXISTS idx_visibility_project ON project_visibility_requests (project_id, status)',
            'CREATE TABLE IF NOT EXISTS project_visibility_ballots (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                request_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                vote TEXT NOT NULL DEFAULT \'pending\',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE (request_id, user_id)
            )',
            'CREATE INDEX IF NOT EXISTS idx_ballots_request ON project_visibility_ballots (request_id)',
            'CREATE TABLE IF NOT EXISTS login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                identifier TEXT NOT NULL,
                ip TEXT NOT NULL,
                attempted_at TEXT NOT NULL
            )',
            'CREATE INDEX IF NOT EXISTS idx_login_attempts ON login_attempts (identifier, ip, attempted_at)',
            'CREATE TABLE IF NOT EXISTS site_settings (
                k TEXT NOT NULL PRIMARY KEY,
                v TEXT NOT NULL DEFAULT \'\',
                updated_at TEXT NOT NULL
            )',
        ];
    }

    private static function mysqlSchema(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS users (
                id INT NOT NULL AUTO_INCREMENT,
                username VARCHAR(100) NOT NULL,
                username_norm VARCHAR(100) NOT NULL,
                password VARCHAR(128) NOT NULL,
                password_salt VARCHAR(128) NOT NULL,
                avatar VARCHAR(2048) NOT NULL DEFAULT \'-1\',
                email VARCHAR(254) NOT NULL,
                email_norm VARCHAR(254) NOT NULL,
                permission VARCHAR(32) NOT NULL DEFAULT \'User\',
                keys_key MEDIUMTEXT NOT NULL,
                ban TINYINT(1) NOT NULL DEFAULT 0,
                cloud_vault TINYINT(1) NOT NULL DEFAULT 0,
                created_at VARCHAR(32) NOT NULL,
                updated_at VARCHAR(32) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_users_username_norm (username_norm),
                UNIQUE KEY uq_users_email_norm (email_norm)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS `keys` (
                id INT NOT NULL AUTO_INCREMENT,
                key_name VARCHAR(255) NOT NULL,
                owner INT NOT NULL,
                content MEDIUMTEXT NOT NULL,
                version INT NOT NULL DEFAULT 0,
                created_at VARCHAR(32) NOT NULL,
                updated_at VARCHAR(32) NOT NULL,
                PRIMARY KEY (id),
                KEY idx_keys_owner (owner)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS projects (
                id INT NOT NULL AUTO_INCREMENT,
                project_name VARCHAR(255) NOT NULL,
                project_keys TEXT NOT NULL,
                owner INT NOT NULL,
                visibility TINYINT(1) NOT NULL DEFAULT 0,
                messaging_record MEDIUMTEXT NOT NULL,
                members TEXT NOT NULL,
                messaging_key_id INT NULL,
                messaging_version INT NOT NULL DEFAULT 0,
                created_at VARCHAR(32) NOT NULL,
                updated_at VARCHAR(32) NOT NULL,
                PRIMARY KEY (id),
                KEY idx_projects_owner (owner),
                KEY idx_projects_visibility (visibility)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS notifications (
                id INT NOT NULL AUTO_INCREMENT,
                user_id INT NOT NULL,
                actor_id INT NULL,
                type VARCHAR(32) NOT NULL,
                subject_type VARCHAR(32) NOT NULL,
                subject_id INT NULL,
                message VARCHAR(500) NOT NULL,
                link_path VARCHAR(255) NOT NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at VARCHAR(32) NOT NULL,
                PRIMARY KEY (id),
                KEY idx_notifications_user (user_id, is_read, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS project_invitations (
                id INT NOT NULL AUTO_INCREMENT,
                project_id INT NOT NULL,
                inviter_id INT NOT NULL,
                invitee_id INT NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT \'pending\',
                created_at VARCHAR(32) NOT NULL,
                updated_at VARCHAR(32) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_invitation_project_invitee (project_id, invitee_id),
                KEY idx_invitations_invitee (invitee_id, status),
                KEY idx_invitations_project (project_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS project_visibility_requests (
                id INT NOT NULL AUTO_INCREMENT,
                project_id INT NOT NULL,
                requested_by INT NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT \'pending\',
                created_at VARCHAR(32) NOT NULL,
                updated_at VARCHAR(32) NOT NULL,
                PRIMARY KEY (id),
                KEY idx_visibility_project (project_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS project_visibility_ballots (
                id INT NOT NULL AUTO_INCREMENT,
                request_id INT NOT NULL,
                user_id INT NOT NULL,
                vote VARCHAR(16) NOT NULL DEFAULT \'pending\',
                created_at VARCHAR(32) NOT NULL,
                updated_at VARCHAR(32) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_ballot_request_user (request_id, user_id),
                KEY idx_ballots_request (request_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS login_attempts (
                id INT NOT NULL AUTO_INCREMENT,
                identifier VARCHAR(255) NOT NULL,
                ip VARCHAR(64) NOT NULL,
                attempted_at VARCHAR(32) NOT NULL,
                PRIMARY KEY (id),
                KEY idx_login_attempts (identifier, ip, attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS site_settings (
                k VARCHAR(64) NOT NULL,
                v MEDIUMTEXT NOT NULL,
                updated_at VARCHAR(32) NOT NULL,
                PRIMARY KEY (k)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ];
    }
}
