[English](INSTALLATION.md) | [Русский](INSTALLATION.ru.md)

# Установка и эксплуатация

Рекомендуемый вариант — Docker Compose с готовым образом. TMS использует контейнер приложения, MariaDB, планировщик уведомлений и, при необходимости, worker Telegram Long polling.

## 1. Подготовка конфигурации

Скопируйте пример:

```bash
cp .env.example .env
```

Обязательные или настоятельно рекомендуемые параметры:

| Переменная | Назначение |
| --- | --- |
| `APP_URL` | Публичный URL, например `https://tasks.example.com` |
| `APP_TIMEZONE` | Часовой пояс IANA, например `Asia/Irkutsk` |
| `APP_LOCALE` | Язык интерфейса и начальных справочников: `en` или `ru` |
| `DB_PASS` | Сильный пароль MariaDB |
| `SESSION_SECURE` | `true` при HTTPS; `false` только для осознанного HTTP |
| `TMS_PORT` | HTTP-порт на хосте, по умолчанию `8080` |

Остальные поддерживаемые настройки:

| Переменная | По умолчанию | Значение |
| --- | --- | --- |
| `APP_ENV` | `production` | Окружение приложения |
| `APP_DEBUG` | `false` | Отладочный вывод; в production не включать |
| `DB_NAME` / `DB_USER` | `tms` / `tms` | Учётные данные БД приложения |
| `SESSION_NAME` | `tms_session` | Имя session-cookie |
| `SESSION_SAMESITE` | `Lax` | Политика SameSite |
| `REMEMBER_COOKIE_NAME` | `tms_remember` | Имя cookie «запомнить меня» |
| `REMEMBER_DAYS` | `30` | Срок постоянной авторизации |
| `REGISTRATION_ENABLED` | `false` | Разрешить открытую регистрацию |
| `REGISTRATION_AUTO_APPROVE_AFTER_EMAIL` | `true` | Одобрять после подтверждения email |
| `ATTACHMENT_MAX_BYTES` | `10485760` | Максимальный размер вложения |
| `NOTIFICATION_INTERVAL_SECONDS` | `60` | Интервал Docker-worker уведомлений |
| `NOTIFICATION_SECRET` | пусто | Опциональный внешний base64-ключ 32 байта |
| `TELEGRAM_*` | пусто | Опциональные bootstrap/fallback настройки Telegram |
| `TMS_IMAGE` | текущий stable | Образ для `compose.portainer.yaml` |

`DB_HOST`, `DB_PORT`, `ATTACHMENT_STORAGE_PATH` и `NOTIFICATION_SECRET_FILE` обычно оставляют стандартными. `TMS_SKIP_MIGRATIONS` — внутренняя переменная worker-контейнеров; для `app` её задавать не надо.

## 2. Запуск TMS

Готовый образ:

```bash
docker compose -f compose.portainer.yaml up -d
```

Сборка из текущих исходников:

```bash
docker compose up -d --build
```

Миграции выполняются автоматически при запуске `app`. MariaDB наружу не публикуется.

Проверка состояния:

```bash
docker compose -f compose.portainer.yaml ps
```

## 3. Первый администратор

```bash
docker compose -f compose.portainer.yaml exec app \
  php bin/create-admin.php admin admin@example.com
```

Пароль вводится интерактивно без отображения. Для одноразовой автоматизации существует `TMS_ADMIN_PASSWORD`, но хранить её постоянно в окружении stack не следует.

## 4. Reverse proxy и HTTPS

На reverse proxy направляется только HTTP-порт приложения. TLS завершается на nginx, Caddy, Traefik или другом прокси. При HTTPS оставляйте `SESSION_SECURE=true`.

Минимальный пример nginx:

```nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
}
```

`APP_URL` должен содержать адрес, который открывает пользователь в браузере, а не имя Docker-сервиса.

## Portainer

Создайте Stack из `compose.portainer.yaml` либо вставьте его содержимое. В Environment задайте `APP_URL`, `APP_TIMEZONE`, `APP_LOCALE`, `DB_PASS`, `SESSION_SECURE` и при необходимости `TMS_PORT`.

Файл по умолчанию закреплён на `ghcr.io/mirivlad/tms:v0.2.7`. `TMS_IMAGE` нужен только если вы сознательно выбираете другую версию, `latest`, `edge` или неизменяемый `sha-*`.

После запуска откройте Console контейнера `app` и выполните `php bin/create-admin.php ...`.

## Данные и резервное копирование

Стандартный stack использует три именованных volume:

- `tms-db` — MariaDB;
- `tms-attachments` — вложения;
- `tms-secrets` — автоматически созданный ключ шифрования уведомлений.

Резервируйте **все три**. Dump БД без вложений — неполный backup. БД с зашифрованными SMTP/Telegram-данными без соответствующего ключа не сможет их расшифровать.

Пример согласованного dump БД:

```bash
docker compose -f compose.portainer.yaml exec -T db \
  sh -c 'mariadb-dump -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' > tms.sql
```

Volumes сохраняйте привычным для вашей инфраструктуры способом и обязательно проверяйте восстановление.

Если задан внешний `NOTIFICATION_SECRET`, резервировать необходимо его. Не меняйте и не удаляйте этот ключ без готовности заново ввести зашифрованные реквизиты уведомлений.

## Обновление

Для фиксированной версии измените `TMS_IMAGE` или default image в compose, затем:

```bash
docker compose -f compose.portainer.yaml pull
docker compose -f compose.portainer.yaml up -d
```

Миграции применятся автоматически. При обычном обновлении **не удаляйте** volumes БД, вложений и секретов.

Перед обновлением прочитайте release notes и сделайте backup. Если важен предсказуемый rollback, используйте версионный тег, а не `latest`.

## Откат

Приложение можно вернуть на предыдущий image tag. Но миграции БД рассчитаны на движение вперёд; при несовместимом изменении схемы полноценный rollback требует восстановления соответствующего backup БД. Простая смена образа не всегда достаточна.

## Нативная установка

Она пригодна прежде всего для разработки; эталонный runtime — Docker/Apache. Для нативных уведомлений запускайте `php bin/notify.php` через cron, а при Long polling — `php bin/telegram-poll.php` как постоянно работающий supervised process. На одну БД должен работать только один polling worker.
