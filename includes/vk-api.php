<?php

class VkApiException extends RuntimeException
{
}

class VkApiClient
{
    private string $accessToken;
    private string $apiVersion;
    private int $groupId;

    public function __construct(string $accessToken, int $groupId, string $apiVersion = '5.131')
    {
        $this->accessToken = trim($accessToken);
        $this->groupId = $groupId;
        $this->apiVersion = trim($apiVersion) !== '' ? trim($apiVersion) : '5.131';

        if ($this->accessToken === '') {
            throw new VkApiException('Не задан VK access token');
        }

        if ($this->groupId <= 0) {
            throw new VkApiException('Некорректный VK group_id');
        }
    }

    public function publishPhotoPost(string $imageFsPath, string $message, bool $fromGroup = true): array
    {
        if (!is_file($imageFsPath) || !is_readable($imageFsPath)) {
            throw new VkApiException('Файл изображения недоступен');
        }

        $uploadServer = $this->apiRequest('photos.getWallUploadServer', [
            'group_id' => $this->groupId,
        ]);

        $uploadUrl = (string) ($uploadServer['upload_url'] ?? '');
        if ($uploadUrl === '') {
            throw new VkApiException('VK не вернул upload_url');
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
            throw new VkApiException('VK не вернул данные сохраненного фото');
        }

        $ownerId = (int) ($photo['owner_id'] ?? 0);
        $photoId = (int) ($photo['id'] ?? 0);
        if ($ownerId === 0 || $photoId === 0) {
            throw new VkApiException('VK вернул некорректный photo id');
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
            throw new VkApiException('VK не вернул post_id');
        }

        return [
            'post_id' => $postId,
            'post_url' => 'https://vk.com/wall-' . $this->groupId . '_' . $postId,
            'attachment' => $attachment,
        ];
    }

    private function uploadPhoto(string $uploadUrl, string $imageFsPath): array
    {
        $ch = curl_init($uploadUrl);
        if ($ch === false) {
            throw new VkApiException('Не удалось инициализировать CURL для upload');
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
            throw new VkApiException('Ошибка загрузки фото: ' . $curlError);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new VkApiException('Ошибка загрузки фото: HTTP ' . $httpCode);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new VkApiException('Некорректный ответ VK upload');
        }

        if (!empty($decoded['error'])) {
            throw new VkApiException('Ошибка загрузки фото: ' . json_encode($decoded['error'], JSON_UNESCAPED_UNICODE));
        }

        return $decoded;
    }

    private function apiRequest(string $method, array $params): array
    {
        $params['access_token'] = $this->accessToken;
        $params['v'] = $this->apiVersion;

        $url = 'https://api.vk.com/method/' . $method;

        $ch = curl_init($url);
        if ($ch === false) {
            throw new VkApiException('Не удалось инициализировать CURL для VK API');
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
            throw new VkApiException('Ошибка VK API: ' . $curlError);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new VkApiException('Некорректный ответ VK API');
        }

        if (isset($decoded['error'])) {
            $errorMessage = (string) ($decoded['error']['error_msg'] ?? 'Неизвестная ошибка VK API');
            throw new VkApiException($errorMessage);
        }

        if (!isset($decoded['response'])) {
            throw new VkApiException('VK API не вернул поле response');
        }

        return (array) $decoded['response'];
    }
}
