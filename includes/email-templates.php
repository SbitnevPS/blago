<?php

function emailTemplateEscape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function getDiplomaEmailTypeLabel(string $diplomaType): string
{
    return match ($diplomaType) {
        'encouragement' => 'Благодарственный диплом',
        'winner' => 'Диплом победителя',
        'laureate' => 'Диплом лауреата',
        'nomination' => 'Диплом номинации',
        default => 'Диплом участника',
    };
}

function diplomaEmailEditorBlocks(): array
{
    return [
        'eyebrow' => [
            'label' => 'Верхняя метка',
            'hint' => 'Короткая строка над заголовком письма.',
            'default_enabled' => 1,
            'default_text' => 'Письмо с дипломом',
        ],
        'headline' => [
            'label' => 'Заголовок',
            'hint' => 'Главный заголовок письма.',
            'default_enabled' => 1,
            'default_text' => 'Ваш диплом готов',
        ],
        'greeting' => [
            'label' => 'Приветствие',
            'hint' => 'Персональное обращение к получателю.',
            'default_enabled' => 1,
            'default_text' => 'Здравствуйте, {recipient_name}!',
        ],
        'intro' => [
            'label' => 'Основной текст',
            'hint' => 'Краткое пояснение, почему отправлено письмо.',
            'default_enabled' => 1,
            'default_text' => 'Для заявки по конкурсу «{contest_title}» сформирован {diploma_type_label_lower} на имя {participant_name}. PDF-файл приложен к письму, а онлайн-версия доступна по кнопке ниже.',
        ],
        'details' => [
            'label' => 'Блок с данными диплома',
            'hint' => 'Заголовок карточки с ключевыми данными диплома.',
            'default_enabled' => 1,
            'default_text' => 'Данные диплома',
        ],
        'cta' => [
            'label' => 'Кнопка',
            'hint' => 'Текст кнопки перехода к онлайн-версии диплома.',
            'default_enabled' => 1,
            'default_text' => 'Открыть диплом',
        ],
        'link_note' => [
            'label' => 'Текст со ссылкой',
            'hint' => 'Запасной текст со ссылкой, если кнопка не сработала.',
            'default_enabled' => 1,
            'default_text' => 'Если кнопка не работает, откройте ссылку напрямую: {diploma_url}',
        ],
        'attachment' => [
            'label' => 'Блок о вложении',
            'hint' => 'Сообщение о PDF, который приложен к письму.',
            'default_enabled' => 1,
            'default_text' => 'PDF диплома приложен к письму отдельным вложением: {attachment_name}.',
        ],
        'footer' => [
            'label' => 'Подвал письма',
            'hint' => 'Финальный служебный блок внизу письма.',
            'default_enabled' => 1,
            'default_text' => "Вы получили это письмо, потому что на сайте {brand_name} для вашей заявки был сформирован диплом.\n\nС уважением, оргкомитет конкурса.\n{site_url}\nЭто автоматическое письмо, пожалуйста, не отвечайте на него напрямую.",
        ],
    ];
}

function diplomaEmailTemplateDefaults(): array
{
    $defaults = [
        'subject' => 'Ваш {diploma_type_label_lower} готов',
        'blocks' => [],
    ];

    foreach (diplomaEmailEditorBlocks() as $key => $meta) {
        $defaults['blocks'][$key] = [
            'enabled' => (int) ($meta['default_enabled'] ?? 1) === 1 ? 1 : 0,
            'text' => (string) ($meta['default_text'] ?? ''),
        ];
    }

    return $defaults;
}

function normalizeDiplomaEmailTemplateSettings(array $settings = []): array
{
    $defaults = diplomaEmailTemplateDefaults();
    $normalized = [
        'subject' => trim((string) ($settings['subject'] ?? $defaults['subject'])),
        'blocks' => [],
    ];

    if ($normalized['subject'] === '') {
        $normalized['subject'] = $defaults['subject'];
    }

    foreach ($defaults['blocks'] as $key => $defaultBlock) {
        $sourceBlock = is_array($settings['blocks'][$key] ?? null) ? $settings['blocks'][$key] : [];
        $normalized['blocks'][$key] = [
            'enabled' => array_key_exists('enabled', $sourceBlock)
                ? ((int) $sourceBlock['enabled'] === 1 ? 1 : 0)
                : (int) ($defaultBlock['enabled'] ?? 1),
            'text' => trim((string) ($sourceBlock['text'] ?? $defaultBlock['text'] ?? '')),
        ];
    }

    return $normalized;
}

