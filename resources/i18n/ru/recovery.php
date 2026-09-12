<?php

declare(strict_types=1);

return [
    'auth.forgot_link' => 'Забыли пароль?',
    'recovery.page_title' => 'Восстановление пароля',
    'recovery.forgot_title' => 'Восстановление доступа',
    'recovery.forgot_help' => 'Введите имя пользователя или email аккаунта. Если доступен канал восстановления, TMS отправит одноразовую ссылку.',
    'recovery.identifier' => 'Имя пользователя или email',
    'recovery.request_submit' => 'Отправить ссылку',
    'recovery.generic_sent' => 'Если аккаунт существует и доступен канал восстановления, ссылка отправлена. Администратор сервера всегда может восстановить доступ локально из консоли TMS.',
    'recovery.back_login' => 'Вернуться ко входу',
    'recovery.reset_title' => 'Новый пароль',
    'recovery.reset_for' => 'Смена пароля для {username}.',
    'recovery.new_password' => 'Новый пароль',
    'recovery.confirm_password' => 'Повторите новый пароль',
    'recovery.reset_submit' => 'Сменить пароль',
    'recovery.invalid_link' => 'Ссылка для смены пароля недействительна, истекла или уже использована.',
    'recovery.password_min' => 'Новый пароль должен содержать не менее 12 символов.',
    'recovery.password_mismatch' => 'Новые пароли не совпадают.',
    'recovery.reset_success' => 'Пароль изменён. Все постоянные токены входа отозваны.',
    'recovery.sign_in' => 'Войти',
    'recovery.request_again' => 'Запросить новую ссылку',
    'recovery.mail_subject' => 'Сброс пароля TMS',
    'recovery.mail_intro' => 'Для вашего аккаунта TMS запрошена смена пароля. Ссылка действует один час.',
    'recovery.mail_text' => "Для вашего аккаунта TMS запрошена смена пароля. Ссылка действует один час:
{url}

Если это были не вы, просто проигнорируйте сообщение.",
    'recovery.telegram_text' => "Сброс пароля TMS. Ссылка действует один час:
{url}

Если это были не вы, просто проигнорируйте сообщение.",
];
