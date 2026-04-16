<?php

class VkApiException extends RuntimeException
{
    private string $technicalMessage;

    public function __construct(string $message, string $technicalMessage = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->technicalMessage = $technicalMessage !== '' ? $technicalMessage : $message;
    }

    public function getTechnicalMessage(): string
    {
        return $this->technicalMessage;
    }
}

class VkApiClient
{
    private string $groupAccessToken;
    private string $apiVersion;
    private int $groupId;
    private string $tokenMask;
    private string $tokenId;

    public function __construct(string $groupAccessToken, int $groupId, string $apiVersion = '5.131')
    {
        $this->groupAccessToken = trim($groupAccessToken);
        $this->groupId = $groupId;
        $this->apiVersion = trim($apiVersion) !== '' ? trim($apiVersion) : '5.131';
        $this->tokenMask = function_exists('maskVkPublicationToken')
            ? (string) maskVkPublicationToken($this->groupAccessToken)
            : (substr($this->groupAccessToken, 0, 4) . '****' . substr($this->groupAccessToken, -4));
        $this->tokenId = substr(hash('sha256', $this->groupAccessToken), 0, 10);

        if ($this->groupAccessToken === '') {
            throw new VkApiException('Не задан ключ доступа сообщества VK для публикации.');
        }

        if ($this->groupId <= 0) {
            throw new VkApiException('Некорректный VK group_id.');
        }
    }

    public function publishPhotoPost(string $imageFsPath, string $message, bool $fromGroup = true, array $extraWallParams = []): array
    {
        if (!is_file($imageFsPath) || !is_readable($imageFsPath)) {
            throw new VkApiException('Файл изображения недоступен для публикации.');
        }

        $this->validatePublicationAccess();

        $uploadServer = $this->apiRequest('photos.getWallUploadServer', [
            'group_id' => $this->groupId,
        ]);

        $uploadUrl = (string) ($uploadServer['upload_url'] ?? '');
        if ($uploadUrl === '') {
            throw new VkApiException('VK не вернул upload_url для загрузки изображения.');
        }

        $uploadResponse = $this->uploadPhoto($uploadUrl, $imageFsPath);

        $savedPhoto = $this->apiRequest('photos.saveWallPhoto', [
            'group_id' => $this->groupId,
            'photo' => $uploadResponse['photo'] ?? '',
            'server' => $uploadResponse['server'] ?? '',
            'hash' => $uploadResponse['hash'] ?? '',
        ]);

        $photo = $savedPhoto[0] ?? null;
        if (!is_array($photo)) {
            throw new VkApiException('VK не вернул данные сохраненного фото.');
        }

        $ownerId = (int) ($photo['owner_id'] ?? 0);
        $photoId = (int) ($photo['id'] ?? 0);
        if ($ownerId === 0 || $photoId === 0) {
            throw new VkApiException('VK вернул некорректный идентификатор фото.');
        }

        $attachment = 'photo' . $ownerId . '_' . $photoId;

        $wallPostParams = [
            'owner_id' => -1 * $this->groupId,
            'from_group' => $fromGroup ? 1 : 0,
            'message' => $message,
            'attachments' => $attachment,
        ];

        foreach ($extraWallParams as $paramKey => $paramValue) {
            $normalizedKey = trim((string) $paramKey);
            if ($normalizedKey === '' || str_starts_with($normalizedKey, '_') || in_array($normalizedKey, ['access_token', 'v'], true)) {
                continue;
            }
            $wallPostParams[$normalizedKey] = $paramValue;
        }

        if (function_exists('vkPublicationLog')) {
            vkPublicationLog('vk_wall_post_request', [
                'vk_method' => 'wall.post',
                'owner_id' => $wallPostParams['owner_id'] ?? null,
                'from_group' => $wallPostParams['from_group'] ?? null,
                'attachments' => $wallPostParams['attachments'] ?? null,
                'message' => $wallPostParams['message'] ?? null,
                'extra_wall_params' => $this->sanitizeParamsForLog($extraWallParams),
                'wall_post_params' => $this->sanitizeParamsForLog($wallPostParams),
                'publication_mode' => (string) ($extraWallParams['_publication_mode'] ?? 'unknown'),
                'token_masked' => $this->tokenMask,
                'token_id' => $this->tokenId,
            ]);
        }

        $post = $this->apiRequest('wall.post', $wallPostParams);
        if (function_exists('vkPublicationLog')) {
            vkPublicationLog('vk_wall_post_response', [
                'response' => $post,
                'token_id' => $this->tokenId,
            ]);
        }

        $postId = (int) ($post['post_id'] ?? 0);
        if ($postId <= 0) {
            throw new VkApiException('VK не вернул post_id опубликованной записи.');
        }

        $ownerId = (int) ($wallPostParams['owner_id'] ?? (-1 * $this->groupId));
        $publicationMode = (string) ($extraWallParams['_publication_mode'] ?? 'unknown');
        $readbackOk = false;
        $readbackError = '';
        $readbackRaw = [];
        $postData = [];

        try {
            $readbackRaw = $this->getWallPostById($ownerId, $postId);
            $postData = $this->extractWallPostFromReadback($readbackRaw);
            $readbackOk = is_array($postData) && !empty($postData) && (int) ($postData['id'] ?? 0) > 0;

            if (!$readbackOk) {
                $readbackError = 'wall.getById returned empty or unexpected post data';
                if (function_exists('vkPublicationLog')) {
                    vkPublicationLog('vk_wall_post_readback_failed_but_post_created', [
                        'post_id' => $postId,
                        'owner_id' => $ownerId,
                        'publication_type' => $publicationMode,
                        'error' => $readbackError,
                        'readback_raw' => $readbackRaw,
                    ]);
                }
            }
        } catch (Throwable $e) {
            $readbackError = $e instanceof VkApiException ? $e->getTechnicalMessage() : $e->getMessage();
            $readbackRaw = [];
            $postData = [];
            if (function_exists('vkPublicationLog')) {
                vkPublicationLog('vk_wall_post_readback_failed_but_post_created', [
                    'post_id' => $postId,
                    'owner_id' => $ownerId,
                    'publication_type' => $publicationMode,
                    'error' => $readbackError,
                ]);
            }
        }

        return [
            'post_id' => $postId,
            'post_url' => 'https://vk.com/wall-' . $this->groupId . '_' . $postId,
            'attachment' => $attachment,
            'owner_id' => $ownerId,
            'wall_post_params' => $this->sanitizeParamsForLog($wallPostParams),
            'wall_post_response' => $post,
            'readback_ok' => $readbackOk,
            'readback_error' => $readbackError,
            'readback_raw' => $readbackRaw,
            'readback_post' => $postData,
            'post_data_excerpt' => is_array($postData) ? $this->extractPostVerificationExcerpt($postData) : [],
        ];
    }

