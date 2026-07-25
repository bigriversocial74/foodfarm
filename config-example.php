<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'Homestead',
        'environment' => 'development',
        'debug' => true,
        'base_url' => 'http://localhost/foodfarm',
        'timezone' => 'America/Phoenix',
    ],
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'homestead',
        'user' => 'homestead_user',
        'password' => 'change-me',
        'charset' => 'utf8mb4',
    ],
    'security' => [
        'session_name' => 'homestead_session',
        'csrf_key' => 'replace-with-a-random-secret',
    ],
    'features' => [
        'simulated_sensors' => true,
        'device_adapters' => false,
        'family_tracking' => true,
        'prepared_food_tracking' => true,
    ],
];
