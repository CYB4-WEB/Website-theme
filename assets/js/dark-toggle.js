(function() {
    'use strict';

    const STORAGE_KEY = 'starter-theme-mode';
    const MODES = ['light', 'dark', 'sepia'];
    const MODE_ICONS = {
        light: '\u2600',  // sun
        dark: '\u263E',    // moon
        sepia: '\u2615'    // coffee
    };
    const MODE_LABELS = {
        light: 'Light Mode',
        dark: 'Dark Mode',
        sepia: 'Sepia Mode'
    };

    let currentMode = 'light';
    let isAutoMode = false;
    let systemDarkQuery = null;

    /**
     * Get stored theme mode
     */
    function getStoredMode() {
        try {
            return localStorage.getItem(STORAGE_KEY);
        } catch (e) {
            return null;
        }
    }

    /**
     * Save theme mode
     */
    function saveMode(mode) {
        try {
            localStorage.setItem(STORAGE_KEY, mode);
        } catch (e) {
            // localStorage unavailable
        }
    }

    /**
     * Detect system color scheme preference
     */
    function getSystemPreference() {
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            return 'dark';
        }
        return 'light';
    }

    /**
     * Apply theme mode to document
     */
    function applyMode(mode) {
        currentMode = mode;

        // Remove all mode classes
        MODES.forEach(function(m) {
            document.documentElement.classList.remove('theme-' + m);
        });

        // Add current mode class
        document.documentElement.classList.add('theme-' + mode);
        document.documentElement.setAttribute('data-theme', mode);

        // Update all toggle buttons
        updateToggleButtons(mode);

        // Dispatch custom event for other components
        const event = new CustomEvent('starterThemeChange', {
            detail: { mode: mode, isAuto: isAutoMode }
        });
        document.dispatchEvent(event);
    }

    /**
     * Update all toggle button icons and labels
     */
    function updateToggleButtons(mode) {
        const buttons = document.querySelectorAll('.theme-toggle-btn');
        buttons.forEach(function(btn) {
            const iconEl = btn.querySelector('.theme-toggle-icon');
            const labelEl = btn.querySelector('.theme-toggle-label');

            if (iconEl) iconEl.textContent = MODE_ICONS[mode] || MODE_ICONS.light;
            if (labelEl) labelEl.textContent = MODE_LABELS[mode] || MODE_LABELS.light;

            btn.setAttribute('aria-label', 'Current: ' + (MODE_LABELS[mode] || 'Light Mode') + '. Click to switch.');
            btn.setAttribute('data-mode', mode);
        });
    }

    /**
     * Cycle to next theme mode
     */
    function cycleMode() {
        isAutoMode = false;
        const currentIndex = MODES.indexOf(currentMode);
        const nextIndex = (currentIndex + 1) % MODES.length;
        const nextMode = MODES[nextIndex];

        saveMode(nextMode);
        applyMode(nextMode);
    }

    /**
     * Set a specific mode
     */
    function setMode(mode) {
        if (!MODES.includes(mode)) return;
        isAutoMode = false;
        saveMode(mode);
        applyMode(mode);
    }

    /**
     * Enable auto mode (follow system preference)
     */
    function enableAutoMode() {
        isAutoMode = true;

        try {
            localStorage.removeItem(STORAGE_KEY);
        } catch (e) {
            // Ignore
        }

        const systemMode = getSystemPreference();
        applyMode(systemMode);
    }

    /**
     * Initialize system preference listener
     */
    function initSystemPreferenceListener() {
        if (!window.matchMedia) return;

        systemDarkQuery = window.matchMedia('(prefers-color-scheme: dark)');

        const handler = function(e) {
            if (isAutoMode) {
                applyMode(e.matches ? 'dark' : 'light');
            }
        };

        if (systemDarkQuery.addEventListener) {
            systemDarkQuery.addEventListener('change', handler);
        } else if (systemDarkQuery.addListener) {
            systemDarkQuery.addListener(handler);
        }
    }

    /**
     * Bind toggle button events
     */
    function initToggleButtons() {
        // Main toggle buttons
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.theme-toggle-btn');
            if (!btn) return;

            e.preventDefault();
            cycleMode();
        });

        // Reader-specific toggle (separate button in reader toolbar)
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.reader-theme-toggle');
            if (!btn) return;

            e.preventDefault();
            cycleMode();
        });
    }

    /**
     * Determine and apply initial mode
     */
    function initMode() {
        const stored = getStoredMode();

        if (stored && MODES.includes(stored)) {
            isAutoMode = false;
            applyMode(stored);
        } else {
            // No stored preference: follow system (auto mode)
            isAutoMode = true;
            const systemMode = getSystemPreference();
            applyMode(systemMode);
        }
    }

    /**
     * Initialize
     */
    function init() {
        initMode();
        initToggleButtons();
        initSystemPreferenceListener();
    }

    // Expose API for other components
    window.starterTheme = {
        setMode: setMode,
        cycleMode: cycleMode,
        getMode: function() { return currentMode; },
        enableAutoMode: enableAutoMode
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