    public function publishTextPost(string $message, bool $fromGroup = true, array $extraWallParams = []): array
    {
        $wallPostParams = [
            'owner_id' => -1 * $this->groupId,
            'from_group' => $fromGroup ? 1 : 0,
            'message' => $message,
        ];

        foreach ($extraWallParams as $paramKey => $paramValue) {
            $normalizedKey = trim((string) $paramKey);
            if ($normalizedKey === '' || str_starts_with($normalizedKey, '_') || in_array($normalizedKey, ['access_token', 'v'], true)) {
                continue;
            }
            $wallPostParams[$normalizedKey] = $paramValue;
        }

        $post = $this->apiRequest('wall.post', $wallPostParams);
        $postId = (int) ($post['post_id'] ?? 0);
        if ($postId <= 0) {
            throw new VkApiException('VK не вернул post_id опубликованной записи.');
        }

        return [
            'post_id' => $postId,
            'post_url' => 'https://vk.com/wall-' . $this->groupId . '_' . $postId,
            'owner_id' => (int) ($wallPostParams['owner_id'] ?? (-1 * $this->groupId)),
            'wall_post_params' => $this->sanitizeParamsForLog($wallPostParams),
            'wall_post_response' => $post,
        ];
    }

    public function validatePublicationAccess(): void
    {
        $groupsResponse = $this->apiRequest('groups.getById', [
            'group_id' => $this->groupId,
        ]);

        if (!$groupsResponse) {
            throw new VkApiException(
                'Не удалось проверить доступ к сообществу VK.',
                'groups.getById returned empty response for group_id=' . $this->groupId
            );
        }

        $permissions = $this->apiRequest('groups.getTokenPermissions', []);
        $permissionItems = [];
        if (isset($permissions['permissions']) && is_array($permissions['permissions'])) {
            $permissionItems = array_values(array_map('strval', $permissions['permissions']));
        } elseif (is_array($permissions)) {
            $permissionItems = array_values(array_filter(array_map(static fn($value) => is_string($value) ? trim($value) : '', $permissions)));
        }

        foreach (['wall', 'photos'] as $requiredPermission) {
            if (!in_array($requiredPermission, $permissionItems, true)) {
                throw new VkApiException(
                    'Недостаточно прав для публикации на стене сообщества.',
                    'groups.getTokenPermissions missing permission "' . $requiredPermission . '"'
                );
            }
        }

        $uploadServer = $this->apiRequest('photos.getWallUploadServer', [
            'group_id' => $this->groupId,
        ]);
        if (empty($uploadServer['upload_url'])) {
            throw new VkApiException(
                'Не удалось загрузить изображение для публикации',
                'photos.getWallUploadServer returned empty upload_url for group_id=' . $this->groupId
            );
        }
    }

