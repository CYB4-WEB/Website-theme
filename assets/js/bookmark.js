(function() {
    'use strict';

    const data = window.starterData || {};
    const ajaxUrl = data.ajaxUrl || '';
    const nonce = data.nonce || '';

    let toastTimeout = null;

    /**
     * Show toast notification
     */
    function showToast(message, type) {
        type = type || 'success';
        let toast = document.querySelector('.starter-toast');

        if (!toast) {
            toast = document.createElement('div');
            toast.className = 'starter-toast';
            document.body.appendChild(toast);
        }

        clearTimeout(toastTimeout);

        toast.textContent = message;
        toast.className = 'starter-toast toast-' + type + ' toast-visible';

        toastTimeout = setTimeout(function() {
            toast.classList.remove('toast-visible');
        }, 3000);
    }

    /**
     * Toggle bookmark button on manga page
     */
    function initBookmarkToggle() {
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.bookmark-toggle');
            if (!btn) return;

            e.preventDefault();

            if (btn.classList.contains('loading')) return;

            const mangaId = btn.getAttribute('data-manga-id');
            if (!mangaId) return;

            const isBookmarked = btn.classList.contains('bookmarked');
            const action = isBookmarked ? 'starter_remove_bookmark' : 'starter_add_bookmark';

            btn.classList.add('loading');

            const formData = new FormData();
            formData.append('action', action);
            formData.append('nonce', nonce);
            formData.append('manga_id', mangaId);

            fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(function(response) {
                if (!response.ok) throw new Error('Bookmark request failed');
                return response.json();
            })
            .then(function(result) {
                btn.classList.remove('loading');

                if (!result.success) {
                    showToast(result.data || 'Failed to update bookmark', 'error');
                    return;
                }

                btn.classList.toggle('bookmarked');
                animateBookmarkIcon(btn);

                const countEl = btn.querySelector('.bookmark-count');
                if (countEl && typeof result.data.count !== 'undefined') {
                    countEl.textContent = result.data.count;
                }

                const newState = btn.classList.contains('bookmarked');
                showToast(
                    newState ? 'Added to bookmarks' : 'Removed from bookmarks',
                    'success'
                );
            })
            .catch(function(error) {
                console.error('Bookmark error:', error);
                btn.classList.remove('loading');
                showToast('Failed to update bookmark. Please try again.', 'error');
            });
        });
    }

    /**
     * Animate bookmark icon (scale + color)
     */
    function animateBookmarkIcon(btn) {
        const icon = btn.querySelector('.bookmark-icon') || btn;
        icon.classList.add('bookmark-animate');

        icon.addEventListener('animationend', function handler() {
            icon.classList.remove('bookmark-animate');
            icon.removeEventListener('animationend', handler);
        });
    }

    /**
     * Bookmarks listing page
     */
    function initBookmarksPage() {
        const container = document.querySelector('.bookmarks-container');
        if (!container) return;

        const sortSelect = container.querySelector('.bookmarks-sort');
        let currentSort = 'newest';

        loadBookmarks(container, currentSort);

        if (sortSelect) {
            sortSelect.addEventListener('change', function() {
                currentSort = this.value;
                loadBookmarks(container, currentSort);
            });
        }

        container.addEventListener('click', function(e) {
            const removeBtn = e.target.closest('.bookmark-remove');
            if (!removeBtn) return;

            e.preventDefault();
            const mangaId = removeBtn.getAttribute('data-manga-id');
            if (!mangaId) return;

            removeBookmark(mangaId, removeBtn, container, currentSort);
        });
    }

    function loadBookmarks(container, sort) {
        const listEl = container.querySelector('.bookmarks-list');
        if (!listEl) return;

        listEl.innerHTML = '<div class="bookmarks-loading"><span class="spinner"></span> Loading bookmarks...</div>';

        const params = new URLSearchParams({
            action: 'starter_get_bookmarks',
            nonce: nonce,
            sort: sort
        });

        fetch(ajaxUrl + '?' + params.toString(), {
            method: 'GET',
            credentials: 'same-origin'
        })
        .then(function(response) {
            if (!response.ok) throw new Error('Failed to load bookmarks');
            return response.json();
        })
        .then(function(result) {
            if (!result.success || !result.data || result.data.length === 0) {
                listEl.innerHTML = '<div class="bookmarks-empty">No bookmarks yet. Start adding manga to your bookmarks!</div>';
                return;
            }

            let html = '';
            result.data.forEach(function(item) {
                html += '<div class="bookmark-card" data-manga-id="' + escapeAttr(item.id) + '">'
                    + '<a href="' + escapeAttr(item.url) + '" class="bookmark-card-link">'
                    + '<div class="bookmark-thumb">'
                    + (item.thumbnail ? '<img src="' + escapeAttr(item.thumbnail) + '" alt="' + escapeAttr(item.title) + '" loading="lazy">' : '')
                    + '</div>'
                    + '<div class="bookmark-info">'
                    + '<h3 class="bookmark-title">' + escapeHtml(item.title) + '</h3>'
                    + (item.latestChapter ? '<span class="bookmark-chapter">' + escapeHtml(item.latestChapter) + '</span>' : '')
                    + '</div>'
                    + '</a>'
                    + (item.continueUrl
                        ? '<a href="' + escapeAttr(item.continueUrl) + '" class="bookmark-continue" title="Continue reading">Continue Reading</a>'
                        : '')
                    + '<button class="bookmark-remove" data-manga-id="' + escapeAttr(item.id) + '" title="Remove bookmark">&times;</button>'
                    + '</div>';
            });

            listEl.innerHTML = html;
        })
        .catch(function(error) {
            console.error('Load bookmarks error:', error);
            listEl.innerHTML = '<div class="bookmarks-error">Failed to load bookmarks. Please refresh the page.</div>';
        });
    }

    function removeBookmark(mangaId, btn, container, sort) {
        btn.disabled = true;

        const formData = new FormData();
        formData.append('action', 'starter_remove_bookmark');
        formData.append('nonce', nonce);
        formData.append('manga_id', mangaId);

        fetch(ajaxUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(function(response) {
            if (!response.ok) throw new Error('Remove bookmark failed');
            return response.json();
        })
        .then(function(result) {
            if (result.success) {
                const card = btn.closest('.bookmark-card');
                if (card) {
                    card.style.transition = 'opacity 0.3s, transform 0.3s';
                    card.style.opacity = '0';
                    card.style.transform = 'scale(0.95)';
                    setTimeout(function() {
                        card.remove();
                        const list = container.querySelector('.bookmarks-list');
                        if (list && !list.querySelector('.bookmark-card')) {
                            list.innerHTML = '<div class="bookmarks-empty">No bookmarks yet. Start adding manga to your bookmarks!</div>';
                        }
                    }, 300);
                }
                showToast('Bookmark removed', 'success');
            } else {
                btn.disabled = false;
                showToast(result.data || 'Failed to remove bookmark', 'error');
            }
        })
        .catch(function(error) {
            console.error('Remove bookmark error:', error);
            btn.disabled = false;
            showToast('Failed to remove bookmark. Please try again.', 'error');
        });
    }

    /**
     * Utility functions
     */
    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function escapeAttr(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    /**
     * Initialize on DOM ready
     */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {
        initBookmarkToggle();
        initBookmarksPage();
    }
})();
