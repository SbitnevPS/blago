# art-kids

Сайт приёма заявок для участия в конкурсах!""

## Структура проекта

```text
/app
  /controllers
  /models
  /views
    /public
    /partials
/includes
/public
```

- `app/views/public` — пользовательские страницы (конкурсы, профиль, сообщения, авторизация и т.д.).
- `app/views/partials` — общие части интерфейса (например, шапка сайта).
- `router.php` — маршрутизатор, который подключает соответствующие view-файлы.
- `docs/vk-publications.md` — документация по модулю заданий публикации работ в VK.

## VK: публикация работ

Публикация работ в VK выполняется только через настройки в `/admin/settings`:

- `vk_publication_group_id`
- `vk_publication_admin_access_token_encrypted`
- `vk_publication_admin_refresh_token_encrypted`
- `vk_publication_admin_user_id`
- `vk_publication_api_version`
- `vk_publication_from_group`
- `vk_publication_post_template`

Подключение токена выполняется отдельным OAuth потоком: `/auth/vk/publication/start` → `/auth/vk/publication/callback`.
Проверка готовности выполняется через `/auth/vk/publication/test`.

## Laravel Valet (если открывается 404)

В корне проекта добавлен `LocalValetDriver.php`, чтобы Valet всегда использовал `index.php` как front controller для этого приложения.

Если снова видите 404 от Valet, выполните:

```bash
valet links
valet unlink blago && valet link blago
valet restart
valet open
```

Если проект открыт из другой папки, перелинкуйте именно текущую директорию проекта.
