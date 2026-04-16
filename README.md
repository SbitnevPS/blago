# art-kids

Сайт приёма заявок для участия в конкурсах!@!

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
- `vk_publication_group_token`
- `vk_publication_api_version`
- `vk_publication_from_group`
- `vk_publication_post_template`

Проверка готовности выполняется через `/auth/vk/publication/test`. Публикационный контур не использует пользовательские VK ID токены.

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
