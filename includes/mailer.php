<?php

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

function mailerLog(string $event, array $context = []): void {
    $safe = [
        'to' => (string)($context['to'] ?? ''),
        'subject' => (string)($context['subject'] ?? ''),
        'smtp_host' => (string)($context['smtp_host'] ?? ''),
        'smtp_port' => (string)($context['smtp_port'] ?? ''),
        'error' => (string)($context['error'] ?? ''),
        'embedded_images_requested' => (int)($context['embedded_images_requested'] ?? 0),
        'embedded_images_added' => (int)($context['embedded_images_added'] ?? 0),
        'embedded_images_skipped' => (int)($context['embedded_images_skipped'] ?? 0),
        'embedded_image_name' => (string)($context['embedded_image_name'] ?? ''),
        'embedded_image_cid' => (string)($context['embedded_image_cid'] ?? ''),
        'embedded_image_reason' => (string)($context['embedded_image_reason'] ?? ''),
        'embedded_images_skipped_details' => array_values(array_map('strval', (array)($context['embedded_images_skipped_details'] ?? []))),
    ];

    error_log('[MAILER] ' . $event . ' ' . json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function detectMailFileMimeType(string $path): string
{
    $mimeType = '';

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detected = finfo_file($finfo, $path);
            if (is_string($detected)) {
                $mimeType = trim($detected);
            }
            finfo_close($finfo);
        }
    }

    if ($mimeType !== '') {
        return $mimeType;
    }

    $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    return match ($extension) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        default => 'application/octet-stream',
    };
}

function buildEmailSettings(array $overrides = []): array {
    $settings = array_merge(getSystemSettings(), $overrides);

    $fromAddress = trim((string)($settings['email_from_address'] ?? ''));
    if (!filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
        $fromAddress = 'no-reply@kids-contests.ru';
    }

    $replyTo = trim((string)($settings['email_reply_to'] ?? ''));
    if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $replyTo = '';
    }

    $encryption = trim((string)($settings['email_smtp_encryption'] ?? 'none'));
    if (!in_array($encryption, ['none', 'ssl', 'tls'], true)) {
        $encryption = 'none';
    }

    return [
        'notifications_enabled' => (int)($settings['email_notifications_enabled'] ?? 1) === 1,
        'from_name' => trim((string)($settings['email_from_name'] ?? 'КОНКУРСЫ/ПРОЕКТЫ - ИА ДОБРОЕ ИНФО')),
        'from_address' => $fromAddress,
        'reply_to' => $replyTo,
        'use_smtp' => (int)($settings['email_use_smtp'] ?? 0) === 1,
        'smtp_host' => trim((string)($settings['email_smtp_host'] ?? '')),
        'smtp_port' => max(1, (int)($settings['email_smtp_port'] ?? 465)),
        'smtp_encryption' => $encryption,
        'smtp_auth_enabled' => (int)($settings['email_smtp_auth_enabled'] ?? 1) === 1,
        'smtp_username' => trim((string)($settings['email_smtp_username'] ?? '')),
        'smtp_password' => (string)($settings['email_smtp_password'] ?? ''),
        'smtp_timeout' => max(1, (int)($settings['email_smtp_timeout'] ?? 15)),
    ];
}

/**
 * @param string|array<int,string> $to
 * @param array<string,mixed> $options
 */
