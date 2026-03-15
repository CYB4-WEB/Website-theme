/**
 * Project Alpha - Manga Chapter Reader
 *
 * Handles image-based chapter reading with single-page and one-shot modes,
 * keyboard navigation, zoom, preloading, progress tracking, and content gates.
 *
 * @package starter
 */

(function () {
  'use strict';

  // ---------------------------------------------------------------------------
  // State
  // ---------------------------------------------------------------------------

  const state = {
    chapterId: 0,
    mangaId: 0,
    pages: [],           // Array of decrypted image URLs
    currentPage: 0,
    totalPages: 0,
    mode: 'single',      // 'single' | 'oneshot'
    zoom: 1,
    minZoom: 1,
    maxZoom: 3,
    loading: false,
    adultGateConfirmed: false,
    familySafe: false,
    infiniteScroll: false,
    retryMap: {},        // pageIndex → retry count
    maxRetries: 3,
    preloadAhead: 2,
    preloadBehind: 1,
    chapterList: [],
    isLastChapter: false,
    isFirstChapter: false,
  };

  // Cached DOM references
  let dom = {};

  // ---------------------------------------------------------------------------
  // Initialization
  // ---------------------------------------------------------------------------

  document.addEventListener('DOMContentLoaded', () => {
    const readerEl = document.querySelector('.chapter-reader');
    if (!readerEl) return;

    // Read data attributes
    state.chapterId = parseInt(readerEl.dataset.chapterId, 10) || 0;
    state.mangaId = parseInt(readerEl.dataset.mangaId, 10) || 0;
    state.familySafe = localStorage.getItem('starter_family_safe') === 'true';

    // Restore layout preference
    const savedMode = localStorage.getItem('starter_reader_mode');
    if (savedMode === 'single' || savedMode === 'oneshot') {
      state.mode = savedMode;
    }

    cacheDom();
    bindEvents();

    // Adult content gate check
    if (readerEl.dataset.adult === 'true' && !state.adultGateConfirmed && !state.familySafe) {
      showAdultGate();
    } else {
      loadChapter();
    }
  });

  /**
   * Cache frequently-accessed DOM elements.
   */
  function cacheDom() {
    dom = {
      reader: document.querySelector('.chapter-reader'),
      container: document.querySelector('.reader-pages'),
      progressBar: document.querySelector('.reader-progress-bar'),
      pageIndicator: document.querySelector('.reader-page-indicator'),
      prevPage: document.querySelector('.reader-prev-page'),
      nextPage: document.querySelector('.reader-next-page'),
      prevChapter: document.querySelector('.reader-prev-chapter'),
      nextChapter: document.querySelector('.reader-next-chapter'),
      chapterSelect: document.querySelector('.reader-chapter-select'),
      modeToggle: document.querySelector('.reader-mode-toggle'),
      zoomIn: document.querySelector('.reader-zoom-in'),
      zoomOut: document.querySelector('.reader-zoom-out'),
      familySafeToggle: document.querySelector('.reader-family-safe'),
      infiniteToggle: document.querySelector('.reader-infinite-toggle'),
    };
  }

  // ---------------------------------------------------------------------------
  // Event Binding
  // ---------------------------------------------------------------------------

  function bindEvents() {
    // Page navigation
    if (dom.prevPage) dom.prevPage.addEventListener('click', prevPage);
    if (dom.nextPage) dom.nextPage.addEventListener('click', nextPage);

    // Chapter navigation
    if (dom.prevChapter) dom.prevChapter.addEventListener('click', prevChapter);
    if (dom.nextChapter) dom.nextChapter.addEventListener('click', nextChapter);

    // Chapter select dropdown
    if (dom.chapterSelect) {
      dom.chapterSelect.addEventListener('click', loadChapterList);
      dom.chapterSelect.addEventListener('change', onChapterSelectChange);
    }

    // Mode toggle
    if (dom.modeToggle) {
      dom.modeToggle.addEventListener('click', toggleMode);
    }

    // Zoom controls
    if (dom.zoomIn) dom.zoomIn.addEventListener('click', () => setZoom(state.zoom + 0.25));
    if (dom.zoomOut) dom.zoomOut.addEventListener('click', () => setZoom(state.zoom - 0.25));

    // Family safe toggle
    if (dom.familySafeToggle) {
      dom.familySafeToggle.addEventListener('click', toggleFamilySafe);
    }

    // Infinite scroll toggle
    if (dom.infiniteToggle) {
      dom.infiniteToggle.addEventListener('click', toggleInfiniteScroll);
    }

    // Keyboard navigation
    document.addEventListener('keydown', onKeyDown);

    // Scroll events: progress bar + infinite scroll
    window.addEventListener('scroll', window.starterThrottle
      ? window.starterThrottle(onScroll, 100)
      : onScroll, { passive: true });

    // Desktop zoom via scroll wheel on reader
    if (dom.reader) {
      dom.reader.addEventListener('wheel', onWheelZoom, { passive: false });
    }

    // Mobile touch: pinch-to-zoom + double-tap
    initTouchHandlers();
  }

  // ---------------------------------------------------------------------------
  // Chapter Loading
  // ---------------------------------------------------------------------------

  /**
   * Load chapter images via AJAX (encrypted URLs handled by chapter-protector).
   */
  function loadChapter() {
    if (state.loading) return;
    state.loading = true;
    showSkeleton();

    window.starterAjax('starter_load_chapter', {
      chapter_id: state.chapterId,
      manga_id: state.mangaId,
    })
      .then((res) => {
        const data = res.data || res;
        state.pages = data.pages || [];
        state.totalPages = state.pages.length;
        state.currentPage = 0;
        state.isFirstChapter = !!data.is_first;
        state.isLastChapter = !!data.is_last;

        updateNavButtons();
        render();
        preloadAdjacentImages();
        saveReadingPosition();
      })
      .catch((err) => {
        console.error('Failed to load chapter:', err);
        showError('Failed to load chapter. Please try again.');
      })
      .finally(() => {
        state.loading = false;
      });
  }

  /**
   * AJAX-load the chapter list for the dropdown selector.
   */
  function loadChapterList() {
    if (state.chapterList.length) return; // Already loaded

    window.starterAjax('starter_chapter_list', { manga_id: state.mangaId })
      .then((res) => {
        state.chapterList = res.data || [];
        if (dom.chapterSelect) {
          dom.chapterSelect.innerHTML = '';
          state.chapterList.forEach((ch) => {
            const opt = document.createElement('option');
            opt.value = ch.id;
            opt.textContent = ch.title || `Chapter ${ch.number}`;
            if (ch.id === state.chapterId) opt.selected = true;
            dom.chapterSelect.appendChild(opt);
          });
        }
      })
      .catch((err) => console.error('Failed to load chapter list:', err));
  }

  function onChapterSelectChange(e) {
    const newId = parseInt(e.target.value, 10);
    if (newId && newId !== state.chapterId) {
      navigateToChapter(newId);
    }
  }

  // ---------------------------------------------------------------------------
  // Rendering
  // ---------------------------------------------------------------------------

  /**
   * Render pages based on current mode.
   */
  function render() {
    if (!dom.container) return;
    dom.container.innerHTML = '';

    if (state.mode === 'single') {
      renderSinglePage();
    } else {
      renderOneShot();
    }
    updateProgressBar();
    updatePageIndicator();
  }

  /**
   * Single-page mode: display one image at a time.
   */
  function renderSinglePage() {
    if (!state.pages.length) return;
    const page = state.pages[state.currentPage];
    const wrapper = createPageElement(page, state.currentPage);
    dom.container.appendChild(wrapper);
    dom.container.style.transform = `scale(${state.zoom})`;
    dom.container.style.transformOrigin = 'center top';
  }

  /**
   * One-shot mode: display all images stacked vertically.
   */
  function renderOneShot() {
    state.pages.forEach((page, idx) => {
      const wrapper = createPageElement(page, idx);
      dom.container.appendChild(wrapper);
    });
    dom.container.style.transform = `scale(${state.zoom})`;
    dom.container.style.transformOrigin = 'center top';
  }

  /**
   * Create a page element (image or canvas placeholder).
   *
   * @param {string} src   - Image URL.
   * @param {number} index - Page index.
   * @returns {HTMLElement}
   */
  function createPageElement(src, index) {
    const wrapper = document.createElement('div');
    wrapper.className = 'reader-page';
    wrapper.dataset.page = index;

    const img = document.createElement('img');
    img.className = 'reader-page__image';
    img.alt = `Page ${index + 1}`;
    img.loading = 'lazy';
    img.draggable = false;

    // Show skeleton until loaded
    const skeleton = document.createElement('div');
    skeleton.className = 'reader-page__skeleton';
    wrapper.appendChild(skeleton);

    img.addEventListener('load', () => {
      skeleton.remove();
      img.classList.add('reader-page__image--loaded');
    });

    img.addEventListener('error', () => {
      handleImageError(img, src, index);
    });

    img.src = src;
    wrapper.appendChild(img);

    return wrapper;
  }

  // ---------------------------------------------------------------------------
  // Skeleton / Error States
  // ---------------------------------------------------------------------------

  function showSkeleton() {
    if (!dom.container) return;
    dom.container.innerHTML = '';
    for (let i = 0; i < 3; i++) {
      const sk = document.createElement('div');
      sk.className = 'reader-page__skeleton reader-page__skeleton--full';
      dom.container.appendChild(sk);
    }
  }

  function showError(message) {
    if (!dom.container) return;
    dom.container.innerHTML = `<div class="reader-error"><p>${message}</p>
      <button class="reader-error__retry" onclick="location.reload()">Retry</button></div>`;
  }

  /**
   * Retry a failed image load up to maxRetries times.
   */
  function handleImageError(img, src, index) {
    const count = state.retryMap[index] || 0;
    if (count < state.maxRetries) {
      state.retryMap[index] = count + 1;
      setTimeout(() => {
        img.src = src + (src.includes('?') ? '&' : '?') + 'retry=' + state.retryMap[index];
      }, 1000 * (count + 1));
    } else {
      const wrapper = img.closest('.reader-page');
      if (wrapper) {
        const errDiv = document.createElement('div');
        errDiv.className = 'reader-page__error';
        errDiv.textContent = 'Failed to load image.';
        wrapper.innerHTML = '';
        wrapper.appendChild(errDiv);
      }
    }
  }

  // ---------------------------------------------------------------------------
  // Navigation
  // ---------------------------------------------------------------------------

  function prevPage() {
    if (state.mode !== 'single') return;
    if (state.currentPage > 0) {
      state.currentPage--;
      render();
      preloadAdjacentImages();
      saveReadingPosition();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  }

  function nextPage() {
    if (state.mode !== 'single') return;
    if (state.currentPage < state.totalPages - 1) {
      state.currentPage++;
      render();
      preloadAdjacentImages();
      saveReadingPosition();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    } else if (state.infiniteScroll && !state.isLastChapter) {
      autoLoadNextChapter();
    }
  }

  function prevChapter() {
    if (state.isFirstChapter) return;
    const idx = state.chapterList.findIndex((c) => c.id === state.chapterId);
    if (idx > 0) {
      navigateToChapter(state.chapterList[idx - 1].id);
    } else {
      // If chapter list isn't loaded, use AJAX
      window.starterAjax('starter_adjacent_chapter', {
        chapter_id: state.chapterId,
        direction: 'prev',
      }).then((res) => {
        if (res.data && res.data.url) {
          window.location.href = res.data.url;
        }
      });
    }
  }

  function nextChapter() {
    if (state.isLastChapter) return;
    const idx = state.chapterList.findIndex((c) => c.id === state.chapterId);
    if (idx >= 0 && idx < state.chapterList.length - 1) {
      navigateToChapter(state.chapterList[idx + 1].id);
    } else {
      window.starterAjax('starter_adjacent_chapter', {
        chapter_id: state.chapterId,
        direction: 'next',
      }).then((res) => {
        if (res.data && res.data.url) {
          window.location.href = res.data.url;
        }
      });
    }
  }

  function navigateToChapter(id) {
    const sd = window.starterData || {};
    const base = sd.readerBaseUrl || '';
    if (base) {
      window.location.href = `${base}?chapter=${id}`;
    } else {
      state.chapterId = id;
      state.chapterList = [];
      loadChapter();
    }
  }

  function updateNavButtons() {
    if (dom.prevChapter) {
      dom.prevChapter.disabled = state.isFirstChapter;
    }
    if (dom.nextChapter) {
      dom.nextChapter.disabled = state.isLastChapter;
    }
  }

  // ---------------------------------------------------------------------------
  // Keyboard Navigation
  // ---------------------------------------------------------------------------

  function onKeyDown(e) {
    // Ignore when inside inputs
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') return;

    switch (e.key) {
      case 'ArrowLeft':
        e.preventDefault();
        prevPage();
        break;
      case 'ArrowRight':
        e.preventDefault();
        nextPage();
        break;
      // Up/Down handled by default browser scroll
    }
  }

  // ---------------------------------------------------------------------------
  // Progress Bar
  // ---------------------------------------------------------------------------

  function updateProgressBar() {
    if (!dom.progressBar) return;
    let progress = 0;
    if (state.mode === 'single' && state.totalPages > 0) {
      progress = ((state.currentPage + 1) / state.totalPages) * 100;
    }
    dom.progressBar.style.width = progress + '%';
  }

  function updatePageIndicator() {
    if (!dom.pageIndicator) return;
    if (state.mode === 'single') {
      dom.pageIndicator.textContent = `${state.currentPage + 1} / ${state.totalPages}`;
    } else {
      dom.pageIndicator.textContent = `${state.totalPages} pages`;
    }
  }

  /**
   * On scroll: update progress bar (one-shot mode) + detect end for infinite scroll.
   */
  function onScroll() {
    if (state.mode === 'oneshot' && dom.progressBar) {
      const scrollTop = window.scrollY;
      const docHeight = document.documentElement.scrollHeight - window.innerHeight;
      const progress = docHeight > 0 ? (scrollTop / docHeight) * 100 : 0;
      dom.progressBar.style.width = progress + '%';
    }

    // Infinite scroll: when near bottom, auto-load next chapter
    if (state.infiniteScroll && !state.isLastChapter && !state.loading) {
      const nearBottom = (window.innerHeight + window.scrollY) >= (document.body.scrollHeight - 500);
      if (nearBottom) {
        autoLoadNextChapter();
      }
    }
  }

  // ---------------------------------------------------------------------------
  // Preload Adjacent Images
  // ---------------------------------------------------------------------------

  function preloadAdjacentImages() {
    const start = Math.max(0, state.currentPage - state.preloadBehind);
    const end = Math.min(state.totalPages - 1, state.currentPage + state.preloadAhead);

    for (let i = start; i <= end; i++) {
      if (i === state.currentPage) continue;
      const link = document.createElement('link');
      link.rel = 'prefetch';
      link.href = state.pages[i];
      link.as = 'image';
      document.head.appendChild(link);
    }
  }

  // ---------------------------------------------------------------------------
  // Zoom
  // ---------------------------------------------------------------------------

  function setZoom(level) {
    state.zoom = Math.min(state.maxZoom, Math.max(state.minZoom, level));
    if (dom.container) {
      dom.container.style.transform = `scale(${state.zoom})`;
    }
  }

  /**
   * Desktop: Ctrl + scroll wheel to zoom.
   */
  function onWheelZoom(e) {
    if (!e.ctrlKey) return;
    e.preventDefault();
    const delta = e.deltaY > 0 ? -0.1 : 0.1;
    setZoom(state.zoom + delta);
  }

  // ---------------------------------------------------------------------------
  // Touch Handlers (mobile pinch-to-zoom + double-tap)
  // ---------------------------------------------------------------------------

  function initTouchHandlers() {
    if (!dom.reader) return;

    let lastTap = 0;
    let initialDistance = 0;
    let initialZoom = 1;

    // Double-tap to zoom
    dom.reader.addEventListener('touchend', (e) => {
      const now = Date.now();
      if (now - lastTap < 300) {
        e.preventDefault();
        // Toggle between 1x and 2x
        setZoom(state.zoom > 1 ? 1 : 2);
      }
      lastTap = now;
    });

    // Pinch to zoom
    dom.reader.addEventListener('touchstart', (e) => {
      if (e.touches.length === 2) {
        initialDistance = getTouchDistance(e.touches);
        initialZoom = state.zoom;
      }
    }, { passive: true });

    dom.reader.addEventListener('touchmove', (e) => {
      if (e.touches.length === 2) {
        e.preventDefault();
        const dist = getTouchDistance(e.touches);
        const scale = dist / initialDistance;
        setZoom(initialZoom * scale);
      }
    }, { passive: false });
  }

  function getTouchDistance(touches) {
    const dx = touches[0].clientX - touches[1].clientX;
    const dy = touches[0].clientY - touches[1].clientY;
    return Math.sqrt(dx * dx + dy * dy);
  }

  // ---------------------------------------------------------------------------
  // Mode Toggle
  // ---------------------------------------------------------------------------

  function toggleMode() {
    state.mode = state.mode === 'single' ? 'oneshot' : 'single';
    localStorage.setItem('starter_reader_mode', state.mode);
    if (dom.modeToggle) {
      dom.modeToggle.textContent = state.mode === 'single' ? 'Single Page' : 'One-Shot';
    }
    render();
  }

  // ---------------------------------------------------------------------------
  // Infinite Scroll (auto-load next chapter)
  // ---------------------------------------------------------------------------

  function toggleInfiniteScroll() {
    state.infiniteScroll = !state.infiniteScroll;
    if (dom.infiniteToggle) {
      dom.infiniteToggle.classList.toggle('active', state.infiniteScroll);
    }
  }

  function autoLoadNextChapter() {
    if (state.loading || state.isLastChapter) return;
    state.loading = true;

    window.starterAjax('starter_adjacent_chapter', {
      chapter_id: state.chapterId,
      direction: 'next',
    }).then((res) => {
      if (res.data && res.data.pages) {
        // Append new pages
        state.pages = state.pages.concat(res.data.pages);
        state.totalPages = state.pages.length;
        state.chapterId = res.data.chapter_id || state.chapterId;
        state.isLastChapter = !!res.data.is_last;

        // Re-render in oneshot mode (append)
        if (state.mode === 'oneshot') {
          res.data.pages.forEach((src, i) => {
            const idx = state.totalPages - res.data.pages.length + i;
            const el = createPageElement(src, idx);
            dom.container.appendChild(el);
          });
        }
      }
    })
      .catch((err) => console.error('Auto-load next chapter failed:', err))
      .finally(() => { state.loading = false; });
  }

  // ---------------------------------------------------------------------------
  // Reading Position / History
  // ---------------------------------------------------------------------------

  function saveReadingPosition() {
    try {
      window.starterAjax('starter_save_reading_position', {
        manga_id: state.mangaId,
        chapter_id: state.chapterId,
        page: state.currentPage,
      });
    } catch (err) {
      // Silently fail for guests – position is not saved server-side
    }
  }

  // ---------------------------------------------------------------------------
  // Adult Content Gate
  // ---------------------------------------------------------------------------

  function showAdultGate() {
    if (!dom.container) return;
    dom.container.innerHTML = '';

    const gate = document.createElement('div');
    gate.className = 'reader-adult-gate';
    gate.innerHTML = `
      <div class="reader-adult-gate__inner">
        <h2>Adult Content Warning</h2>
        <p>This chapter contains adult content. You must be 18 or older to view it.</p>
        <div class="reader-adult-gate__actions">
          <button class="reader-adult-gate__confirm btn btn-primary">I am 18+ — Continue</button>
          <a href="/" class="reader-adult-gate__cancel btn btn-secondary">Go Back</a>
        </div>
      </div>
    `;

    gate.querySelector('.reader-adult-gate__confirm').addEventListener('click', () => {
      state.adultGateConfirmed = true;
      loadChapter();
    });

    dom.container.appendChild(gate);
  }

  // ---------------------------------------------------------------------------
  // Family Safe Mode
  // ---------------------------------------------------------------------------

  function toggleFamilySafe() {
    state.familySafe = !state.familySafe;
    localStorage.setItem('starter_family_safe', String(state.familySafe));
    if (dom.familySafeToggle) {
      dom.familySafeToggle.classList.toggle('active', state.familySafe);
    }
    if (window.starterToast) {
      window.starterToast.show(
        state.familySafe ? 'Family safe mode enabled' : 'Family safe mode disabled',
        'info'
      );
    }
  }
})();
