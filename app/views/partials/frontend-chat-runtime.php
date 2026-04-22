<script>
if (typeof window.initFrontendLiveChat !== 'function') {
    window.initFrontendLiveChat = function(options) {
        const config = Object.assign({
            modalId: 'disputeChatModal',
            messagesContainerId: 'disputeChatMessages',
            formSelector: '#disputeChatModal form.dispute-chat-modal__composer',
            textareaSelector: 'textarea',
            pollUrlBuilder: null,
            submitUrl: window.location.href,
            openState: false,
            updateOpenState: null,
            onToastClick: null,
            onSubmitSuccess: null,
            onSubmitError: null,
            onDeleteAttachment: null,
            onCloseOverlay: null,
            autoOpenHash: '',
            pollOpenDelay: 5000,
            pollClosedDelay: 30000,
        }, options || {});

        const modal = document.getElementById(config.modalId);
        const messagesContainer = document.getElementById(config.messagesContainerId);
        if (!modal || !messagesContainer) {
            return null;
        }

        let pollTimerId = null;
        let latestMessageId = Math.max(
            0,
            ...Array.from(messagesContainer.querySelectorAll('.dispute-chat-message'))
                .map((node) => Number(node.dataset.messageId || 0))
                .filter((value) => Number.isFinite(value))
        );

        function setOpenState(nextState) {
            config.openState = Boolean(nextState);
            if (typeof config.updateOpenState === 'function') {
                config.updateOpenState(config.openState);
            }
        }

        function scrollToBottom() {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }

        function appendAttachment(bubble, attachment, messageData) {
            if (!bubble || !attachment || !attachment.url) return;

            const attachmentWrap = document.createElement('div');
            attachmentWrap.className = 'message-attachment';
            attachmentWrap.style.marginTop = '10px';

            if (attachment.is_image) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'message-attachment__image-button';
                button.addEventListener('click', () => {
                    window.openMessageImageModal(
                        encodeURIComponent(attachment.url || ''),
                        encodeURIComponent(attachment.name || 'Изображение')
                    );
                });

                const image = document.createElement('img');
                image.className = 'message-attachment__thumb';
                image.src = attachment.url || '';
                image.alt = attachment.name || 'Изображение';

                const caption = document.createElement('span');
                caption.className = 'message-attachment__caption';
                caption.innerHTML = '<i class="fas fa-search-plus"></i> Посмотреть изображение';

                button.appendChild(image);
                button.appendChild(caption);
                attachmentWrap.appendChild(button);
            } else {
                const link = document.createElement('a');
                link.className = 'message-attachment__file';
                link.href = attachment.url || '#';
                link.target = '_blank';
                link.rel = 'noopener';
                link.download = attachment.name || 'attachment';
                link.innerHTML = '<i class="fas fa-download"></i><span>' + window.escapeHtml(attachment.name || 'Файл') + '</span>';
                attachmentWrap.appendChild(link);
            }

            if (messageData && messageData.can_delete_attachment) {
                const removeButton = document.createElement('button');
                removeButton.type = 'button';
                removeButton.className = 'btn btn--ghost btn--sm js-delete-chat-attachment';
                removeButton.dataset.messageId = String(Number(messageData.id || 0));
                removeButton.style.marginTop = '8px';
                removeButton.style.color = '#ef4444';
                removeButton.innerHTML = '<i class="fas fa-trash"></i> Удалить файл';
                attachmentWrap.appendChild(removeButton);
            }

            bubble.appendChild(attachmentWrap);
        }

        function appendMessage(messageData) {
            if (!messageData) return;

            const numericId = Number(messageData.id || 0);
            if (numericId > 0 && messagesContainer.querySelector(`.dispute-chat-message[data-message-id="${numericId}"]`)) {
                return;
            }

            const messageWrap = document.createElement('div');
            messageWrap.className = 'dispute-chat-message ' + (messageData.from_admin ? 'dispute-chat-message--user' : 'dispute-chat-message--admin');
            messageWrap.dataset.messageId = String(numericId || 0);

            const bubble = document.createElement('div');
            bubble.className = 'dispute-chat-message__bubble';

            const meta = document.createElement('div');
            meta.className = 'dispute-chat-message__meta';
            meta.textContent = (messageData.author_label || 'Пользователь') + ' • ' + (messageData.created_at || '');

            const text = document.createElement('div');
            text.className = 'dispute-chat-message__text';
            text.textContent = messageData.content || '';

            bubble.appendChild(meta);
            bubble.appendChild(text);

            if (messageData.attachment) {
                appendAttachment(bubble, messageData.attachment, messageData);
            }

            messageWrap.appendChild(bubble);
            messagesContainer.appendChild(messageWrap);
            if (typeof window.bindFrontendChatHelpers === 'function') {
                window.bindFrontendChatHelpers();
            }
            scrollToBottom();

            if (numericId > latestMessageId) {
                latestMessageId = numericId;
            }
        }

        function showToast(messageData) {
            if (!messageData) return;
            const previewSource = (messageData.content || '').trim();
            const preview = previewSource !== '' ? previewSource.slice(0, 50) : 'Новое сообщение в чате';
            const authorName = (messageData.author_name || 'Пользователь').trim();

            const toast = document.createElement('div');
            toast.className = 'alert alert--success';
            toast.style.cssText = 'position:fixed; top:20px; right:20px; z-index:3200; min-width:280px; max-width:420px; box-shadow:0 12px 30px rgba(0,0,0,.12); cursor:pointer;';
            toast.innerHTML =
                '<div style="font-size:11px; opacity:.8; margin-bottom:4px;">новое сообщение</div>' +
                '<div style="font-weight:600;">' + window.escapeHtml(authorName) + '</div>' +
                '<div style="margin-top:4px; opacity:.9;">' + window.escapeHtml(preview) + (previewSource.length > 50 ? '...' : '') + '</div>';
            toast.addEventListener('click', () => {
                if (typeof config.onToastClick === 'function') {
                    config.onToastClick(messageData);
                }
                toast.remove();
            });
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 6000);
        }

        async function pollMessages() {
            if (typeof config.pollUrlBuilder !== 'function') return;

            try {
                const requestUrl = config.pollUrlBuilder(latestMessageId);
                if (!requestUrl) return;
                const response = await fetch(requestUrl, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await response.json();
                if (!response.ok || !data.success || !Array.isArray(data.messages)) return;

                data.messages.forEach((messageData) => {
                    appendMessage(messageData);
                    if (!config.openState) {
                        showToast(messageData);
                    }
                });
            } catch (error) {
                console.error('Ошибка polling чата:', error);
            }
        }

        function schedulePolling() {
            if (pollTimerId) {
                clearTimeout(pollTimerId);
                pollTimerId = null;
            }

            if (typeof config.pollUrlBuilder !== 'function') return;

            const delay = config.openState ? config.pollOpenDelay : config.pollClosedDelay;
            pollTimerId = setTimeout(async () => {
                await pollMessages();
                schedulePolling();
            }, delay);
        }

        modal.addEventListener('click', (event) => {
            if (event.target === modal && typeof config.onCloseOverlay === 'function') {
                config.onCloseOverlay();
            }
        });

        messagesContainer.addEventListener('click', async (event) => {
            const target = event.target instanceof Element ? event.target.closest('.js-delete-chat-attachment') : null;
            if (!target) return;
            if (!(target instanceof HTMLButtonElement)) return;
            const messageId = Number(target.dataset.messageId || 0);
            if (!messageId || typeof config.onDeleteAttachment !== 'function') return;
            event.preventDefault();
            const ok = window.confirm('Удалить загруженный файл из этого сообщения?');
            if (!ok) return;
            target.disabled = true;
            try {
                await config.onDeleteAttachment(messageId, target);
            } catch (error) {
                console.error(error);
            } finally {
                target.disabled = false;
            }
        });

        const form = document.querySelector(config.formSelector);
        if (form) {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const textarea = form.querySelector(config.textareaSelector);
                if (!textarea || !form.reportValidity()) return;

                const submitButton = form.querySelector('button[type="submit"]');
                const originalButtonHtml = submitButton ? submitButton.innerHTML : '';
                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Отправка...';
                }

                const formData = new FormData(form);
                formData.append('ajax', '1');

                try {
                    const response = await fetch(config.submitUrl, {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: formData,
                    });
                    const data = await response.json();
                    if (!response.ok || !data.success) {
                        throw new Error(data.error || 'Не удалось отправить сообщение');
                    }

                    appendMessage(data.message);
                    textarea.value = '';
                    if (typeof window.resetFrontendAttachmentPreview === 'function') {
                        window.resetFrontendAttachmentPreview(form);
                    }
                    if (typeof config.onSubmitSuccess === 'function') {
                        config.onSubmitSuccess(data);
                    }
                } catch (error) {
                    if (typeof config.onSubmitError === 'function') {
                        config.onSubmitError(error);
                    } else {
                        console.error(error);
                    }
                } finally {
                    if (submitButton) {
                        submitButton.disabled = false;
                        submitButton.innerHTML = originalButtonHtml;
                    }
                }
            });
        }

        if (typeof window.bindFrontendChatHelpers === 'function') {
            window.bindFrontendChatHelpers();
        }
        scrollToBottom();

        if (config.autoOpenHash && window.location.hash === config.autoOpenHash && typeof config.onToastClick === 'function') {
            config.onToastClick(null);
        }

        schedulePolling();

        return {
            appendMessage,
            schedulePolling,
            setOpenState,
            getLatestMessageId: () => latestMessageId,
            scrollToBottom,
        };
    };
}
</script>