function diplomaEmailTemplateVariables(array $data): array
{
    $diplomaTypeLabel = getDiplomaEmailTypeLabel((string) ($data['diploma_type'] ?? 'contest_participant'));
    $recipientName = trim((string) ($data['user_name'] ?? ''));
    if ($recipientName === '') {
        $recipientName = trim((string) ($data['participant_name'] ?? ''));
    }

    return [
        '{recipient_name}' => $recipientName !== '' ? $recipientName : 'участник',
        '{participant_name}' => trim((string) ($data['participant_name'] ?? '')) !== ''
            ? trim((string) ($data['participant_name'] ?? ''))
            : 'Участник',
        '{contest_title}' => trim((string) ($data['contest_title'] ?? '')) !== ''
            ? trim((string) ($data['contest_title'] ?? ''))
            : 'Конкурс',
        '{diploma_number}' => trim((string) ($data['diploma_number'] ?? '')) !== ''
            ? trim((string) ($data['diploma_number'] ?? ''))
            : '—',
        '{diploma_type_label}' => $diplomaTypeLabel,
        '{diploma_type_label_lower}' => mb_strtolower($diplomaTypeLabel),
        '{brand_name}' => trim((string) ($data['brand_name'] ?? siteBrandName())),
        '{brand_subtitle}' => trim((string) ($data['brand_subtitle'] ?? siteBrandSubtitle())),
        '{diploma_url}' => trim((string) ($data['diploma_url'] ?? '')),
        '{site_url}' => trim((string) ($data['site_url'] ?? SITE_URL)),
        '{attachment_name}' => trim((string) ($data['attachment_name'] ?? 'diploma.pdf')),
    ];
}

function renderDiplomaEmailTemplateString(string $template, array $data): string
{
    return trim(strtr($template, diplomaEmailTemplateVariables($data)));
}

function diplomaEmailRichText(string $template, array $data, string $linkColor = '#2563eb'): string
{
    $rendered = emailTemplateEscape(renderDiplomaEmailTemplateString($template, $data));
    $rendered = preg_replace(
        '~(https?://[^\s<]+)~u',
        '<a href="$1" style="color:' . emailTemplateEscape($linkColor) . ';text-decoration:none;word-break:break-word;">$1</a>',
        $rendered
    );

    return nl2br((string) $rendered);
}

function buildDiplomaEmailSubject(array $data): string
{
    $settings = normalizeDiplomaEmailTemplateSettings((array) ($data['email_template'] ?? []));
    $subject = renderDiplomaEmailTemplateString((string) ($settings['subject'] ?? ''), $data);
    return $subject !== '' ? $subject : 'Ваш диплом готов';
}

/**
 * @param array<string,mixed> $data
 */
