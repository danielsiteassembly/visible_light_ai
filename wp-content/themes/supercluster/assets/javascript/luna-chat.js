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
      .luna-canned-prompts {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 0.75rem;
        margin-bottom: 1.25rem;
      }
      .luna-canned-prompts__button {
        border: 1px solid rgba(148, 163, 184, 0.35);
        border-radius: 9999px;
        background: rgba(15, 23, 42, 0.55);
        color: inherit;
        padding: 0.55rem 0.9rem;
        font-size: 0.9rem;
        line-height: 1.2;
        text-align: left;
        cursor: pointer;
        transition: border-color 120ms ease, background-color 120ms ease, transform 120ms ease;
      }
      .luna-canned-prompts__button:hover,
      .luna-canned-prompts__button:focus-visible {
        border-color: rgba(99, 102, 241, 0.65);
        background: rgba(79, 70, 229, 0.18);
        outline: none;
        transform: translateY(-1px);
      }
      .luna-canned-prompts__button[aria-pressed="true"] {
        border-color: rgba(99, 102, 241, 0.85);
        background: rgba(79, 70, 229, 0.28);
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

  const cannedDefaults = [
    {
      label: 'What can Luna help me with?',
      prompt: "Hey Luna! What can you help me with today?"
    },
    {
      label: 'Site health overview',
      prompt: 'Can you give me a quick health check of my WordPress site?'
    },
    {
      label: 'Pending updates',
      prompt: 'Do I have any plugin, theme, or WordPress core updates waiting?'
    },
    {
      label: 'Security status',
      prompt: 'Is my SSL certificate active and are there any security concerns?'
    },
    {
      label: 'Content inventory',
      prompt: 'How many pages and posts are on the site right now?'
    },
    {
      label: 'Help contact info',
      prompt: 'Remind me how someone can contact our team for help.'
    }
  ];

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
    let activePromptButton = null;
    let submitting = false;

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
      if (activePromptButton) {
        activePromptButton.setAttribute('aria-pressed', 'false');
        activePromptButton = null;
      }
      sendPrompt(value);
    };

    composer.addEventListener('submit', handleSubmit);

    editor.addEventListener('input', () => {
      updatePlaceholderState();
      if (activePromptButton) {
        activePromptButton.setAttribute('aria-pressed', 'false');
        activePromptButton = null;
      }
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

    const promptsHostCandidates = [
      document.querySelector('[data-luna-prompts]'),
      document.querySelector('.luna-canned-prompts'),
      document.querySelector('#luna-canned'),
      document.querySelector('.ai-canned-prompts'),
    ].filter(Boolean);
    let promptsHost = promptsHostCandidates.length ? promptsHostCandidates[0] : null;
    if (!promptsHost) {
      const firstLegacyPrompt = document.querySelector('[data-luna-prompt-item]');
      if (firstLegacyPrompt) {
        promptsHost = firstLegacyPrompt.closest('[data-luna-prompt-root]') || firstLegacyPrompt.parentElement;
      }
    }

    const hydrateLegacyPrompts = (items) => {
      let hydrated = false;
      items.forEach((item) => {
        if (!item || item.dataset.lunaPromptBound === 'true') {
          return;
        }
        const prompt = item.getAttribute('data-luna-prompt') || item.textContent || '';
        const normalized = prompt.replace(/\s+/g, ' ').trim();
        if (normalized === '') {
          return;
        }
        hydrated = true;
        item.dataset.lunaPromptBound = 'true';
        item.setAttribute('data-luna-prompt-ready', 'true');
        if (!item.hasAttribute('role')) {
          item.setAttribute('role', 'button');
        }
        if (!item.hasAttribute('tabindex')) {
          item.tabIndex = 0;
        }
        item.setAttribute('aria-pressed', 'false');

        const activate = () => {
          if (activePromptButton && activePromptButton !== item) {
            activePromptButton.setAttribute('aria-pressed', 'false');
          }
          activePromptButton = item;
          item.setAttribute('aria-pressed', 'true');
          setEditorText(normalized);
        };

        item.addEventListener('click', (event) => {
          event.preventDefault();
          activate();
        });
        item.addEventListener('keydown', (event) => {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            activate();
          }
        });
      });
      return hydrated;
    };

    const renderPrompts = (container, prompts) => {
      if (!container) {
        return;
      }
      if (!container.classList.contains('luna-canned-prompts')) {
        container.classList.add('luna-canned-prompts');
        container.innerHTML = '';
      }
      prompts.forEach(({ label, prompt }) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'luna-canned-prompts__button';
        button.textContent = label;
        button.setAttribute('data-prompt', prompt);
        button.setAttribute('aria-pressed', 'false');
        button.addEventListener('click', () => {
          if (activePromptButton && activePromptButton !== button) {
            activePromptButton.setAttribute('aria-pressed', 'false');
          }
          activePromptButton = button;
          button.setAttribute('aria-pressed', 'true');
          setEditorText(prompt);
        });
        container.appendChild(button);
      });
    };

    const legacyHydrated = hydrateLegacyPrompts(Array.from((promptsHost || document).querySelectorAll('[data-luna-prompt-item]')));

    if (!legacyHydrated) {
      if (promptsHost) {
        if (!promptsHost.hasChildNodes()) {
          renderPrompts(promptsHost, cannedDefaults);
        }
      } else {
        const wrapper = document.createElement('div');
        renderPrompts(wrapper, cannedDefaults);
        composer.parentNode.insertBefore(wrapper, composer);
      }
    }

    window.lunaBootstrapped = true;
  };

  ready(initComposer);
})();
