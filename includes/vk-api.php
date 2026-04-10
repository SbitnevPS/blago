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
    private string $publicationAccessToken;
    private string $apiVersion;
    private int $groupId;

    public function __construct(string $publicationAccessToken, int $groupId, string $apiVersion = '5.131')
    {
        $this->publicationAccessToken = trim($publicationAccessToken);
        $this->groupId = $groupId;
        $this->apiVersion = trim($apiVersion) !== '' ? trim($apiVersion) : '5.131';

        if ($this->publicationAccessToken === '') {
            throw new VkApiException('Не задан publication token VK для публикации.');
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

        $this->assertUserTokenCanPublish();

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
            if ($normalizedKey === '' || in_array($normalizedKey, ['access_token', 'v'], true)) {
                continue;
            }
            $wallPostParams[$normalizedKey] = $paramValue;
        }

        if (function_exists('vkPublicationLog')) {
            vkPublicationLog('vk_wall_post_request', [
                'owner_id' => $wallPostParams['owner_id'] ?? null,
                'from_group' => $wallPostParams['from_group'] ?? null,
                'extra_wall_params' => $this->sanitizeParamsForLog($extraWallParams),
                'wall_post_params' => $this->sanitizeParamsForLog($wallPostParams),
            ]);
        }

        $post = $this->apiRequest('wall.post', $wallPostParams);
        if (function_exists('vkPublicationLog')) {
            vkPublicationLog('vk_wall_post_response', [
                'response' => $post,
            ]);
        }

        $postId = (int) ($post['post_id'] ?? 0);
        if ($postId <= 0) {
            throw new VkApiException('VK не вернул post_id опубликованной записи.');
        }

        $ownerId = (int) ($wallPostParams['owner_id'] ?? (-1 * $this->groupId));
        $expectedDonationGoalId = trim((string) ($wallPostParams['donation_goal_id'] ?? ''));
        $postData = null;
        if ($expectedDonationGoalId !== '') {
            $postData = $this->getWallPostById($ownerId, $postId);
            $hasDonationAttachment = $this->hasDonationGoalAttachment($postData, $expectedDonationGoalId);
            if (function_exists('vkPublicationLog')) {
                vkPublicationLog('vk_wall_post_donation_verification', [
                    'owner_id' => $ownerId,
                    'post_id' => $postId,
                    'expected_donation_goal_id' => $expectedDonationGoalId,
                    'donation_found' => $hasDonationAttachment ? 1 : 0,
                    'post_excerpt' => $this->extractPostVerificationExcerpt($postData),
                ]);
            }
            if (!$hasDonationAttachment) {
                throw new VkApiException(
                    'Пост создан, но цель доната к записи не прикрепилась. Публикация считается неуспешной.',
                    'donation verification failed: donation_goal_id=' . $expectedDonationGoalId . ', owner_id=' . $ownerId . ', post_id=' . $postId
                );
            }
        }

        return [
            'post_id' => $postId,
            'post_url' => 'https://vk.com/wall-' . $this->groupId . '_' . $postId,
            'attachment' => $attachment,
            'owner_id' => $ownerId,
            'expected_donation_goal_id' => $expectedDonationGoalId,
            'donation_verified' => $expectedDonationGoalId !== '' ? 1 : 0,
            'post_data_excerpt' => is_array($postData) ? $this->extractPostVerificationExcerpt($postData) : [],
        ];
    }

    public function validatePublicationAccess(): void
    {
        $response = $this->apiRequest('users.get', []);
        if (!$response) {
            throw new VkApiException(
                'Не удалось проверить токен VK: метод users.get вернул пустой ответ.',
                'users.get returned empty response during token validation'
            );
        }

        $groupsResponse = $this->apiRequest('groups.getById', [
            'group_id' => $this->groupId,
        ]);

        if (!$groupsResponse) {
            throw new VkApiException(
                'Не удалось проверить доступ к сообществу VK.',
                'groups.getById returned empty response for group_id=' . $this->groupId
            );
        }
    }

    public function probePublicationPermissions(): void
    {
        $uploadServer = $this->apiRequest('photos.getWallUploadServer', [
            'group_id' => $this->groupId,
        ]);
        $uploadUrl = (string) ($uploadServer['upload_url'] ?? '');
        if ($uploadUrl === '') {
            throw new VkApiException(
                'Токен не прошёл проверку прав на публикацию фото в сообществе.',
                'photos.getWallUploadServer returned empty upload_url for group_id=' . $this->groupId
            );
        }
    }


    public function apiRequestForDiagnostics(string $method, array $params): array
    {
        return $this->apiRequest($method, $params);
    }

    public function getDonutGoals(): array
    {
        $response = $this->apiRequest('donut.getGoals', [
            'owner_id' => -1 * $this->groupId,
        ]);

        if (isset($response['items']) && is_array($response['items'])) {
            return $response['items'];
        }
        if (isset($response['goals']) && is_array($response['goals'])) {
            return $response['goals'];
        }

        return array_values(array_filter($response, static fn($item) => is_array($item)));
    }

    public function getWallPostById(int $ownerId, int $postId): array
    {
        $response = $this->apiRequest('wall.getById', [
            'posts' => $ownerId . '_' . $postId,
            'extended' => 0,
        ]);

        $items = $response['items'] ?? $response;
        if (!is_array($items)) {
            throw new VkApiException('VK не вернул данные опубликованной записи для проверки доната.');
        }

        $post = null;
        if (isset($items[0]) && is_array($items[0])) {
            $post = $items[0];
        } elseif (isset($items['id'])) {
            $post = $items;
        }

        if (!is_array($post)) {
            throw new VkApiException('VK вернул пустые данные записи при пост-проверке доната.');
        }

        if (function_exists('vkPublicationLog')) {
            vkPublicationLog('vk_wall_get_by_id_response', [
                'owner_id' => $ownerId,
                'post_id' => $postId,
                'post_excerpt' => $this->extractPostVerificationExcerpt($post),
            ]);
        }

        return $post;
    }

    private function assertUserTokenCanPublish(): void
    {
        $this->validatePublicationAccess();
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
        $params['access_token'] = $this->publicationAccessToken;
        $params['v'] = $this->apiVersion;

        $url = 'https://api.vk.com/method/' . $method;

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
            $friendlyMessage = $this->normalizeVkError($errorCode, $errorMessage);
            throw new VkApiException($friendlyMessage, 'VK API ' . $method . ' error #' . $errorCode . ': ' . $errorMessage, $errorCode);
        }

        if (!isset($decoded['response'])) {
            throw new VkApiException('VK API не вернул поле response.', 'response: ' . mb_substr((string) $response, 0, 1000));
        }

        return (array) $decoded['response'];
    }

    private function normalizeVkError(int $errorCode, string $errorMessage): string
    {
        $msg = mb_strtolower($errorMessage);

        if (str_contains($msg, 'method is unavailable with group auth')) {
            return 'Указан токен сообщества. Для публикации рисунков нужен пользовательский токен администратора сообщества с правами photos, wall, groups и offline.';
        }

        if ($errorCode === 5) {
            return 'Невалидный VK токен или у токена нет необходимых прав (photos, wall, groups, offline).';
        }

        if ($errorCode === 7 || $errorCode === 15) {
            return 'Недостаточно прав для публикации: убедитесь, что владелец токена администратор нужного сообщества VK.';
        }

        if ($errorCode === 100) {
            return 'VK отклонил параметры запроса. Проверьте ID сообщества, текст и изображение публикации.';
        }

        if ($errorCode === 214) {
            return 'Публикация на стене сообщества запрещена настройками сообщества VK.';
        }

        return 'Ошибка VK API: ' . $errorMessage;
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

    private function hasDonationGoalAttachment(array $postData, string $expectedGoalId): bool
    {
        $normalizedExpected = trim($expectedGoalId);
        if ($normalizedExpected === '') {
            return false;
        }

        $fieldsToCheck = [
            $postData['donation_goal_id'] ?? null,
            $postData['donation_goal']['id'] ?? null,
            $postData['donut']['donation_goal_id'] ?? null,
            $postData['donut']['goal_id'] ?? null,
            $postData['donut']['goal']['id'] ?? null,
        ];

        foreach ($fieldsToCheck as $candidate) {
            if ((string) $candidate === $normalizedExpected) {
                return true;
            }
        }

        $stack = [$postData];
        while (!empty($stack)) {
            $current = array_pop($stack);
            if (!is_array($current)) {
                continue;
            }
            foreach ($current as $key => $value) {
                if (is_array($value)) {
                    $stack[] = $value;
                    continue;
                }
                $keyLower = mb_strtolower((string) $key);
                if (
                    in_array($keyLower, ['donation_goal_id', 'goal_id', 'vk_donate_id', 'id'], true)
                    && (string) $value === $normalizedExpected
                ) {
                    return true;
                }
            }
        }

        return false;
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
