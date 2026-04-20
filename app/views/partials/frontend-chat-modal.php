<?php
$chatModalId = (string) ($chatModalId ?? 'disputeChatModal');
$chatModalActive = !empty($chatModalActive);
$chatModalTitle = (string) ($chatModalTitle ?? 'Чат');
$chatMessagesContainerId = (string) ($chatMessagesContainerId ?? 'disputeChatMessages');
$chatMessages = is_array($chatMessages ?? null) ? $chatMessages : [];
$chatCloseHandler = (string) ($chatCloseHandler ?? 'closeDisputeChatModal()');
$chatOpenApplicationUrl = (string) ($chatOpenApplicationUrl ?? '');
$chatClosed = !empty($chatClosed);
$chatClosedText = (string) ($chatClosedText ?? 'Чат завершён. Доступен только просмотр.');
$chatFormAction = (string) ($chatFormAction ?? '');
$chatComposerLabel = (string) ($chatComposerLabel ?? 'Сообщение');
$chatComposerTextareaName = (string) ($chatComposerTextareaName ?? 'chat_message');
$chatComposerPlaceholder = (string) ($chatComposerPlaceholder ?? 'Напишите сообщение...');
$chatComposerButtonText = (string) ($chatComposerButtonText ?? 'Отправить');
$chatComposerHiddenFields = is_array($chatComposerHiddenFields ?? null) ? $chatComposerHiddenFields : [];
$chatCurrentUserLabel = trim((string) ($chatCurrentUserLabel ?? 'Пользователь'));
$chatInvertAdminBubble = !empty($chatInvertAdminBubble);
$chatSupportsAttachments = !empty($chatSupportsAttachments);
$chatAttachmentHelp = (string) ($chatAttachmentHelp ?? 'Изображение покажем миниатюрой, для остальных файлов сохраним название. До 10 МБ.');
$chatAttachmentInputId = preg_replace('/[^a-zA-Z0-9_-]/', '', $chatModalId . '-attachment-input');
?>
<div class="modal<?= $chatModalActive ? ' active' : '' ?>" id="<?= htmlspecialchars($chatModalId) ?>">
    <div class="modal__content message-modal dispute-chat-modal">
        <div class="modal__header">
            <h3><?= htmlspecialchars($chatModalTitle) ?></h3>
            <div class="flex items-center gap-sm">
                <?php if ($chatOpenApplicationUrl !== ''): ?>
                    <a href="<?= htmlspecialchars($chatOpenApplicationUrl) ?>" class="btn btn--ghost btn--sm">
                        <i class="fas fa-external-link-alt"></i> Открыть заявку
                    </a>
                <?php endif; ?>
                <button type="button" class="modal__close" onclick="<?= htmlspecialchars($chatCloseHandler, ENT_QUOTES, 'UTF-8') ?>">&times;</button>
            </div>
        </div>
        <div class="modal__body dispute-chat-modal__body">
            <div class="dispute-chat-modal__messages" id="<?= htmlspecialchars($chatMessagesContainerId) ?>">
                <?php if (!empty($chatMessages)): ?>
                    <?php foreach ($chatMessages as $chatMessage): ?>
                        <?php
                            $chatFromAdmin = (int) ($chatMessage['is_admin'] ?? $chatMessage['author_is_admin'] ?? 0) === 1;
                            $chatAuthorName = trim((string) (($chatMessage['surname'] ?? $chatMessage['author_surname'] ?? '') . ' ' . ($chatMessage['name'] ?? $chatMessage['author_name'] ?? '') . ' ' . ($chatMessage['patronymic'] ?? $chatMessage['author_patronymic'] ?? '')));
                            if ($chatAuthorName === '') {
                                $chatAuthorName = $chatFromAdmin ? 'Администратор' : $chatCurrentUserLabel;
                            }
                            $chatMessageClass = $chatFromAdmin
                                ? ($chatInvertAdminBubble ? 'dispute-chat-message--user' : 'dispute-chat-message--admin')
                                : ($chatInvertAdminBubble ? 'dispute-chat-message--admin' : 'dispute-chat-message--user');
                            $chatAttachmentFile = (string) ($chatMessage['attachment_file'] ?? '');
                            $chatAttachmentUrl = $chatAttachmentFile !== '' ? buildMessageAttachmentPublicUrl($chatAttachmentFile) : '';
                            $chatAttachmentName = (string) ($chatMessage['attachment_original_name'] ?? basename($chatAttachmentFile));
                            $chatAttachmentIsImage = $chatAttachmentUrl !== '' && isImageMessageAttachment((string) ($chatMessage['attachment_mime_type'] ?? ''), $chatAttachmentName);
                        ?>
                        <div class="dispute-chat-message <?= htmlspecialchars($chatMessageClass) ?>" data-message-id="<?= (int) ($chatMessage['id'] ?? 0) ?>">
                            <div class="dispute-chat-message__bubble">
                                <div class="dispute-chat-message__meta">
                                    <?= htmlspecialchars($chatFromAdmin ? 'Руководитель проекта — ' . $chatAuthorName : $chatAuthorName) ?>
                                    <span>• <?= date('d.m.Y H:i', strtotime((string) ($chatMessage['created_at'] ?? 'now'))) ?></span>
                                </div>
                                <div class="dispute-chat-message__text"><?= htmlspecialchars((string) ($chatMessage['content'] ?? '')) ?></div>
                                <?php if ($chatAttachmentUrl !== ''): ?>
                                    <div class="message-attachment" style="margin-top:10px;">
                                        <?php if ($chatAttachmentIsImage): ?>
                                            <button type="button" class="message-attachment__image-button js-open-message-image" data-image-url="<?= htmlspecialchars($chatAttachmentUrl) ?>" data-image-title="<?= htmlspecialchars($chatAttachmentName) ?>">
                                                <img src="<?= htmlspecialchars($chatAttachmentUrl) ?>" alt="<?= htmlspecialchars($chatAttachmentName) ?>" class="message-attachment__thumb">
                                                <span class="message-attachment__caption"><i class="fas fa-search-plus"></i> Посмотреть изображение</span>
                                            </button>
                                        <?php else: ?>
                                            <a href="<?= htmlspecialchars($chatAttachmentUrl) ?>" class="message-attachment__file" target="_blank" rel="noopener" download="<?= htmlspecialchars($chatAttachmentName) ?>">
                                                <i class="fas fa-download"></i>
                                                <span><?= htmlspecialchars($chatAttachmentName) ?></span>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-secondary">Сообщений пока нет.</p>
                <?php endif; ?>
            </div>

            <?php if ($chatClosed): ?>
                <div class="alert alert--warning" style="margin-top:12px;">
                    <i class="fas fa-lock"></i> <?= htmlspecialchars($chatClosedText) ?>
                </div>
            <?php elseif ($chatFormAction !== ''): ?>
                <form method="POST" class="dispute-chat-modal__composer"<?= $chatSupportsAttachments ? ' enctype="multipart/form-data"' : '' ?>>
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <?php foreach ($chatComposerHiddenFields as $fieldName => $fieldValue): ?>
                        <input type="hidden" name="<?= htmlspecialchars((string) $fieldName) ?>" value="<?= htmlspecialchars((string) $fieldValue, ENT_QUOTES, 'UTF-8') ?>">
                    <?php endforeach; ?>
                    <?php if ($chatSupportsAttachments): ?>
                        <input type="file" id="<?= htmlspecialchars($chatAttachmentInputId) ?>" name="attachment" class="chat-composer__attachment-input js-message-attachment-input" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.doc,.docx,.rtf,.xls,.xlsx,.csv,.zip,image/*,application/pdf,text/plain,text/csv">
                        <div class="message-attachment-preview chat-composer__attachment-preview js-message-attachment-preview" hidden></div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars($chatComposerLabel) ?></label>
                        <textarea name="<?= htmlspecialchars($chatComposerTextareaName) ?>" class="form-textarea js-chat-hotkey" rows="4" placeholder="<?= htmlspecialchars($chatComposerPlaceholder) ?>"></textarea>
                    </div>
                    <div class="chat-composer__actions">
                        <?php if ($chatSupportsAttachments): ?>
                            <label class="chat-composer__attachment-trigger" for="<?= htmlspecialchars($chatAttachmentInputId) ?>" title="Прикрепить файл">
                                <i class="fas fa-paperclip"></i>
                                <span>Файл</span>
                            </label>
                            <div class="chat-composer__attachment-help"><?= htmlspecialchars($chatAttachmentHelp) ?></div>
                        <?php endif; ?>
                        <button type="submit" class="btn btn--primary">
                            <i class="fas fa-paper-plane"></i> <?= htmlspecialchars($chatComposerButtonText) ?>
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
