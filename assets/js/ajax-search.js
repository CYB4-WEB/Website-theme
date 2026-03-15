(function() {
  'use strict';

  const config = window.starterData || {};
  const ajaxUrl = config.ajaxUrl || '/wp-admin/admin-ajax.php';
  const nonce = config.nonce || '';

  const DEBOUNCE_DELAY = 300;
  const HISTORY_KEY = 'starter-search-history';
  const MAX_HISTORY = 10;
  const RESULTS_PER_PAGE = 12;

  let debounceTimer = null;
  let currentPage = 1;
  let currentQuery = {};
  let isLoading = false;
  let selectedSuggestionIndex = -1;

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
   * Search history management via localStorage
   */
  const SearchHistory = {
    get() {
      try {
        const data = localStorage.getItem(HISTORY_KEY);
        return data ? JSON.parse(data) : [];
      } catch (e) {
        return [];
      }
    },

    add(term) {
      if (!term || typeof term !== 'string') return;
      const trimmed = term.trim();
      if (!trimmed) return;

      try {
        let history = this.get();
        history = history.filter(item => item !== trimmed);
        history.unshift(trimmed);
        if (history.length > MAX_HISTORY) {
          history = history.slice(0, MAX_HISTORY);
        }
        localStorage.setItem(HISTORY_KEY, JSON.stringify(history));
      } catch (e) {
        // localStorage unavailable
      }
    },

    clear() {
      try {
        localStorage.removeItem(HISTORY_KEY);
      } catch (e) {
        // localStorage unavailable
      }
    }
  };

  /**
   * Create and manage the suggestions dropdown
   */
  function createSuggestionsDropdown(inputEl) {
    let dropdown = inputEl.parentElement.querySelector('.search-suggestions');
    if (dropdown) return dropdown;

    dropdown = document.createElement('div');
    dropdown.className = 'search-suggestions';
    dropdown.setAttribute('role', 'listbox');
    dropdown.style.display = 'none';
    inputEl.parentElement.style.position = 'relative';
    inputEl.parentElement.appendChild(dropdown);
    return dropdown;
  }

  /**
   * Render suggestion items in the dropdown
   */
  function renderSuggestions(dropdown, results) {
    if (!results || results.length === 0) {
      dropdown.innerHTML = '<div class="suggestion-empty">No results found</div>';
      dropdown.style.display = 'block';
      return;
    }

    dropdown.innerHTML = results.map((item, index) => {
      const thumbnail = item.thumbnail
        ? `<img src="${escapeHtml(item.thumbnail)}" alt="" class="suggestion-thumb" loading="lazy">`
        : '<div class="suggestion-thumb suggestion-thumb--placeholder"></div>';

      const typeBadge = item.type
        ? `<span class="suggestion-badge suggestion-badge--${escapeHtml(item.type)}">${escapeHtml(item.type)}</span>`
        : '';

      const genres = item.genres && item.genres.length
        ? `<span class="suggestion-genres">${item.genres.map(g => escapeHtml(g)).join(', ')}</span>`
        : '';

      return `
        <a href="${escapeHtml(item.url)}" class="suggestion-item" role="option" data-index="${index}">
          ${thumbnail}
          <div class="suggestion-info">
            <span class="suggestion-title">${escapeHtml(item.title)}</span>
            <div class="suggestion-meta">${typeBadge}${genres}</div>
          </div>
        </a>
      `;
    }).join('');

    dropdown.style.display = 'block';
    selectedSuggestionIndex = -1;
  }

  /**
   * Fetch live search suggestions via AJAX
   */
  function fetchSuggestions(query, dropdown) {
    if (!query || query.trim().length < 2) {
      dropdown.style.display = 'none';
      return;
    }

    showDropdownSpinner(dropdown);

    const formData = new FormData();
    formData.append('action', 'starter_live_search');
    formData.append('nonce', nonce);
    formData.append('query', query.trim());

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
        if (data.success && data.data) {
          renderSuggestions(dropdown, data.data.results || []);
        } else {
          renderSuggestions(dropdown, []);
        }
      })
      .catch(error => {
        console.error('Search suggestion error:', error);
        dropdown.innerHTML = '<div class="suggestion-empty">Search failed. Please try again.</div>';
        dropdown.style.display = 'block';
      });
  }

  /**
   * Show loading spinner in dropdown
   */
  function showDropdownSpinner(dropdown) {
    dropdown.innerHTML = '<div class="suggestion-loading"><span class="spinner"></span> Searching...</div>';
    dropdown.style.display = 'block';
  }

  /**
   * Keyboard navigation for suggestions
   */
  function handleSuggestionKeyboard(e, dropdown) {
    const items = dropdown.querySelectorAll('.suggestion-item');
    if (!items.length || dropdown.style.display === 'none') return;

    switch (e.key) {
      case 'ArrowDown':
        e.preventDefault();
        selectedSuggestionIndex = Math.min(selectedSuggestionIndex + 1, items.length - 1);
        updateSuggestionHighlight(items);
        break;

      case 'ArrowUp':
        e.preventDefault();
        selectedSuggestionIndex = Math.max(selectedSuggestionIndex - 1, -1);
        updateSuggestionHighlight(items);
        break;

      case 'Enter':
        if (selectedSuggestionIndex >= 0 && items[selectedSuggestionIndex]) {
          e.preventDefault();
          const href = items[selectedSuggestionIndex].getAttribute('href');
          if (href) {
            SearchHistory.add(e.target.value);
            window.location.href = href;
          }
        }
        break;

      case 'Escape':
        dropdown.style.display = 'none';
        selectedSuggestionIndex = -1;
        break;
    }
  }

  /**
   * Update visual highlight on keyboard-navigated suggestion
   */
  function updateSuggestionHighlight(items) {
    items.forEach((item, i) => {
      item.classList.toggle('suggestion-item--active', i === selectedSuggestionIndex);
    });

    if (selectedSuggestionIndex >= 0 && items[selectedSuggestionIndex]) {
      items[selectedSuggestionIndex].scrollIntoView({ block: 'nearest' });
    }
  }

  /**
   * Initialize live search on input fields
   */
  function initLiveSearch() {
    const searchInputs = document.querySelectorAll('.starter-search-input, #starter-search-field');

    searchInputs.forEach(input => {
      const dropdown = createSuggestionsDropdown(input);
      const debouncedFetch = debounce((query) => fetchSuggestions(query, dropdown), DEBOUNCE_DELAY);

      input.addEventListener('input', (e) => {
        debouncedFetch(e.target.value);
      });

      input.addEventListener('keydown', (e) => {
        handleSuggestionKeyboard(e, dropdown);
      });

      input.addEventListener('focus', (e) => {
        if (e.target.value.trim().length >= 2) {
          debouncedFetch(e.target.value);
        }
      });

      // Click on suggestion navigates to manga page
      dropdown.addEventListener('click', (e) => {
        const item = e.target.closest('.suggestion-item');
        if (item) {
          SearchHistory.add(input.value);
        }
      });

      // Close dropdown on outside click
      document.addEventListener('click', (e) => {
        if (!input.contains(e.target) && !dropdown.contains(e.target)) {
          dropdown.style.display = 'none';
          selectedSuggestionIndex = -1;
        }
      });
    });
  }

  /**
   * Advanced search form handler
   */
  function initAdvancedSearch() {
    const form = document.querySelector('.advanced-search-form');
    if (!form) return;

    const resultsContainer = document.querySelector('.search-results-grid');
    const loadMoreBtn = document.querySelector('.search-load-more');

    if (!resultsContainer) return;

    form.addEventListener('submit', (e) => {
      e.preventDefault();
      currentPage = 1;
      resultsContainer.innerHTML = '';
      performAdvancedSearch(form, resultsContainer, false);
    });

    if (loadMoreBtn) {
      loadMoreBtn.addEventListener('click', () => {
        currentPage++;
        performAdvancedSearch(form, resultsContainer, true);
      });
    }
  }

  /**
   * Perform advanced search via AJAX
   */
  function performAdvancedSearch(form, container, append) {
    if (isLoading) return;
    isLoading = true;

    const loadMoreBtn = document.querySelector('.search-load-more');
    showLoadingState(container, append);

    const formData = new FormData(form);
    formData.append('action', 'starter_advanced_search');
    formData.append('nonce', nonce);
    formData.append('page', currentPage);
    formData.append('per_page', RESULTS_PER_PAGE);

    // Collect selected genres
    const genreCheckboxes = form.querySelectorAll('input[name="genres[]"]:checked');
    genreCheckboxes.forEach(cb => {
      formData.append('genres[]', cb.value);
    });

    // Store current query for reference
    currentQuery = {
      keyword: formData.get('keyword') || '',
      type: formData.get('type') || '',
      status: formData.get('status') || '',
      sort: formData.get('sort') || 'latest'
    };

    // Save search term to history
    if (currentQuery.keyword) {
      SearchHistory.add(currentQuery.keyword);
    }

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
        isLoading = false;
        removeLoadingState(container);

        if (data.success && data.data) {
          const results = data.data.results || [];
          const totalPages = data.data.totalPages || 1;

          if (results.length === 0 && !append) {
            container.innerHTML = renderNoResults();
          } else {
            const html = results.map(renderResultCard).join('');
            if (append) {
              container.insertAdjacentHTML('beforeend', html);
            } else {
              container.innerHTML = html;
            }
          }

          // Toggle load more button
          if (loadMoreBtn) {
            loadMoreBtn.style.display = currentPage < totalPages ? 'block' : 'none';
          }
        } else {
          if (!append) {
            container.innerHTML = renderNoResults();
          }
        }
      })
      .catch(error => {
        isLoading = false;
        removeLoadingState(container);
        console.error('Advanced search error:', error);
        if (!append) {
          container.innerHTML = '<div class="search-error">Search failed. Please try again.</div>';
        }
      });
  }

  /**
   * Render a single result card
   */
  function renderResultCard(item) {
    const thumbnail = item.thumbnail
      ? `<img src="${escapeHtml(item.thumbnail)}" alt="${escapeHtml(item.title)}" class="result-card__thumb" loading="lazy">`
      : '<div class="result-card__thumb result-card__thumb--placeholder"></div>';

    const typeBadge = item.type
      ? `<span class="result-card__badge result-card__badge--${escapeHtml(item.type)}">${escapeHtml(item.type)}</span>`
      : '';

    const genres = item.genres && item.genres.length
      ? `<div class="result-card__genres">${item.genres.map(g => `<span class="genre-tag">${escapeHtml(g)}</span>`).join('')}</div>`
      : '';

    return `
      <div class="result-card">
        <a href="${escapeHtml(item.url)}" class="result-card__link">
          <div class="result-card__image">
            ${thumbnail}
            ${typeBadge}
          </div>
          <div class="result-card__body">
            <h3 class="result-card__title">${escapeHtml(item.title)}</h3>
            ${genres}
          </div>
        </a>
      </div>
    `;
  }

  /**
   * Render no results message
   */
  function renderNoResults() {
    return `
      <div class="search-no-results">
        <div class="search-no-results__icon">&#128269;</div>
        <p>No results found. Try different keywords or filters.</p>
      </div>
    `;
  }

  /**
   * Show loading spinner in results area
   */
  function showLoadingState(container, append) {
    const spinner = '<div class="search-loading"><span class="spinner"></span> Loading results...</div>';
    if (append) {
      container.insertAdjacentHTML('beforeend', spinner);
    } else {
      container.innerHTML = spinner;
    }
  }

  /**
   * Remove loading spinner
   */
  function removeLoadingState(container) {
    const loader = container.querySelector('.search-loading');
    if (loader) loader.remove();
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
   * Initialize all search functionality on DOMContentLoaded
   */
  function init() {
    initLiveSearch();
    initAdvancedSearch();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
