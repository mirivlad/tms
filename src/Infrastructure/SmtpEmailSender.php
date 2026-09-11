<?php

declare(strict_types=1);

namespace Tms\Infrastructure;

use PHPMailer\PHPMailer\PHPMailer;
use Throwable;
use Tms\Domain\Notification\SmtpSettingsRepository;

final class SmtpEmailSender implements EmailSender
{
    public function __construct(
        private readonly SmtpSettingsRepository $settings,
        private readonly ?SecretBox $secretBox,
    ) {
    }

    public function send(string $toEmail, string $toName, string $subject, string $html, string $text): bool
    {
        $settings = $this->settings->get();
        if ($settings === null || !$settings->enabled) {
            return false;
        }
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $settings->host;
            $mail->Port = $settings->port;
            $mail->CharSet = 'UTF-8';
            $mail->SMTPAuth = $settings->username !== '';
            $mail->Username = $settings->username;
            if ($settings->passwordCiphertext !== null && $settings->passwordCiphertext !== '') {
                if ($this->secretBox === null) {
                    return false;
                }
                $mail->Password = $this->secretBox->decrypt($settings->passwordCiphertext);
            }
            $mail->SMTPSecure = $settings->encryption;
            $mail->setFrom($settings->fromEmail, $settings->fromName);
            $mail->addAddress($toEmail, $toName);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $html;
            $mail->AltBody = $text;
            return $mail->send();
        } catch (Throwable $error) {
            error_log('TMS email notification failed: ' . $error->getMessage());
            return false;
        }
    }
}
