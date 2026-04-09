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

    public function publishPhotoPost(string $imageFsPath, string $message, bool $fromGroup = true): array
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

        $post = $this->apiRequest('wall.post', [
            'owner_id' => -1 * $this->groupId,
            'from_group' => $fromGroup ? 1 : 0,
            'message' => $message,
            'attachments' => $attachment,
        ]);

        $postId = (int) ($post['post_id'] ?? 0);
        if ($postId <= 0) {
            throw new VkApiException('VK не вернул post_id опубликованной записи.');
        }

        return [
            'post_id' => $postId,
            'post_url' => 'https://vk.com/wall-' . $this->groupId . '_' . $postId,
            'attachment' => $attachment,
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
}
