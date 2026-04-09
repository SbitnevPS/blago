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

## VK: два независимых сценария

- Вход пользователей: `VK ID login` через `/auth/vk/user/start` и `/auth/vk/user/callback`.
- Публикация работ: отдельный ручной `publication token` в `/admin/settings` + проверка через `/auth/vk/publication/test`.

Публикация не использует OAuth callback и не зависит от пользовательского VK ID login.

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
