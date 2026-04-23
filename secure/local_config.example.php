<?php
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'socket' => '/tmp/mysql.sock',
        'name' => 'your_database_name',
        'user' => 'your_database_user',
        'pass' => 'your_database_password',
    ],
    'gemini' => [
        'api_key' => 'your-gemini-key',
    ],
    'smtp' => [
        'host' => 'smtp.example.com',
        'user' => 'user@example.com',
        'pass' => 'your_smtp_password',
        'port' => 587,
        'secure' => 'tls',
        'from_email' => 'user@example.com',
        'from_name' => 'DealerFAI',
    ],
    'aes_key' => 'hex-encoded-32-byte-key',
];