function buildDiplomaEmailTemplate(array $data): string {
    $diplomaType = (string)($data['diploma_type'] ?? 'contest_participant');
    $settings = normalizeDiplomaEmailTemplateSettings((array) ($data['email_template'] ?? []));
    $brandName = trim((string)($data['brand_name'] ?? siteBrandName()));
    $brandSubtitle = trim((string)($data['brand_subtitle'] ?? siteBrandSubtitle()));
    $participantName = trim((string)($data['participant_name'] ?? ''));
    $contestTitle = trim((string)($data['contest_title'] ?? ''));
    $diplomaNumber = trim((string)($data['diploma_number'] ?? ''));
    $diplomaUrl = trim((string)($data['diploma_url'] ?? ''));
    $typeLabel = getDiplomaEmailTypeLabel($diplomaType);
    $accentColor = $diplomaType === 'encouragement' ? '#0f766e' : '#2563eb';
    $accentSoft = $diplomaType === 'encouragement' ? '#ccfbf1' : '#dbeafe';
    $surfaceTone = $diplomaType === 'encouragement' ? '#f0fdfa' : '#f8fafc';
    $textTone = $diplomaType === 'encouragement' ? '#115e59' : '#1d4ed8';

    $subject = buildDiplomaEmailSubject($data);
    $safeSubject = emailTemplateEscape($subject);
    $safeBrandName = emailTemplateEscape($brandName);
    $safeBrandSubtitle = emailTemplateEscape($brandSubtitle);
    $safeParticipantName = emailTemplateEscape($participantName !== '' ? $participantName : 'Участник');
    $safeContestTitle = emailTemplateEscape($contestTitle !== '' ? $contestTitle : 'Конкурс');
    $safeDiplomaNumber = emailTemplateEscape($diplomaNumber !== '' ? $diplomaNumber : '—');
    $safeTypeLabel = emailTemplateEscape($typeLabel);
    $safeDiplomaUrl = emailTemplateEscape($diplomaUrl);

    $renderBlock = static function (string $key) use ($settings, $data, $accentColor): string {
        return diplomaEmailRichText((string) ($settings['blocks'][$key]['text'] ?? ''), $data, $accentColor);
    };
    $blockEnabled = static function (string $key) use ($settings): bool {
        return (int) ($settings['blocks'][$key]['enabled'] ?? 0) === 1;
    };

    $headerBadge = $blockEnabled('eyebrow')
        ? '<span style="display:inline-flex;align-items:center;padding:7px 12px;border-radius:999px;background:' . emailTemplateEscape($accentSoft) . ';font-size:12px;line-height:1.2;font-weight:700;color:' . emailTemplateEscape($textTone) . ';">' . $renderBlock('eyebrow') . '</span>'
        : '';

    $headlineBlock = $blockEnabled('headline')
        ? '<div style="margin-top:16px;font-size:32px;line-height:1.15;font-weight:800;letter-spacing:-0.02em;color:#0f172a;">' . $renderBlock('headline') . '</div>'
        : '';

    $greetingBlock = $blockEnabled('greeting')
        ? '<p style="margin:0;font-size:16px;line-height:1.7;color:#0f172a;">' . $renderBlock('greeting') . '</p>'
        : '';

    $introBlock = $blockEnabled('intro')
        ? '<p style="margin:14px 0 0 0;font-size:15px;line-height:1.75;color:#334155;">' . $renderBlock('intro') . '</p>'
        : '';

    $detailsBlock = '';
    if ($blockEnabled('details')) {
        $detailsBlock = '<div style="margin-top:24px;border:1px solid #dbe7f5;border-radius:22px;background:' . emailTemplateEscape($surfaceTone) . ';">'
            . '<div style="padding:18px 20px;border-bottom:1px solid #dbe7f5;font-size:16px;line-height:1.4;font-weight:700;color:#0f172a;">' . $renderBlock('details') . '</div>'
            . '<div style="padding:4px 20px 20px 20px;">'
            . '<div style="padding-top:14px;font-size:12px;line-height:1.5;color:#64748b;text-transform:uppercase;letter-spacing:.08em;">ФИО участника</div>'
            . '<div style="padding-top:4px;font-size:15px;line-height:1.65;font-weight:700;color:#0f172a;">' . $safeParticipantName . '</div>'
            . '<div style="padding-top:14px;font-size:12px;line-height:1.5;color:#64748b;text-transform:uppercase;letter-spacing:.08em;">Конкурс</div>'
            . '<div style="padding-top:4px;font-size:15px;line-height:1.65;font-weight:700;color:#0f172a;">' . $safeContestTitle . '</div>'
            . '<div style="padding-top:14px;font-size:12px;line-height:1.5;color:#64748b;text-transform:uppercase;letter-spacing:.08em;">Номер диплома</div>'
            . '<div style="padding-top:4px;font-size:15px;line-height:1.65;font-weight:700;color:#0f172a;">' . $safeDiplomaNumber . '</div>'
            . '<div style="padding-top:14px;font-size:12px;line-height:1.5;color:#64748b;text-transform:uppercase;letter-spacing:.08em;">Тип диплома</div>'
            . '<div style="padding-top:4px;font-size:15px;line-height:1.65;font-weight:700;color:#0f172a;">' . $safeTypeLabel . '</div>'
            . '</div>'
            . '</div>';
    }

    $ctaBlock = '';
    if ($blockEnabled('cta') && $diplomaUrl !== '') {
        $ctaBlock = '<div style="margin-top:24px;text-align:center;">'
            . '<a href="' . $safeDiplomaUrl . '" style="display:inline-block;min-width:220px;padding:15px 28px;border-radius:16px;background:' . emailTemplateEscape($accentColor) . ';color:#ffffff;text-decoration:none;font-size:16px;line-height:1.2;font-weight:700;box-shadow:0 14px 30px rgba(37,99,235,.18);">'
            . $renderBlock('cta')
            . '</a>'
            . '</div>';
    }

    $linkNoteBlock = $blockEnabled('link_note')
        ? '<div style="margin-top:18px;font-size:14px;line-height:1.75;color:#475569;">' . $renderBlock('link_note') . '</div>'
        : '';

    $attachmentBlock = $blockEnabled('attachment')
        ? '<div style="margin-top:18px;padding:15px 16px;border-radius:18px;background:#fff7ed;border:1px solid #fed7aa;font-size:14px;line-height:1.75;color:#9a3412;">' . $renderBlock('attachment') . '</div>'
        : '';

    $footerBlock = $blockEnabled('footer')
        ? '<div style="margin-top:26px;padding-top:18px;border-top:1px solid #e2e8f0;font-size:12px;line-height:1.8;color:#64748b;">' . $renderBlock('footer') . '</div>'
        : '';

    return '<!doctype html>'
        . '<html lang="ru"><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>' . $safeSubject . '</title></head>'
        . '<body style="margin:0;padding:0;background:#eef2f7;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . $safeSubject . '</div>'
        . '<div style="margin:0;padding:28px 12px;background:#eef2f7;">'
        . '<div style="width:100%;max-width:640px;margin:0 auto;">'
        . '<div style="padding:1px;border-radius:28px;background:linear-gradient(135deg,' . emailTemplateEscape($accentSoft) . ' 0%, #ffffff 48%, #ffffff 100%);box-shadow:0 24px 60px rgba(15,23,42,.1);">'
        . '<div style="border-radius:27px;background:#ffffff;overflow:hidden;font-family:Arial,Helvetica,sans-serif;">'
        . '<div style="padding:28px 28px 20px 28px;background:linear-gradient(180deg,#ffffff 0%,' . emailTemplateEscape($surfaceTone) . ' 100%);">'
        . '<div style="font-size:24px;line-height:1.15;font-weight:800;color:#0f172a;">' . $safeBrandName . '</div>'
        . '<div style="margin-top:8px;font-size:13px;line-height:1.7;color:#64748b;">' . $safeBrandSubtitle . '</div>'
        . $headerBadge
        . $headlineBlock
        . '</div>'
        . '<div style="padding:28px;">'
        . $greetingBlock
        . $introBlock
        . $detailsBlock
        . $ctaBlock
        . $linkNoteBlock
        . $attachmentBlock
        . $footerBlock
        . '</div>'
        . '</div>'
        . '</div>'
        . '</div>'
        . '</div>'
        . '</body></html>';
}