    public function apiRequestForDiagnostics(string $method, array $params): array
    {
        return $this->apiRequest($method, $params);
    }

    public function getWallPostById(int $ownerId, int $postId): array
    {
        $response = $this->apiRequest('wall.getById', [
            'posts' => $ownerId . '_' . $postId,
            'extended' => 1,
            'copy_history_depth' => 2,
        ]);

        if (function_exists('vkPublicationLog')) {
            vkPublicationLog('vk_wall_get_by_id_response', [
                'vk_method' => 'wall.getById',
                'owner_id' => $ownerId,
                'post_id' => $postId,
                'response' => $response,
                'post_excerpt' => $this->extractPostVerificationExcerpt($this->extractWallPostFromReadback($response)),
                'token_id' => $this->tokenId,
            ]);
        }

        return $response;
    }

    private function uploadPhoto(string $uploadUrl, string $imageFsPath): array
    {
        $ch = curl_init($uploadUrl);
        if ($ch === false) {
            throw new VkApiException('Не удалось инициализировать CURL для загрузки фото.');
        }

        $payload = [
            'photo' => new CURLFile($imageFsPath),
        ];

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new VkApiException('Ошибка загрузки фото в VK.', 'upload error: ' . $curlError);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new VkApiException('VK вернул ошибку при загрузке фото.', 'upload HTTP status: ' . $httpCode);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new VkApiException('Некорректный ответ сервера загрузки VK.', 'upload response: ' . mb_substr((string) $response, 0, 1000));
        }

        if (!empty($decoded['error'])) {
            $rawError = json_encode($decoded['error'], JSON_UNESCAPED_UNICODE);
            throw new VkApiException('Ошибка загрузки изображения в VK.', 'upload error: ' . $rawError);
        }

