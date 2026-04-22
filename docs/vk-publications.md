# Модуль публикации работ в VK (админ-панель)

## Базовый сценарий

Публикация выполняется в формате **только текст по шаблону** через ключ доступа сообщества (`group access token`).

Используемые настройки:

- `vk_publication_admin_access_token_encrypted`
- `vk_publication_group_id`
- `vk_publication_api_version`
- `vk_publication_from_group`
- `vk_publication_post_template`

Ключ публикации хранится только на сервере в зашифрованном виде.

## Проверка подключения

Проверка (`POST /auth/vk/publication/test`) выполняет:

1. наличие group access token;
2. наличие `group_id`;
3. `groups.getById` (доступ к публикации в сообществе).

## Публикация

Поддерживается только обязательный сценарий: **текст + шаблонные переменные**.

Цепочка VK API: `wall.post` с `owner_id=-GROUP_ID`, `from_group=1`, `message=...`.
