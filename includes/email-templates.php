<?php

function emailTemplateEscape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

    $heroImage = trim((string)($data['hero_image'] ?? ($siteUrl . '/contest-hero-placeholder.svg')));
    $decorImage = trim((string)($data['decor_image'] ?? ($siteUrl . '/placeholders/contest-cover-purple.svg')));

    $headline = $isEncouragement
        ? 'Спасибо за участие — ваш диплом уже готов'
        : 'Ваш диплом готов';
    $ctaLabel = $isEncouragement ? 'Посмотреть диплом' : 'Открыть диплом';
    $typeLabel = $isEncouragement ? 'Благодарственный диплом' : 'Диплом участника';
    $greeting = $userName !== '' ? 'Здравствуйте, ' . $userName . '!' : 'Здравствуйте!';
    $accentColor = $isEncouragement ? '#0ea5a4' : '#7c3aed';

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
    $safeHeroImage = emailTemplateEscape($heroImage);
    $safeDecorImage = emailTemplateEscape($decorImage);

    return '<!doctype html>'
        . '<html lang="ru"><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>' . $safeHeadline . '</title></head>'
        . '<body style="margin:0;padding:0;background-color:#f3f4f6;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f3f4f6;margin:0;padding:0;">'
        . '<tr><td align="center" style="padding:24px 12px;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="width:100%;max-width:600px;background-color:#ffffff;border-radius:14px;overflow:hidden;">'
        . '<tr><td style="padding:0;">'
        . '<img src="' . $safeHeroImage . '" width="600" alt="Детский конкурс творчества" style="display:block;width:100%;height:auto;border:0;">'
        . '</td></tr>'
        . '<tr><td style="padding:22px 24px 8px 24px;font-family:Arial,Helvetica,sans-serif;">'
        . '<div style="font-size:24px;line-height:1.25;font-weight:700;color:#111827;">' . $safeBrandName . '</div>'
        . '<div style="font-size:14px;line-height:1.45;color:#6b7280;margin-top:4px;">' . $safeBrandSubtitle . '</div>'
        . '</td></tr>'
        . '<tr><td style="padding:8px 24px 0 24px;font-family:Arial,Helvetica,sans-serif;">'
        . '<div style="font-size:30px;line-height:1.2;font-weight:700;color:#111827;">' . $safeHeadline . '</div>'
        . '</td></tr>'
        . '<tr><td style="padding:18px 24px 0 24px;font-family:Arial,Helvetica,sans-serif;">'
        . '<p style="margin:0;font-size:16px;line-height:1.6;color:#111827;">' . $safeGreeting . '</p>'
        . '<p style="margin:12px 0 0 0;font-size:15px;line-height:1.7;color:#374151;">Диплом сформирован автоматически по итогам участия в конкурсе <strong>' . $safeContestTitle . '</strong> и выдан участнику <strong>' . $safeParticipantName . '</strong>. PDF-файл диплома прикреплён к этому письму, а также доступен по ссылке ниже.</p>'
        . '</td></tr>'
        . '<tr><td style="padding:18px 24px 0 24px;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border:1px solid #e5e7eb;border-radius:12px;background-color:#f9fafb;">'
        . '<tr><td style="padding:16px 18px;font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#374151;line-height:1.7;">'
        . '<strong style="color:#111827;display:block;margin-bottom:8px;">Детали диплома</strong>'
        . 'ФИО участника: <strong>' . $safeParticipantName . '</strong><br>'
        . 'Название конкурса: <strong>' . $safeContestTitle . '</strong><br>'
        . 'Номер диплома: <strong>' . $safeDiplomaNumber . '</strong><br>'
        . 'Тип диплома: <strong>' . $safeTypeLabel . '</strong>'
        . '</td></tr></table>'
        . '</td></tr>'
        . '<tr><td align="center" style="padding:26px 24px 0 24px;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" bgcolor="' . $accentColor . '" style="border-radius:10px;">'
        . '<a href="' . $safeDiplomaUrl . '" style="display:inline-block;padding:14px 28px;font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:700;color:#ffffff;text-decoration:none;">' . $safeCta . '</a>'
        . '</td></tr></table>'
        . '</td></tr>'
        . '<tr><td style="padding:18px 24px 0 24px;font-family:Arial,Helvetica,sans-serif;">'
        . '<p style="margin:0;font-size:14px;line-height:1.7;color:#4b5563;">Сохраните это письмо, чтобы не потерять диплом. При необходимости его можно скачать позже по ссылке из этого сообщения.</p>'
        . '</td></tr>'
        . '<tr><td style="padding:18px 24px 0 24px;">'
        . '<img src="' . $safeDecorImage . '" width="552" alt="Декоративный блок" style="display:block;width:100%;height:auto;border:0;border-radius:10px;">'
        . '</td></tr>'
        . '<tr><td style="padding:18px 24px 24px 24px;font-family:Arial,Helvetica,sans-serif;">'
        . '<p style="margin:0;font-size:12px;line-height:1.6;color:#6b7280;">Вы получили это письмо, потому что на сайте ' . $safeBrandName . ' для вашей заявки был сформирован диплом.</p>'
        . '<p style="margin:8px 0 0 0;font-size:12px;line-height:1.6;color:#6b7280;">С уважением, оргкомитет конкурса.<br><a href="' . $safeSiteUrl . '" style="color:' . $accentColor . ';text-decoration:none;">' . $safeSiteUrl . '</a><br>Это автоматическое письмо, пожалуйста, не отвечайте на него напрямую.</p>'
        . '</td></tr>'
        . '</table>'
        . '</td></tr></table>'
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

    $greeting = $userName !== '' ? 'Здравствуйте, ' . $userName . '!' : 'Здравствуйте!';
    $title = $isEncouragement ? 'Ваш благодарственный диплом готов.' : 'Ваш диплом участника готов.';
    $ctaLabel = $isEncouragement ? 'Посмотреть диплом' : 'Открыть диплом';
    $typeLabel = $isEncouragement ? 'Благодарственный диплом' : 'Диплом участника';

    return implode("\n", [
        $greeting,
        '',
        $title,
        'Диплом сформирован автоматически и прикреплён к письму в формате PDF.',
        'Конкурс: ' . ($contestTitle !== '' ? $contestTitle : 'Конкурс'),
        'Кому выдан: ' . ($participantName !== '' ? $participantName : 'Участник'),
        'Номер диплома: ' . ($diplomaNumber !== '' ? $diplomaNumber : '—'),
        'Тип диплома: ' . $typeLabel,
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
