<?php

declare(strict_types=1);

namespace App\Support;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

/**
 * Envío de correo por SMTP autenticado (PHPMailer).
 *
 * La configuración SMTP se lee de variables de entorno (.env):
 *   SMTP_HOST, SMTP_PORT, SMTP_SECURE (ssl|tls), SMTP_USER, SMTP_PASS,
 *   SMTP_FROM, SMTP_FROM_NAME.
 */
final class Mailer
{
    /**
     * Envía un correo HTML.
     *
     * @throws RuntimeException si el SMTP no está configurado o falla el envío.
     */
    public function enviar(string $paraEmail, string $paraNombre, string $asunto, string $html, string $texto = ''): void
    {
        $host = (string) ($_ENV['SMTP_HOST'] ?? '');
        $user = (string) ($_ENV['SMTP_USER'] ?? '');
        $pass = (string) ($_ENV['SMTP_PASS'] ?? '');

        if ($host === '' || $user === '' || $pass === '') {
            throw new RuntimeException('SMTP no configurado (revisar SMTP_HOST/SMTP_USER/SMTP_PASS).');
        }

        $secure = strtolower((string) ($_ENV['SMTP_SECURE'] ?? 'ssl'));
        $port   = (int) ($_ENV['SMTP_PORT'] ?? ($secure === 'tls' ? 587 : 465));
        $from   = (string) ($_ENV['SMTP_FROM'] ?? $user);
        $fromN  = (string) ($_ENV['SMTP_FROM_NAME'] ?? 'Hospital Antonio Lorena');

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = $host;
            $mail->SMTPAuth   = true;
            $mail->Username   = $user;
            $mail->Password   = $pass;
            $mail->Port       = $port;
            $mail->CharSet    = PHPMailer::CHARSET_UTF8;
            $mail->SMTPSecure = $secure === 'tls'
                ? PHPMailer::ENCRYPTION_STARTTLS
                : PHPMailer::ENCRYPTION_SMTPS;

            $mail->setFrom($from, $fromN);
            $mail->addAddress($paraEmail, $paraNombre);

            $mail->isHTML(true);
            $mail->Subject = $asunto;
            $mail->Body    = $html;
            $mail->AltBody = $texto !== '' ? $texto : strip_tags($html);

            $mail->send();
        } catch (PHPMailerException $e) {
            // No exponer detalles internos del SMTP al cliente.
            throw new RuntimeException('No se pudo enviar el correo: ' . $e->getMessage(), 0, $e);
        }
    }
}
