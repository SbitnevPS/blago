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

## VK OAuth для публикации работ (важно)

Модуль публикаций использует legacy OAuth authorize endpoint `https://oauth.vk.com/authorize` и callback:

- `https://konkurs.tolkodobroe.info/auth/vk/publication/callback`

Для корректной работы в кабинете VK приложения должны быть выставлены:

- `Authorized redirect URI = https://konkurs.tolkodobroe.info/auth/vk/publication/callback`
- `Website address = https://konkurs.tolkodobroe.info`
- `Base domain = konkurs.tolkodobroe.info`

Если на `oauth.vk.com/authorize` приходит `401 Unauthorized`, сначала проверьте эти поля, пару `client_id/client_secret` от одного приложения, доступность приложения и тип приложения (не VK ID-only для legacy authorize).

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
