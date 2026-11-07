<?php
declare(strict_types=1);

/**
 * Copy this file to config.local.php and adjust as needed.
 * config.local.php is intentionally ignored by git.
 */
return [
    'app' => [
        // 'base_path' => '/solvingkeys', // only needed behind a sub-path proxy
        // 'timezone' => 'Asia/Shanghai',
        // 'debug' => false,
    ],

    'db' => [
        // SQLite is the default and needs no extra configuration.
        // Uncomment the block below to use MySQL / MariaDB instead.
        // 'driver' => 'mysql',
        // 'mysql' => [
        //     'host' => '127.0.0.1',
        //     'port' => 3306,
        //     'database' => 'solvingkeys',
        //     'username' => 'solvingkeys',
        //     'password' => 'change-me',
        //     'charset' => 'utf8mb4',
        // ],
    ],

    'security' => [
        // 'vault_iterations' => 310000,
        // 'login_max_attempts' => 6,
        // 'login_window' => 900,
    ],
];
