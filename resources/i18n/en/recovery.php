<?php

declare(strict_types=1);

return [
    'auth.forgot_link' => 'Forgot password?',
    'recovery.page_title' => 'Password recovery',
    'recovery.forgot_title' => 'Recover access',
    'recovery.forgot_help' => 'Enter your username or account email. If a recovery channel is available, TMS will send a one-time link.',
    'recovery.identifier' => 'Username or email',
    'recovery.request_submit' => 'Send recovery link',
    'recovery.generic_sent' => 'If the account exists and a recovery channel is available, a reset link has been sent. A server administrator can always recover access locally from the TMS console.',
    'recovery.back_login' => 'Back to sign in',
    'recovery.reset_title' => 'Set a new password',
    'recovery.reset_for' => 'Resetting password for {username}.',
    'recovery.new_password' => 'New password',
    'recovery.confirm_password' => 'Confirm new password',
    'recovery.reset_submit' => 'Change password',
    'recovery.invalid_link' => 'This password-reset link is invalid, expired, or has already been used.',
    'recovery.password_min' => 'The new password must contain at least 12 characters.',
    'recovery.password_mismatch' => 'The new passwords do not match.',
    'recovery.reset_success' => 'Password changed. Existing persistent-login tokens were revoked.',
    'recovery.sign_in' => 'Sign in',
    'recovery.request_again' => 'Request another reset link',
    'recovery.mail_subject' => 'TMS password reset',
    'recovery.mail_intro' => 'A password reset was requested for your TMS account. The link is valid for one hour.',
    'recovery.mail_text' => "A password reset was requested for your TMS account. This link is valid for one hour:
{url}

If you did not request it, ignore this message.",
    'recovery.telegram_text' => "TMS password reset. The link is valid for one hour:
{url}

If you did not request it, ignore this message.",
];
