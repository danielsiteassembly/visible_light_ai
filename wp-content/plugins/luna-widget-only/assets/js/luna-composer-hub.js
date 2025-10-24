(function(){
  window.LunaComposerIntegrated = true;
  const ready = (fn) => {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn, { once: true });
    } else {
      fn();
    }
  };

  const settings = window.lunaComposerSettings || {};
  const restUrl = settings.restUrlChat || '/wp-json/luna_widget/v1/chat';
  const nonce = settings.nonce || null;
  const defaults = Array.isArray(settings.prompts) && settings.prompts.length ? settings.prompts : [
    { label: 'What can Luna help me with?', prompt: "Hey Luna! What can you help me with today?" },
    { label: 'Site health overview', prompt: 'Can you give me a quick health check of my WordPress site?' },
    { label: 'Pending updates', prompt: 'Do I have any plugin, theme, or WordPress core updates waiting?' },
    { label: 'Security status', prompt: 'Is my SSL certificate active and are there any security concerns?' },
    { label: 'Content inventory', prompt: 'How many pages and posts are on the site right now?' },
    { label: 'Help contact info', prompt: 'Remind me how someone can contact our team for help.' },
  ];

  const accountsRaw = Array.isArray(settings.accounts) ? settings.accounts : [];
  const accountsByLicense = new Map();
  const accounts = accountsRaw.reduce((collection, item) => {
    if (!item || typeof item !== 'object') {
      return collection;
    }
    const license = typeof item.license === 'string' ? item.license.trim() : '';
    if (!license) {
      return collection;
    }
    const normalized = {
      license,
      label: typeof item.label === 'string' && item.label.trim() ? item.label.trim() : license,
      site: typeof item.site === 'string' ? item.site.trim() : '',
      status: typeof item.status === 'string' ? item.status.trim() : '',
      isDemo: Boolean(item.isDemo),
    };
    collection.push(normalized);
    accountsByLicense.set(license, normalized);
    return collection;
  }, []);

  let sharedAccountKey = typeof settings.activeAccount === 'string' ? settings.activeAccount.trim() : '';
  if (sharedAccountKey && !accountsByLicense.has(sharedAccountKey)) {
    sharedAccountKey = '';
  }
  if (!sharedAccountKey && accounts.length) {
    const demoAccount = accounts.find((account) => account.isDemo);
    sharedAccountKey = demoAccount ? demoAccount.license : accounts[0].license;
  }

  const composerInstances = new Map();
  let stylesInjected = false;

  const escapeHTML = (input) => {
    return (input || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  };

  const formatAnswer = (answer) => {
    const safe = escapeHTML((answer || '').trim());
    return safe.replace(/\n{2,}/g, '<br><br>').replace(/\n/g, '<br>');
  };

  const injectStyles = () => {
    if (stylesInjected || document.getElementById('luna-composer-style')) {
      stylesInjected = true;
      return;
    }
    const style = document.createElement('style');
    style.id = 'luna-composer-style';
    style.textContent = `
      [data-luna-composer] {
        --luna-composer-bg: rgba(15, 23, 42, 0.85);
        --luna-composer-border: rgba(148, 163, 184, 0.35);
        --luna-composer-radius: 18px;
        --luna-composer-text: #f8fafc;
        --luna-composer-placeholder: rgba(148, 163, 184, 0.75);
        display: block;
        max-width: 740px;
        margin: 0 auto 2rem auto;
      }
      [data-luna-composer] .luna-composer__card {
        background: var(--luna-composer-bg);
        border: 1px solid var(--luna-composer-border);
        border-radius: var(--luna-composer-radius);
        color: var(--luna-composer-text);
        padding: 1.5rem;
        box-shadow: 0 18px 40px rgba(15, 23, 42, 0.25);
        backdrop-filter: blur(12px);
      }
      [data-luna-composer] .luna-composer__header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 1.25rem;
      }
      [data-luna-composer] .luna-composer__header h2 {
        margin: 0;
        font-size: 1.25rem;
      }
      [data-luna-composer] .luna-composer__account {
        margin-bottom: 1rem;
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
      }
      [data-luna-composer] .luna-composer__account select {
        background: rgba(15, 23, 42, 0.55);
        border: 1px solid rgba(148, 163, 184, 0.45);
        border-radius: 12px;
        color: inherit;
        padding: 0.55rem 0.75rem;
        font-size: 0.95rem;
      }
      [data-luna-composer] .luna-composer__account select:focus {
        outline: none;
        border-color: rgba(129, 140, 248, 0.95);
        box-shadow: 0 0 0 1px rgba(129, 140, 248, 0.35);
      }
      [data-luna-composer] .luna-composer__account-meta {
        font-size: 0.85rem;
        opacity: 0.85;
      }
      [data-luna-composer] .luna-composer__editor {
        position: relative;
        min-height: 120px;
        padding: 1rem;
        border: 1px solid rgba(148, 163, 184, 0.45);
        border-radius: 16px;
        background: rgba(15, 23, 42, 0.55);
        font-size: 1rem;
        line-height: 1.5;
        outline: none;
        white-space: pre-wrap;
        overflow-y: auto;
      }
      [data-luna-composer] .luna-composer__editor.is-empty::before {
        content: attr(data-placeholder);
        position: absolute;
        top: 1rem;
        left: 1rem;
        right: 1rem;
        color: var(--luna-composer-placeholder);
        pointer-events: none;
      }
      [data-luna-composer] .luna-composer__editor:focus {
        border-color: rgba(129, 140, 248, 0.95);
        box-shadow: 0 0 0 1px rgba(129, 140, 248, 0.35);
      }
      [data-luna-composer] .luna-composer__actions {
        display: flex;
        justify-content: flex-end;
        gap: 0.75rem;
        margin-top: 1rem;
      }
      [data-luna-composer] .luna-composer__submit {
        background: linear-gradient(135deg, rgba(99, 102, 241, 0.95), rgba(79, 70, 229, 0.9));
        color: #fff;
        border: none;
        border-radius: 999px;
        padding: 0.65rem 1.75rem;
        font-size: 0.95rem;
        font-weight: 600;
        cursor: pointer;
        transition: transform 120ms ease, box-shadow 120ms ease;
      }
      [data-luna-composer] .luna-composer__submit:hover,
      [data-luna-composer] .luna-composer__submit:focus-visible {
        transform: translateY(-1px);
        box-shadow: 0 12px 30px rgba(99, 102, 241, 0.35);
        outline: none;
      }
      [data-luna-composer] .luna-composer__submit[disabled] {
        opacity: 0.65;
        cursor: progress;
        transform: none;
        box-shadow: none;
      }
      [data-luna-composer] .luna-composer__response {
        margin-top: 1.5rem;
        padding: 1.25rem;
        border-radius: 16px;
        border: 1px solid rgba(148, 163, 184, 0.35);
        background: rgba(15, 23, 42, 0.45);
        min-height: 60px;
      }
      [data-luna-composer] .luna-composer__response[data-loading="true"]::after {
        content: 'Thinking…';
        display: inline-block;
        margin-top: 0.35rem;
        opacity: 0.75;
        font-style: italic;
        animation: lunaComposerPulse 1.2s ease-in-out infinite;
      }
      [data-luna-composer] .luna-composer__response .luna-composer__answer {
        white-space: pre-wrap;
      }
      [data-luna-composer] .luna-composer__response .luna-composer__meta {
        margin-top: 0.75rem;
        font-size: 0.85rem;
        opacity: 0.85;
      }
      [data-luna-composer] .luna-composer__response .luna-composer__error {
        color: #fecaca;
      }
      [data-luna-composer] .luna-composer-prompts {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 0.75rem;
        margin-bottom: 1.25rem;
      }
      [data-luna-composer] .luna-composer-prompts__button {
        border: 1px solid rgba(148, 163, 184, 0.35);
        border-radius: 999px;
        background: rgba(15, 23, 42, 0.55);
        color: inherit;
        padding: 0.55rem 0.9rem;
        font-size: 0.9rem;
        line-height: 1.2;
        text-align: left;
        cursor: pointer;
        transition: border-color 120ms ease, background-color 120ms ease, transform 120ms ease;
      }
      [data-luna-composer] .luna-composer-prompts__button:hover,
      [data-luna-composer] .luna-composer-prompts__button:focus-visible {
        border-color: rgba(99, 102, 241, 0.65);
        background: rgba(79, 70, 229, 0.18);
        outline: none;
        transform: translateY(-1px);
      }
      [data-luna-composer] .luna-composer-prompts__button[aria-pressed="true"] {
        border-color: rgba(99, 102, 241, 0.85);
        background: rgba(79, 70, 229, 0.28);
      }
      @keyframes lunaComposerPulse {
        0%, 100% { opacity: 0.35; }
        50% { opacity: 0.9; }
      }
    `;
    document.head.appendChild(style);
    stylesInjected = true;
  };

  const hydrateLegacyPrompts = () => {
    const items = Array.from(document.querySelectorAll('[data-luna-prompt-item]'));
    if (!items.length || !composerInstances.size) {
      return;
    }
    const instances = Array.from(composerInstances.values());
    const fallback = instances[0];

    items.forEach((item) => {
      if (!item || item.dataset.lunaPromptBound === 'true') {
        return;
      }
      const target = item.getAttribute('data-luna-prompt-target');
      const instance = target && composerInstances.has(target) ? composerInstances.get(target) : fallback;
      if (!instance) {
        return;
      }
      const prompt = item.getAttribute('data-luna-prompt') || item.textContent || '';
      if (instance.bindPrompt(item, prompt)) {
        item.setAttribute('data-luna-prompt-target', instance.id);
      }
    });
  };

  const initComposer = (root, index) => {
    if (!root || root.dataset.lunaComposerInit === 'true') {
      return;
    }

    const form = root.querySelector('form');
    const editor = root.querySelector('[data-luna-composer-editor]');
    const responseNode = root.querySelector('[data-luna-composer-response]');
    const promptsContainer = root.querySelector('[data-luna-prompts]');
    const submitButton = root.querySelector('[data-luna-composer-submit]');
    const accountWrapper = root.querySelector('[data-luna-account-picker]');
    const accountSelect = root.querySelector('[data-luna-composer-account]');
    const accountMeta = root.querySelector('[data-luna-composer-account-meta]');
    const requireAccount = accountsByLicense.size > 0;

    if (!form || !editor) {
      return;
    }

    const id = root.dataset.lunaComposerId || `composer-${index + 1}`;
    root.dataset.lunaComposerId = id;

    const state = {
      activePrompt: null,
      submitting: false,
      account: null,
    };

    const formatAccountMeta = (account) => {
      if (!account) {
        return '';
      }
      const pieces = [];
      if (account.site) {
        pieces.push(account.site);
      }
      if (account.status) {
        pieces.push(account.status);
      }
      if (account.isDemo) {
        pieces.push('Demo');
      }
      return pieces.join(' · ');
    };

    const updateAccountMeta = () => {
      if (!accountMeta) {
        return;
      }
      if (state.account) {
        const summary = formatAccountMeta(state.account);
        accountMeta.textContent = summary;
        accountMeta.style.display = summary ? 'block' : 'none';
      } else if (requireAccount) {
        accountMeta.textContent = 'Select an account to compose responses.';
        accountMeta.style.display = 'block';
      } else {
        accountMeta.textContent = '';
        accountMeta.style.display = 'none';
      }
    };

    const setAccount = (account, options = {}) => {
      state.account = account || null;
      if (accountSelect) {
        accountSelect.value = state.account ? state.account.license : '';
      }
      updateAccountMeta();
      if (submitButton) {
        submitButton.disabled = state.submitting || (requireAccount && !state.account);
      }
      if (state.account) {
        sharedAccountKey = state.account.license;
      }
      if (options.broadcast !== false) {
        composerInstances.forEach((instance) => {
          if (instance.id !== id && typeof instance.setAccount === 'function') {
            instance.setAccount(account, { broadcast: false });
          }
        });
      }
    };

    const updatePlaceholder = () => {
      const text = editor.textContent.replace(/\u00a0/g, ' ').trim();
      if (text === '') {
        editor.classList.add('is-empty');
      } else {
        editor.classList.remove('is-empty');
      }
    };

    const focusEditor = () => {
      editor.focus({ preventScroll: false });
      const selection = window.getSelection();
      if (!selection) {
        return;
      }
      const range = document.createRange();
      range.selectNodeContents(editor);
      range.collapse(false);
      selection.removeAllRanges();
      selection.addRange(range);
    };

    const setEditorText = (text, shouldFocus = true) => {
      editor.textContent = text;
      updatePlaceholder();
      if (shouldFocus) {
        focusEditor();
      }
    };

    const setActivePrompt = (button) => {
      if (state.activePrompt && state.activePrompt !== button) {
        state.activePrompt.setAttribute('aria-pressed', 'false');
      }
      state.activePrompt = button || null;
      if (button) {
        button.setAttribute('aria-pressed', 'true');
      }
    };

    if (accountWrapper && accountSelect) {
      accountSelect.innerHTML = '';
      const placeholderOption = document.createElement('option');
      placeholderOption.value = '';
      placeholderOption.textContent = 'Select an account…';
      accountSelect.appendChild(placeholderOption);
      accounts.forEach((account) => {
        const option = document.createElement('option');
        option.value = account.license;
        option.textContent = account.isDemo ? `${account.label} (Demo)` : account.label;
        accountSelect.appendChild(option);
      });
      accountSelect.addEventListener('change', () => {
        const chosen = accountsByLicense.get(accountSelect.value) || null;
        setAccount(chosen);
      });
    }

    if (requireAccount) {
      const initialAccount = sharedAccountKey && accountsByLicense.get(sharedAccountKey)
        ? accountsByLicense.get(sharedAccountKey)
        : (accounts.length ? accounts[0] : null);
      setAccount(initialAccount, { broadcast: false });
    } else {
      updateAccountMeta();
    }

    const setLoading = (flag) => {
      state.submitting = flag;
      if (submitButton) {
        submitButton.disabled = flag || (requireAccount && !state.account);
      }
      form.setAttribute('aria-busy', flag ? 'true' : 'false');
      if (responseNode) {
        responseNode.dataset.loading = flag ? 'true' : 'false';
      }
    };

    const showError = (message) => {
      if (!responseNode) {
        return;
      }
      responseNode.innerHTML = `<p class="luna-composer__error">${escapeHTML(message)}</p>`;
    };

    const showAnswer = (answer, meta) => {
      if (!responseNode) {
        return;
      }
      const formatted = formatAnswer(answer || '');
      const metaLines = [];
      if (meta && meta.source) {
        metaLines.push(`Source: ${escapeHTML(meta.source)}`);
      }
      if (meta && meta.account_label) {
        metaLines.push(`Account: ${escapeHTML(meta.account_label)}`);
      }
      if (meta && meta.account_site) {
        metaLines.push(`Site: ${escapeHTML(meta.account_site)}`);
      }
      const metaBlock = metaLines.map((line) => `<p class="luna-composer__meta">${line}</p>`).join('');
      responseNode.innerHTML = `<div class="luna-composer__answer">${formatted}</div>${metaBlock}`;
    };

    const sendPrompt = async (prompt) => {
      if (!prompt || state.submitting) {
        return;
      }
      if (requireAccount && !state.account) {
        showError('Select an account before sending a prompt.');
        return;
      }
      setLoading(true);
      try {
        const payload = { prompt, context: 'composer' };
        if (state.account && state.account.license) {
          payload.license_override = state.account.license;
        }
        const headers = { 'Content-Type': 'application/json' };
        if (nonce) {
          headers['X-WP-Nonce'] = nonce;
        }
        const response = await fetch(restUrl, {
          method: 'POST',
          headers,
          body: JSON.stringify(payload),
          credentials: 'same-origin',
        });
        if (!response.ok) {
          const text = await response.text();
          throw new Error(text || `Request failed with status ${response.status}`);
        }
        const data = await response.json();
        const answer = data && (data.answer || data.message) ? (data.answer || data.message) : 'Luna did not return a response.';
        if (state.account && data && data.meta) {
          const current = state.account;
          if (data.meta.account_label) {
            current.label = data.meta.account_label;
          }
          if (data.meta.account_site) {
            current.site = data.meta.account_site;
          }
          accountsByLicense.set(current.license, current);
          updateAccountMeta();
          if (accountSelect) {
            Array.from(accountSelect.options).forEach((option) => {
              if (option.value === current.license) {
                option.textContent = current.isDemo ? `${current.label} (Demo)` : current.label;
              }
            });
          }
        }
        showAnswer(answer, data && data.meta ? data.meta : {});
      } catch (error) {
        console.error('[Luna Composer] request failed:', error);
        showError('Sorry, something went wrong reaching Luna. Please try again.');
      } finally {
        setLoading(false);
      }
    };

    const bindPrompt = (button, promptText) => {
      if (!button) {
        return false;
      }
      const normalized = (promptText || '').replace(/\s+/g, ' ').trim();
      if (normalized === '') {
        return false;
      }
      button.dataset.lunaPromptBound = 'true';
      if (!button.hasAttribute('role')) {
        button.setAttribute('role', 'button');
      }
      if (!button.hasAttribute('tabindex')) {
        button.tabIndex = 0;
      }
      button.setAttribute('aria-pressed', 'false');
      button.setAttribute('data-luna-prompt-target', id);

      const activate = () => {
        setActivePrompt(button);
        setEditorText(normalized);
      };

      button.addEventListener('click', (event) => {
        event.preventDefault();
        activate();
      });
      button.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          activate();
        }
      });
      return true;
    };

    composerInstances.set(id, {
      id,
      bindPrompt,
      setEditorText,
      setAccount: (account, options) => setAccount(account, options),
      getAccount: () => state.account,
      clearActivePrompt: () => {
        if (state.activePrompt) {
          state.activePrompt.setAttribute('aria-pressed', 'false');
          state.activePrompt = null;
        }
      },
    });

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      const value = editor.textContent.replace(/\u00a0/g, ' ').trim();
      if (!value) {
        editor.classList.add('luna-composer__editor--invalid');
        focusEditor();
        setTimeout(() => editor.classList.remove('luna-composer__editor--invalid'), 600);
        return;
      }
      sendPrompt(value);
    });

    editor.addEventListener('input', () => {
      updatePlaceholder();
      if (state.activePrompt) {
        state.activePrompt.setAttribute('aria-pressed', 'false');
        state.activePrompt = null;
      }
    });

    editor.addEventListener('focus', updatePlaceholder);
    editor.addEventListener('blur', updatePlaceholder);
    editor.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
      }
    });

    if (submitButton) {
      submitButton.addEventListener('click', () => {
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
      });
    }

    if (promptsContainer) {
      if (!promptsContainer.classList.contains('luna-composer-prompts')) {
        promptsContainer.classList.add('luna-composer-prompts');
      }
      if (!promptsContainer.hasChildNodes()) {
        defaults.forEach(({ label, prompt }) => {
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'luna-composer-prompts__button';
          button.textContent = label;
          promptsContainer.appendChild(button);
          bindPrompt(button, prompt);
        });
      } else {
        Array.from(promptsContainer.querySelectorAll('[data-luna-prompt-item], [data-prompt]')).forEach((item) => {
          if (item.dataset.lunaPromptBound === 'true') {
            return;
          }
          const prompt = item.getAttribute('data-luna-prompt') || item.getAttribute('data-prompt') || item.textContent || '';
          bindPrompt(item, prompt);
        });
      }
    }

    updatePlaceholder();
    root.dataset.lunaComposerInit = 'true';
    root.setAttribute('data-luna-composer-ready', 'true');
  };

  ready(() => {
    const roots = Array.from(document.querySelectorAll('[data-luna-composer]'));
    if (!roots.length) {
      return;
    }
    injectStyles();
    roots.forEach((root, index) => initComposer(root, index));
    hydrateLegacyPrompts();
  });
})();
