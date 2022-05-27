<?php

return [
    'default' => 'public_html',
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => 'storage',
        ],
        'public_html' => [
            'driver' => 'local',
            'root' => env('PUBLIC_HTML_PATH', '/var/www/html'),
        ],
    ],
];