/**
 * @param array<string,mixed> $data
 */
function buildDiplomaEmailText(array $data): string {
    $settings = normalizeDiplomaEmailTemplateSettings((array) ($data['email_template'] ?? []));
    $participantName = trim((string)($data['participant_name'] ?? ''));
    $contestTitle = trim((string)($data['contest_title'] ?? ''));
    $diplomaNumber = trim((string)($data['diploma_number'] ?? ''));
    $diplomaUrl = trim((string)($data['diploma_url'] ?? ''));
    $typeLabel = getDiplomaEmailTypeLabel((string)($data['diploma_type'] ?? 'contest_participant'));

    $lines = [];

    foreach (['eyebrow', 'headline', 'greeting', 'intro'] as $key) {
        if ((int) ($settings['blocks'][$key]['enabled'] ?? 0) !== 1) {
            continue;
        }
        $text = renderDiplomaEmailTemplateString((string) ($settings['blocks'][$key]['text'] ?? ''), $data);
        if ($text === '') {
            continue;
        }
        $lines[] = $text;
        $lines[] = '';
    }

    if ((int) ($settings['blocks']['details']['enabled'] ?? 0) === 1) {
        $detailsTitle = renderDiplomaEmailTemplateString((string) ($settings['blocks']['details']['text'] ?? ''), $data);
        if ($detailsTitle !== '') {
            $lines[] = $detailsTitle;
        }
        $lines[] = 'ФИО участника: ' . ($participantName !== '' ? $participantName : 'Участник');
        $lines[] = 'Конкурс: ' . ($contestTitle !== '' ? $contestTitle : 'Конкурс');
        $lines[] = 'Номер диплома: ' . ($diplomaNumber !== '' ? $diplomaNumber : '—');
        $lines[] = 'Тип диплома: ' . $typeLabel;
        $lines[] = '';
    }

    if ((int) ($settings['blocks']['cta']['enabled'] ?? 0) === 1 && $diplomaUrl !== '') {
        $cta = renderDiplomaEmailTemplateString((string) ($settings['blocks']['cta']['text'] ?? ''), $data);
        $lines[] = ($cta !== '' ? $cta : 'Открыть диплом') . ': ' . $diplomaUrl;
        $lines[] = '';
    }

    foreach (['link_note', 'attachment', 'footer'] as $key) {
        if ((int) ($settings['blocks'][$key]['enabled'] ?? 0) !== 1) {
            continue;
        }
        $text = renderDiplomaEmailTemplateString((string) ($settings['blocks'][$key]['text'] ?? ''), $data);
        if ($text === '') {
            continue;
        }
        $lines[] = $text;
        $lines[] = '';
    }

    while (!empty($lines) && end($lines) === '') {
        array_pop($lines);
    }

    return implode("\n", $lines);
}

