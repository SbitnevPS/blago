<?php
$adminChatModalId = (string) ($adminChatModalId ?? 'adminChatModal');
$adminChatModalActive = !empty($adminChatModalActive);
$adminChatModalTitle = (string) ($adminChatModalTitle ?? 'Чат');
$adminChatCloseHandler = (string) ($adminChatCloseHandler ?? 'closeThreadChatModal()');
$adminChatApplicationUrl = (string) ($adminChatApplicationUrl ?? '');
$adminChatMessagesContainerId = (string) ($adminChatMessagesContainerId ?? 'adminChatMessages');
$adminChatMessages = is_array($adminChatMessages ?? null) ? $adminChatMessages : [];
$adminChatCurrentUserLabel = trim((string) ($adminChatCurrentUserLabel ?? 'Пользователь'));
$adminChatClosed = !empty($adminChatClosed);
$adminChatClosedText = (string) ($adminChatClosedText ?? 'Чат завершён. Доступен только просмотр.');
$adminChatFormAction = (string) ($adminChatFormAction ?? '');
$adminChatComposerLabel = (string) ($adminChatComposerLabel ?? 'Сообщение');
$adminChatComposerTextareaName = (string) ($adminChatComposerTextareaName ?? 'reply_text');
$adminChatComposerPlaceholder = (string) ($adminChatComposerPlaceholder ?? 'Введите сообщение');
$adminChatComposerSubmitText = (string) ($adminChatComposerSubmitText ?? 'Отправить');
$adminChatComposerHiddenFields = is_array($adminChatComposerHiddenFields ?? null) ? $adminChatComposerHiddenFields : [];
$adminChatSupportsAttachments = !empty($adminChatSupportsAttachments);
$adminChatAttachmentHelp = (string) ($adminChatAttachmentHelp ?? '');
$adminChatExtraTopHtml = (string) ($adminChatExtraTopHtml ?? '');
$adminChatExtraMiddleHtml = (string) ($adminChatExtraMiddleHtml ?? '');
$adminChatExtraBottomHtml = (string) ($adminChatExtraBottomHtml ?? '');
$adminChatImageButtonClass = (string) ($adminChatImageButtonClass ?? 'js-open-message-image');
$adminChatAttachmentInputId = preg_replace('/[^a-zA-Z0-9_-]/', '', $adminChatModalId . '-attachment-input');
?>
<div class="modal<?= $adminChatModalActive ? ' active' : '' ?>" id="<?= htmlspecialchars($adminChatModalId) ?>">
    <div class="modal__content message-modal dispute-chat-modal">
        <div class="modal__header">
            <h3><?= htmlspecialchars($adminChatModalTitle) ?></h3>
            <div class="flex items-center gap-sm">
                <?php if ($adminChatApplicationUrl !== ''): ?>
                    <a href="<?= htmlspecialchars($adminChatApplicationUrl) ?>" class="btn btn--ghost btn--sm">
                        <i class="fas fa-external-link-alt"></i> Открыть заявку
                    </a>
                <?php endif; ?>
                <button type="button" class="modal__close" onclick="<?= htmlspecialchars($adminChatCloseHandler, ENT_QUOTES, 'UTF-8') ?>">&times;</button>
            </div>
        </div>
        <div class="modal__body dispute-chat-modal__body">
            <?= $adminChatExtraTopHtml ?>

            <?php if (empty($adminChatMessages)): ?>
                <p class="text-secondary">Сообщения не найдены.</p>
            <?php else: ?>
                <div class="dispute-chat-modal__messages" id="<?= htmlspecialchars($adminChatMessagesContainerId) ?>">
                    <?php foreach ($adminChatMessages as $chatMessage): ?>
                        <?php
                            $adminChatFromAdmin = (int) ($chatMessage['author_is_admin'] ?? $chatMessage['is_admin'] ?? 0) === 1;
                            $adminChatAuthorName = trim((string) (($chatMessage['author_surname'] ?? $chatMessage['surname'] ?? '') . ' ' . ($chatMessage['author_name'] ?? $chatMessage['name'] ?? '') . ' ' . ($chatMessage['author_patronymic'] ?? $chatMessage['patronymic'] ?? '')));
                            if ($adminChatAuthorName === '') {
                                $adminChatAuthorName = $adminChatFromAdmin ? 'Администратор' : $adminChatCurrentUserLabel;
                            }
                            $adminChatAuthorLabel = $adminChatFromAdmin
                                ? 'Руководитель проекта — ' . $adminChatAuthorName
                                : $adminChatAuthorName;
                            $adminChatAttachmentUrl = !empty($chatMessage['attachment_file']) ? buildMessageAttachmentPublicUrl((string) $chatMessage['attachment_file']) : '';
                            $adminChatAttachmentName = (string) ($chatMessage['attachment_original_name'] ?? basename((string) ($chatMessage['attachment_file'] ?? '')));
                            $adminChatAttachmentIsImage = $adminChatAttachmentUrl !== '' && isImageMessageAttachment((string) ($chatMessage['attachment_mime_type'] ?? ''), $adminChatAttachmentName);
                        ?>
                        <div class="dispute-chat-message <?= $adminChatFromAdmin ? 'dispute-chat-message--admin' : 'dispute-chat-message--user' ?>" data-message-id="<?= (int) ($chatMessage['id'] ?? 0) ?>">
                            <div class="dispute-chat-message__bubble">
                                <div class="dispute-chat-message__meta">
                                    <?= htmlspecialchars($adminChatAuthorLabel) ?>
                                    <span>• <?= date('d.m.Y H:i', strtotime((string) ($chatMessage['created_at'] ?? 'now'))) ?></span>
                                </div>
                                <div class="dispute-chat-message__text"><?= htmlspecialchars((string) ($chatMessage['content'] ?? '')) ?></div>
                                <?php if ($adminChatAttachmentUrl !== ''): ?>
                                    <div class="message-attachment" style="margin-top:10px;">
                                        <?php if ($adminChatAttachmentIsImage): ?>
                                            <button
                                                type="button"
                                                class="message-attachment__image-button <?= htmlspecialchars($adminChatImageButtonClass) ?>"
                                                data-image-url="<?= htmlspecialchars($adminChatAttachmentUrl) ?>"
                                                data-image-title="<?= htmlspecialchars($adminChatAttachmentName) ?>">
                                                <img src="<?= htmlspecialchars($adminChatAttachmentUrl) ?>" alt="<?= htmlspecialchars($adminChatAttachmentName) ?>" class="message-attachment__thumb">
                                                <span class="message-attachment__caption"><i class="fas fa-search-plus"></i> Посмотреть изображение</span>
                                            </button>
                                        <?php else: ?>
                                            <a href="<?= htmlspecialchars($adminChatAttachmentUrl) ?>" class="message-attachment__file" target="_blank" rel="noopener" download="<?= htmlspecialchars($adminChatAttachmentName) ?>">
                                                <i class="fas fa-download"></i>
                                                <span><?= htmlspecialchars($adminChatAttachmentName) ?></span>
                                            </a>
                                        <?php endif; ?>
                                        <?php if (!empty($chatMessage['can_delete_attachment'])): ?>
                                            <button type="button" class="btn btn--ghost btn--sm js-delete-dispute-attachment" data-message-id="<?= (int) ($chatMessage['id'] ?? 0) ?>" style="margin-top:8px; color:#ef4444;">
                                                <i class="fas fa-trash"></i> Удалить файл
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($adminChatClosed): ?>
                <div class="alert alert--warning" style="margin-top:12px;">
                    <i class="fas fa-lock"></i> <?= htmlspecialchars($adminChatClosedText) ?>
                </div>
            <?php elseif ($adminChatFormAction !== ''): ?>
                <?= $adminChatExtraMiddleHtml ?>
                <form method="POST" class="dispute-chat-modal__composer"<?= $adminChatSupportsAttachments ? ' enctype="multipart/form-data"' : '' ?>>
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <?php foreach ($adminChatComposerHiddenFields as $fieldName => $fieldValue): ?>
                        <input type="hidden" name="<?= htmlspecialchars((string) $fieldName) ?>" value="<?= htmlspecialchars((string) $fieldValue, ENT_QUOTES, 'UTF-8') ?>">
                    <?php endforeach; ?>
                    <?php if ($adminChatSupportsAttachments): ?>
                        <input
                            type="file"
                            id="<?= htmlspecialchars($adminChatAttachmentInputId) ?>"
                            name="attachment"
                            class="chat-composer__attachment-input js-message-attachment-input"
                            accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.doc,.docx,.rtf,.xls,.xlsx,.csv,.zip,image/*,application/pdf,text/plain,text/csv">
                        <div class="message-attachment-preview chat-composer__attachment-preview js-message-attachment-preview" hidden></div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars($adminChatComposerLabel) ?></label>
                        <textarea name="<?= htmlspecialchars($adminChatComposerTextareaName) ?>" class="form-textarea js-chat-hotkey" rows="4" placeholder="<?= htmlspecialchars($adminChatComposerPlaceholder) ?>"></textarea>
                    </div>
                    <div class="chat-composer__actions">
                        <?php if ($adminChatSupportsAttachments): ?>
                            <label class="chat-composer__attachment-trigger" for="<?= htmlspecialchars($adminChatAttachmentInputId) ?>" title="Прикрепить файл">
                                <i class="fas fa-paperclip"></i>
                                <span>Файл</span>
                            </label>
                            <?php if ($adminChatAttachmentHelp !== ''): ?>
                                <div class="chat-composer__attachment-help"><?= htmlspecialchars($adminChatAttachmentHelp) ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <button type="submit" class="btn btn--primary">
                            <i class="fas fa-paper-plane"></i> <?= htmlspecialchars($adminChatComposerSubmitText) ?>
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <?= $adminChatExtraMiddleHtml ?>
            <?php endif; ?>

            <?= $adminChatExtraBottomHtml ?>
        </div>
    </div>
</div>
