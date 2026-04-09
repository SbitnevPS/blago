# Конкурс детских рисунков - Спецификация:

## 1. Обзор проекта

**Название:** ДетскиеКонкурсы.рф  
**Тип:** Веб-приложение для проведения конкурсов детских рисунков  
**Целевая аудитория:** Родители, образовательные учреждения, кураторы конкурсов

## 2. Технологический стек

- **Backend:** PHP 8.2 + MySQL
- **Frontend:** HTML5, CSS3, JavaScript (ES6+)
- **Аутентификация:** VK.com API
- **Редактор текста:** TinyMCE (Joomla 5)
- **CSS Framework:** Собственная система (минимализм, светлые тона)

## 3. Структура базы данных

### users - Пользователи
| Поле | Тип | Описание |
|------|-----|----------|
| id | INT (PK, AI) | ID пользователя |
| vk_id | VARCHAR(50) | ID в VK |
| vk_access_token | TEXT | Токен доступа VK |
| name | VARCHAR(100) | Имя |
| surname | VARCHAR(100) | Фамилия |
| email | VARCHAR(255) | Email |
| avatar_url | TEXT | URL аватарки |
| created_at | DATETIME | Дата регистрации |
| updated_at | DATETIME | Дата обновления |

### contests - Конкурсы
| Поле | Тип | Описание |
|------|-----|----------|
| id | INT (PK, AI) | ID конкурса |
| title | VARCHAR(255) | Название |
| description | TEXT | Описание (TinyMCE) |
| document_file | VARCHAR(255) | Файл положения (docx/doc/pdf) |
| is_published | TINYINT(1) | Опубликован (0/1) |
| date_from | DATE | Дата начала |
| date_to | DATE | Дата окончания (nullable) |
| created_at | DATETIME | Дата создания |
| updated_at | DATETIME | Дата обновления |

### applications - Заявки
| Поле | Тип | Описание |
|------|-----|----------|
| id | INT (PK, AI) | ID заявки |
| user_id | INT (FK) | Пользователь |
| contest_id | INT (FK) | Конкурс |
| status | ENUM('draft','submitted') | Статус |
| parent_fio | VARCHAR(255) | ФИО родителя/куратора |
| source_info | VARCHAR(255) | Откуда узнали о конкурсе |
| colleagues_info | VARCHAR(255) | Информация о коллегах |
| recommendations_wishes | TEXT | Рекомендации и пожелания участника |
| payment_receipt | VARCHAR(255) | Файл квитанции |
| created_at | DATETIME | Дата создания |
| updated_at | DATETIME | Дата обновления |

### participants - Участники
| Поле | Тип | Описание |
|------|-----|----------|
| id | INT (PK, AI) | ID участника |
| application_id | INT (FK) | Заявка |
| fio | VARCHAR(255) | ФИО участника |
| age | INT | Возраст |
| region | VARCHAR(255) | Регион |
| organization_name | VARCHAR(255) | Название организации |
| organization_address | TEXT | Адрес организации |
| organization_email | VARCHAR(255) | Email организации |
| leader_fio | VARCHAR(255) | ФИО руководителя |
| curator_1_fio | VARCHAR(255) | ФИО куратора 1 |
| curator_2_fio | VARCHAR(255) | ФИО куратора 2 |
| drawing_file | VARCHAR(255) | Файл рисунка |
| created_at | DATETIME | Дата создания |

## 4. Страницы и функционал

### 4.1. Главная страница (index.php)
- Приветствие
- Кнопка "Войти через VK"
- Список конкурсов (если авторизован)

### 4.2. Страница конкурсов (contests.php)
- Сетка карточек конкурсов (2 колонки, 350px высота)
- Краткое название
- Кнопки: "Подробнее", "Отправить заявку"

### 4.3. Подробнее о конкурсе (contest-view.php)
- Полное описание (TinyMCE)
- Кнопка скачивания документа положения

### 4.4. Заявка на участие (application-form.php)
- Форма с динамическим добавлением участников
- Загрузка рисунков
- Сохранение/отправка

### 4.5. Список заявок пользователя (my-applications.php)
- Карточки заявок
- Статус, дата, количество участников

### 4.6. Просмотр заявки (application-view.php)
- Полное содержимое заявки
- Информация об участниках

### 4.7. Админ-панель
- /admin/users - Список пользователей
- /admin/contests - Управление конкурсами
- /admin/applications - Список заявок
- /admin/contest-edit.php - Редактирование конкурса

## 5. Дизайн

### Цветовая палитра
- **Основной:** #FFFFFF (белый)
- **Фон:** #F8F9FA (светло-серый)
- **Акцент:** #6C63FF (фиолетовый)
- **Текст:** #2D3436 (тёмно-серый)
- **Второстепенный:** #636E72 (серый)
- **Успех:** #00B894 (зелёный)
- **Ошибка:** #E74C3C (красный)

### Типографика
- **Заголовки:** 'Inter', sans-serif
- **Основной текст:** 'Inter', sans-serif
- **Размеры:** H1: 32px, H2: 24px, H3: 18px, Body: 15px

### Стиль
- Минимализм
- Светлые тона
- Скруглённые углы (12px)
- Тени: 0 4px 20px rgba(0,0,0,0.08)
- Отступы: 20px

## 6. API VK авторизация

### Этапы:
1. Перенаправление на VK OAuth
2. Получение code
3. Обмен на access_token
4. Получение данных пользователя
5. Сохранение/обновление в БД

## 7. Структура файлов

```
/var/www/eco-kids/
├── config.php
├── index.php
├── contests.php
├── contest-view.php
├── application-form.php
├── application-view.php
├── my-applications.php
├── login.php
├── logout.php
├── api/
│   ├── vk-auth.php
│   ├── application.php
│   └── contest.php
├── admin/
│   ├── index.php
│   ├── login.php
│   ├── users.php
│   ├── contests.php
│   ├── contest-edit.php
│   ├── applications.php
│   ├── application-view.php
│   └── includes/
│       ├── header.php
│       ├── sidebar.php
│       └── footer.php
├── css/
│   └── style.css
├── js/
│   └── main.js
├── uploads/
│   ├── drawings/
│   └── documents/
└── images/
```