function sendEmail($to, string $subject, string $html, array $options = []): bool {
    $settings = buildEmailSettings((array)($options['settings_override'] ?? []));
    if (!$settings['notifications_enabled']) {
        return false;
    }

    $recipients = is_array($to) ? $to : [$to];
    $recipients = array_values(array_filter(array_map('trim', $recipients), static function ($email): bool {
        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);
    }));

    if (empty($recipients)) {
        return false;
    }

    if (!class_exists(PHPMailer::class)) {
        mailerLog('send_failed', [
            'to' => implode(',', $recipients),
            'subject' => $subject,
            'smtp_host' => $settings['smtp_host'],
            'smtp_port' => (string)$settings['smtp_port'],
            'error' => 'PHPMailer not installed',
        ]);
        return false;
    }

    $plainText = trim((string)($options['text'] ?? ($options['alt_body'] ?? '')));
    if ($plainText === '') {
        $plainText = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)));
    }

    $attachments = is_array($options['attachments'] ?? null) ? $options['attachments'] : [];
    $embeddedImages = is_array($options['embedded_images'] ?? null) ? $options['embedded_images'] : [];

    $mail = new PHPMailer(true);
    try {
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($settings['from_address'], $settings['from_name']);
        if ($settings['reply_to'] !== '') {
            $mail->addReplyTo($settings['reply_to']);
        }

        foreach ($recipients as $email) {
            $mail->addAddress($email);
        }

        if ($settings['use_smtp']) {
            $mail->isSMTP();
            $mail->SMTPDebug = 0;
            $mail->Host = $settings['smtp_host'];
            $mail->Port = (int)$settings['smtp_port'];
            $mail->SMTPAuth = $settings['smtp_auth_enabled'];
            $mail->Timeout = (int)$settings['smtp_timeout'];
            if ($settings['smtp_encryption'] !== 'none') {
                $mail->SMTPSecure = $settings['smtp_encryption'];
            }
            if ($settings['smtp_auth_enabled']) {
                $mail->Username = $settings['smtp_username'];
                $mail->Password = $settings['smtp_password'];
            }
        } else {
            $mail->isMail();
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $plainText;

        $embeddedImagesRequested = count($embeddedImages);
        $embeddedImagesAdded = 0;
        $embeddedImagesSkippedDetails = [];

        foreach ($embeddedImages as $embeddedImage) {
            if (!is_array($embeddedImage)) {
                $embeddedImagesSkippedDetails[] = 'invalid_definition';
                continue;
            }

            $path = trim((string)($embeddedImage['path'] ?? ''));
            $cid = trim((string)($embeddedImage['cid'] ?? ''));
            $name = trim((string)($embeddedImage['name'] ?? ''));
            $displayName = $name !== '' ? $name : basename($path);

            if ($path === '') {
                $embeddedImagesSkippedDetails[] = 'missing_path:' . $displayName;
                continue;
            }

            if ($cid === '') {
                $embeddedImagesSkippedDetails[] = 'missing_cid:' . $displayName;
                continue;
            }

            if (!is_file($path) || !is_readable($path)) {
                $embeddedImagesSkippedDetails[] = 'missing_file:' . $displayName . ' [' . $cid . ']';
                continue;
            }

            $mimeType = detectMailFileMimeType($path);
            if (strpos($mimeType, 'image/') !== 0) {
                $embeddedImagesSkippedDetails[] = 'invalid_mime:' . $displayName . ' [' . $cid . ']';
                continue;
            }

            try {
                $mail->addEmbeddedImage(
                    $path,
                    $cid,
                    $name !== '' ? $name : basename($path),
                    PHPMailer::ENCODING_BASE64,
                    $mimeType,
                    'inline'
                );
                $embeddedImagesAdded++;
            } catch (Throwable $e) {
                $embeddedImagesSkippedDetails[] = 'phpmailer_error:' . $displayName . ' [' . $cid . ']';
                mailerLog('embedded_image_add_failed', [
                    'to' => implode(',', $recipients),
                    'subject' => $subject,
                    'smtp_host' => $settings['smtp_host'],
                    'smtp_port' => (string)$settings['smtp_port'],
                    'embedded_image_name' => $displayName,
                    'embedded_image_cid' => $cid,
                    'embedded_image_reason' => 'phpmailer_exception',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($embeddedImagesRequested > 0) {
            mailerLog('embedded_images_processed', [
                'to' => implode(',', $recipients),
                'subject' => $subject,
                'smtp_host' => $settings['smtp_host'],
                'smtp_port' => (string)$settings['smtp_port'],
                'embedded_images_requested' => $embeddedImagesRequested,
                'embedded_images_added' => $embeddedImagesAdded,
                'embedded_images_skipped' => count($embeddedImagesSkippedDetails),
                'embedded_images_skipped_details' => $embeddedImagesSkippedDetails,
            ]);
        }

        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            $path = trim((string)($attachment['path'] ?? ''));
            if ($path === '' || !is_file($path)) {
                continue;
            }
            $name = trim((string)($attachment['name'] ?? ''));
            $mail->addAttachment($path, $name !== '' ? $name : basename($path));
        }

        $sent = $mail->send();
        if ($sent) {
            mailerLog('send_success', [
                'to' => implode(',', $recipients),
                'subject' => $subject,
                'smtp_host' => $settings['smtp_host'],
                'smtp_port' => (string)$settings['smtp_port'],
                'embedded_images_requested' => $embeddedImagesRequested ?? 0,
                'embedded_images_added' => $embeddedImagesAdded ?? 0,
                'embedded_images_skipped' => isset($embeddedImagesSkippedDetails) ? count($embeddedImagesSkippedDetails) : 0,
            ]);
        }

        return $sent;
    } catch (Throwable $e) {
        mailerLog('send_failed', [
            'to' => implode(',', $recipients),
            'subject' => $subject,
            'smtp_host' => $settings['smtp_host'],
            'smtp_port' => (string)$settings['smtp_port'],
            'embedded_images_requested' => isset($embeddedImages) ? count($embeddedImages) : 0,
            'error' => $e->getMessage(),
        ]);
        return false;
    }
}
