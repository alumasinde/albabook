<?php

declare(strict_types=1);

return [
    'channel' => env('LOG_CHANNEL', 'file'),     // file | stderr
    'format' => env('LOG_FORMAT', 'line'),       // line | json
    'level' => env('LOG_LEVEL', 'info'),
    'path' => 'storage/logs/albabook.log',
    'max_files' => (int) env('LOG_MAX_FILES', 14),
];
