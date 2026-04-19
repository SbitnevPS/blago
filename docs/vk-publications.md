# Модуль публикации работ в VK (админ-панель)

## Базовый сценарий

Публикация работ выполняется только через OAuth VK Business / VK ID в режиме `admin_user_token`.

Используемые настройки:

- `vk_publication_admin_access_token_encrypted`
- `vk_publication_admin_refresh_token_encrypted`
- `vk_publication_admin_token_expires_at`
- `vk_publication_admin_user_id`
- `vk_publication_group_id`
- `vk_publication_api_version`
- `vk_publication_from_group`
- `vk_publication_post_template`

Токены публикации хранятся только на сервере в зашифрованном виде.

## OAuth для публикации

Отдельный поток авторизации:

- `POST /auth/vk/publication/start`
- `GET /auth/vk/publication/callback`

Запрашиваемый scope: `wall,photos,offline`.

После callback выполняется серверная валидация прав. Если проверка не пройдена, токен не считается рабочим.

## Проверка подключения

Проверка (`POST /auth/vk/publication/test`) выполняет:

1. наличие user access token;
2. наличие `group_id`;
3. `groups.getById` (пользователь — владелец/админ);
4. `photos.getWallUploadServer` (доступность загрузки изображений).

## Публикация

Поддерживается только обязательный сценарий: **изображение + текст**.

Цепочка VK API:

1. `photos.getWallUploadServer`
2. upload файла
3. `photos.saveWallPhoto`
4. `wall.post` с `owner_id=-GROUP_ID`, `from_group=1`, `attachments=photo...`

Без изображения публикация не выполняется.
