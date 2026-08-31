<?php

return [
    'map_version' => env('GAME_MAP_VERSION', 'v1'),
    'starting_coins' => (int) env('GAME_STARTING_COINS', 1000),
    'idempotency_ttl_seconds' => (int) env('GAME_IDEMPOTENCY_TTL_SECONDS', 604800),

    'maps' => [
        'v1' => [
            'width' => 16,
            'height' => 16,
            'obstacles' => [
                'tree-001' => [
                    'type' => 'tree',
                    'x' => 3,
                    'y' => 4,
                    'clear_cost' => 100,
                ],
                'tree-002' => [
                    'type' => 'tree',
                    'x' => 8,
                    'y' => 10,
                    'clear_cost' => 100,
                ],
                'rock-001' => [
                    'type' => 'rock',
                    'x' => 6,
                    'y' => 2,
                    'clear_cost' => 150,
                ],
                'rock-002' => [
                    'type' => 'rock',
                    'x' => 12,
                    'y' => 13,
                    'clear_cost' => 150,
                ],
            ],
        ],
    ],
];
