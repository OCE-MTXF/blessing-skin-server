<?php

return [
    'default' => env('MAIL_MAILER', 'smtp'),

    'mailers' => [
        // Implicit TLS on port 465; require verified STARTTLS on other ports.
        // Do not let a DSN or fallback transport override these safeguards.
        'smtp' => [
            'transport' => 'smtp',
            'host' => env('MAIL_HOST', 'localhost'),
            'port' => (int) env('MAIL_PORT', 465),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'require_tls' => true,
            'verify_peer' => true,
            'timeout' => 10,
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ],
        'array' => [
            'transport' => 'array',
        ],
    ],

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.test'),
        'name' => env('MAIL_FROM_NAME', 'Blessing Skin'),
    ],
];
