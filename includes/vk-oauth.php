<?php
// includes/vk-oauth.php

if (!function_exists('build_vk_authorize_url')) {
    /**
     * Формирует authorize URL для классического VK Web OAuth code flow.
     *
     * Для отдельного VK web OAuth приложения в настройках VK должны быть разрешены redirect URL:
     * - https://konkurs.tolkodobroe.info/vk-auth
     * - https://konkurs.tolkodobroe.info/admin/vk-auth.php
     */
    function build_vk_authorize_url(string $redirectUri, string $state): string
    {
        return 'https://oauth.vk.com/authorize?' . http_build_query([
            'client_id' => VK_CLIENT_ID,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'email',
            'state' => $state,
        ]);
    }
}
