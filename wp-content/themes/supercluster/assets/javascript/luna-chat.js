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
    `;
    document.head.appendChild(style);
  };

  const resolveRestUrl = () => {
    if (window.lunaVars && window.lunaVars.restUrlChat) {
      return window.lunaVars.restUrlChat;
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

    if (!composer || !editor) {
      window.lunaBootstrapped = true;
      return;
    }

    injectPlaceholderStyles();

    const restUrl = resolveRestUrl();
    const nonce = resolveNonce();
    let activePromptElement = null;
    let submitting = false;

    const clearActivePrompt = () => {
      if (!activePromptElement) {
        return;
      }
      activePromptElement.setAttribute('aria-pressed', 'false');
      activePromptElement.removeAttribute('data-luna-active');
      activePromptElement = null;
    };

    const updatePlaceholderState = () => {
      const text = editor.textContent.replace(/\u00a0/g, ' ').trim();
      if (text === '') {
        editor.classList.add('is-empty');
      } else {
        editor.classList.remove('is-empty');
      }
    };

    const setEditorText = (text, shouldFocus = true) => {
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

    const setLoading = (flag) => {
      submitting = flag;
      composer.setAttribute('aria-busy', flag ? 'true' : 'false');
      if (responseTarget) {
        responseTarget.dataset.loading = flag ? 'true' : 'false';
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
      if (meta && meta.source) {
        metaBlock = `<p class="luna-response__meta">Source: ${escapeHTML(meta.source)}</p>`;
      }
      responseTarget.innerHTML = `<div class="luna-response__message">${formatted}</div>${metaBlock}`;
    };

    const sendPrompt = async (prompt) => {
      if (!prompt || submitting) {
        return;
      }
      setLoading(true);
      try {
        const payload = { prompt };
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
        showAnswer(answer, { source: data && data.meta && data.meta.source ? data.meta.source : '' });
      } catch (error) {
        console.error('[Luna] Composer request failed:', error);
        showError('Sorry, something went wrong reaching Luna. Please try again.');
      } finally {
        setLoading(false);
      }
    };

    const handleSubmit = (event) => {
      event.preventDefault();
      const value = editor.textContent.replace(/\u00a0/g, ' ').trim();
      if (!value) {
        editor.focus();
        editor.classList.add('luna-editor--invalid');
        setTimeout(() => editor.classList.remove('luna-editor--invalid'), 600);
        return;
      }
      clearActivePrompt();
      sendPrompt(value);
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
        composer.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
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
      sendPrompt(prompt);
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

    window.lunaBootstrapped = true;
  };

  ready(initComposer);
})();
