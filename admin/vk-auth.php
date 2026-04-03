<?php
// api/vk-auth.php - Callback обработчик для VK OAuth
require_once __DIR__ . '/../config.php';

// Заголовок JSON
header('Content-Type: application/json');

// Если это код из VK
if (isset($_GET['code']) && isset($_GET['state']) && $_GET['state'] === 'vk_auth') {
    $code = $_GET['code'];
    
    // Обмен кода на токен
    $tokenUrl = 'https://oauth.vk.com/access_token';
    $params = [
        'client_id' => VK_CLIENT_ID,
        'client_secret' => VK_CLIENT_SECRET,
        'redirect_uri' => VK_REDIRECT_URI,
        'code' => $code
    ];
    
    $response = file_get_contents($tokenUrl . '?' . http_build_query($params));
    $tokenData = json_decode($response, true);
    
    if (isset($tokenData['access_token'])) {
        // Получение данных пользователя
        $userUrl = 'https://api.vk.com/method/users.get';
        $userParams = [
            'access_token' => $tokenData['access_token'],
            'v' => VK_API_VERSION,
            'fields' => 'photo_100,email'
        ];
        
        $userResponse = file_get_contents($userUrl . '?' . http_build_query($userParams));
        $userData = json_decode($userResponse, true);
        
        if (isset($userData['response'][0])) {
            $vkUser = $userData['response'][0];
            
            // Проверяем, есть ли пользователь в базе
            $stmt = $pdo->prepare("SELECT * FROM users WHERE vk_id = ?");
            $stmt->execute([$vkUser['id']]);
            $existingUser = $stmt->fetch();
            
            if ($existingUser) {
                // Обновляем данные
                $stmt = $pdo->prepare("
                    UPDATE users SET 
                        vk_access_token = ?,
                        name = ?,
                        surname = ?,
                        avatar_url = ?,
                        email = ?,
                        updated_at = NOW()
                    WHERE vk_id = ?
                ");
                $stmt->execute([
                    $tokenData['access_token'],
                    $vkUser['first_name'],
                    $vkUser['last_name'] ?? '',
                    $vkUser['photo_100'] ?? '',
                    $tokenData['email'] ?? $existingUser['email'],
                    $vkUser['id']
                ]);
                
                $userId = $existingUser['id'];
            } else {
                // Создаём нового пользователя
                $stmt = $pdo->prepare("
                    INSERT INTO users (vk_id, vk_access_token, name, surname, avatar_url, email)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $vkUser['id'],
                    $tokenData['access_token'],
                    $vkUser['first_name'],
                    $vkUser['last_name'] ?? '',
                    $vkUser['photo_100'] ?? '',
                    $tokenData['email'] ?? ''
                ]);
                
                $userId = $pdo->lastInsertId();
            }
            
            // Устанавливаем сессию
            $_SESSION['user_id'] = $userId;
            $_SESSION['vk_token'] = $tokenData['access_token'];
            
            echo json_encode([
                'success' => true,
                'redirect' => SITE_URL . '/'
            ]);
            exit;
        }
    }
    
    echo json_encode([
        'success' => false,
        'message' => 'Не удалось получить данные пользователя'
    ]);
    exit;
}

echo json_encode([
    'success' => false,
    'message' => 'Неверный запрос'
]);
