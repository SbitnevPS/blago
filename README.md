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

## VK Web OAuth (отдельное приложение)

Для авторизации используется **классический Web OAuth code flow** VK:
- authorize: `https://oauth.vk.com/authorize`
- `response_type=code`
- callback flow в PHP остаётся прежним: `/vk-auth` и `/admin/vk-auth.php`

Перед запуском укажите в `config.php` значения от **отдельного VK web OAuth приложения**:
- `VK_CLIENT_ID`
- `VK_CLIENT_SECRET`

И добавьте в разрешённые redirect URL приложения VK:
- `https://konkurs.tolkodobroe.info/vk-auth`
- `https://konkurs.tolkodobroe.info/admin/vk-auth.php`

В проекте не используется VK ID SDK для входа пользователя.
