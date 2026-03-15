/**
 * Project Alpha - Main Theme JavaScript
 *
 * Core functionality: navigation, theme toggle, toast notifications,
 * modals, lazy loading, AJAX helper, and utility functions.
 *
 * @package suspended starter starter
 */

(function () {
  'use strict';

  // ---------------------------------------------------------------------------
  // Apply saved theme BEFORE paint to prevent flash
  // (This block runs synchronously on script parse)
  // ---------------------------------------------------------------------------
  const savedTheme = localStorage.getItem('starter_theme_mode');
  if (savedTheme) {
    document.documentElement.setAttribute('data-theme', savedTheme);
  }

  // ---------------------------------------------------------------------------
  // Utility helpers
  // ---------------------------------------------------------------------------

  /**
   * Debounce – delays fn execution until after `wait` ms of inactivity.
   *
   * @param {Function} fn   - Function to debounce.
   * @param {number}   wait - Milliseconds to wait.
   * @returns {Function}
   */
  const debounce = (fn, wait = 200) => {
    let timer;
    return (...args) => {
      clearTimeout(timer);
      timer = setTimeout(() => fn.apply(null, args), wait);
    };
  };

  /**
   * Throttle – ensures fn runs at most once every `limit` ms.
   *
   * @param {Function} fn    - Function to throttle.
   * @param {number}   limit - Milliseconds between calls.
   * @returns {Function}
   */
  const throttle = (fn, limit = 200) => {
    let waiting = false;
    return (...args) => {
      if (waiting) return;
      fn.apply(null, args);
      waiting = true;
      setTimeout(() => { waiting = false; }, limit);
    };
  };

  // Expose utilities globally
  window.starterDebounce = debounce;
  window.starterThrottle = throttle;

  // ---------------------------------------------------------------------------
  // AJAX Helper
  // ---------------------------------------------------------------------------

  /**
   * Send an AJAX request to the WordPress admin-ajax endpoint.
   *
   * @param {string} action - WordPress AJAX action name.
   * @param {Object} data   - Key/value pairs to send.
   * @returns {Promise<Object>} Parsed JSON response.
   */
  const starterAjax = (action, data = {}) => {
    const sd = window.starterData || {};
    const url = sd.ajaxUrl || '/wp-admin/admin-ajax.php';
    const nonce = sd.nonce || '';

    const formData = new FormData();
    formData.append('action', action);
    formData.append('nonce', nonce);

    Object.keys(data).forEach((key) => {
      formData.append(key, data[key]);
    });

    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      body: formData,
    })
      .then((res) => {
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
      })
      .then((json) => {
        if (json.success === false) {
          throw new Error(json.data || 'Request failed');
        }
        return json;
      });
  };

  window.starterAjax = starterAjax;

  // ---------------------------------------------------------------------------
  // Toast Notification System
  // ---------------------------------------------------------------------------

  const starterToast = {
    _container: null,

    /**
     * Get or create the toast container element.
     * @returns {HTMLElement}
     */
    _getContainer() {
      if (!this._container) {
        this._container = document.createElement('div');
        this._container.className = 'starter-toast-container';
        document.body.appendChild(this._container);
      }
      return this._container;
    },

    /**
     * Show a toast notification.
     *
     * @param {string} message  - Text to display.
     * @param {string} type     - One of 'success', 'error', 'warning', 'info'.
     * @param {number} duration - Auto-dismiss after ms (default 3000).
     */
    show(message, type = 'info', duration = 3000) {
      const container = this._getContainer();
      const toast = document.createElement('div');
      toast.className = `starter-toast starter-toast--${type}`;
      toast.textContent = message;

      // Close button
      const close = document.createElement('button');
      close.className = 'starter-toast__close';
      close.innerHTML = '&times;';
      close.setAttribute('aria-label', 'Close notification');
      close.addEventListener('click', () => this._dismiss(toast));
      toast.appendChild(close);

      container.appendChild(toast);

      // Trigger entrance animation
      requestAnimationFrame(() => {
        toast.classList.add('starter-toast--visible');
      });

      // Auto dismiss
      if (duration > 0) {
        setTimeout(() => this._dismiss(toast), duration);
      }
    },

    /**
     * Dismiss a single toast element.
     * @param {HTMLElement} el
     */
    _dismiss(el) {
      if (!el || !el.parentNode) return;
      el.classList.remove('starter-toast--visible');
      el.addEventListener('transitionend', () => el.remove(), { once: true });
      // Fallback removal if transition never fires
      setTimeout(() => { if (el.parentNode) el.remove(); }, 500);
    },
  };

  window.starterToast = starterToast;

  // ---------------------------------------------------------------------------
  // Modal System
  // ---------------------------------------------------------------------------

  const starterModal = {
    _overlay: null,
    _body: null,

    /**
     * Build the modal overlay + wrapper (once).
     */
    _build() {
      if (this._overlay) return;

      this._overlay = document.createElement('div');
      this._overlay.className = 'starter-modal-overlay';
      this._overlay.setAttribute('role', 'dialog');
      this._overlay.setAttribute('aria-modal', 'true');

      const wrapper = document.createElement('div');
      wrapper.className = 'starter-modal';

      const closeBtn = document.createElement('button');
      closeBtn.className = 'starter-modal__close';
      closeBtn.innerHTML = '&times;';
      closeBtn.setAttribute('aria-label', 'Close modal');
      closeBtn.addEventListener('click', () => this.close());

      this._body = document.createElement('div');
      this._body.className = 'starter-modal__body';

      wrapper.appendChild(closeBtn);
      wrapper.appendChild(this._body);
      this._overlay.appendChild(wrapper);
      document.body.appendChild(this._overlay);

      // Close on overlay click
      this._overlay.addEventListener('click', (e) => {
        if (e.target === this._overlay) this.close();
      });

      // Close on Escape key
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') this.close();
      });
    },

    /**
     * Open modal with the given content.
     *
     * @param {string|HTMLElement} content - HTML string or DOM node.
     */
    open(content) {
      this._build();
      if (typeof content === 'string') {
        this._body.innerHTML = content;
      } else {
        this._body.innerHTML = '';
        this._body.appendChild(content);
      }
      this._overlay.classList.add('starter-modal-overlay--visible');
      document.body.classList.add('starter-modal-open');
    },

    /**
     * Close the modal.
     */
    close() {
      if (!this._overlay) return;
      this._overlay.classList.remove('starter-modal-overlay--visible');
      document.body.classList.remove('starter-modal-open');
    },
  };

  window.starterModal = starterModal;

  // ---------------------------------------------------------------------------
  // DOM Ready
  // ---------------------------------------------------------------------------

  document.addEventListener('DOMContentLoaded', () => {
    initMobileMenu();
    initThemeToggle();
    initSmoothScroll();
    initBackToTop();
    initDropdowns();
    initLazyLoad();
  });

  // ---------------------------------------------------------------------------
  // Mobile Hamburger Menu
  // ---------------------------------------------------------------------------

  function initMobileMenu() {
    const toggle = document.querySelector('.mobile-menu-toggle');
    const nav = document.querySelector('.main-navigation');
    if (!toggle || !nav) return;

    toggle.addEventListener('click', () => {
      const expanded = toggle.getAttribute('aria-expanded') === 'true';
      toggle.setAttribute('aria-expanded', String(!expanded));
      nav.classList.toggle('main-navigation--open');
      toggle.classList.toggle('mobile-menu-toggle--active');
      document.body.classList.toggle('menu-open');
    });

    // Close menu on link click (mobile)
    nav.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', () => {
        nav.classList.remove('main-navigation--open');
        toggle.classList.remove('mobile-menu-toggle--active');
        toggle.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('menu-open');
      });
    });
  }

  // ---------------------------------------------------------------------------
  // Dark / Light / Sepia Mode Toggle
  // ---------------------------------------------------------------------------

  function initThemeToggle() {
    const buttons = document.querySelectorAll('[data-theme-toggle]');
    if (!buttons.length) return;

    buttons.forEach((btn) => {
      btn.addEventListener('click', () => {
        const modes = ['light', 'dark', 'sepia'];
        const current = document.documentElement.getAttribute('data-theme') || 'light';
        const idx = modes.indexOf(current);
        const next = modes[(idx + 1) % modes.length];

        document.documentElement.setAttribute('data-theme', next);
        localStorage.setItem('starter_theme_mode', next);

        // Update all toggle button labels
        document.querySelectorAll('[data-theme-toggle]').forEach((b) => {
          b.setAttribute('aria-label', `Current theme: ${next}`);
        });
      });
    });
  }

  // ---------------------------------------------------------------------------
  // Smooth Scroll for Anchor Links
  // ---------------------------------------------------------------------------

  function initSmoothScroll() {
    document.querySelectorAll('a[href^="#"]').forEach((link) => {
      link.addEventListener('click', (e) => {
        const targetId = link.getAttribute('href');
        if (targetId === '#') return;

        const target = document.querySelector(targetId);
        if (!target) return;

        e.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        // Update URL hash without jump
        history.pushState(null, null, targetId);
      });
    });
  }

  // ---------------------------------------------------------------------------
  // Back-to-Top Button
  // ---------------------------------------------------------------------------

  function initBackToTop() {
    const btn = document.querySelector('.back-to-top');
    if (!btn) return;

    const toggleVisibility = throttle(() => {
      if (window.scrollY > 400) {
        btn.classList.add('back-to-top--visible');
      } else {
        btn.classList.remove('back-to-top--visible');
      }
    }, 150);

    window.addEventListener('scroll', toggleVisibility, { passive: true });

    btn.addEventListener('click', () => {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  }

  // ---------------------------------------------------------------------------
  // Dropdown Menus (click-based)
  // ---------------------------------------------------------------------------

  function initDropdowns() {
    const triggers = document.querySelectorAll('.dropdown-toggle');

    triggers.forEach((trigger) => {
      const menu = trigger.nextElementSibling;
      if (!menu) return;

      trigger.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();

        // Close other open dropdowns
        triggers.forEach((t) => {
          if (t !== trigger) {
            t.classList.remove('dropdown-toggle--open');
            const m = t.nextElementSibling;
            if (m) m.classList.remove('dropdown-menu--open');
          }
        });

        trigger.classList.toggle('dropdown-toggle--open');
        menu.classList.toggle('dropdown-menu--open');
      });
    });

    // Close all dropdowns when clicking outside
    document.addEventListener('click', () => {
      triggers.forEach((t) => {
        t.classList.remove('dropdown-toggle--open');
        const m = t.nextElementSibling;
        if (m) m.classList.remove('dropdown-menu--open');
      });
    });
  }

  // ---------------------------------------------------------------------------
  // Lazy Load Images (IntersectionObserver)
  // ---------------------------------------------------------------------------

  function initLazyLoad() {
    const images = document.querySelectorAll('img[data-src]');
    if (!images.length) return;

    if ('IntersectionObserver' in window) {
      const observer = new IntersectionObserver(
        (entries) => {
          entries.forEach((entry) => {
            if (entry.isIntersecting) {
              const img = entry.target;
              img.src = img.dataset.src;
              if (img.dataset.srcset) img.srcset = img.dataset.srcset;
              img.removeAttribute('data-src');
              img.removeAttribute('data-srcset');
              img.classList.add('lazy-loaded');
              observer.unobserve(img);
            }
          });
        },
        { rootMargin: '200px 0px' }
      );

      images.forEach((img) => observer.observe(img));
    } else {
      // Fallback: load all images immediately
      images.forEach((img) => {
        img.src = img.dataset.src;
        if (img.dataset.srcset) img.srcset = img.dataset.srcset;
      });
    }
  }
})();
