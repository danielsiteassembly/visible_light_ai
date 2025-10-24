(function () {
  const ready = (fn) => {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn, { once: true });
    } else {
      fn();
    }
  };

  const escapeHTML = (value) => {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  };

  const formatAnswer = (answer) => {
    return escapeHTML(answer).replace(/\n{2,}/g, '<br><br>').replace(/\n/g, '<br>');
  };

  const ensurePlaceholderStyles = () => {
    if (document.getElementById('luna-composer-placeholder-style')) {
      return;
    }
    const style = document.createElement('style');
    style.id = 'luna-composer-placeholder-style';
    style.textContent = `
      .luna-editor.is-empty::before {
        content: attr(data-placeholder);
        color: rgba(148, 163, 184, 0.85);
        pointer-events: none;
      }
      .luna-editor.is-empty:focus::before {
        color: rgba(226, 232, 240, 0.9);
      }
      .luna-editor.luna-editor--invalid {
        animation: lunaComposerShake 0.25s linear 0s 2;
        border-color: rgba(239, 68, 68, 0.75);
      }
      @keyframes lunaComposerShake {
        0%, 100% { transform: translateX(0); }
        25% { transform: translateX(-4px); }
        75% { transform: translateX(4px); }
      }
      #luna-response[data-loading="true"]::after {
        content: 'Drafting a response for your team…';
        display: block;
        font-style: italic;
        opacity: 0.75;
        margin-top: 0.75rem;
      }
      #luna-response .luna-response__error {
        color: #fca5a5;
      }
      section.ai-canned-prompts#luna-canned [data-luna-prompt-item] {
        cursor: pointer;
        transition: outline-color 0.2s ease, transform 0.15s ease;
      }
      section.ai-canned-prompts#luna-canned [data-luna-prompt-item][data-luna-active="true"] {
        outline: 2px solid rgba(59, 130, 246, 0.85);
        outline-offset: 3px;
        transform: translateY(-1px);
      }
      section.ai-canned-prompts#luna-canned [data-luna-prompt-item]:focus-visible {
        outline: 2px solid rgba(59, 130, 246, 0.65);
        outline-offset: 3px;
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
        background: linear-gradient(155deg, rgba(15, 23, 42, 0.96), rgba(30, 41, 59, 0.94));
        color: #f8fafc;
        box-shadow: 0 35px 70px rgba(15, 23, 42, 0.45);
      }
      #luna-attach-lightbox .luna-lightbox__close {
        position: absolute;
        top: 0.75rem;
        right: 0.75rem;
        border: none;
        background: transparent;
        color: inherit;
        font-size: 1.5rem;
        cursor: pointer;
      }
      #luna-attach-lightbox .luna-lightbox__actions {
        margin-top: 1.5rem;
        display: grid;
        gap: 0.75rem;
      }
      #luna-attach-lightbox .luna-lightbox__action {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        width: 100%;
        border-radius: 0.75rem;
        border: 1px solid rgba(96, 165, 250, 0.4);
        padding: 0.9rem 1rem;
        background: rgba(59, 130, 246, 0.16);
        color: inherit;
        cursor: pointer;
        transition: border-color 0.2s ease, background 0.2s ease, transform 0.2s ease;
      }
      #luna-attach-lightbox .luna-lightbox__action:hover {
        border-color: rgba(96, 165, 250, 0.85);
        background: rgba(59, 130, 246, 0.32);
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

  ready(() => {
    ensurePlaceholderStyles();

    const storageKey = 'luna-compose-queued-prompt';
    const defaultClient = (window.lunaVars && typeof window.lunaVars.composeClient === 'string' && window.lunaVars.composeClient)
      ? window.lunaVars.composeClient
      : 'commonwealthhealthservices';

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

    const restUrl = resolveRestUrl();
    const nonce = resolveNonce();

    const responsePath = '/products/luna/chat/response';
    const responseUrl = (() => {
      try {
        const url = new URL(responsePath.replace(/\/?$/, '/'), window.location.origin);
        url.hash = 'luna-response';
        return url.toString();
      } catch (error) {
        return '/products/luna/chat/response/#luna-response';
      }
    })();

    const isResponseRoute = () => {
      const pathname = window.location.pathname.replace(/\/+$/, '');
      const target = responsePath.replace(/\/+$/, '');
      return pathname === target || pathname.startsWith(target + '/');
    };

    const composer = document.getElementById('luna-composer');
    const editor = composer ? composer.querySelector('#prompt-textarea') : null;
    const responseTarget = document.getElementById('luna-response');

    let submitButton = null;
    let attachLightbox = null;
    let attachKeyListener = null;
    let hiddenFileInput = null;
    let activePromptElement = null;
    let dictationRecognition = null;
    let dictationActive = false;
    let submitting = false;

    const setEditorText = (text, focus = true) => {
      if (!editor) return;
      const normalized = typeof text === 'string' ? text : '';
      editor.innerHTML = '';
      if (normalized) {
        editor.appendChild(document.createTextNode(normalized));
      }
      updatePlaceholderState();
      if (focus) {
        editor.focus({ preventScroll: false });
        const selection = window.getSelection();
        if (selection) {
          const range = document.createRange();
          range.selectNodeContents(editor);
          range.collapse(false);
          selection.removeAllRanges();
          selection.addRange(range);
        }
      }
      try {
        const syntheticInput = new Event('input', { bubbles: true });
        editor.dispatchEvent(syntheticInput);
      } catch (error) {
        const legacyEvent = document.createEvent('Event');
        legacyEvent.initEvent('input', true, true);
        editor.dispatchEvent(legacyEvent);
      }
    };

    const getEditorValue = () => {
      if (!editor) return '';
      return editor.textContent.replace(/\u00a0/g, ' ').trim();
    };

    const clearActivePrompt = () => {
      if (!activePromptElement) return;
      activePromptElement.setAttribute('aria-pressed', 'false');
      activePromptElement.removeAttribute('data-luna-active');
      activePromptElement = null;
    };

    const updatePlaceholderState = () => {
      if (!editor) return;
      const value = getEditorValue();
      if (value === '') {
        editor.classList.add('is-empty');
      } else {
        editor.classList.remove('is-empty');
      }
    };

    const setLoading = (flag) => {
      submitting = flag;
      if (composer) {
        composer.setAttribute('aria-busy', flag ? 'true' : 'false');
      }
      if (submitButton) {
        submitButton.disabled = flag;
        submitButton.setAttribute('aria-disabled', flag ? 'true' : 'false');
      }
      if (responseTarget) {
        responseTarget.dataset.loading = flag ? 'true' : 'false';
      }
    };

    const showError = (message) => {
      if (!responseTarget) return;
      responseTarget.innerHTML = `<p class="luna-response__error">${escapeHTML(message)}</p>`;
    };

    const showAnswer = (answer, meta = null) => {
      if (!responseTarget) return;
      const formatted = formatAnswer(answer || '');
      let metaBlock = '';
      if (meta && typeof meta === 'object') {
        const lines = [];
        if (meta.client) lines.push(`Client: ${escapeHTML(meta.client)}`);
        if (meta.site) lines.push(`Site: ${escapeHTML(meta.site)}`);
        if (meta.license) lines.push(`License: ${escapeHTML(meta.license)}`);
        if (meta.profile_last_synced) lines.push(`Profile synced: ${escapeHTML(meta.profile_last_synced)}`);
        if (lines.length) {
          metaBlock = `<p class="luna-response__meta">${lines.join('<br>')}</p>`;
        }
      }
      responseTarget.innerHTML = `<div class="luna-response__message">${formatted}</div>${metaBlock}`;
    };

    const queuePromptForResponse = (prompt, client) => {
      try {
        const payload = { prompt, client: client || defaultClient, ts: Date.now() };
        sessionStorage.setItem(storageKey, JSON.stringify(payload));
      } catch (error) {
        console.warn('[Luna] Unable to cache prompt for redirect', error);
      }
    };

    const takeQueuedPrompt = () => {
      try {
        const raw = sessionStorage.getItem(storageKey);
        if (!raw) return null;
        sessionStorage.removeItem(storageKey);
        const payload = JSON.parse(raw);
        if (!payload || typeof payload.prompt !== 'string') {
          return null;
        }
        if (payload.ts && Date.now() - payload.ts > 5 * 60 * 1000) {
          return null;
        }
        return {
          prompt: payload.prompt,
          client: typeof payload.client === 'string' && payload.client ? payload.client : defaultClient,
        };
      } catch (error) {
        console.warn('[Luna] Unable to read queued prompt', error);
        return null;
      }
    };

    const sendPrompt = async (prompt, options = {}) => {
      if (!prompt || submitting) {
        return;
      }
      setLoading(true);
      try {
        const targetClient = (options.client && typeof options.client === 'string' && options.client.trim())
          ? options.client.trim()
          : defaultClient;
        const payload = { prompt, client: targetClient };
        if (options.refresh) {
          payload.refresh = true;
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
          throw new Error(text || `Request failed (${response.status})`);
        }
        const data = await response.json();
        const answer = (data && typeof data.answer === 'string') ? data.answer : (data && typeof data.message === 'string' ? data.message : '');
        if (!answer) {
          throw new Error('Empty response from Luna.');
        }
        const meta = data && data.meta && typeof data.meta === 'object' ? data.meta : null;
        showAnswer(answer, meta);
      } catch (error) {
        console.error('[Luna] Composer request failed:', error);
        showError('Sorry, something went wrong reaching Luna. Please try again.');
      } finally {
        setLoading(false);
      }
    };

    const redirectToResponse = () => {
      window.location.assign(responseUrl);
    };

    const markPromptInvalid = () => {
      if (!editor) return;
      editor.classList.add('luna-editor--invalid');
      setTimeout(() => {
        editor.classList.remove('luna-editor--invalid');
      }, 600);
      editor.focus();
    };

    const attemptSubmit = (explicitPrompt, options = {}) => {
      const rawPrompt = typeof explicitPrompt === 'string' ? explicitPrompt : getEditorValue();
      const prompt = rawPrompt.trim();
      if (!prompt) {
        markPromptInvalid();
        return false;
      }
      const client = (options.client && typeof options.client === 'string' && options.client.trim())
        ? options.client.trim()
        : defaultClient;
      queuePromptForResponse(prompt, client);
      if (isResponseRoute()) {
        sendPrompt(prompt, { client });
      } else {
        redirectToResponse();
      }
      if (!options.fromPrompt) {
        clearActivePrompt();
      }
      return true;
    };

    const bootstrapResponsePage = (applyToEditor = true) => {
      if (!isResponseRoute() || !responseTarget) {
        return;
      }
      const queued = takeQueuedPrompt();
      if (queued) {
        if (editor && applyToEditor) {
          setEditorText(queued.prompt, false);
        }
        sendPrompt(queued.prompt, { client: queued.client });
        return;
      }
      const prefill = (window.lunaVars && typeof window.lunaVars.prefillPrompt === 'string') ? window.lunaVars.prefillPrompt.trim() : '';
      if (prefill) {
        if (editor && applyToEditor) {
          setEditorText(prefill, false);
        }
        sendPrompt(prefill, { client: defaultClient });
      }
    };

    const ensureSubmitButton = () => {
      if (!composer) return null;
      const existing = composer.querySelector('#luna-submit');
      if (existing) {
        return existing;
      }
      const button = document.createElement('button');
      button.type = 'button';
      button.id = 'luna-submit';
      button.className = 'composer-btn send luna-submit';
      button.textContent = 'Submit';
      button.setAttribute('data-action', 'submit-prompt');
      button.setAttribute('aria-label', 'Submit to Luna');
      let trailing = composer.querySelector('.composer-shell [class*="grid-area:trailing"]');
      if (!trailing) {
        trailing = composer.querySelector('.composer-shell [class*="[grid-area:trailing]"]');
      }
      const shell = composer.querySelector('.composer-shell');
      if (trailing instanceof HTMLElement) {
        trailing.appendChild(button);
      } else if (shell instanceof HTMLElement) {
        shell.appendChild(button);
      } else {
        composer.appendChild(button);
      }
      return button;
    };

    const ensureHiddenFileInput = () => {
      if (hiddenFileInput) return hiddenFileInput;
      if (!composer) return null;
      hiddenFileInput = document.createElement('input');
      hiddenFileInput.type = 'file';
      hiddenFileInput.id = 'luna-attach-file-input';
      hiddenFileInput.style.position = 'absolute';
      hiddenFileInput.style.width = '1px';
      hiddenFileInput.style.height = '1px';
      hiddenFileInput.style.opacity = '0';
      hiddenFileInput.style.pointerEvents = 'none';
      hiddenFileInput.setAttribute('tabindex', '-1');
      composer.appendChild(hiddenFileInput);
      return hiddenFileInput;
    };

    const closeAttachLightbox = () => {
      if (!attachLightbox) return;
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
            <p class="luna-lightbox__copy">Choose how you would like to share supporting files with Luna.</p>
            <div class="luna-lightbox__actions">
              <button type="button" class="luna-lightbox__action" data-luna-attach="upload">
                <span>Upload from device</span>
                <span aria-hidden="true">&#8682;</span>
              </button>
              <button type="button" class="luna-lightbox__action" data-luna-attach="drop">
                <span>Drop into Luna chat</span>
                <span aria-hidden="true">&#10515;</span>
              </button>
            </div>
            <p class="luna-lightbox__hint" data-luna-attach-summary>Files are secured through your Visible Light workspace.</p>
          </div>
        `;
        document.body.appendChild(attachLightbox);

        const closeButton = attachLightbox.querySelector('.luna-lightbox__close');
        if (closeButton) {
          closeButton.addEventListener('click', closeAttachLightbox);
        }

        attachLightbox.addEventListener('click', (event) => {
          if (event.target === attachLightbox) {
            closeAttachLightbox();
          }
        });

        const panel = attachLightbox.querySelector('.luna-lightbox__panel');
        const uploadButton = attachLightbox.querySelector('[data-luna-attach="upload"]');
        const dropButton = attachLightbox.querySelector('[data-luna-attach="drop"]');
        const hint = attachLightbox.querySelector('[data-luna-attach-summary]');

        if (uploadButton) {
          uploadButton.addEventListener('click', () => {
            const input = ensureHiddenFileInput();
            if (!input) return;
            hint.textContent = 'Select a file to share with Luna.';
            input.click();
          });
        }

        if (dropButton) {
          dropButton.addEventListener('click', () => {
            closeAttachLightbox();
            if (editor) {
              editor.focus();
            }
          });
        }

        if (panel) {
          panel.addEventListener('transitionend', () => {
            panel.focus();
          }, { once: true });
        }

        ensureHiddenFileInput();
        if (hiddenFileInput) {
          hiddenFileInput.addEventListener('change', () => {
            if (!hint) return;
            if (hiddenFileInput.files && hiddenFileInput.files.length > 0) {
              hint.textContent = `Ready to upload: ${hiddenFileInput.files[0].name}`;
            } else {
              hint.textContent = 'Files are secured through your Visible Light workspace.';
            }
          });
        }
      }

      attachLightbox.removeAttribute('hidden');
      document.body.classList.add('luna-lightbox-open');
      const panel = attachLightbox.querySelector('.luna-lightbox__panel');
      const hintNode = attachLightbox.querySelector('[data-luna-attach-summary]');
      if (hintNode) {
        if (hiddenFileInput && hiddenFileInput.files && hiddenFileInput.files.length > 0) {
          hintNode.textContent = `Ready to upload: ${hiddenFileInput.files[0].name}`;
        } else {
          hintNode.textContent = 'Files are secured through your Visible Light workspace.';
        }
      }
      if (panel) {
        panel.focus();
      }
      attachKeyListener = (event) => {
        if (event.key === 'Escape') {
          event.preventDefault();
          closeAttachLightbox();
        }
      };
      window.addEventListener('keydown', attachKeyListener, true);
    };

    const wireCannedPrompts = () => {
      const section = document.querySelector('section.ai-canned-prompts#luna-canned');
      if (!section) return;
      const rows = section.querySelectorAll('#columns-row-one, #columns-row-two, .columns-row-one, .columns-row-two');
      rows.forEach((row) => {
        const targets = row.querySelectorAll('[data-prompt], a, button, .wp-block-column, .wp-block-button__link');
        targets.forEach((candidate) => {
          const element = candidate.closest('a, button') || candidate;
          if (!(element instanceof HTMLElement)) {
            return;
          }
          if (element === section || element === row) {
            return;
          }
          const existing = element.dataset.lunaPromptReady === 'true';
          if (existing) {
            return;
          }
          const promptText = (element.getAttribute('data-luna-prompt')
            || element.getAttribute('data-prompt')
            || element.textContent
            || '').replace(/\s+/g, ' ').trim();
          if (!promptText) {
            return;
          }
          element.dataset.lunaPromptReady = 'true';
          element.dataset.lunaPromptItem = 'true';
          element.dataset.lunaPrompt = promptText;
          if (!element.hasAttribute('aria-pressed')) {
            element.setAttribute('aria-pressed', 'false');
          }
          if (!element.matches('button, a')) {
            element.setAttribute('role', 'button');
            element.tabIndex = 0;
          }
          const activate = (event) => {
            if (event) {
              event.preventDefault();
            }
            if (activePromptElement && activePromptElement !== element) {
              activePromptElement.setAttribute('aria-pressed', 'false');
              activePromptElement.removeAttribute('data-luna-active');
            }
            activePromptElement = element;
            element.setAttribute('aria-pressed', 'true');
            element.setAttribute('data-luna-active', 'true');
            setEditorText(promptText);
            attemptSubmit(promptText, { client: defaultClient, fromPrompt: true });
          };
          element.addEventListener('click', activate);
          element.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
              event.preventDefault();
              activate(event);
            }
          });
        });
      });
    };

    bootstrapResponsePage(Boolean(editor));

    if (!composer || !editor) {
      window.lunaBootstrapped = true;
      return;
    }

    updatePlaceholderState();

    composer.addEventListener('submit', (event) => {
      event.preventDefault();
      attemptSubmit(undefined, { client: defaultClient });
    });

    editor.addEventListener('input', () => {
      updatePlaceholderState();
      clearActivePrompt();
    });

    editor.addEventListener('focus', updatePlaceholderState);
    editor.addEventListener('blur', updatePlaceholderState);

    editor.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        attemptSubmit(undefined, { client: defaultClient });
      }
    });

    submitButton = ensureSubmitButton();
    if (submitButton) {
      submitButton.addEventListener('click', (event) => {
        event.preventDefault();
        attemptSubmit(undefined, { client: defaultClient });
      });
    }

    const dictationButton = composer.querySelector('#luna-send');
    if (dictationButton) {
      dictationButton.type = 'button';
      dictationButton.addEventListener('click', (event) => {
        event.preventDefault();
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SpeechRecognition) {
          editor.focus();
          return;
        }
        if (dictationActive && dictationRecognition) {
          dictationRecognition.stop();
          return;
        }
        try {
          dictationRecognition = new SpeechRecognition();
        } catch (error) {
          console.warn('[Luna] Dictation unavailable', error);
          editor.focus();
          return;
        }
        dictationActive = true;
        dictationButton.dataset.dictating = 'true';
        dictationButton.setAttribute('aria-pressed', 'true');
        dictationRecognition.lang = document.documentElement.lang || 'en-US';
        dictationRecognition.continuous = false;
        dictationRecognition.interimResults = true;
        dictationRecognition.addEventListener('result', (evt) => {
          let transcript = '';
          for (let i = evt.resultIndex; i < evt.results.length; i += 1) {
            const result = evt.results[i];
            if (result.isFinal && result[0]) {
              transcript += result[0].transcript;
            }
          }
          transcript = transcript.trim();
          if (transcript) {
            const existing = getEditorValue();
            const combined = existing ? `${existing} ${transcript}`.trim() : transcript;
            setEditorText(combined);
          }
        });
        dictationRecognition.addEventListener('error', (evt) => {
          console.warn('[Luna] Dictation error', evt.error);
        });
        dictationRecognition.addEventListener('end', () => {
          dictationActive = false;
          dictationButton.dataset.dictating = 'false';
          dictationButton.setAttribute('aria-pressed', 'false');
          dictationRecognition = null;
        });
        dictationRecognition.start();
      });
    }

    const attachButton = composer.querySelector('#luna-attach');
    if (attachButton) {
      attachButton.type = 'button';
      attachButton.addEventListener('click', (event) => {
        event.preventDefault();
        openAttachLightbox();
      });
    }

    wireCannedPrompts();

    window.lunaBootstrapped = true;
  });
})();
