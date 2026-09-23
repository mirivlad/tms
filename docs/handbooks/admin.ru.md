# TMS — Полное руководство администратора

Это эксплуатационный учебник для владельца self-hosted установки TMS. Он охватывает развёртывание, обновления, пользователей, системные уведомления, исходящие webhooks, backup/restore, обслуживание и диагностику.

[TOC]

## Что администратор должен знать в первую очередь

Стандартный Docker/Portainer stack состоит из MariaDB и четырёх ролей приложения:

| Сервис | Назначение |
| --- | --- |
| `db` | MariaDB и постоянные данные |
| `app` | Web UI, API, миграции и административные действия |
| `notifier` | Повторяющиеся задачи и доставка внутренних/email/Telegram уведомлений |
| `webhook-worker` | Асинхронная доставка исходящих подписанных webhooks |
| `telegram-poller` | Приём команд Telegram в режиме Long polling |

Критичные постоянные данные образуют единый комплект: `tms-db`, `tms-attachments`, `tms-secrets`. Потеря одного из них может сделать восстановление неполным.

## Каноническое администрирование TMS

{{include:../ADMINISTRATION.ru.md|shift=1|strip_nav}}

## Установка, reverse proxy, обновление и резервное копирование

{{include:../INSTALLATION.ru.md|shift=1|strip_nav}}

## Системные уведомления, SMTP и Telegram

{{include:../notifications.ru.md|shift=1|strip_nav}}

## Исходящие webhooks

{{include:../webhooks.ru.md|shift=1|strip_nav}}

## Восстановление доступа пользователей

{{include:../ACCOUNT_RECOVERY.ru.md|shift=1|strip_nav}}

## Практические runbook'и

### Runbook 1. Первый запуск через Portainer

1. Возьмите `compose.portainer.yaml` из версии TMS, которую собираетесь запускать.
2. Создайте Stack и задайте минимум `APP_URL`, `APP_TIMEZONE`, `APP_LOCALE`, `DB_PASS`, `SESSION_SECURE`.
3. Для воспроизводимого production используйте версионный `TMS_IMAGE`, а не плавающий `edge`.
4. Запустите stack и дождитесь healthy состояния `app`.
5. В Console контейнера `app` создайте первого администратора:

```bash
php bin/create-admin.php admin admin@example.com
```

6. Войдите в UI, проверьте часовой пояс установки и административное меню.
7. Проверьте `app`, `notifier` и `webhook-worker`; `telegram-poller` нужен, когда выбран Long polling.
8. До ввода production-данных настройте регулярный backup трёх persistent volumes.

### Runbook 2. Безопасное обновление

1. Прочитайте release notes новой версии.
2. Сделайте согласованный dump MariaDB и резервную копию `tms-attachments` + `tms-secrets`.
3. Зафиксируйте текущий image tag и время backup.
4. Измените `TMS_IMAGE` на новую версию.
5. Выполните:

```bash
docker compose -f compose.portainer.yaml pull
docker compose -f compose.portainer.yaml up -d
```

6. Проверьте health и миграции, затем войдите в TMS.
7. Проверьте создание/редактирование задачи, scheduler, уведомления и webhooks, если они используются.
8. Не удаляйте volumes во время обычного обновления.

Миграции рассчитаны на движение вперёд. Для полного rollback после несовместимой миграции нужен соответствующий backup БД, а не только старый image tag.

### Runbook 3. Полный backup

Сначала сохраните базу:

```bash
docker compose -f compose.portainer.yaml exec -T db \
  sh -c 'mariadb-dump -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' > tms.sql
```

Затем тем же backup-set сохраните:

- volume `tms-attachments`;
- volume `tms-secrets`;
- используемый compose/.env и image tag;
- внешний `NOTIFICATION_SECRET`, если он задан вместо ключа в volume.

Периодически проверяйте восстановление на отдельной тестовой установке. Backup, который никогда не восстанавливался, нельзя считать проверенным.

### Runbook 4. Восстановление после аварии