/**
 * @param array<string,mixed> $data
 */
function buildEmailVerificationTemplate(array $data): string {
    $brandName = trim((string)($data['brand_name'] ?? siteBrandName()));
    $siteUrl = trim((string)($data['site_url'] ?? SITE_URL));
    $userName = trim((string)($data['user_name'] ?? ''));
    $verificationUrl = trim((string)($data['verification_url'] ?? ''));

    $greeting = $userName !== '' ? 'Здравствуйте, ' . $userName . '!' : 'Здравствуйте!';

    $safeBrandName = emailTemplateEscape($brandName);
    $safeSiteUrl = emailTemplateEscape($siteUrl);
    $safeGreeting = emailTemplateEscape($greeting);
    $safeVerificationUrl = emailTemplateEscape($verificationUrl);

    return '<!doctype html><html lang="ru"><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Подтверждение email</title></head><body style="margin:0;padding:0;background:#f3f4f6;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden;">'
        . '<tr><td style="padding:24px;font-family:Arial,sans-serif;">'
        . '<div style="font-size:24px;font-weight:700;color:#111827;">' . $safeBrandName . '</div>'
        . '<p style="font-size:16px;color:#111827;line-height:1.6;margin:20px 0 0;">' . $safeGreeting . '</p>'
        . '<p style="font-size:15px;color:#374151;line-height:1.7;margin:12px 0 0;">Пожалуйста, подтвердите адрес электронной почты. После подтверждения вам станет доступна отправка заявок на участие в конкурсах.</p>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:20px 0 0;"><tr><td bgcolor="#16a34a" style="border-radius:10px;">'
        . '<a href="' . $safeVerificationUrl . '" style="display:inline-block;padding:14px 24px;color:#ffffff;text-decoration:none;font-family:Arial,sans-serif;font-size:16px;font-weight:700;">Подтвердить электронную почту</a>'
        . '</td></tr></table>'
        . '<p style="font-size:13px;color:#4b5563;line-height:1.7;margin:18px 0 0;">Если кнопка не работает, перейдите по ссылке:<br><a href="' . $safeVerificationUrl . '" style="color:#2563eb;word-break:break-all;">' . $safeVerificationUrl . '</a></p>'
        . '<p style="font-size:12px;color:#6b7280;line-height:1.6;margin:20px 0 0;">С уважением,<br>команда ' . $safeBrandName . '<br><a href="' . $safeSiteUrl . '" style="color:#2563eb;">' . $safeSiteUrl . '</a></p>'
        . '</td></tr></table></td></tr></table></body></html>';
}

/**
 * @param array<string,mixed> $data
 */
function buildEmailVerificationText(array $data): string {
    $brandName = trim((string)($data['brand_name'] ?? siteBrandName()));
    $siteUrl = trim((string)($data['site_url'] ?? SITE_URL));
    $userName = trim((string)($data['user_name'] ?? ''));
    $verificationUrl = trim((string)($data['verification_url'] ?? ''));

    $greeting = $userName !== '' ? 'Здравствуйте, ' . $userName . '!' : 'Здравствуйте!';

    return implode("\n", [
        $greeting,
        '',
        'Пожалуйста, подтвердите адрес электронной почты.',
        'После подтверждения вам станет доступна отправка заявок на участие в конкурсах.',
        '',
        'Подтвердить электронную почту: ' . $verificationUrl,
        '',
        'С уважением, команда ' . $brandName,
        $siteUrl,
    ]);
}

/**
 * @param array<string,mixed> $data
 */