        return $decoded;
    }

    private function apiRequest(string $method, array $params): array
    {
        $params['access_token'] = $this->groupAccessToken;
        $params['v'] = $this->apiVersion;

        $url = 'https://api.vk.com/method/' . $method;

        if (function_exists('vkPublicationLog')) {
            vkPublicationLog('vk_api_request', [
                'method' => $method,
                'group_id' => $this->groupId,
                'token_masked' => $this->tokenMask,
                'token_id' => $this->tokenId,
            ]);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new VkApiException('Не удалось инициализировать CURL для VK API.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new VkApiException('Ошибка соединения с VK API.', 'curl error: ' . $curlError);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new VkApiException('Некорректный ответ VK API.', 'raw response: ' . mb_substr((string) $response, 0, 1000));
        }

        if (isset($decoded['error'])) {
            $error = (array) $decoded['error'];
            $errorCode = (int) ($error['error_code'] ?? 0);
            $errorMessage = (string) ($error['error_msg'] ?? 'Неизвестная ошибка VK API');
            $friendlyMessage = $this->normalizeVkError($method, $errorCode, $errorMessage);
            throw new VkApiException($friendlyMessage, 'VK API ' . $method . ' error #' . $errorCode . ': ' . $errorMessage, $errorCode);
        }

        if (!isset($decoded['response'])) {
            throw new VkApiException('VK API не вернул поле response.', 'response: ' . mb_substr((string) $response, 0, 1000));
        }

        return (array) $decoded['response'];
    }

    private function normalizeVkError(string $method, int $errorCode, string $errorMessage): string
    {
        $msg = mb_strtolower($errorMessage);
        $methodLower = mb_strtolower($method);

        if (str_contains($msg, 'application is blocked')) {
            return 'VK отклонил ключ сообщества: приложение заблокировано.';
        }

        if (str_contains($msg, 'invalid request')) {
            return 'VK отклонил запрос как некорректный. Проверьте актуальность токена, ID сообщества и параметры публикации.';
        }

        if (str_contains($msg, 'method is unavailable with group auth')) {
            return 'VK отклонил запрос из-за неподходящего типа авторизации для ключа сообщества.';
        }

        if ($errorCode === 5) {
            return 'Ключ доступа сообщества недействителен.';
        }

        if ($errorCode === 15 && str_contains($msg, 'current scopes')) {
            if (str_contains($methodLower, 'photos.')) {
                return 'Роль в сообществе подтверждена, но текущий access token не имеет права photos.';
            }
            if (str_contains($methodLower, 'wall.')) {
                return 'Роль в сообществе подтверждена, но текущий access token не имеет права wall.';
            }
            if (str_contains($methodLower, 'groups.')) {
                return 'Текущий access token не имеет права groups.';
            }
            return 'Доступ запрещён: текущий access token не имеет нужных прав (scope).';
        }

        // VK sometimes returns #15/#7 without explicitly spelling out "current scopes".
        // Prefer a scope-focused message for publication-specific methods to avoid misleading "role in group" hints.
        if (($errorCode === 7 || $errorCode === 15) && str_contains($methodLower, 'photos.getwalluploadserver')) {
                return 'Доступ к photos.getWallUploadServer запрещён. Проверьте права ключа сообщества (wall, photos).';
        }
        if (($errorCode === 7 || $errorCode === 15) && str_contains($methodLower, 'photos.savewallphoto')) {
                return 'Доступ к photos.saveWallPhoto запрещён. Проверьте права ключа сообщества (photos).';
        }
        if (($errorCode === 7 || $errorCode === 15) && str_contains($methodLower, 'wall.post')) {
                return 'Доступ к wall.post запрещён. Проверьте права ключа сообщества (wall).';
        }

        if ($errorCode === 7 || $errorCode === 15) {
            return 'Недостаточно прав для публикации на стене сообщества.';
        }

        if ($errorCode === 100) {
            return 'VK отклонил параметры запроса. Проверьте ID сообщества, текст и изображение публикации.';
        }

        if ($errorCode === 214) {
            return 'Публикация на стене сообщества запрещена настройками сообщества VK.';
        }

        return 'Ошибка VK API: ' . $errorMessage;
    }


    public function extractWallPostFromReadback(array $readbackRaw): array
    {
        $items = $readbackRaw['items'] ?? $readbackRaw;
        if (isset($items[0]) && is_array($items[0])) {
            return $items[0];
        }
        return is_array($items) ? $items : [];
    }

    private function sanitizeParamsForLog(array $params): array
    {
        $sanitized = [];
        foreach ($params as $key => $value) {
            $paramKey = trim((string) $key);
            if ($paramKey === '' || in_array($paramKey, ['access_token', 'token', 'v'], true)) {
                continue;
            }
            $sanitized[$paramKey] = $value;
        }
        return $sanitized;
    }


    private function extractPostVerificationExcerpt(array $postData): array
    {
        return [
            'id' => $postData['id'] ?? null,
            'owner_id' => $postData['owner_id'] ?? null,
            'donation_goal_id' => $postData['donation_goal_id'] ?? null,
            'donation_goal' => $postData['donation_goal'] ?? null,
            'donut' => $postData['donut'] ?? null,
            'attachments_count' => is_array($postData['attachments'] ?? null) ? count($postData['attachments']) : 0,
        ];
    }
}
