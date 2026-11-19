<?php

return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3319,
        'name' => 'starforge',
        'user' => 'starforge',
        'pass' => 'sf_local_dev',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'env' => 'local',
        'token_secret' => 'local-dev-secret',
    ],
];
