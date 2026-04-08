# Модуль публикации работ в VK (админ-панель)

## Назначение

Модуль позволяет администратору сформировать задание на публикацию работ участников и публиковать элементы по одному или всем пакетом в сообщество VK.

## Авторизация VK OAuth (authorization_code, legacy oauth.vk.com)

Для публикации поста с изображением используется **пользовательский токен** администратора сообщества VK, полученный через legacy OAuth на `oauth.vk.com` по схеме `authorization_code`.

> Важно: токен сообщества (group token) для цепочки загрузки изображения на стену не подходит и приводит к ошибке `method is unavailable with group auth`.

### Маршруты подключения

- `POST /auth/vk/publication/start` — старт OAuth, генерация `state`, `code_verifier`, `code_challenge`;
- `GET /auth/vk/publication/callback` — callback, валидация `state`, обмен `code` на токены, сохранение подключения;
- `POST /auth/vk/publication/test` — проверка готовности подключения для публикации;
- `POST /auth/vk/publication/disconnect` — отключение VK-подключения.

На этапе `POST /auth/vk/publication/start` добавлен диагностический лог с полями:

- `client_id`
- `redirect_uri`
- `authorize_endpoint`
- `flow_mode`

`client_secret` в лог не пишется.

### Проверка настроек VK-приложения (критично при 401 на authorize)

Если авторизация падает прямо на `https://oauth.vk.com/authorize` с `401 Unauthorized` (до callback), это означает, что VK отклоняет authorize-запрос. Проверьте:

1. Приложение VK настроено под тот же сценарий, который использует сайт: **legacy OAuth на `oauth.vk.com`**, flow `authorization_code`.
2. В настройках приложения VK заданы точные значения:
   - `Authorized redirect URI = https://konkurs.tolkodobroe.info/auth/vk/publication/callback`
   - `Website address = https://konkurs.tolkodobroe.info`
   - `Base domain = konkurs.tolkodobroe.info`
3. `client_id` и `client_secret` взяты из **одного и того же** VK-приложения.
4. Приложение включено и доступно всем (не ограничено только владельцем/тестерами).
5. Не используется неподходящий тип приложения (например, VK ID-only) при авторизации через legacy `oauth.vk.com`.

### Какие настройки используются

В системных настройках (`storage/settings.json`) сохраняются:

- `vk_publication_user_token` — пользовательский access token для публикации (маскируется в UI);
- `vk_publication_refresh_token` — refresh token (если выдан);
- `vk_publication_group_id` — ID сообщества VK;
- `vk_publication_api_version` — версия VK API (по умолчанию `5.131`);
- `vk_publication_from_group` — публиковать ли от имени сообщества;
- `vk_publication_post_template` — шаблон текста публикации.
- `vk_publication_oauth_user_id`, `vk_publication_oauth_user_name` — данные подключенного VK-аккаунта;
- `vk_publication_token_obtained_at`, `vk_publication_token_expires_at` — сроки токена;
- `vk_publication_last_checked_at`, `vk_publication_last_check_status`, `vk_publication_last_check_message` — результат последней проверки.

Для обратной совместимости читается также старый ключ `vk_publication_access_token`.

### Требуемые права токена

Минимальный набор: `wall`, `photos`, `groups`, `offline`.

## Цепочка публикации изображения + текста

Перед публикацией выполняется проверка готовности:

1. подключен ли VK;
2. не пустой ли токен;
3. не истек ли токен (при наличии `refresh_token` выполняется попытка обновления);
4. указан ли `group_id`;
5. проходят ли базовые вызовы `users.get` и `groups.getById`.

Если проверка не проходит, публикация не запускается и администратор получает читаемую ошибку (`VK не подключён`, `Токен VK истёк, требуется переподключение`, `Не указан ID сообщества`, `Недостаточно прав для публикации изображений`).

Для каждого элемента задания вызывается последовательность:

1. `users.get` — проверка, что токен пользовательский и валиден;
2. `photos.getWallUploadServer`;
3. загрузка файла на `upload_url`;
4. `photos.saveWallPhoto`;
5. `wall.post` с `attachments=photo{owner_id}_{photo_id}`.

Результат успешной публикации:

- `vk_post_id`;
- `vk_post_url`;
- `published_at`.

## Таблицы и данные

### `vk_publication_tasks`

Хранит задания публикации:

- заголовок, режим, фильтры;
- агрегированные счетчики (`total_items`, `published_items`, `failed_items` и т.д.);
- статус задания.

### `vk_publication_task_items`

Хранит элементы задания:

- ссылка на работу и участника;
- `post_text`, путь к изображению;
- `item_status`;
- `vk_post_id`, `vk_post_url`;
- `error_message` (читаемая ошибка для UI);
- `technical_error` (технические детали для диагностики).

### `works`

Синхронизируются поля публикации:

- `vk_published_at`;
- `vk_post_id`;
- `vk_post_url`;
- `vk_publish_error`.

## Статусы

### Статусы задания (`vk_publication_tasks.task_status`)

- `draft`
- `ready`
- `publishing`
- `published`
- `partially_failed`
- `failed`
- `archived`

### Статусы элемента (`vk_publication_task_items.item_status`)

- `pending`
- `ready`
- `published`
- `failed`
- `skipped`

## Обработка ошибок

Ошибки VK нормализуются до понятных сообщений для администратора:

- неверный/неподходящий токен;
- недостаточно прав;
- запрет публикации на стене;
- некорректные параметры запроса.

Если VK возвращает `method is unavailable with group auth`, показывается объяснение, что вставлен токен сообщества и нужен пользовательский токен администратора.

Технические детали ошибки сохраняются в `technical_error`, а в интерфейсе выводится читаемая причина с возможностью открыть детали.

## Бизнес-логика заданий

Существующий цикл работы сохранен:

- создание задания;
- предпросмотр;
- публикация одного элемента;
- публикация всего задания;
- повтор `failed`;
- исключение элементов из задания;
- поддержка статусов `published / failed / skipped`.
