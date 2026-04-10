(() => {
  const escapeHtml = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');

  const normalizeText = (value) => String(value ?? '').replace(/\s+/g, ' ').trim();

  const resolveTemplateToken = (token, item) => {
    const [rawFields, rawFallback = ''] = token.split('||');
    const fields = String(rawFields || '')
      .split('+')
      .map((part) => part.trim())
      .filter(Boolean);

    const values = fields
      .map((field) => normalizeText(item?.[field]))
      .filter(Boolean);

    if (values.length > 0) {
      return values.join(' ');
    }

    return normalizeText(rawFallback);
  };

  const resolveTemplate = (template, item) => String(template || '').replace(/\{\{([^}]+)\}\}/g, (_, token) => {
    return resolveTemplateToken(token, item);
  });

  const formatItem = (item, config) => {
    const primary = normalizeText(resolveTemplate(config.primaryTemplate, item));
    const secondary = normalizeText(resolveTemplate(config.secondaryTemplate, item));
    const value = normalizeText(resolveTemplate(config.valueTemplate, item));
    const rawId = item?.[config.idField];

    return {
      id: Number(rawId || 0),
      primary,
      secondary,
      value,
    };
  };

  const initLiveSearch = (root, index) => {
    const input = root.querySelector('[data-live-search-input]');
    const hiddenInput = root.querySelector('[data-live-search-hidden]');
    const results = root.querySelector('[data-live-search-results]');

    if (!input || !hiddenInput || !results) {
      return;
    }

    const endpoint = root.dataset.endpoint || '';
    if (endpoint === '') {
      return;
    }

    const primaryTemplate = root.dataset.primaryTemplate || '';
    const valueTemplate = root.dataset.valueTemplate || primaryTemplate;
    if (primaryTemplate === '') {
      return;
    }

    const queryParam = root.dataset.queryParam || 'q';
    const idField = root.dataset.idField || 'id';
    const secondaryTemplate = root.dataset.secondaryTemplate || '';
    const emptyText = root.dataset.emptyText || 'Ничего не найдено';
    const limit = Number.parseInt(root.dataset.limit || '', 10);
    const minLength = Number.parseInt(root.dataset.minLength || '2', 10);
    const minLengthNumeric = Number.parseInt(root.dataset.minLengthNumeric || '1', 10);
    const debounceMs = Number.parseInt(root.dataset.debounce || '220', 10);
    const listboxId = `admin-live-search-results-${index}`;
    const templateConfig = {
      idField,
      primaryTemplate,
      secondaryTemplate,
      valueTemplate,
    };

    let timerId = null;
    let activeIndex = -1;
    let abortController = null;
    let renderedItems = [];

    results.id = results.id || listboxId;
    results.setAttribute('role', 'listbox');
    input.setAttribute('autocomplete', 'off');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', results.id);

    const isResultsOpen = () => results.style.display === 'block';

    const syncActiveItem = () => {
      const buttons = results.querySelectorAll('.user-results__item');
      buttons.forEach((button, buttonIndex) => {
        const isActive = buttonIndex === activeIndex;
        button.classList.toggle('is-active', isActive);
        button.setAttribute('aria-selected', isActive ? 'true' : 'false');
        if (isActive) {
          input.setAttribute('aria-activedescendant', button.id);
          button.scrollIntoView({ block: 'nearest' });
        }
      });

      if (activeIndex < 0) {
        input.removeAttribute('aria-activedescendant');
      }
    };

    const hideResults = () => {
      renderedItems = [];
      activeIndex = -1;
      if (abortController) {
        abortController.abort();
        abortController = null;
      }
      results.style.display = 'none';
      results.innerHTML = '';
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
    };

    const selectItem = (item) => {
      hiddenInput.value = String(item.id || '');
      input.value = item.value || '';
      hideResults();
    };

    const setActiveIndex = (nextIndex) => {
      if (!renderedItems.length) {
        activeIndex = -1;
        syncActiveItem();
        return;
      }

      if (nextIndex < 0) {
        activeIndex = renderedItems.length - 1;
      } else if (nextIndex >= renderedItems.length) {
        activeIndex = 0;
      } else {
        activeIndex = nextIndex;
      }

      syncActiveItem();
    };

    const renderItems = (items) => {
      renderedItems = Array.isArray(items)
        ? items.map((item) => formatItem(item, templateConfig)).filter((item) => item.id > 0 && item.primary !== '')
        : [];

      if (!renderedItems.length) {
        results.innerHTML = `<div class="user-results__empty">${escapeHtml(emptyText)}</div>`;
        results.style.display = 'block';
        input.setAttribute('aria-expanded', 'true');
        activeIndex = -1;
        input.removeAttribute('aria-activedescendant');
        return;
      }

      results.innerHTML = renderedItems.map((item, itemIndex) => {
        const itemId = `${results.id}-item-${itemIndex}`;
        return `
          <button
            type="button"
            id="${itemId}"
            class="user-results__item"
            role="option"
            aria-selected="false"
            data-index="${itemIndex}">
            <div class="user-results__name">${escapeHtml(item.primary)}</div>
            ${item.secondary !== '' ? `<div class="user-results__email">${escapeHtml(item.secondary)}</div>` : ''}
          </button>
        `;
      }).join('');

      results.style.display = 'block';
      input.setAttribute('aria-expanded', 'true');
      activeIndex = -1;
      input.removeAttribute('aria-activedescendant');
    };

    const requestItems = async (query) => {
      if (abortController) {
        abortController.abort();
      }

      abortController = new AbortController();
      const url = new URL(endpoint, window.location.origin);
      url.searchParams.set(queryParam, query);
      if (Number.isFinite(limit) && limit > 0) {
        url.searchParams.set('limit', String(limit));
      }

      const response = await fetch(url.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        cache: 'no-store',
        signal: abortController.signal,
      });

      if (!response.ok) {
        throw new Error(`Request failed with status ${response.status}`);
      }

      const payload = await response.json();
      if (!Array.isArray(payload)) {
        const message = typeof payload?.error === 'string' && payload.error.trim() !== ''
          ? payload.error.trim()
          : emptyText;
        results.innerHTML = `<div class="user-results__empty">${escapeHtml(message)}</div>`;
        results.style.display = 'block';
        input.setAttribute('aria-expanded', 'true');
        activeIndex = -1;
        input.removeAttribute('aria-activedescendant');
        abortController = null;
        return;
      }
      renderItems(payload);
      abortController = null;
    };

    input.addEventListener('input', () => {
      hiddenInput.value = '';
      const query = input.value.trim();

      if (timerId) {
        clearTimeout(timerId);
      }

      if (query === '') {
        hideResults();
        return;
      }

      const isNumericQuery = /^\d+$/.test(query);
      const requiredLength = isNumericQuery ? minLengthNumeric : minLength;
      if (query.length < requiredLength) {
        hideResults();
        return;
      }

      timerId = window.setTimeout(() => {
        requestItems(query).catch((error) => {
          if (error.name !== 'AbortError') {
            hideResults();
          }
        });
      }, debounceMs);
    });

    input.addEventListener('keydown', (event) => {
      if (event.key === 'Backspace' && !input.value.trim()) {
        hiddenInput.value = '';
      }

      if (!isResultsOpen()) {
        if (event.key === 'Escape') {
          hideResults();
        }
        return;
      }

      if (event.key === 'ArrowDown') {
        event.preventDefault();
        setActiveIndex(activeIndex + 1);
        return;
      }

      if (event.key === 'ArrowUp') {
        event.preventDefault();
        setActiveIndex(activeIndex - 1);
        return;
      }

      if (event.key === 'Enter') {
        if (!renderedItems.length) {
          return;
        }
        event.preventDefault();
        const selectedItem = renderedItems[activeIndex >= 0 ? activeIndex : 0];
        if (selectedItem) {
          selectItem(selectedItem);
        }
        return;
      }

      if (event.key === 'Escape') {
        event.preventDefault();
        hideResults();
      }
    });

    results.addEventListener('mousedown', (event) => {
      event.preventDefault();
    });

    results.addEventListener('mousemove', (event) => {
      const item = event.target.closest('.user-results__item');
      if (!item) {
        return;
      }
      const nextIndex = Number.parseInt(item.dataset.index || '-1', 10);
      if (Number.isFinite(nextIndex) && nextIndex !== activeIndex) {
        activeIndex = nextIndex;
        syncActiveItem();
      }
    });

    results.addEventListener('click', (event) => {
      const item = event.target.closest('.user-results__item');
      if (!item) {
        return;
      }
      const nextIndex = Number.parseInt(item.dataset.index || '-1', 10);
      const selectedItem = Number.isFinite(nextIndex) ? renderedItems[nextIndex] : null;
      if (selectedItem) {
        selectItem(selectedItem);
      }
    });

    document.addEventListener('click', (event) => {
      if (!root.contains(event.target)) {
        hideResults();
      }
    });
  };

  const roots = document.querySelectorAll('[data-live-search]');
  roots.forEach((root, index) => initLiveSearch(root, index + 1));
})();
