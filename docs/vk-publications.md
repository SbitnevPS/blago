# Модуль публикации работ в VK (админ-панель)

## Базовый сценарий

Публикация работ выполняется **только** через сохранённый в настройках ключ доступа сообщества VK:

- `vk_publication_group_id`
- `vk_publication_group_token`
- `vk_publication_api_version`
- `vk_publication_from_group`
- `vk_publication_post_template`

VK ID сессия администратора, OAuth callback, refresh/access user tokens и fallback-режимы не участвуют в публикации работ.

## Проверка подключения

Проверка (`POST /auth/vk/publication/test`) делает только проверки, необходимые для публикации через ключ сообщества:

1. задан ли `group_id`;
2. задан ли `group token`;
3. проходит ли `groups.getById`;
4. доступны ли права публикации (`groups.getTokenPermissions`, `photos.getWallUploadServer`).

Сообщения ошибок в UI нормализуются до понятных формулировок:

- «Не задан ключ доступа сообщества VK»
- «Не указан ID сообщества VK»
- «Ключ доступа сообщества недействителен»
- «Недостаточно прав для публикации на стене сообщества»
- «Не удалось загрузить изображение для публикации»
- «Публикация в VK завершилась ошибкой»

## Отбор работ

В задачу публикации попадают только работы со статусом `accepted` (UI: «Рисунок принят»).

Дополнительно проверяется:

- наличие файла рисунка;
- наличие файла на диске;
- доступность файла для чтения;
- размер файла больше 0;
- исключение уже опубликованных работ (если включён флаг `exclude_vk_published`).

## Формирование текста

Текст поста берётся только из `vk_publication_post_template`.

Поддерживаются переменные:

- `{participant_name}`
- `{participant_full_name}`
- `{organization_name}`
- `{region_name}`
- `{contest_title}`
- `{participant_age}`
- `{age_category}`
- `{work_title}` / `{drawing_title}`

Если шаблон пустой, элемент помечается ошибкой: «Не заполнен шаблон текста публикации в настройках».

## Публикация элемента

Для каждого элемента выполняется публикация на стену сообщества с:

- текстом из шаблона;
- локальным файлом изображения работы.

## Данные в БД

Используются существующие таблицы:

- `vk_publication_tasks`
- `vk_publication_task_items`
- `works`

После успеха:

- `vk_publication_task_items.item_status = published`
- `vk_publication_task_items.vk_post_id`, `vk_post_url`, `published_at`
- `works.vk_published_at`, `vk_post_id`, `vk_post_url`, `vk_publish_error = NULL`

После ошибки:

- `vk_publication_task_items.item_status = failed`
- `vk_publication_task_items.error_message`, `technical_error`
- `works.vk_publish_error`

## Статусы

Сохраняются текущие статусы задач и элементов:

- задачи: `draft`, `ready`, `publishing`, `published`, `partially_failed`, `failed`, `archived`
- элементы: `pending`, `ready`, `published`, `failed`, `skipped`

## UI сценарий

Сохраняется существующий цикл:

- создание задания;
- предпросмотр;
- публикация одного элемента;
- публикация всех готовых элементов;
- повтор `failed`.

Сценарии Donut/Donation не используются в контуре публикации работ.
