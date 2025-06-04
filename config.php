<?php
return [
    'rate_limiting_enabled' => true, // Set to false to disable rate limiting
    'rate_limits' => [
        'chat_messages' => [
            'limit' => 50,
            'window' => 3600, // in seconds (1 hour)
        ],
        'create_articles' => [
            'limit' => 5,
            'window' => 3600, // in seconds (1 hour)
        ],
    ],
];