function buildPasswordResetRequestEmailTemplate(array $data): string
{
    $brandName = trim((string) ($data['brand_name'] ?? 'КОНКУРСЫ/ПРОЕКТЫ - ИА ДОБРОЕ ИНФО'));
    $siteUrl = trim((string) ($data['site_url'] ?? SITE_URL));
    $userName = trim((string) ($data['user_name'] ?? ''));
    $resetUrl = trim((string) ($data['reset_url'] ?? ''));
    $greeting = $userName !== '' ? 'Здравствуйте, ' . $userName . '!' : 'Здравствуйте!';

    $safeBrandName = emailTemplateEscape($brandName);
    $safeSiteUrl = emailTemplateEscape($siteUrl);
    $safeGreeting = emailTemplateEscape($greeting);
    $safeResetUrl = emailTemplateEscape($resetUrl);

    return '<!doctype html><html lang="ru"><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Восстановление пароля</title></head><body style="margin:0;padding:0;background:#f3f4f6;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden;">'
        . '<tr><td style="padding:24px;font-family:Arial,sans-serif;">'
        . '<div style="font-size:24px;font-weight:700;color:#111827;">' . $safeBrandName . '</div>'
        . '<p style="font-size:16px;color:#111827;line-height:1.6;margin:20px 0 0;">' . $safeGreeting . '</p>'
        . '<p style="font-size:15px;color:#374151;line-height:1.7;margin:12px 0 0;">Вы запросили восстановление пароля. Перейдите по ссылке ниже, чтобы получить временный пароль для входа.</p>'
        . '<p style="font-size:15px;color:#374151;line-height:1.7;margin:12px 0 0;">Временный пароль будет действовать 1 час. Если вы не смените его вовремя, восстановление отменится и снова будет действовать ваш старый пароль.</p>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:20px 0 0;"><tr><td bgcolor="#2563eb" style="border-radius:10px;">'
        . '<a href="' . $safeResetUrl . '" style="display:inline-block;padding:14px 24px;color:#ffffff;text-decoration:none;font-family:Arial,sans-serif;font-size:16px;font-weight:700;">Получить временный пароль</a>'
        . '</td></tr></table>'
        . '<p style="font-size:13px;color:#4b5563;line-height:1.7;margin:18px 0 0;">Если кнопка не работает, перейдите по ссылке:<br><a href="' . $safeResetUrl . '" style="color:#2563eb;word-break:break-all;">' . $safeResetUrl . '</a></p>'
        . '<p style="font-size:12px;color:#6b7280;line-height:1.6;margin:20px 0 0;">Если вы не запрашивали восстановление пароля, просто проигнорируйте это письмо.</p>'
        . '<p style="font-size:12px;color:#6b7280;line-height:1.6;margin:16px 0 0;">С уважением,<br>команда ' . $safeBrandName . '<br><a href="' . $safeSiteUrl . '" style="color:#2563eb;">' . $safeSiteUrl . '</a></p>'
        . '</td></tr></table></td></tr></table></body></html>';
}

/**
 * @param array<string,mixed> $data
 */
function buildPasswordResetRequestEmailText(array $data): string
{
    $brandName = trim((string) ($data['brand_name'] ?? 'КОНКУРСЫ/ПРОЕКТЫ - ИА ДОБРОЕ ИНФО'));
    $siteUrl = trim((string) ($data['site_url'] ?? SITE_URL));
    $userName = trim((string) ($data['user_name'] ?? ''));
    $resetUrl = trim((string) ($data['reset_url'] ?? ''));
    $greeting = $userName !== '' ? 'Здравствуйте, ' . $userName . '!' : 'Здравствуйте!';

    return implode("\n", [
        $greeting,
        '',
        'Вы запросили восстановление пароля.',
        'Перейдите по ссылке, чтобы получить временный пароль для входа:',
        $resetUrl,
        '',
        'Временный пароль будет действовать 1 час.',
        'Если не сменить его вовремя, восстановление отменится и снова будет действовать старый пароль.',
        '',
        'Если вы не запрашивали восстановление, просто проигнорируйте это письмо.',
        'С уважением, команда ' . $brandName,
        $siteUrl,
    ]);
}

/**
 * @param array<string,mixed> $data
 */
