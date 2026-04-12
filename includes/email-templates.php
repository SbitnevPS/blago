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

/**
 * @param array<string,mixed> $data
 */
function buildDiplomaEmailTemplate(array $data): string {
    $diplomaType = (string)($data['diploma_type'] ?? 'contest_participant');
    $isEncouragement = $diplomaType === 'encouragement';

    $brandName = trim((string)($data['brand_name'] ?? 'ДетскиеКонкурсы.рф'));
    $brandSubtitle = trim((string)($data['brand_subtitle'] ?? 'Всероссийские конкурсы детского творчества'));
    $userName = trim((string)($data['user_name'] ?? ''));
    $participantName = trim((string)($data['participant_name'] ?? ''));
    $contestTitle = trim((string)($data['contest_title'] ?? ''));
    $diplomaNumber = trim((string)($data['diploma_number'] ?? ''));
    $diplomaUrl = trim((string)($data['diploma_url'] ?? ''));
    $siteUrl = trim((string)($data['site_url'] ?? SITE_URL));
    $logoCid = trim((string)($data['logo_cid'] ?? ''));
    $heroCid = trim((string)($data['hero_cid'] ?? ''));
    $footerCid = trim((string)($data['footer_cid'] ?? ''));
    $attachmentName = trim((string)($data['attachment_name'] ?? 'diploma.pdf'));

    $headline = $isEncouragement
        ? 'Спасибо за участие — ваш диплом уже готов'
        : 'Ваш диплом готов';
    $ctaLabel = 'Открыть диплом';
    $typeLabel = getDiplomaEmailTypeLabel($diplomaType);
    $greetingName = $userName !== '' ? $userName : $participantName;
    $greeting = $greetingName !== '' ? 'Здравствуйте, ' . $greetingName . '!' : 'Здравствуйте!';
    $accentColor = $isEncouragement ? '#0ea5a4' : '#7c3aed';
    $heroAlt = $isEncouragement ? 'Баннер благодарственного диплома' : 'Баннер письма с дипломом';
    $logoAlt = $brandName;

    $safeBrandName = emailTemplateEscape($brandName);
    $safeBrandSubtitle = emailTemplateEscape($brandSubtitle);
    $safeHeadline = emailTemplateEscape($headline);
    $safeGreeting = emailTemplateEscape($greeting);
    $safeContestTitle = emailTemplateEscape($contestTitle !== '' ? $contestTitle : 'Конкурс');
    $safeParticipantName = emailTemplateEscape($participantName !== '' ? $participantName : 'Участник');
    $safeDiplomaNumber = emailTemplateEscape($diplomaNumber !== '' ? $diplomaNumber : '—');
    $safeTypeLabel = emailTemplateEscape($typeLabel);
    $safeCta = emailTemplateEscape($ctaLabel);
    $safeDiplomaUrl = emailTemplateEscape($diplomaUrl);
    $safeSiteUrl = emailTemplateEscape($siteUrl);
    $safeAttachmentName = emailTemplateEscape($attachmentName);
    $safeAccentColor = emailTemplateEscape($accentColor);
    $safeHeroAlt = emailTemplateEscape($heroAlt);
    $safeLogoAlt = emailTemplateEscape($logoAlt);

    $logoBlock = $logoCid !== ''
        ? '<img src="cid:' . emailTemplateEscape($logoCid) . '" alt="' . $safeLogoAlt . '" width="132" style="display:block;border:0;width:132px;max-width:132px;height:auto;">'
        : '<div style="font-size:24px;line-height:1.25;font-weight:700;color:#111827;">' . $safeBrandName . '</div>';

    $heroBlock = $heroCid !== ''
        ? '<img src="cid:' . emailTemplateEscape($heroCid) . '" alt="' . $safeHeroAlt . '" width="600" style="display:block;border:0;width:100%;max-width:600px;height:auto;">'
        : '<div style="padding:28px 24px;background-color:' . $safeAccentColor . ';color:#ffffff;font-family:Arial,Helvetica,sans-serif;">'
            . '<div style="font-size:14px;line-height:1.5;opacity:.9;">' . $safeBrandSubtitle . '</div>'
            . '<div style="margin-top:8px;font-size:28px;line-height:1.2;font-weight:700;">' . $safeHeadline . '</div>'
            . '</div>';

    $footerDecorBlock = $footerCid !== ''
        ? '<img src="cid:' . emailTemplateEscape($footerCid) . '" alt="" width="600" style="display:block;border:0;width:100%;max-width:600px;height:auto;">'
        : '<div style="height:8px;font-size:0;line-height:0;background-color:' . $safeAccentColor . ';">&nbsp;</div>';

    $typeBadgeColor = $isEncouragement ? '#ccfbf1' : '#ede9fe';
    $typeBadgeTextColor = $isEncouragement ? '#115e59' : '#5b21b6';

    return '<!doctype html>'
        . '<html lang="ru"><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>' . $safeHeadline . '</title></head>'
        . '<body style="margin:0;padding:0;background-color:#f4f6fb;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . $safeHeadline . '. PDF диплома приложен к письму, а также доступен по ссылке.</div>'
        . '<div style="margin:0;padding:24px 12px;background-color:#f4f6fb;">'
        . '<div style="width:100%;max-width:600px;margin:0 auto;background-color:#ffffff;border:1px solid #e5eaf3;border-radius:24px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;">'
        . '<div style="padding:28px 28px 16px 28px;background-color:#ffffff;">'
        . $logoBlock
        . '<div style="font-size:13px;line-height:1.5;color:#6b7280;margin-top:8px;">' . $safeBrandSubtitle . '</div>'
        . '</div>'
        . '<div style="padding:0;font-size:0;line-height:0;">'
        . $heroBlock
        . '</div>'
        . '<div style="padding:28px 28px 10px 28px;">'
        . '<div style="display:inline-block;padding:7px 12px;border-radius:999px;background-color:' . emailTemplateEscape($typeBadgeColor) . ';font-size:12px;line-height:1.2;font-weight:700;color:' . emailTemplateEscape($typeBadgeTextColor) . ';">' . $safeTypeLabel . '</div>'
        . '</div>'
        . '<div style="padding:0 28px 8px 28px;">'
        . '<div style="font-size:30px;line-height:1.2;font-weight:700;color:#111827;">' . $safeHeadline . '</div>'
        . '</div>'
        . '<div style="padding:0 28px;">'
        . '<p style="margin:0;font-size:16px;line-height:1.6;color:#111827;">' . $safeGreeting . '</p>'
        . '<p style="margin:12px 0 0 0;font-size:15px;line-height:1.7;color:#374151;">Для заявки по конкурсу <strong>' . $safeContestTitle . '</strong> сформирован диплом на имя <strong>' . $safeParticipantName . '</strong>. PDF-файл диплома прикреплён к письму, а онлайн-версия доступна по кнопке ниже.</p>'
        . '</div>'
        . '<div style="padding:22px 28px 0 28px;">'
        . '<div style="border:1px solid #dbe3f1;border-radius:18px;background:linear-gradient(180deg,#fbfdff 0%,#f7fbff 100%);">'
        . '<div style="padding:18px 20px 12px 20px;font-size:16px;line-height:1.4;font-weight:700;color:#111827;border-bottom:1px solid #e8eef7;">Данные диплома</div>'
        . '<div style="padding:2px 20px 20px 20px;">'
        . '<div style="padding:10px 0 6px 0;font-size:13px;line-height:1.6;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">ФИО участника</div>'
        . '<div style="font-size:14px;line-height:1.6;color:#111827;font-weight:700;">' . $safeParticipantName . '</div>'
        . '<div style="padding:14px 0 6px 0;font-size:13px;line-height:1.6;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">Название конкурса</div>'
        . '<div style="font-size:14px;line-height:1.6;color:#111827;font-weight:700;">' . $safeContestTitle . '</div>'
        . '<div style="padding:14px 0 6px 0;font-size:13px;line-height:1.6;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">Номер диплома</div>'
        . '<div style="font-size:14px;line-height:1.6;color:#111827;font-weight:700;">' . $safeDiplomaNumber . '</div>'
        . '<div style="padding:14px 0 6px 0;font-size:13px;line-height:1.6;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">Тип диплома</div>'
        . '<div style="font-size:14px;line-height:1.6;color:#111827;font-weight:700;">' . $safeTypeLabel . '</div>'
        . '</div>'
        . '</div>'
        . '</div>'
        . '<div style="padding:28px 28px 0 28px;text-align:center;">'
        . '<a href="' . $safeDiplomaUrl . '" style="display:inline-block;min-width:220px;padding:15px 30px;background-color:' . $safeAccentColor . ';border-radius:14px;font-size:16px;font-weight:700;line-height:1.2;color:#ffffff;text-decoration:none;box-shadow:0 10px 24px rgba(15,23,42,.12);">' . $safeCta . '</a>'
        . '</div>'
        . '<div style="padding:18px 28px 0 28px;">'
        . '<p style="margin:0;font-size:14px;line-height:1.7;color:#4b5563;">Если кнопка не работает, откройте ссылку напрямую: <a href="' . $safeDiplomaUrl . '" style="color:' . $safeAccentColor . ';text-decoration:none;word-break:break-all;">' . $safeDiplomaUrl . '</a></p>'
        . '</div>'
        . '<div style="padding:18px 28px 0 28px;">'
        . '<div style="background-color:#fff7ed;border:1px solid #fed7aa;border-radius:16px;padding:14px 16px;font-size:14px;line-height:1.7;color:#7c2d12;">PDF диплома приложен к письму отдельным вложением: <strong>' . $safeAttachmentName . '</strong>.</div>'
        . '</div>'
        . '<div style="padding:20px 0 0 0;font-size:0;line-height:0;">'
        . $footerDecorBlock
        . '</div>'
        . '<div style="padding:20px 28px 28px 28px;background-color:#fcfdff;">'
        . '<div style="height:1px;background-color:#e8edf5;margin-bottom:16px;"></div>'
        . '<p style="margin:0;font-size:12px;line-height:1.7;color:#6b7280;">Вы получили это письмо, потому что на сайте ' . $safeBrandName . ' для вашей заявки был сформирован диплом.</p>'
        . '<p style="margin:8px 0 0 0;font-size:12px;line-height:1.7;color:#6b7280;">С уважением, оргкомитет конкурса.<br><a href="' . $safeSiteUrl . '" style="color:' . $safeAccentColor . ';text-decoration:none;">' . $safeSiteUrl . '</a><br>Это автоматическое письмо, пожалуйста, не отвечайте на него напрямую.</p>'
        . '</div>'
        . '</div>'
        . '</div>'
        . '</body></html>';
}

