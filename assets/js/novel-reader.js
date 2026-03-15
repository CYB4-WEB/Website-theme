/**
 * Project Alpha - Novel Chapter Reader
 *
 * Text-based reader with full customization (font, size, colors, line height),
 * reading progress, estimated time, and pagination for long chapters.
 *
 * @package starter
 */

(function () {
  'use strict';

  // ---------------------------------------------------------------------------
  // Default Settings
  // ---------------------------------------------------------------------------

  const DEFAULTS = {
    bgColor: '#ffffff',
    textColor: '#333333',
    fontFamily: 'Georgia, serif',
    fontSize: 18,
    lineHeight: 1.8,
  };

  // Preset color schemes
  const PRESETS = {
    light:  { bg: '#ffffff',  color: '#333333' },
    dark:   { bg: '#1a1a2e',  color: '#e0e0e0' },
    sepia:  { bg: '#f4ecd8',  color: '#5b4636' },
  };

  // Available fonts (these should match fonts enqueued by the theme)
  const FONTS = [
    { label: 'Georgia', value: 'Georgia, serif' },
    { label: 'Merriweather', value: '"Merriweather", serif' },
    { label: 'Lora', value: '"Lora", serif' },
    { label: 'Open Sans', value: '"Open Sans", sans-serif' },
    { label: 'Roboto', value: '"Roboto", sans-serif' },
    { label: 'Source Code Pro', value: '"Source Code Pro", monospace' },
    { label: 'System Default', value: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif' },
  ];

  // Words per minute for estimated reading time
  const WPM = 200;

  // Pagination: max words per page (0 = no pagination)
  const WORDS_PER_PAGE = 3000;

  // ---------------------------------------------------------------------------
  // State
  // ---------------------------------------------------------------------------

  const state = {
    settings: Object.assign({}, DEFAULTS),
    chapterId: 0,
    mangaId: 0,
    totalWords: 0,
    currentTextPage: 0,
    textPages: [],       // Paginated text chunks
    toolsPanelOpen: false,
    isLoggedIn: false,
  };

  let dom = {};

  // ---------------------------------------------------------------------------
  // Initialization
  // ---------------------------------------------------------------------------

  document.addEventListener('DOMContentLoaded', () => {
    const readerEl = document.querySelector('.novel-reader');
    if (!readerEl) return;

    state.chapterId = parseInt(readerEl.dataset.chapterId, 10) || 0;
    state.mangaId = parseInt(readerEl.dataset.mangaId, 10) || 0;
    state.isLoggedIn = !!(window.starterData && window.starterData.isLoggedIn);

    cacheDom();
    loadSettings();
    applySettings();
    initContent();
    buildToolsPanel();
    bindEvents();
    updateEstimatedTime();
  });

  function cacheDom() {
    dom = {
      reader: document.querySelector('.novel-reader'),
      content: document.querySelector('.novel-reader__content'),
      toolsToggle: document.querySelector('.novel-reader__tools-toggle'),
      toolsPanel: document.querySelector('.novel-reader__tools-panel'),
      progress: document.querySelector('.novel-reader__progress'),
      progressText: document.querySelector('.novel-reader__progress-text'),
      readingTime: document.querySelector('.novel-reader__reading-time'),
      prevPage: document.querySelector('.novel-reader__prev-page'),
      nextPage: document.querySelector('.novel-reader__next-page'),
      pageIndicator: document.querySelector('.novel-reader__page-indicator'),
    };
  }

  // ---------------------------------------------------------------------------
  // Settings Persistence
  // ---------------------------------------------------------------------------

  /**
   * Load settings from AJAX (logged-in) or localStorage (guest).
   */
  function loadSettings() {
    // First try localStorage for immediate display
    const local = localStorage.getItem('starter_novel_settings');
    if (local) {
      try {
        const parsed = JSON.parse(local);
        Object.assign(state.settings, parsed);
      } catch (e) {
        // Ignore invalid JSON
      }
    }

    // If logged in, load from server (may override localStorage)
    if (state.isLoggedIn) {
      window.starterAjax('starter_get_novel_settings')
        .then((res) => {
          if (res.data && typeof res.data === 'object') {
            Object.assign(state.settings, res.data);
            applySettings();
            syncToolsPanel();
          }
        })
        .catch(() => {
          // Silently use local settings
        });
    }
  }

  /**
   * Save current settings.
   */
  function saveSettings() {
    // Always save to localStorage for fast next load
    localStorage.setItem('starter_novel_settings', JSON.stringify(state.settings));

    // If logged in, persist to server
    if (state.isLoggedIn) {
      window.starterAjax('starter_save_novel_settings', {
        settings: JSON.stringify(state.settings),
      }).catch(() => {
        // Silent fail
      });
    }
  }

  /**
   * Apply settings via CSS custom properties for smooth transitions.
   */
  function applySettings() {
    const el = dom.reader || document.documentElement;
    el.style.setProperty('--novel-bg', state.settings.bgColor);
    el.style.setProperty('--novel-color', state.settings.textColor);
    el.style.setProperty('--novel-font', state.settings.fontFamily);
    el.style.setProperty('--novel-size', state.settings.fontSize + 'px');
    el.style.setProperty('--novel-line-height', String(state.settings.lineHeight));
  }

  /**
   * Reset all settings to defaults.
   */
  function resetSettings() {
    Object.assign(state.settings, DEFAULTS);
    applySettings();
    saveSettings();
    syncToolsPanel();
    if (window.starterToast) {
      window.starterToast.show('Settings reset to defaults', 'info');
    }
  }

  // ---------------------------------------------------------------------------
  // Content & Pagination
  // ---------------------------------------------------------------------------

  /**
   * Prepare content: count words, split into pages if needed.
   */
  function initContent() {
    if (!dom.content) return;

    const rawText = dom.content.textContent || '';
    const words = rawText.trim().split(/\s+/);
    state.totalWords = words.length;

    // Paginate if chapter is long
    if (WORDS_PER_PAGE > 0 && state.totalWords > WORDS_PER_PAGE) {
      const fullHTML = dom.content.innerHTML;
      state.textPages = splitHTMLByWordCount(fullHTML, WORDS_PER_PAGE);
      state.currentTextPage = 0;
      renderTextPage();
    } else {
      state.textPages = [dom.content.innerHTML];
      state.currentTextPage = 0;
    }

    updatePageIndicator();
  }

  /**
   * Approximate HTML splitting by word count.
   * This is a simplified approach that splits on whitespace boundaries.
   *
   * @param {string} html       - HTML content.
   * @param {number} wordsPerPage - Words per page.
   * @returns {string[]}
   */
  function splitHTMLByWordCount(html, wordsPerPage) {
    // Use a temporary element to get text nodes
    const temp = document.createElement('div');
    temp.innerHTML = html;
    const text = temp.textContent || '';
    const words = text.split(/\s+/);
    const pages = [];
    const totalPages = Math.ceil(words.length / wordsPerPage);

    // Simple approach: split the raw HTML at approximate word boundaries
    // by counting words in text content
    const allParagraphs = temp.querySelectorAll('p, div, br, h1, h2, h3, h4, h5, h6');

    if (allParagraphs.length === 0) {
      // No block elements, split by word count directly
      for (let i = 0; i < totalPages; i++) {
        const chunk = words.slice(i * wordsPerPage, (i + 1) * wordsPerPage);
        pages.push('<p>' + chunk.join(' ') + '</p>');
      }
      return pages;
    }

    // Split by paragraphs
    let currentPage = '';
    let currentWordCount = 0;

    allParagraphs.forEach((p) => {
      const pWords = (p.textContent || '').trim().split(/\s+/).length;
      if (currentWordCount + pWords > wordsPerPage && currentPage) {
        pages.push(currentPage);
        currentPage = '';
        currentWordCount = 0;
      }
      currentPage += p.outerHTML;
      currentWordCount += pWords;
    });

    if (currentPage) {
      pages.push(currentPage);
    }

    return pages.length ? pages : [html];
  }

  function renderTextPage() {
    if (!dom.content || !state.textPages.length) return;
    dom.content.innerHTML = state.textPages[state.currentTextPage];
    updatePageIndicator();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function prevTextPage() {
    if (state.currentTextPage > 0) {
      state.currentTextPage--;
      renderTextPage();
    }
  }

  function nextTextPage() {
    if (state.currentTextPage < state.textPages.length - 1) {
      state.currentTextPage++;
      renderTextPage();
    }
  }

  function updatePageIndicator() {
    if (!dom.pageIndicator) return;
    if (state.textPages.length > 1) {
      dom.pageIndicator.textContent = `Page ${state.currentTextPage + 1} / ${state.textPages.length}`;
      if (dom.prevPage) dom.prevPage.style.display = '';
      if (dom.nextPage) dom.nextPage.style.display = '';
    } else {
      dom.pageIndicator.textContent = '';
      if (dom.prevPage) dom.prevPage.style.display = 'none';
      if (dom.nextPage) dom.nextPage.style.display = 'none';
    }
  }

  // ---------------------------------------------------------------------------
  // Reading Progress & Estimated Time
  // ---------------------------------------------------------------------------

  function updateReadingProgress() {
    const scrollTop = window.scrollY;
    const docHeight = document.documentElement.scrollHeight - window.innerHeight;
    const progress = docHeight > 0 ? Math.round((scrollTop / docHeight) * 100) : 0;

    if (dom.progress) {
      dom.progress.style.width = progress + '%';
    }
    if (dom.progressText) {
      dom.progressText.textContent = progress + '%';
    }
  }

  function updateEstimatedTime() {
    if (!dom.readingTime) return;
    const minutes = Math.ceil(state.totalWords / WPM);
    if (minutes > 0) {
      dom.readingTime.textContent = `~${minutes} min read`;
    }
  }

  // ---------------------------------------------------------------------------
  // Tools Panel
  // ---------------------------------------------------------------------------

  /**
   * Build the reading tools panel dynamically.
   */
  function buildToolsPanel() {
    if (!dom.toolsPanel) return;

    dom.toolsPanel.innerHTML = `
      <div class="novel-tools">
        <h3 class="novel-tools__title">Reading Settings</h3>

        <!-- Background Color Presets -->
        <div class="novel-tools__group">
          <label class="novel-tools__label">Background</label>
          <div class="novel-tools__presets" id="novel-bg-presets">
            <button class="novel-tools__preset-btn" data-preset="light" style="background:#fff;color:#333" title="Light">A</button>
            <button class="novel-tools__preset-btn" data-preset="dark" style="background:#1a1a2e;color:#e0e0e0" title="Dark">A</button>
            <button class="novel-tools__preset-btn" data-preset="sepia" style="background:#f4ecd8;color:#5b4636" title="Sepia">A</button>
          </div>
          <div class="novel-tools__custom-color">
            <label>Custom: <input type="color" id="novel-bg-picker" value="${state.settings.bgColor}"></label>
          </div>
        </div>

        <!-- Text Color -->
        <div class="novel-tools__group">
          <label class="novel-tools__label">Text Color</label>
          <div class="novel-tools__custom-color">
            <input type="color" id="novel-text-picker" value="${state.settings.textColor}">
          </div>
        </div>

        <!-- Font Family -->
        <div class="novel-tools__group">
          <label class="novel-tools__label" for="novel-font-select">Font</label>
          <select id="novel-font-select" class="novel-tools__select">
            ${FONTS.map((f) => `<option value='${f.value}' style="font-family:${f.value}" ${f.value === state.settings.fontFamily ? 'selected' : ''}>${f.label}</option>`).join('')}
          </select>
        </div>

        <!-- Font Size -->
        <div class="novel-tools__group">
          <label class="novel-tools__label">Font Size: <span id="novel-size-value">${state.settings.fontSize}px</span></label>
          <input type="range" id="novel-size-slider" min="12" max="32" step="1" value="${state.settings.fontSize}">
        </div>

        <!-- Line Height -->
        <div class="novel-tools__group">
          <label class="novel-tools__label">Line Height: <span id="novel-lh-value">${state.settings.lineHeight}</span></label>
          <input type="range" id="novel-lh-slider" min="1.2" max="3.0" step="0.1" value="${state.settings.lineHeight}">
        </div>

        <!-- Reset -->
        <div class="novel-tools__group">
          <button id="novel-reset-btn" class="novel-tools__reset btn btn-secondary">Reset to Defaults</button>
        </div>
      </div>
    `;

    bindToolsEvents();
  }

  /**
   * Bind events on the tools panel controls.
   */
  function bindToolsEvents() {
    // Preset buttons
    const presetBtns = document.querySelectorAll('#novel-bg-presets .novel-tools__preset-btn');
    presetBtns.forEach((btn) => {
      btn.addEventListener('click', () => {
        const preset = PRESETS[btn.dataset.preset];
        if (preset) {
          state.settings.bgColor = preset.bg;
          state.settings.textColor = preset.color;
          applySettings();
          saveSettings();
          syncToolsPanel();
        }
      });
    });

    // Custom background color
    const bgPicker = document.getElementById('novel-bg-picker');
    if (bgPicker) {
      bgPicker.addEventListener('input', (e) => {
        state.settings.bgColor = e.target.value;
        applySettings();
      });
      bgPicker.addEventListener('change', () => saveSettings());
    }

    // Custom text color
    const textPicker = document.getElementById('novel-text-picker');
    if (textPicker) {
      textPicker.addEventListener('input', (e) => {
        state.settings.textColor = e.target.value;
        applySettings();
      });
      textPicker.addEventListener('change', () => saveSettings());
    }

    // Font family
    const fontSelect = document.getElementById('novel-font-select');
    if (fontSelect) {
      fontSelect.addEventListener('change', (e) => {
        state.settings.fontFamily = e.target.value;
        applySettings();
        saveSettings();
        preloadFont(e.target.value);
      });
    }

    // Font size slider
    const sizeSlider = document.getElementById('novel-size-slider');
    const sizeValue = document.getElementById('novel-size-value');
    if (sizeSlider) {
      sizeSlider.addEventListener('input', (e) => {
        state.settings.fontSize = parseInt(e.target.value, 10);
        if (sizeValue) sizeValue.textContent = state.settings.fontSize + 'px';
        applySettings();
      });
      sizeSlider.addEventListener('change', () => saveSettings());
    }

    // Line height slider
    const lhSlider = document.getElementById('novel-lh-slider');
    const lhValue = document.getElementById('novel-lh-value');
    if (lhSlider) {
      lhSlider.addEventListener('input', (e) => {
        state.settings.lineHeight = parseFloat(e.target.value);
        if (lhValue) lhValue.textContent = state.settings.lineHeight.toFixed(1);
        applySettings();
      });
      lhSlider.addEventListener('change', () => saveSettings());
    }

    // Reset button
    const resetBtn = document.getElementById('novel-reset-btn');
    if (resetBtn) {
      resetBtn.addEventListener('click', resetSettings);
    }
  }

  /**
   * Sync tools panel controls with current state (after loading settings).
   */
  function syncToolsPanel() {
    const bgPicker = document.getElementById('novel-bg-picker');
    const textPicker = document.getElementById('novel-text-picker');
    const fontSelect = document.getElementById('novel-font-select');
    const sizeSlider = document.getElementById('novel-size-slider');
    const sizeValue = document.getElementById('novel-size-value');
    const lhSlider = document.getElementById('novel-lh-slider');
    const lhValue = document.getElementById('novel-lh-value');

    if (bgPicker) bgPicker.value = state.settings.bgColor;
    if (textPicker) textPicker.value = state.settings.textColor;
    if (fontSelect) fontSelect.value = state.settings.fontFamily;
    if (sizeSlider) sizeSlider.value = state.settings.fontSize;
    if (sizeValue) sizeValue.textContent = state.settings.fontSize + 'px';
    if (lhSlider) lhSlider.value = state.settings.lineHeight;
    if (lhValue) lhValue.textContent = state.settings.lineHeight.toFixed(1);
  }

  // ---------------------------------------------------------------------------
  // Font Preloading
  // ---------------------------------------------------------------------------

  /**
   * Preload a web font if not already loaded.
   *
   * @param {string} fontFamily - CSS font-family value.
   */
  function preloadFont(fontFamily) {
    // Extract the first font name
    const name = fontFamily.split(',')[0].replace(/["']/g, '').trim();

    if (document.fonts && document.fonts.check) {
      if (!document.fonts.check(`16px "${name}"`)) {
        // Attempt to load via Font Loading API
        try {
          const face = new FontFace(name, `local("${name}")`);
          face.load().catch(() => {
            // Font not available locally, that is fine — theme should enqueue it
          });
        } catch (e) {
          // Font Loading API not supported
        }
      }
    }
  }

  // ---------------------------------------------------------------------------
  // Event Binding
  // ---------------------------------------------------------------------------

  function bindEvents() {
    // Toggle tools panel
    if (dom.toolsToggle) {
      dom.toolsToggle.addEventListener('click', () => {
        state.toolsPanelOpen = !state.toolsPanelOpen;
        if (dom.toolsPanel) {
          dom.toolsPanel.classList.toggle('novel-reader__tools-panel--open', state.toolsPanelOpen);
        }
        dom.toolsToggle.classList.toggle('novel-reader__tools-toggle--active', state.toolsPanelOpen);
      });
    }

    // Text pagination
    if (dom.prevPage) dom.prevPage.addEventListener('click', prevTextPage);
    if (dom.nextPage) dom.nextPage.addEventListener('click', nextTextPage);

    // Reading progress on scroll
    const throttledProgress = window.starterThrottle
      ? window.starterThrottle(updateReadingProgress, 100)
      : updateReadingProgress;
    window.addEventListener('scroll', throttledProgress, { passive: true });
  }
})();
