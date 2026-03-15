(function() {
    'use strict';

    const data = window.starterData || {};
    const ajaxUrl = data.ajaxUrl || '';
    const nonce = data.nonce || '';

    const DEBOUNCE_DELAY = 300;
    const MAX_HISTORY = 10;
    const HISTORY_KEY = 'starter-search-history';

    let debounceTimer = null;
    let activeIndex = -1;
    let suggestions = [];

    /**
     * Debounce utility
     */
    function debounce(fn, delay) {
        return function(...args) {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => fn.apply(this, args), delay);
        };
    }

    /**
     * Search history management
     */
    function getSearchHistory() {
        try {
            const stored = localStorage.getItem(HISTORY_KEY);
            return stored ? JSON.parse(stored) : [];
        } catch (e) {
            return [];
        }
    }

    function saveSearchHistory(term) {
        if (!term || !term.trim()) return;
        try {
            let history = getSearchHistory();
            history = history.filter(item => item !== term);
            history.unshift(term);
            if (history.length > MAX_HISTORY) {
                history = history.slice(0, MAX_HISTORY);
            }
            localStorage.setItem(HISTORY_KEY, JSON.stringify(history));
        } catch (e) {
            // localStorage unavailable
        }
    }

    /**
     * Live search suggestions
     */
    function initLiveSearch() {
        const searchInput = document.querySelector('.search-input');
        const suggestionsContainer = document.querySelector('.search-suggestions');

        if (!searchInput || !suggestionsContainer) return;

        const handleInput = debounce(function() {
            const query = searchInput.value.trim();

            if (query.length < 2) {
                hideSuggestions(suggestionsContainer);
                return;
            }

            fetchSuggestions(query, suggestionsContainer);
        }, DEBOUNCE_DELAY);

        searchInput.addEventListener('input', handleInput);

        searchInput.addEventListener('keydown', function(e) {
            handleKeyboardNavigation(e, suggestionsContainer, searchInput);
        });

        searchInput.addEventListener('focus', function() {
            const query = searchInput.value.trim();
            if (query.length >= 2 && suggestions.length > 0) {
                suggestionsContainer.classList.add('active');
            }
        });

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.search-wrapper')) {
                hideSuggestions(suggestionsContainer);
            }
        });
    }

    function fetchSuggestions(query, container) {
        showLoading(container);

        const params = new URLSearchParams({
            action: 'starter_live_search',
            nonce: nonce,
            query: query
        });

        fetch(ajaxUrl + '?' + params.toString(), {
            method: 'GET',
            credentials: 'same-origin'
        })
        .then(function(response) {
            if (!response.ok) throw new Error('Search request failed');
            return response.json();
        })
        .then(function(result) {
            if (result.success && result.data && result.data.length > 0) {
                suggestions = result.data;
                renderSuggestions(result.data, container);
                saveSearchHistory(query);
            } else {
                suggestions = [];
                showNoResults(container);
            }
        })
        .catch(function(error) {
            console.error('Search error:', error);
            suggestions = [];
            showError(container);
        });
    }

    function renderSuggestions(items, container) {
        activeIndex = -1;
        let html = '<ul class="suggestions-list">';

        items.forEach(function(item, index) {
            const genres = item.genres
                ? item.genres.map(function(g) { return '<span class="genre-tag">' + escapeHtml(g) + '</span>'; }).join('')
                : '';
            const thumbnail = item.thumbnail
                ? '<img src="' + escapeHtml(item.thumbnail) + '" alt="' + escapeHtml(item.title) + '" class="suggestion-thumb" loading="lazy">'
                : '<div class="suggestion-thumb placeholder"></div>';

            html += '<li class="suggestion-item" data-index="' + index + '" data-url="' + escapeHtml(item.url) + '">'
                + '<div class="suggestion-thumb-wrap">' + thumbnail + '</div>'
                + '<div class="suggestion-info">'
                + '<span class="suggestion-title">' + escapeHtml(item.title) + '</span>'
                + (item.type ? '<span class="type-badge type-' + escapeHtml(item.type) + '">' + escapeHtml(item.type) + '</span>' : '')
                + (genres ? '<div class="suggestion-genres">' + genres + '</div>' : '')
                + '</div>'
                + '</li>';
        });

        html += '</ul>';
        container.innerHTML = html;
        container.classList.add('active');

        container.querySelectorAll('.suggestion-item').forEach(function(item) {
            item.addEventListener('click', function() {
                const url = this.getAttribute('data-url');
                if (url) {
                    window.location.href = url;
                }
            });
        });
    }

    function handleKeyboardNavigation(e, container, input) {
        const items = container.querySelectorAll('.suggestion-item');
        if (!items.length) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIndex = Math.min(activeIndex + 1, items.length - 1);
            updateActiveItem(items);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIndex = Math.max(activeIndex - 1, -1);
            updateActiveItem(items);
        } else if (e.key === 'Enter' && activeIndex >= 0) {
            e.preventDefault();
            const url = items[activeIndex].getAttribute('data-url');
            if (url) {
                window.location.href = url;
            }
        } else if (e.key === 'Escape') {
            hideSuggestions(container);
            input.blur();
        }
    }

    function updateActiveItem(items) {
        items.forEach(function(item, i) {
            item.classList.toggle('active', i === activeIndex);
        });

        if (activeIndex >= 0 && items[activeIndex]) {
            items[activeIndex].scrollIntoView({ block: 'nearest' });
        }
    }

    function showLoading(container) {
        container.innerHTML = '<div class="search-loading"><span class="spinner"></span> Searching...</div>';
        container.classList.add('active');
    }

    function showNoResults(container) {
        container.innerHTML = '<div class="search-no-results">No results found</div>';
        container.classList.add('active');
    }

    function showError(container) {
        container.innerHTML = '<div class="search-error">Search failed. Please try again.</div>';
        container.classList.add('active');
    }

    function hideSuggestions(container) {
        container.classList.remove('active');
        activeIndex = -1;
    }

    /**
     * Advanced search form
     */
    function initAdvancedSearch() {
        const form = document.querySelector('.advanced-search-form');
        if (!form) return;

        let currentPage = 1;
        let isLoading = false;
        const resultsContainer = document.querySelector('.search-results-grid');
        const loadMoreBtn = document.querySelector('.search-load-more');

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            currentPage = 1;
            if (resultsContainer) {
                resultsContainer.innerHTML = '';
            }
            performAdvancedSearch(form, resultsContainer, currentPage);
        });

        if (loadMoreBtn) {
            loadMoreBtn.addEventListener('click', function() {
                if (isLoading) return;
                currentPage++;
                performAdvancedSearch(form, resultsContainer, currentPage, true);
            });
        }

        function performAdvancedSearch(searchForm, container, page, append) {
            if (!container || isLoading) return;
            isLoading = true;

            if (!append) {
                container.innerHTML = '<div class="search-loading"><span class="spinner"></span> Searching...</div>';
            }
            if (loadMoreBtn) {
                loadMoreBtn.disabled = true;
            }

            const formData = new FormData(searchForm);
            formData.append('action', 'starter_advanced_search');
            formData.append('nonce', nonce);
            formData.append('page', page);

            fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(function(response) {
                if (!response.ok) throw new Error('Search request failed');
                return response.json();
            })
            .then(function(result) {
                isLoading = false;

                if (!result.success) {
                    if (!append) {
                        container.innerHTML = '<div class="search-no-results">No results found</div>';
                    }
                    if (loadMoreBtn) {
                        loadMoreBtn.style.display = 'none';
                    }
                    return;
                }

                const resultsHtml = result.data.html || '';

                if (append) {
                    container.insertAdjacentHTML('beforeend', resultsHtml);
                } else {
                    container.innerHTML = resultsHtml;
                }

                if (loadMoreBtn) {
                    loadMoreBtn.style.display = result.data.hasMore ? '' : 'none';
                    loadMoreBtn.disabled = false;
                }
            })
            .catch(function(error) {
                console.error('Advanced search error:', error);
                isLoading = false;
                if (!append) {
                    container.innerHTML = '<div class="search-error">Search failed. Please try again.</div>';
                }
                if (loadMoreBtn) {
                    loadMoreBtn.disabled = false;
                }
            });
        }
    }

    /**
     * HTML escape utility
     */
    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
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
        initLiveSearch();
        initAdvancedSearch();
    }
})();