/**
 * @param array<string,mixed> $data
 */
function buildDiplomaEmailText(array $data): string {
    $diplomaType = (string)($data['diploma_type'] ?? 'contest_participant');
    $isEncouragement = $diplomaType === 'encouragement';

    $brandName = trim((string)($data['brand_name'] ?? 'ДетскиеКонкурсы.рф'));
    $userName = trim((string)($data['user_name'] ?? ''));
    $participantName = trim((string)($data['participant_name'] ?? ''));
    $contestTitle = trim((string)($data['contest_title'] ?? ''));
    $diplomaNumber = trim((string)($data['diploma_number'] ?? ''));
    $diplomaUrl = trim((string)($data['diploma_url'] ?? ''));
    $siteUrl = trim((string)($data['site_url'] ?? SITE_URL));
    $attachmentName = trim((string)($data['attachment_name'] ?? 'diploma.pdf'));

    $greeting = $userName !== '' ? 'Здравствуйте, ' . $userName . '!' : 'Здравствуйте!';
    $title = $isEncouragement ? 'Ваш благодарственный диплом готов.' : 'Ваш диплом готов.';
    $ctaLabel = 'Открыть диплом';
    $typeLabel = getDiplomaEmailTypeLabel($diplomaType);

    return implode("\n", [
        $greeting,
        '',
        $title,
        'Диплом сформирован автоматически и прикреплён к письму в формате PDF.',
        'Конкурс: ' . ($contestTitle !== '' ? $contestTitle : 'Конкурс'),
        'Кому выдан: ' . ($participantName !== '' ? $participantName : 'Участник'),
        'Номер диплома: ' . ($diplomaNumber !== '' ? $diplomaNumber : '—'),
        'Тип диплома: ' . $typeLabel,
        'PDF во вложении: ' . $attachmentName,
        '',
        $ctaLabel . ': ' . $diplomaUrl,
        'Сохраните это письмо, чтобы не потерять диплом. При необходимости его можно скачать позже по ссылке.',
        '',
        'Вы получили это письмо, потому что на сайте ' . $brandName . ' для вашей заявки был сформирован диплом.',
        'С уважением, оргкомитет конкурса.',
        $siteUrl,
        'Это автоматическое письмо, пожалуйста, не отвечайте на него напрямую.',
    ]);
}

/**
 * @param array<string,mixed> $data
 */
function buildEmailVerificationTemplate(array $data): string {
    $brandName = trim((string)($data['brand_name'] ?? 'ДетскиеКонкурсы.рф'));
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
    $brandName = trim((string)($data['brand_name'] ?? 'ДетскиеКонкурсы.рф'));
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
    $brandName = trim((string) ($data['brand_name'] ?? 'ДетскиеКонкурсы.рф'));
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
    $brandName = trim((string) ($data['brand_name'] ?? 'ДетскиеКонкурсы.рф'));
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
    $brandName = trim((string) ($data['brand_name'] ?? 'ДетскиеКонкурсы.рф'));
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
    $brandName = trim((string) ($data['brand_name'] ?? 'ДетскиеКонкурсы.рф'));
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
