(function() {
  'use strict';

  const config = window.starterData || {};
  const ajaxUrl = config.ajaxUrl || '/wp-admin/admin-ajax.php';
  const nonce = config.nonce || '';

  let toastTimer = null;

  /**
   * Show a toast notification
   */
  function showToast(message, type = 'success') {
    let container = document.querySelector('.starter-toast-container');
    if (!container) {
      container = document.createElement('div');
      container.className = 'starter-toast-container';
      document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `starter-toast starter-toast--${type}`;
    toast.textContent = message;
    container.appendChild(toast);

    // Trigger enter animation
    requestAnimationFrame(() => {
      toast.classList.add('starter-toast--visible');
    });

    // Auto dismiss
    const timer = setTimeout(() => {
      toast.classList.remove('starter-toast--visible');
      toast.addEventListener('transitionend', () => toast.remove(), { once: true });
      // Fallback removal if transitionend doesn't fire
      setTimeout(() => {
        if (toast.parentNode) toast.remove();
      }, 500);
    }, 3000);
  }

  /**
   * Animate the bookmark button (scale + color change)
   */
  function animateButton(button, isAdding) {
    button.classList.add('bookmark-btn--animating');
    if (isAdding) {
      button.classList.add('bookmark-btn--active');
    } else {
      button.classList.remove('bookmark-btn--active');
    }

    // Remove animation class after animation completes
    setTimeout(() => {
      button.classList.remove('bookmark-btn--animating');
    }, 400);
  }

  /**
   * Update the bookmark count display near a button
   */
  function updateBookmarkCount(button, delta) {
    const countEl = button.querySelector('.bookmark-count')
      || button.closest('.bookmark-wrapper')?.querySelector('.bookmark-count');

    if (countEl) {
      const current = parseInt(countEl.textContent, 10) || 0;
      const newCount = Math.max(0, current + delta);
      countEl.textContent = newCount;
    }
  }

  /**
   * Toggle bookmark via AJAX
   */
  function toggleBookmark(button) {
    const mangaId = button.dataset.mangaId;
    if (!mangaId) {
      console.error('Bookmark button missing data-manga-id');
      return;
    }

    const isCurrentlyBookmarked = button.classList.contains('bookmark-btn--active');
    const action = isCurrentlyBookmarked ? 'starter_remove_bookmark' : 'starter_add_bookmark';

    // Optimistic UI update
    animateButton(button, !isCurrentlyBookmarked);
    button.disabled = true;

    const formData = new FormData();
    formData.append('action', action);
    formData.append('nonce', nonce);
    formData.append('manga_id', mangaId);

    fetch(ajaxUrl, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    })
      .then(response => {
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        return response.json();
      })
      .then(data => {
        button.disabled = false;

        if (data.success) {
          if (isCurrentlyBookmarked) {
            updateBookmarkCount(button, -1);
            showToast('Bookmark removed', 'info');
          } else {
            updateBookmarkCount(button, 1);
            showToast('Bookmark added!', 'success');
          }
        } else {
          // Revert optimistic update on failure
          animateButton(button, isCurrentlyBookmarked);
          showToast(data.data?.message || 'Failed to update bookmark', 'error');
        }
      })
      .catch(error => {
        button.disabled = false;
        // Revert optimistic update
        animateButton(button, isCurrentlyBookmarked);
        console.error('Bookmark toggle error:', error);
        showToast('Something went wrong. Please try again.', 'error');
      });
  }

  /**
   * Initialize bookmark toggle buttons on manga pages
   */
  function initBookmarkButtons() {
    document.addEventListener('click', (e) => {
      const button = e.target.closest('.bookmark-btn');
      if (button) {
        e.preventDefault();
        toggleBookmark(button);
      }
    });
  }

  /**
   * Bookmarks page: load user bookmarks via AJAX
   */
  function initBookmarksPage() {
    const container = document.querySelector('.bookmarks-list');
    if (!container) return;

    const sortSelect = document.querySelector('.bookmarks-sort');
    let currentSort = sortSelect ? sortSelect.value : 'latest';

    loadBookmarks(container, currentSort);

    if (sortSelect) {
      sortSelect.addEventListener('change', () => {
        currentSort = sortSelect.value;
        loadBookmarks(container, currentSort);
      });
    }

    // Remove individual bookmark from bookmarks page
    container.addEventListener('click', (e) => {
      const removeBtn = e.target.closest('.bookmark-remove-btn');
      if (!removeBtn) return;

      e.preventDefault();
      const mangaId = removeBtn.dataset.mangaId;
      if (!mangaId) return;

      removeBookmarkFromPage(removeBtn, mangaId, container, currentSort);
    });
  }

  /**
   * Load bookmarks list via AJAX
   */
  function loadBookmarks(container, sort) {
    container.innerHTML = '<div class="bookmarks-loading"><span class="spinner"></span> Loading bookmarks...</div>';

    const formData = new FormData();
    formData.append('action', 'starter_get_bookmarks');
    formData.append('nonce', nonce);
    formData.append('sort', sort);

    fetch(ajaxUrl, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    })
      .then(response => {
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        return response.json();
      })
      .then(data => {
        if (data.success && data.data && data.data.bookmarks && data.data.bookmarks.length > 0) {
          container.innerHTML = data.data.bookmarks.map(renderBookmarkItem).join('');
        } else {
          container.innerHTML = '<div class="bookmarks-empty"><p>No bookmarks yet. Start reading and bookmark your favorites!</p></div>';
        }
      })
      .catch(error => {
        console.error('Load bookmarks error:', error);
        container.innerHTML = '<div class="bookmarks-error">Failed to load bookmarks. Please refresh the page.</div>';
      });
  }

  /**
   * Render a single bookmark item
   */
  function renderBookmarkItem(item) {
    const thumbnail = item.thumbnail
      ? `<img src="${escapeHtml(item.thumbnail)}" alt="${escapeHtml(item.title)}" class="bookmark-item__thumb" loading="lazy">`
      : '<div class="bookmark-item__thumb bookmark-item__thumb--placeholder"></div>';

    const continueReading = item.lastChapterUrl
      ? `<a href="${escapeHtml(item.lastChapterUrl)}" class="bookmark-item__continue">Continue Reading - ${escapeHtml(item.lastChapterTitle || 'Last Chapter')}</a>`
      : '';

    return `
      <div class="bookmark-item" data-manga-id="${escapeHtml(String(item.id))}">
        <div class="bookmark-item__image">
          ${thumbnail}
        </div>
        <div class="bookmark-item__info">
          <h3 class="bookmark-item__title">
            <a href="${escapeHtml(item.url)}">${escapeHtml(item.title)}</a>
          </h3>
          ${continueReading}
        </div>
        <button class="bookmark-remove-btn" data-manga-id="${escapeHtml(String(item.id))}" aria-label="Remove bookmark">
          <span class="bookmark-remove-icon">&times;</span>
        </button>
      </div>
    `;
  }

  /**
   * Remove a bookmark from the bookmarks page
   */
  function removeBookmarkFromPage(button, mangaId, container, sort) {
    const item = button.closest('.bookmark-item');
    if (item) {
      item.classList.add('bookmark-item--removing');
    }

    const formData = new FormData();
    formData.append('action', 'starter_remove_bookmark');
    formData.append('nonce', nonce);
    formData.append('manga_id', mangaId);

    fetch(ajaxUrl, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    })
      .then(response => {
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        return response.json();
      })
      .then(data => {
        if (data.success) {
          if (item) {
            item.addEventListener('transitionend', () => item.remove(), { once: true });
            item.style.maxHeight = '0';
            item.style.opacity = '0';
            // Fallback
            setTimeout(() => {
              if (item.parentNode) item.remove();
              // Check if list is empty
              if (container.querySelectorAll('.bookmark-item').length === 0) {
                container.innerHTML = '<div class="bookmarks-empty"><p>No bookmarks yet. Start reading and bookmark your favorites!</p></div>';
              }
            }, 400);
          }
          showToast('Bookmark removed', 'info');
        } else {
          if (item) item.classList.remove('bookmark-item--removing');
          showToast('Failed to remove bookmark', 'error');
        }
      })
      .catch(error => {
        if (item) item.classList.remove('bookmark-item--removing');
        console.error('Remove bookmark error:', error);
        showToast('Something went wrong. Please try again.', 'error');
      });
  }

  /**
   * Escape HTML to prevent XSS
   */
  function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
  }

  /**
   * Initialize
   */
  function init() {
    initBookmarkButtons();
    initBookmarksPage();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