function buildTemporaryPasswordEmailTemplate(array $data): string
{
    $brandName = trim((string) ($data['brand_name'] ?? 'КОНКУРСЫ/ПРОЕКТЫ - ИА ДОБРОЕ ИНФО'));
    $siteUrl = trim((string) ($data['site_url'] ?? SITE_URL));
    $userName = trim((string) ($data['user_name'] ?? ''));
    $login = trim((string) ($data['login'] ?? ''));
    $temporaryPassword = trim((string) ($data['temporary_password'] ?? ''));
    $expiresAt = trim((string) ($data['expires_at'] ?? ''));
    $greeting = $userName !== '' ? 'Здравствуйте, ' . $userName . '!' : 'Здравствуйте!';

    $safeBrandName = emailTemplateEscape($brandName);
    $safeSiteUrl = emailTemplateEscape($siteUrl);
    $safeGreeting = emailTemplateEscape($greeting);
    $safeLogin = emailTemplateEscape($login);
    $safeTemporaryPassword = emailTemplateEscape($temporaryPassword);
    $safeExpiresAt = emailTemplateEscape($expiresAt);

    return '<!doctype html><html lang="ru"><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Временный пароль</title></head><body style="margin:0;padding:0;background:#f3f4f6;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden;">'
        . '<tr><td style="padding:24px;font-family:Arial,sans-serif;">'
        . '<div style="font-size:24px;font-weight:700;color:#111827;">' . $safeBrandName . '</div>'
        . '<p style="font-size:16px;color:#111827;line-height:1.6;margin:20px 0 0;">' . $safeGreeting . '</p>'
        . '<p style="font-size:15px;color:#374151;line-height:1.7;margin:12px 0 0;">Для вашего аккаунта сформирован временный пароль. Используйте его для входа и сразу смените на постоянный в профиле.</p>'
        . '<div style="margin-top:20px;border:1px solid #dbe3f1;border-radius:14px;padding:18px 20px;background:#f8fbff;">'
        . '<div style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.05em;">Логин</div>'
        . '<div style="margin-top:4px;font-size:16px;font-weight:700;color:#111827;">' . $safeLogin . '</div>'
        . '<div style="margin-top:16px;font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.05em;">Временный пароль</div>'
        . '<div style="margin-top:4px;font-size:22px;font-weight:700;color:#111827;word-break:break-all;">' . $safeTemporaryPassword . '</div>'
        . '<div style="margin-top:16px;font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.05em;">Действует до</div>'
        . '<div style="margin-top:4px;font-size:15px;font-weight:700;color:#111827;">' . $safeExpiresAt . '</div>'
        . '</div>'
        . '<div style="margin-top:18px;padding:14px 16px;background:#fff7ed;border:1px solid #fdba74;border-radius:12px;font-size:14px;line-height:1.7;color:#7c2d12;">Временный пароль действует 1 час. Если не сменить его вовремя, восстановление отменится и снова будет действовать старый пароль.</div>'
        . '<p style="font-size:12px;color:#6b7280;line-height:1.6;margin:20px 0 0;">С уважением,<br>команда ' . $safeBrandName . '<br><a href="' . $safeSiteUrl . '" style="color:#2563eb;">' . $safeSiteUrl . '</a></p>'
        . '</td></tr></table></td></tr></table></body></html>';
}

/**
 * @param array<string,mixed> $data
 */
function buildTemporaryPasswordEmailText(array $data): string
{
    $brandName = trim((string) ($data['brand_name'] ?? 'КОНКУРСЫ/ПРОЕКТЫ - ИА ДОБРОЕ ИНФО'));
    $siteUrl = trim((string) ($data['site_url'] ?? SITE_URL));
    $userName = trim((string) ($data['user_name'] ?? ''));
    $login = trim((string) ($data['login'] ?? ''));
    $temporaryPassword = trim((string) ($data['temporary_password'] ?? ''));
    $expiresAt = trim((string) ($data['expires_at'] ?? ''));
    $greeting = $userName !== '' ? 'Здравствуйте, ' . $userName . '!' : 'Здравствуйте!';

    return implode("\n", [
        $greeting,
        '',
        'Для вашего аккаунта сформирован временный пароль.',
        'Логин: ' . $login,
        'Временный пароль: ' . $temporaryPassword,
        'Действует до: ' . $expiresAt,
        '',
        'Пароль действует 1 час.',
        'Обязательно смените его после входа.',
        'Если не сменить пароль вовремя, восстановление отменится и снова будет действовать старый пароль.',
        '',
        'С уважением, команда ' . $brandName,
        $siteUrl,
    ]);
}
