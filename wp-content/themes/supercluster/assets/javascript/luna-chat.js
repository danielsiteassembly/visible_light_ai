(function(){
  const ready = (fn) => {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn, { once: true });
    } else {
      fn();
    }
  };

  const escapeHTML = (input) => {
    return input
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  };

  const formatAnswer = (answer) => {
    const safe = escapeHTML(answer.trim());
    return safe.replace(/\n{2,}/g, '<br><br>').replace(/\n/g, '<br>');
  };

  const injectPlaceholderStyles = () => {
    if (document.getElementById('luna-composer-placeholder-style')) {
      return;
    }
    const style = document.createElement('style');
    style.id = 'luna-composer-placeholder-style';
    style.textContent = `
      .luna-editor.is-empty::before {
        content: attr(data-placeholder);
        color: var(--luna-placeholder-color, rgba(100, 116, 139, 0.85));
        pointer-events: none;
      }
      .luna-editor.is-empty:focus::before {
        color: var(--luna-placeholder-color, rgba(148, 163, 184, 0.85));
      }
      #luna-response[data-loading="true"]::after {
        content: 'Thinking…';
        display: block;
        font-style: italic;
        opacity: 0.7;
        animation: lunaPulse 1.2s ease-in-out infinite;
      }
      @keyframes lunaPulse {
        0%, 100% { opacity: 0.35; }
        50% { opacity: 0.9; }
      }
      #luna-response .luna-response__error {
        color: #fca5a5;
      }
      section.ai-canned-prompts#luna-canned [data-luna-prompt-item] {
        cursor: pointer;
      }
      section.ai-canned-prompts#luna-canned [data-luna-prompt-item]:focus-visible {
        outline: 2px solid rgba(99, 102, 241, 0.55);
        outline-offset: 2px;
      }
      section.ai-canned-prompts#luna-canned [data-luna-prompt-item][data-luna-active="true"] {
        outline: 2px solid rgba(99, 102, 241, 0.85);
        outline-offset: 2px;
      }
      #luna-attach-lightbox {
        position: fixed;
        inset: 0;
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1.5rem;
        background: rgba(15, 23, 42, 0.62);
      }
      #luna-attach-lightbox[hidden] {
        display: none;
      }
      #luna-attach-lightbox .luna-lightbox__panel {
        position: relative;
        width: min(420px, 100%);
        border-radius: 1rem;
        padding: 1.75rem 1.5rem 1.5rem;
        background: linear-gradient(160deg, rgba(15, 23, 42, 0.94), rgba(30, 41, 59, 0.96));
        color: #f8fafc;
        box-shadow: 0 35px 70px rgba(15, 23, 42, 0.45);
      }
      #luna-attach-lightbox .luna-lightbox__panel:focus {
        outline: none;
      }
      #luna-attach-lightbox .luna-lightbox__close {
        position: absolute;
        top: 0.75rem;
        right: 0.75rem;
        background: transparent;
        border: none;
        color: inherit;
        font-size: 1.5rem;
        cursor: pointer;
      }
      #luna-attach-lightbox .luna-lightbox__title {
        font-size: 1.25rem;
        margin: 0;
      }
      #luna-attach-lightbox .luna-lightbox__copy {
        margin-top: 0.5rem;
        font-size: 0.95rem;
        color: rgba(226, 232, 240, 0.9);
      }
      #luna-attach-lightbox .luna-lightbox__actions {
        margin-top: 1.5rem;
        display: grid;
        gap: 0.75rem;
      }
      #luna-attach-lightbox .luna-lightbox__action {
        display: inline-flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        width: 100%;
        border-radius: 0.75rem;
        border: 1px solid rgba(96, 165, 250, 0.4);
        padding: 0.8rem 1rem;
        background: rgba(59, 130, 246, 0.18);
        color: inherit;
        cursor: pointer;
        transition: border-color 0.2s ease, background 0.2s ease, transform 0.2s ease;
      }
      #luna-attach-lightbox .luna-lightbox__action:hover {
        border-color: rgba(96, 165, 250, 0.85);
        background: rgba(59, 130, 246, 0.35);
        transform: translateY(-1px);
      }
      #luna-attach-lightbox .luna-lightbox__action:focus-visible {
        outline: 2px solid rgba(244, 114, 182, 0.75);
        outline-offset: 2px;
      }
      #luna-attach-lightbox .luna-lightbox__hint {
        margin-top: 1rem;
        font-size: 0.85rem;
        color: rgba(203, 213, 225, 0.85);
      }
      body.luna-lightbox-open {
        overflow: hidden;
      }
    `;
    document.head.appendChild(style);
  };

  const resolveRestUrl = () => {
    if (window.lunaVars) {
      if (window.lunaVars.restUrlCompose) {
        return window.lunaVars.restUrlCompose;
      }
      if (window.lunaVars.restUrlChat) {
        return window.lunaVars.restUrlChat;
      }
    }
    if (window.wpApiSettings && window.wpApiSettings.root) {
      return window.wpApiSettings.root.replace(/\/?$/, '/') + 'luna_widget/v1/chat';
    }
    return '/wp-json/luna_widget/v1/chat';
  };

  const resolveNonce = () => {
    if (window.lunaVars && window.lunaVars.nonce) {
      return window.lunaVars.nonce;
    }
    if (window.wpApiSettings && window.wpApiSettings.nonce) {
      return window.wpApiSettings.nonce;
    }
    return null;
  };

  const initComposer = () => {
    const composer = document.getElementById('luna-composer');
    const editor = composer ? composer.querySelector('#prompt-textarea') : null;
    const responseTarget = document.getElementById('luna-response');

    const restUrl = resolveRestUrl();
    const nonce = resolveNonce();
    const composeClientSlug = (window.lunaVars && typeof window.lunaVars.composeClient === 'string' && window.lunaVars.composeClient)
      ? window.lunaVars.composeClient
      : 'commonwealthhealthservices';
    const storageKey = 'lunaPendingPrompt';
    const responsePath = '/products/luna/chat/response';
    const responseUrl = (() => {
      try {
        const url = new URL(responsePath.replace(/\/?$/, '/'), window.location.origin);
        url.hash = 'luna-response';
        return url.toString();
      } catch (error) {
        console.warn('[Luna] Unable to resolve response URL', error);
        return '/products/luna/chat/response/#luna-response';
      }
    })();
    const isOnResponsePage = () => {
      const normalized = window.location.pathname.replace(/\/+$/, '');
      const target = responsePath.replace(/\/+$/, '');
      return normalized === target || normalized.startsWith(`${target}/`);
    };

    let activePromptElement = null;
    let submitting = false;
    let dictationActive = false;
    let recognition = null;
    let attachLightbox = null;
    let attachKeyListener = null;
    let hiddenFileInput = null;
    let submitButton = null;

    const clearActivePrompt = () => {
      if (!activePromptElement) {
        return;
      }
      activePromptElement.setAttribute('aria-pressed', 'false');
      activePromptElement.removeAttribute('data-luna-active');
      activePromptElement = null;
    };

    const updatePlaceholderState = () => {
      if (!editor) {
        return;
      }
      const text = editor.textContent.replace(/\u00a0/g, ' ').trim();
      if (text === '') {
        editor.classList.add('is-empty');
      } else {
        editor.classList.remove('is-empty');
      }
    };

    const setEditorText = (text, shouldFocus = true) => {
      if (!editor) {
        return;
      }
      editor.textContent = text;
      updatePlaceholderState();
      if (!shouldFocus) {
        return;
      }
      editor.focus({ preventScroll: false });
      const selection = window.getSelection();
      if (!selection) return;
      const range = document.createRange();
      range.selectNodeContents(editor);
      range.collapse(false);
      selection.removeAllRanges();
      selection.addRange(range);
    };

    const getEditorValue = () => (editor ? editor.textContent.replace(/\u00a0/g, ' ').trim() : '');

    const queuePromptForResponse = (prompt, client) => {
      try {
        sessionStorage.setItem(storageKey, JSON.stringify({ prompt, client: client || composeClientSlug, ts: Date.now() }));
      } catch (error) {
        console.warn('[Luna] Unable to store pending prompt', error);
      }
    };

    const redirectToResponse = () => {
      window.location.assign(responseUrl);
    };

    const setLoading = (flag) => {
      submitting = flag;
      if (composer) {
        composer.setAttribute('aria-busy', flag ? 'true' : 'false');
      }
      if (responseTarget) {
        responseTarget.dataset.loading = flag ? 'true' : 'false';
      }
      if (submitButton) {
        submitButton.disabled = flag;
        submitButton.setAttribute('aria-disabled', flag ? 'true' : 'false');
      }
    };

    const showError = (message) => {
      if (!responseTarget) return;
      responseTarget.innerHTML = `<p class="luna-response__error">${escapeHTML(message)}</p>`;
    };

    const showAnswer = (answer, meta = {}) => {
      if (!responseTarget) return;
      const formatted = formatAnswer(answer);
      let metaBlock = '';
      if (meta && typeof meta === 'object') {
        const details = [];
        if (meta.source) {
          details.push(`Source: ${escapeHTML(String(meta.source))}`);
        }
        if (meta.client) {
          details.push(`Client: ${escapeHTML(String(meta.client))}`);
        }
        if (meta.site) {
          details.push(`Site: ${escapeHTML(String(meta.site))}`);
        }
        if (meta.profile_last_synced) {
          details.push(`Profile synced: ${escapeHTML(String(meta.profile_last_synced))}`);
        }
        if (details.length > 0) {
          metaBlock = `<p class="luna-response__meta">${details.join('<br>')}</p>`;
        }
      }
      responseTarget.innerHTML = `<div class="luna-response__message">${formatted}</div>${metaBlock}`;
    };

    const sendPrompt = async (prompt, options = {}) => {
      if (!prompt || submitting) {
        return;
      }
      setLoading(true);
      try {
        const client = (options && typeof options.client === 'string' && options.client.trim() !== '')
          ? options.client.trim()
          : composeClientSlug;
        const payload = { prompt, client };
        if (options && typeof options.refresh !== 'undefined') {
          payload.refresh = Boolean(options.refresh);
        }
        const headers = { 'Content-Type': 'application/json' };
        if (nonce) {
          headers['X-WP-Nonce'] = nonce;
        }
        const response = await fetch(restUrl, {
          method: 'POST',
          headers,
          body: JSON.stringify(payload),
        });
        if (!response.ok) {
          const text = await response.text();
          throw new Error(text || `Request failed with status ${response.status}`);
        }
        const data = await response.json();
        const answer = (data && (data.answer || data.message)) ? (data.answer || data.message) : 'Luna did not return a response.';
        const meta = data && data.meta && typeof data.meta === 'object' ? data.meta : { source: '' };
        showAnswer(answer, meta);
      } catch (error) {
        console.error('[Luna] Composer request failed:', error);
        showError('Sorry, something went wrong reaching Luna. Please try again.');
      } finally {
        setLoading(false);
        try {
          sessionStorage.removeItem(storageKey);
        } catch (err) {
          // ignore storage cleanup issues
        }
      }
    };

    const markPromptInvalid = () => {
      if (!editor) {
        return;
      }
      editor.focus();
      editor.classList.add('luna-editor--invalid');
      setTimeout(() => editor.classList.remove('luna-editor--invalid'), 600);
    };

    const attemptSubmit = (prompt, options = {}) => {
      const value = typeof prompt === 'string' ? prompt.trim() : getEditorValue();
      if (!value) {
        markPromptInvalid();
        return false;
      }
      const targetClient = (options && typeof options.client === 'string' && options.client.trim() !== '')
        ? options.client.trim()
        : composeClientSlug;
      queuePromptForResponse(value, targetClient);
      if (isOnResponsePage()) {
        sendPrompt(value, { client: targetClient });
      } else {
        redirectToResponse();
      }
      return true;
    };

    const readQueuedPrompt = () => {
      let payload = null;
      try {
        const raw = sessionStorage.getItem(storageKey);
        if (!raw) {
          return null;
        }
        payload = JSON.parse(raw);
      } catch (error) {
        sessionStorage.removeItem(storageKey);
        console.warn('[Luna] Could not parse pending prompt', error);
        return null;
      }

      if (!payload || !payload.prompt) {
        sessionStorage.removeItem(storageKey);
        return null;
      }

      if (payload.ts && Date.now() - payload.ts > 5 * 60 * 1000) {
        sessionStorage.removeItem(storageKey);
        return null;
      }

      sessionStorage.removeItem(storageKey);
      const storedClient = (typeof payload.client === 'string' && payload.client.trim() !== '')
        ? payload.client.trim()
        : composeClientSlug;

      return { prompt: payload.prompt, client: storedClient };
    };

    const consumeQueuedPrompt = ({ applyToEditor = true } = {}) => {
      const payload = readQueuedPrompt();
      if (!payload) {
        return null;
      }
      if (applyToEditor && editor) {
        setEditorText(payload.prompt, false);
      }
      clearActivePrompt();
      return payload;
    };

    const bootstrapResponseQueue = () => {
      if (!isOnResponsePage() || !responseTarget) {
        return;
      }
      const payload = consumeQueuedPrompt({ applyToEditor: Boolean(editor) });
      if (payload) {
        sendPrompt(payload.prompt, { client: payload.client });
        return;
      }

      const prefill = (window.lunaVars && typeof window.lunaVars.prefillPrompt === 'string')
        ? window.lunaVars.prefillPrompt.trim()
        : '';
      if (prefill) {
        if (editor) {
          setEditorText(prefill);
        }
        sendPrompt(prefill, { client: composeClientSlug });
      }
    };

    bootstrapResponseQueue();

    if (!composer || !editor) {
      window.lunaBootstrapped = true;
      return;
    }

    injectPlaceholderStyles();

    const handleSubmit = (event) => {
      event.preventDefault();
      clearActivePrompt();
      attemptSubmit(undefined, { client: composeClientSlug });
    };

    composer.addEventListener('submit', handleSubmit);

    editor.addEventListener('input', () => {
      updatePlaceholderState();
      clearActivePrompt();
    });

    editor.addEventListener('focus', updatePlaceholderState);
    editor.addEventListener('blur', updatePlaceholderState);

    editor.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        attemptSubmit(undefined, { client: composeClientSlug });
      }
    });

    updatePlaceholderState();

    const handlePromptActivation = (element, prompt) => {
      if (!prompt) {
        return;
      }
      if (activePromptElement && activePromptElement !== element) {
        activePromptElement.setAttribute('aria-pressed', 'false');
        activePromptElement.removeAttribute('data-luna-active');
      }
      activePromptElement = element;
      element.setAttribute('aria-pressed', 'true');
      element.setAttribute('data-luna-active', 'true');
      setEditorText(prompt);
      attemptSubmit(prompt, { client: composeClientSlug });
    };

    const attachExistingCannedPrompts = () => {
      const section = document.querySelector('section.ai-canned-prompts#luna-canned');
      if (!section) {
        return;
      }

      const rowSelectors = ['.columns-row-one', '.columns-row-two'];
      const prepared = new Set();

      const prepareNode = (node, promptText) => {
        if (!(node instanceof HTMLElement)) {
          return;
        }
        if (!promptText) {
          return;
        }
        const normalizedPrompt = promptText.replace(/\s+/g, ' ').trim();
        if (!normalizedPrompt) {
          return;
        }
        if (prepared.has(node) || node.dataset.lunaPromptReady === 'true') {
          return;
        }

        prepared.add(node);
        node.dataset.lunaPromptItem = 'true';
        node.dataset.lunaPrompt = normalizedPrompt;
        node.dataset.lunaPromptReady = 'true';
        if (!node.hasAttribute('aria-pressed')) {
          node.setAttribute('aria-pressed', 'false');
        }

        if (!node.matches('button, a, input, textarea, select')) {
          if (!node.hasAttribute('role')) {
            node.setAttribute('role', 'button');
          }
          if (!node.hasAttribute('tabindex')) {
            node.tabIndex = 0;
          }
        }

        const activate = (event) => {
          if (event) {
            event.preventDefault();
          }
          handlePromptActivation(node, normalizedPrompt);
        };

        node.addEventListener('click', activate);
        node.addEventListener('keydown', (event) => {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            activate(event);
          }
        });
      };

      rowSelectors.forEach((selector) => {
        const row = section.querySelector(selector);
        if (!row) {
          return;
        }

        const columns = Array.from(row.querySelectorAll('.wp-block-column')).filter((col) => {
          const parentRow = col.closest('.columns-row-one, .columns-row-two');
          return parentRow === row;
        });

        const candidates = columns.length > 0 ? columns : Array.from(row.children);

        candidates.forEach((candidate) => {
          if (!(candidate instanceof HTMLElement)) {
            return;
          }

          const interactive = candidate.matches('a, button')
            ? candidate
            : candidate.querySelector('a, button');

          const target = (interactive instanceof HTMLElement && interactive.closest('.columns-row-one, .columns-row-two') === row)
            ? interactive
            : candidate;

          const promptText = target.getAttribute('data-luna-prompt')
            || target.getAttribute('data-prompt')
            || target.textContent
            || '';
          prepareNode(target, promptText);
        });
      });
    };

    attachExistingCannedPrompts();

    const ensureHiddenFileInput = () => {
      if (hiddenFileInput) {
        return hiddenFileInput;
      }
      hiddenFileInput = document.createElement('input');
      hiddenFileInput.type = 'file';
      hiddenFileInput.id = 'luna-attach-file-input';
      hiddenFileInput.style.position = 'absolute';
      hiddenFileInput.style.opacity = '0';
      hiddenFileInput.style.pointerEvents = 'none';
      hiddenFileInput.style.width = '0';
      hiddenFileInput.style.height = '0';
      composer.appendChild(hiddenFileInput);
      hiddenFileInput.addEventListener('change', () => {
        if (!attachLightbox) {
          return;
        }
        const hint = attachLightbox.querySelector('[data-luna-attach-summary]');
        if (!hint) {
          return;
        }
        const files = hiddenFileInput.files;
        if (files && files.length > 0) {
          hint.textContent = `Ready to upload: ${files[0].name}`;
        } else {
          hint.textContent = 'Files are secured through your Visible Light workspace.';
        }
      });
      return hiddenFileInput;
    };

    const closeAttachLightbox = () => {
      if (!attachLightbox) {
        return;
      }
      attachLightbox.setAttribute('hidden', '');
      document.body.classList.remove('luna-lightbox-open');
      if (attachKeyListener) {
        window.removeEventListener('keydown', attachKeyListener, true);
        attachKeyListener = null;
      }
    };

    const openAttachLightbox = () => {
      if (!attachLightbox) {
        attachLightbox = document.createElement('div');
        attachLightbox.id = 'luna-attach-lightbox';
        attachLightbox.setAttribute('role', 'dialog');
        attachLightbox.setAttribute('aria-modal', 'true');
        attachLightbox.setAttribute('hidden', '');
        attachLightbox.innerHTML = `
          <div class="luna-lightbox__panel" tabindex="-1">
            <button type="button" class="luna-lightbox__close" aria-label="Close attachment options">&times;</button>
            <h2 class="luna-lightbox__title" id="luna-attach-title">Add files to Luna</h2>
            <p class="luna-lightbox__copy">Choose how you'd like to share files with Luna.</p>
            <div class="luna-lightbox__actions">
              <button type="button" class="luna-lightbox__action" data-action="upload">
                <span>Upload from device</span>
                <span aria-hidden="true">&#8679;</span>
              </button>
              <button type="button" class="luna-lightbox__action" data-action="drop">
                <span>Drop into Luna chat</span>
                <span aria-hidden="true">&#10515;</span>
              </button>
            </div>
            <p class="luna-lightbox__hint" data-luna-attach-summary>Files are secured through your Visible Light workspace.</p>
          </div>
        `;
        attachLightbox.addEventListener('click', (event) => {
          const target = event.target;
          if (!(target instanceof HTMLElement)) {
            return;
          }
          if (target.matches('.luna-lightbox__close')) {
            event.preventDefault();
            closeAttachLightbox();
            return;
          }
          if (target === attachLightbox) {
            closeAttachLightbox();
            return;
          }
          if (target.closest('[data-action="upload"]')) {
            event.preventDefault();
            const input = ensureHiddenFileInput();
            input.click();
            return;
          }
          if (target.closest('[data-action="drop"]')) {
            event.preventDefault();
            const hint = attachLightbox.querySelector('[data-luna-attach-summary]');
            if (hint) {
              hint.textContent = 'Drag and drop files into this window to attach them to your next Luna request.';
            }
          }
        });
        document.body.appendChild(attachLightbox);
      }

      attachLightbox.removeAttribute('hidden');
      document.body.classList.add('luna-lightbox-open');
      const panel = attachLightbox.querySelector('.luna-lightbox__panel');
      if (panel instanceof HTMLElement) {
        panel.focus({ preventScroll: true });
      }
      attachKeyListener = (event) => {
        if (event.key === 'Escape') {
          closeAttachLightbox();
        }
      };
      window.addEventListener('keydown', attachKeyListener, true);
    };

    const attachButton = composer.querySelector('#luna-attach');
    if (attachButton) {
      attachButton.addEventListener('click', (event) => {
        event.preventDefault();
        openAttachLightbox();
      });
    }

    const ensureSubmitButton = () => {
      const existing = composer.querySelector('#luna-submit');
      if (existing instanceof HTMLButtonElement) {
        return existing;
      }
      const button = document.createElement('button');
      button.type = 'button';
      button.id = 'luna-submit';
      button.className = 'composer-btn send submit';
      button.setAttribute('aria-label', 'Submit prompt to Luna');
      button.textContent = 'Submit';
      const dictation = composer.querySelector('#luna-send');
      if (dictation instanceof HTMLElement) {
        dictation.insertAdjacentElement('afterend', button);
      } else {
        const trailing = composer.querySelector('[grid-area="trailing"], .composer-shell [grid-area="trailing"]');
        if (trailing instanceof HTMLElement) {
          trailing.appendChild(button);
        } else {
          composer.appendChild(button);
        }
      }
      return button;
    };

    submitButton = ensureSubmitButton();

    if (submitButton) {
      submitButton.addEventListener('click', (event) => {
        event.preventDefault();
        clearActivePrompt();
        attemptSubmit(undefined, { client: composeClientSlug });
      });
    }

    const dictationButton = composer.querySelector('#luna-send');
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (dictationButton) {
      dictationButton.type = 'button';
      dictationButton.addEventListener('click', (event) => {
        event.preventDefault();
        if (!SpeechRecognition) {
          editor.focus();
          return;
        }
        if (dictationActive && recognition) {
          recognition.stop();
          return;
        }
        try {
          recognition = new SpeechRecognition();
        } catch (error) {
          console.warn('[Luna] Dictation could not be started', error);
          editor.focus();
          return;
        }
        dictationActive = true;
        dictationButton.dataset.dictating = 'true';
        dictationButton.setAttribute('aria-pressed', 'true');
        recognition.continuous = false;
        recognition.interimResults = true;
        recognition.lang = document.documentElement.lang || 'en-US';
        recognition.addEventListener('result', (evt) => {
          let finalTranscript = '';
          for (let i = evt.resultIndex; i < evt.results.length; i += 1) {
            const result = evt.results[i];
            if (result.isFinal && result[0]) {
              finalTranscript += result[0].transcript;
            }
          }
          finalTranscript = finalTranscript.trim();
          if (finalTranscript) {
            const existing = getEditorValue();
            const combined = existing ? `${existing} ${finalTranscript}`.trim() : finalTranscript;
            setEditorText(combined);
          }
        });
        recognition.addEventListener('error', (evt) => {
          console.warn('[Luna] Dictation error', evt.error);
        });
        recognition.addEventListener('end', () => {
          dictationActive = false;
          dictationButton.dataset.dictating = 'false';
          dictationButton.setAttribute('aria-pressed', 'false');
          recognition = null;
        });
        recognition.start();
      });
    }

    window.lunaBootstrapped = true;
  };

  ready(initComposer);
})();
