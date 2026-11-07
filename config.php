<?php
declare(strict_types=1);

/**
 * SolvingKeys default configuration.
 *
 * Do not put secrets here if this file is tracked by git.
 * Create config.local.php returning a partial array to override any value.
 */
return [
    'app' => [
        'name'             => 'SolvingKeys',
        'version'          => '1.0.0',
        'author'           => 'BL.BlueLighting',
        'timezone'         => getenv('SK_TIMEZONE') ?: 'Asia/Shanghai',
        'debug'            => getenv('SK_DEBUG') === '1',
        'base_path'        => getenv('SK_BASE_PATH') !== false ? rtrim((string) getenv('SK_BASE_PATH'), '/') : null,
        'registration_open'=> true,
        'first_user_admin' => true,
        'session_name'     => 'solvingkeys_sid',
        'session_lifetime' => 60 * 60 * 24 * 14,
    ],

    'db' => [
        'driver' => getenv('SK_DB_DRIVER') ?: 'sqlite',
        'sqlite_path' => getenv('SK_DB_PATH') ?: __DIR__ . '/data/solvingkeys.sqlite',
        'mysql' => [
            'host'     => getenv('SK_DB_HOST') ?: '127.0.0.1',
            'port'     => (int) (getenv('SK_DB_PORT') ?: 3306),
            'database' => getenv('SK_DB_NAME') ?: 'solvingkeys',
            'username' => getenv('SK_DB_USER') ?: 'root',
            'password' => getenv('SK_DB_PASS') ?: '',
            'charset'  => 'utf8mb4',
        ],
    ],

    'security' => [
        'login_max_attempts' => 6,
        'login_window'       => 15 * 60,
        'password_min'       => 8,
        'password_max'       => 200,
        'vault_iterations'   => 310000,
    ],

    'limits' => [
        'username_max'         => 32,
        'key_name_max'         => 120,
        'project_name_max'     => 120,
        'vault_bytes'          => 2 * 1024 * 1024,
        'keys_content_bytes'   => 2 * 1024 * 1024,
        'messaging_bytes'      => 4 * 1024 * 1024,
        'notification_fetch'   => 50,
        'admin_page_size'      => 50,
    ],

    'theme' => 'default',
];
