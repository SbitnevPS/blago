<script>
if (typeof window.escapeHtml !== 'function') {
    window.escapeHtml = function(text) {
        const div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    };
}

if (typeof window.openMessageImageModal !== 'function') {
    window.openMessageImageModal = function(encodedUrl, encodedTitle) {
        const modal = document.getElementById('messageImageModal');
        const image = document.getElementById('messageImageModalImage');
        const title = document.getElementById('messageImageModalTitle');
        const imageUrl = decodeURIComponent(encodedUrl || '');
        const imageTitle = decodeURIComponent(encodedTitle || '');
        if (!modal || !image || !title || !imageUrl) return;
        image.src = imageUrl;
        image.alt = imageTitle;
        title.textContent = imageTitle || 'Просмотр изображения';
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    };
}

if (typeof window.closeMessageImageModal !== 'function') {
    window.closeMessageImageModal = function() {
        const modal = document.getElementById('messageImageModal');
        const image = document.getElementById('messageImageModalImage');
        if (!modal || !image) return;
        modal.classList.remove('active');
        image.src = '';
        image.alt = '';
        const otherModal = document.querySelector('.modal.active:not(#messageImageModal)');
        document.body.style.overflow = otherModal ? 'hidden' : '';
    };
}

if (typeof window.buildFrontendAttachmentPreviewMarkup !== 'function') {
    const formatAttachmentSize = (size) => {
        const value = Number(size || 0);
        if (!Number.isFinite(value) || value <= 0) return '';
        if (value >= 1024 * 1024) return (value / (1024 * 1024)).toFixed(1).replace('.0', '') + ' МБ';
        if (value >= 1024) return Math.round(value / 1024) + ' КБ';
        return value + ' Б';
    };

    window.buildFrontendAttachmentPreviewMarkup = function(file) {
        if (!file) return '';
        const fileName = window.escapeHtml(file.name || 'Файл');
        const fileSize = window.escapeHtml(formatAttachmentSize(file.size));
        const fileMeta = fileSize !== '' ? `<span class="chat-composer__attachment-preview-size">${fileSize}</span>` : '';
        const isImage = String(file.type || '').startsWith('image/') || /\.(png|jpe?g|gif|webp|bmp|svg)$/i.test(String(file.name || ''));
        if (isImage) {
            const objectUrl = URL.createObjectURL(file);
            return `<div class="chat-composer__attachment-preview-item chat-composer__attachment-preview-item--image" title="${fileName}"><button type="button" class="chat-composer__attachment-preview-main js-local-image-preview" data-image-src="${window.escapeHtml(objectUrl)}" data-image-title="${fileName}" title="${fileName}"><img src="${window.escapeHtml(objectUrl)}" alt="${fileName}" class="chat-composer__attachment-preview-thumb"><span class="chat-composer__attachment-preview-text"><span class="chat-composer__attachment-preview-name">${fileName}</span>${fileMeta}</span></button><button type="button" class="chat-composer__attachment-remove js-message-attachment-remove" title="Удалить вложение" aria-label="Удалить вложение"><i class="fas fa-times"></i></button></div>`;
        }
        return `<div class="chat-composer__attachment-preview-item" title="${fileName}"><span class="chat-composer__attachment-preview-icon"><i class="fas fa-paperclip"></i></span><span class="chat-composer__attachment-preview-text"><span class="chat-composer__attachment-preview-name">${fileName}</span>${fileMeta}</span><button type="button" class="chat-composer__attachment-remove js-message-attachment-remove" title="Удалить вложение" aria-label="Удалить вложение"><i class="fas fa-times"></i></button></div>`;
    };
}

if (typeof window.resetFrontendAttachmentPreview !== 'function') {
    window.resetFrontendAttachmentPreview = function(form) {
        if (!form) return;
        const input = form.querySelector('.js-message-attachment-input');
        const preview = form.querySelector('.js-message-attachment-preview');
        if (preview) {
            preview.querySelectorAll('.js-local-image-preview').forEach((button) => {
                const src = String(button.dataset.imageSrc || '');
                if (src.startsWith('blob:')) {
                    URL.revokeObjectURL(src);
                }
            });
        }
        if (input) {
            input.value = '';
        }
        if (preview) {
            preview.innerHTML = '';
            preview.hidden = true;
        }
    };
}

if (typeof window.bindFrontendChatHelpers !== 'function') {
    window.bindFrontendChatHelpers = function() {
        document.querySelectorAll('.js-open-message-image').forEach((button) => {
            if (button.dataset.boundImagePreview === '1') return;
            button.dataset.boundImagePreview = '1';
            button.addEventListener('click', () => {
                window.openMessageImageModal(
                    encodeURIComponent(button.dataset.imageUrl || ''),
                    encodeURIComponent(button.dataset.imageTitle || 'Просмотр изображения')
                );
            });
        });

        const imageModal = document.getElementById('messageImageModal');
        if (imageModal && imageModal.dataset.boundImageModal !== '1') {
            imageModal.dataset.boundImageModal = '1';
            imageModal.addEventListener('click', function(event) {
                if (event.target === this) {
                    window.closeMessageImageModal();
                }
            });
        }

        document.querySelectorAll('.js-chat-hotkey').forEach((textarea) => {
            if (textarea.dataset.boundChatHotkey === '1') return;
            textarea.dataset.boundChatHotkey = '1';
            textarea.addEventListener('keydown', (event) => {
                if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
                    event.preventDefault();
                    const form = textarea.closest('form');
                    if (form && form.reportValidity()) {
                        form.requestSubmit();
                    }
                }
            });
        });

        document.querySelectorAll('.js-message-attachment-input').forEach((input) => {
            if (input.dataset.boundAttachmentPreview === '1') return;
            input.dataset.boundAttachmentPreview = '1';
            const preview = input.closest('form')?.querySelector('.js-message-attachment-preview');
            if (!preview) return;
            input.addEventListener('change', () => {
                preview.querySelectorAll('.js-local-image-preview').forEach((button) => {
                    const src = String(button.dataset.imageSrc || '');
                    if (src.startsWith('blob:')) {
                        URL.revokeObjectURL(src);
                    }
                });
                const file = input.files && input.files[0] ? input.files[0] : null;
                preview.innerHTML = '';
                preview.hidden = !file;
                if (!file) return;
                preview.innerHTML = window.buildFrontendAttachmentPreviewMarkup(file);
                preview.querySelectorAll('.js-local-image-preview').forEach((button) => {
                    button.addEventListener('click', () => {
                        window.openMessageImageModal(
                            encodeURIComponent(button.dataset.imageSrc || ''),
                            encodeURIComponent(button.dataset.imageTitle || 'Просмотр изображения')
                        );
                    });
                });
                preview.querySelectorAll('.js-message-attachment-remove').forEach((button) => {
                    button.addEventListener('click', () => {
                        preview.querySelectorAll('.js-local-image-preview').forEach((previewButton) => {
                            const src = String(previewButton.dataset.imageSrc || '');
                            if (src.startsWith('blob:')) {
                                URL.revokeObjectURL(src);
                            }
                        });
                        input.value = '';
                        preview.innerHTML = '';
                        preview.hidden = true;
                    });
                });
            });
        });
    };
}

window.bindFrontendChatHelpers();
</script>