1. Не запускайте новую пустую TMS поверх единственных копий старых volumes.
2. Подготовьте версию приложения, совместимую с сохранённой схемой БД.
3. Восстановите `tms-db`, `tms-attachments` и `tms-secrets` как один согласованный комплект.
4. Если храните SQL dump вместо raw volume БД, создайте чистую MariaDB и импортируйте dump вашим стандартным способом.
5. Верните тот же внешний `NOTIFICATION_SECRET`, если он использовался.
6. Запустите stack.
7. Проверьте вход, вложения, системные credentials, scheduler и workers.
8. Только после проверки переключайте production-трафик на восстановленную установку.

### Runbook 5. Добавить или одобрить пользователя

1. Откройте **Администрирование → Пользователи**.
2. При закрытой регистрации создайте пользователя напрямую.
3. При открытой регистрации проверьте ожидающие аккаунты.
4. Подтвердите email или одобрите аккаунт согласно политике установки.
5. Администраторскую роль выдавайте только тем, кому нужны системные настройки.
6. Перед отключением/удалением пользователя убедитесь, что он не последний тимлид команды.

### Runbook 6. Настроить SMTP

1. Откройте **Администрирование → Системные уведомления**.
2. Укажите host, port, encryption, login/password и From.
3. Сохраните.
4. Нажмите **Отправить тестовое письмо SMTP**.
5. Проверьте реальную доставку, а не только отсутствие ошибки в UI.
6. Убедитесь, что `tms-secrets` или внешний `NOTIFICATION_SECRET` попадает в backup.

### Runbook 7. Настроить Telegram

1. Создайте бота через BotFather.
2. Введите bot name/token в системных уведомлениях.
3. Выполните тест соединения.
4. Выберите Long polling для обычной self-hosted установки или Webhook при публичном HTTPS `APP_URL`.
5. Если нужен proxy, задайте корректную схему самого proxy.
6. В пользовательском аккаунте создайте одноразовую link-команду и отправьте её боту.
7. Отправьте тестовое сообщение.
8. Для Long polling проверьте logs `telegram-poller`.

### Runbook 8. Создать исходящий webhook

1. Откройте **Администрирование → Webhooks**.
2. Создайте подписку, задайте endpoint и только необходимые event types.
3. Скопируйте signing secret сразу: после первого показа получить его в открытом виде нельзя.
4. На принимающей стороне проверяйте HMAC-SHA256 для строки `timestamp.body` согласно webhook guide.
5. Создайте тестовое событие в TMS.
6. Проверьте delivery history и HTTP status.
7. При временной ошибке дождитесь retry или используйте ручной retry после исправления endpoint.
8. При компрометации секрета выполните rotation и обновите consumer.

### Runbook 9. Быстрая диагностика workers

Начните с:

```bash
docker compose -f compose.portainer.yaml ps
docker compose -f compose.portainer.yaml logs --tail=150 app
docker compose -f compose.portainer.yaml logs --tail=150 notifier
docker compose -f compose.portainer.yaml logs --tail=150 webhook-worker
docker compose -f compose.portainer.yaml logs --tail=150 telegram-poller
```

Если web UI здоров, но автоматические события не приходят, сначала определите слой:

- нет повторяющихся задач/напоминаний → `notifier`;
- внутреннее событие есть, но webhook не доставлен → `webhook-worker` и delivery history;
- Telegram отправляет, но команды не принимаются → `telegram-poller`/Webhook mode;
- приложение не стартует после обновления → logs `app`, состояние MariaDB и миграции.

### Runbook 10. Минимальный security-чек после изменений

- Production работает с `APP_DEBUG=false`.
- `APP_URL` указывает на реальный внешний HTTPS URL.
- `SESSION_SECURE=true` за HTTPS.
- БД не опубликована наружу без необходимости.
- Backup содержит БД, вложения и secrets.
- Администраторские права выданы минимальному числу аккаунтов.
- SMTP/Telegram/webhook secrets не лежат в открытом виде в issue, chat или репозитории.
- После ротации ключей выполнен реальный тест доставки.
- Для integration endpoints проверяется webhook signature.

## Регулярное обслуживание

Еженедельно или после заметных изменений просматривайте состояние контейнеров и ошибки workers. Перед каждым обновлением делайте backup; после обновления выполняйте короткий smoke по критичным для вашей установки функциям. При изменении deployment-конфигурации сохраняйте её рядом с backup metadata, чтобы аварийное восстановление не зависело от памяти администратора.
