<?php

return [
    'db' => [
        'host' => 'localhost',
        'name' => 'db_name',
        'user' => 'db_user',
        'pass' => 'db_password',
    ],

    'app' => [
        'site_url' => 'https://example.com',
        'env' => 'production',
    ],

    'vk' => [
        'client_id' => 'vk_client_id',
        'client_secret' => 'vk_client_secret',
        'api_version' => '5.131',
        'admin_redirect_uri' => 'https://example.com/auth/vk/admin/callback',
        'user_redirect_uri' => 'https://example.com/auth/vk/user/callback',
    ],

    // VK ID SDK scopes (used on login pages).
    // Admin scope should include publication permissions if you want to publish to VK while admin session is active.
    'vkid' => [
        'user_scope' => 'email',
        'admin_scope' => 'email,wall,photos,groups,offline',
    ],

    'secrets' => [
        'github_webhook_secret' => '',
    ],

    'mail' => [
        'host' => '',
        'port' => '',
        'username' => '',
        'password' => '',
    ],
];
