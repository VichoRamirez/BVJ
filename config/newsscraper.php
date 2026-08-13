<?php

return [
    'ai' => [
        'driver' => env('NEWS_AI_DRIVER', 'unconfigured'),
        'ollama' => [
            'base_url' => env('NEWS_OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),
            'model' => env('NEWS_OLLAMA_MODEL', 'llama3.2:3b'),
            'connect_timeout' => (float) env('NEWS_OLLAMA_CONNECT_TIMEOUT', 3),
            'timeout' => (float) env('NEWS_OLLAMA_TIMEOUT', 60),
            'retry_attempts' => (int) env('NEWS_OLLAMA_RETRY_ATTEMPTS', 2),
            'retry_backoff' => (int) env('NEWS_OLLAMA_RETRY_BACKOFF', 100),
            'max_response_bytes' => (int) env('NEWS_OLLAMA_MAX_RESPONSE_BYTES', 1_048_576),
        ],
    ],
];
