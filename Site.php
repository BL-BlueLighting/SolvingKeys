<?php
declare(strict_types=1);

if (!defined('SOLVINGKEYS')) {
    http_response_code(403);
    exit;
}

final class Site
{
    public const ALLOWED_KEYS = [
        'site_name',
        'site_logo',
        'site_description',
        'footer_text',
        'registration_open',
    ];

    private static ?array $cache = null;

    /** @return array<string,string> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        try {
            $rows = Database::pdo()->query('SELECT k, v FROM site_settings')->fetchAll();
        } catch (Throwable) {
            self::$cache = [];
            return self::$cache;
        }
        $settings = [];
        foreach ($rows as $row) {
            $settings[(string) $row['k']] = (string) $row['v'];
        }
        self::$cache = $settings;
        return $settings;
    }

    public static function get(string $key, string $default = ''): string
    {
        $settings = self::all();
        return array_key_exists($key, $settings) ? $settings[$key] : $default;
    }

    /**
     * Store a partial set of site settings. Unknown keys are ignored.
     *
     * @param array<string,string> $values
     */
    public static function setMany(array $values): void
    {
        $now = now_utc();
        $isSqlite = Database::isSqlite();

        $filtered = [];
        foreach (self::ALLOWED_KEYS as $key) {
            if (array_key_exists($key, $values)) {
                $filtered[$key] = (string) $values[$key];
            }
        }
        if ($filtered === []) {
            return;
        }

        Database::transaction(function (PDO $pdo) use ($filtered, $now, $isSqlite): void {
            if ($isSqlite) {
                $statement = $pdo->prepare(
                    'INSERT INTO site_settings (k, v, updated_at) VALUES (?, ?, ?)
                     ON CONFLICT(k) DO UPDATE SET v = excluded.v, updated_at = excluded.updated_at'
                );
            } else {
                $statement = $pdo->prepare(
                    'INSERT INTO site_settings (k, v, updated_at) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = VALUES(updated_at)'
                );
            }
            foreach ($filtered as $key => $value) {
                $statement->execute([$key, $value, $now]);
            }
        });

        self::$cache = null;
    }

    public static function registrationOpen(): bool
    {
        $stored = self::get('registration_open', '');
        if ($stored === '') {
            return (bool) config('app.registration_open', true);
        }
        return $stored === '1';
    }
}
