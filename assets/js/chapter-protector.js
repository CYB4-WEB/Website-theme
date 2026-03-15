(function() {
    'use strict';

    const data = window.starterData || {};
    const ajaxUrl = data.ajaxUrl || '';
    const nonce = data.nonce || '';
    const config = data.chapterProtector || {};

    const MAX_RETRIES = 3;
    const BASE_RETRY_DELAY = 1000;
    const TOKEN_REFRESH_BUFFER = 60000; // Refresh 60s before expiry

    let sessionKey = null;
    let currentToken = config.token || '';
    let tokenExpiry = 0;
    let imageDataCache = [];
    let observer = null;

    /**
     * Initialize chapter protector
     */
    function init() {
        if (!config.chapterId) return;

        if (config.blockRightClick) {
            disableRightClick();
        }
        disableKeyboardSave();
        disableImageDrag();

        fetchChapterData();
    }

    /**
     * Fetch encrypted image data from server
     */
    function fetchChapterData() {
        const formData = new FormData();
        formData.append('action', 'starter_get_chapter_images');
        formData.append('nonce', nonce);
        formData.append('chapter_id', config.chapterId);
        formData.append('token', currentToken);

        fetch(ajaxUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(function(response) {
            if (!response.ok) throw new Error('Failed to fetch chapter data');
            return response.json();
        })
        .then(function(result) {
            if (!result.success) {
                throw new Error(result.data || 'Chapter data request failed');
            }

            sessionKey = result.data.sessionKey || '';
            imageDataCache = result.data.images || [];
            tokenExpiry = result.data.tokenExpiry || 0;

            if (tokenExpiry > 0) {
                scheduleTokenRefresh();
            }

            setupCanvasRendering();
        })
        .catch(function(error) {
            console.error('Chapter protector error:', error);
            showChapterError('Failed to load chapter. Please refresh the page.');
        });
    }

    /**
     * XOR-based decryption of image URLs
     */
    function decrypt(encryptedStr, key) {
        if (!encryptedStr || !key) return '';

        try {
            const decoded = atob(encryptedStr);
            let result = '';
            for (let i = 0; i < decoded.length; i++) {
                result += String.fromCharCode(decoded.charCodeAt(i) ^ key.charCodeAt(i % key.length));
            }
            return result;
        } catch (e) {
            console.error('Decryption failed:', e);
            return '';
        }
    }

    /**
     * Set up canvas rendering with IntersectionObserver for lazy loading
     */
    function setupCanvasRendering() {
        const container = document.querySelector('.reader-container');
        if (!container) return;

        // Clear loading state
        container.innerHTML = '';

        imageDataCache.forEach(function(imageData, index) {
            const wrapper = document.createElement('div');
            wrapper.className = 'protected-image-wrapper';
            wrapper.setAttribute('data-index', index);

            const canvas = document.createElement('canvas');
            canvas.className = 'protected-canvas';
            canvas.setAttribute('data-index', index);

            const placeholder = document.createElement('div');
            placeholder.className = 'canvas-placeholder';
            placeholder.innerHTML = '<div class="canvas-spinner"></div>';

            // Set approximate dimensions if available
            if (imageData.width && imageData.height) {
                const aspectRatio = imageData.height / imageData.width;
                wrapper.style.paddingBottom = (aspectRatio * 100) + '%';
            } else {
                wrapper.style.minHeight = '400px';
            }

            wrapper.appendChild(placeholder);
            wrapper.appendChild(canvas);
            container.appendChild(wrapper);
        });

        // IntersectionObserver for lazy decryption and rendering
        observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    const wrapper = entry.target;
                    const index = parseInt(wrapper.getAttribute('data-index'), 10);

                    if (!wrapper.classList.contains('loaded') && !wrapper.classList.contains('loading')) {
                        wrapper.classList.add('loading');
                        decryptAndRender(index, wrapper);
                    }
                }
            });
        }, {
            rootMargin: '200px 0px',
            threshold: 0.01
        });

        container.querySelectorAll('.protected-image-wrapper').forEach(function(wrapper) {
            observer.observe(wrapper);
        });
    }

    /**
     * Decrypt image URL and render to canvas
     */
    function decryptAndRender(index, wrapper) {
        const imageData = imageDataCache[index];
        if (!imageData) return;

        const imageUrl = imageData.encrypted
            ? decrypt(imageData.url, sessionKey)
            : imageData.url;

        if (!imageUrl) {
            showImageError(wrapper, 'Failed to decrypt image');
            return;
        }

        fetchImageWithRetry(imageUrl, MAX_RETRIES)
            .then(function(blob) {
                return renderToCanvas(blob, wrapper, index);
            })
            .then(function() {
                wrapper.classList.remove('loading');
                wrapper.classList.add('loaded');

                const placeholder = wrapper.querySelector('.canvas-placeholder');
                if (placeholder) placeholder.remove();
            })
            .catch(function(error) {
                console.error('Image render failed for index ' + index + ':', error);
                showImageError(wrapper, 'Failed to load image');
            });
    }

    /**
     * Fetch image as blob with retry and exponential backoff
     */
    function fetchImageWithRetry(url, retriesLeft) {
        return fetch(url, { credentials: 'same-origin' })
            .then(function(response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.blob();
            })
            .catch(function(error) {
                if (retriesLeft <= 1) {
                    return Promise.reject(error);
                }

                const delay = BASE_RETRY_DELAY * Math.pow(2, MAX_RETRIES - retriesLeft);
                return new Promise(function(resolve) {
                    setTimeout(resolve, delay);
                }).then(function() {
                    return fetchImageWithRetry(url, retriesLeft - 1);
                });
            });
    }

    /**
     * Render image blob to canvas with retina support
     */
    function renderToCanvas(blob, wrapper, index) {
        return new Promise(function(resolve, reject) {
            const blobUrl = URL.createObjectURL(blob);
            const img = new Image();

            img.onload = function() {
                const canvas = wrapper.querySelector('.protected-canvas');
                if (!canvas) {
                    URL.revokeObjectURL(blobUrl);
                    reject(new Error('Canvas not found'));
                    return;
                }

                const dpr = window.devicePixelRatio || 1;
                const displayWidth = wrapper.clientWidth || img.naturalWidth;
                const displayHeight = Math.round(displayWidth * (img.naturalHeight / img.naturalWidth));

                canvas.width = displayWidth * dpr;
                canvas.height = displayHeight * dpr;
                canvas.style.width = displayWidth + 'px';
                canvas.style.height = displayHeight + 'px';

                // Reset wrapper padding now that we have real dimensions
                wrapper.style.paddingBottom = '0';
                wrapper.style.minHeight = '0';
                wrapper.style.height = 'auto';

                const ctx = canvas.getContext('2d');
                ctx.scale(dpr, dpr);

                requestAnimationFrame(function() {
                    ctx.drawImage(img, 0, 0, displayWidth, displayHeight);
                    URL.revokeObjectURL(blobUrl);
                    resolve();
                });
            };

            img.onerror = function() {
                URL.revokeObjectURL(blobUrl);
                reject(new Error('Image load failed'));
            };

            img.src = blobUrl;
        });
    }

    /**
     * Token refresh management
     */
    function scheduleTokenRefresh() {
        const now = Date.now();
        const refreshAt = tokenExpiry - TOKEN_REFRESH_BUFFER;

        if (refreshAt <= now) {
            refreshToken();
            return;
        }

        setTimeout(function() {
            refreshToken();
        }, refreshAt - now);
    }

    function refreshToken() {
        const formData = new FormData();
        formData.append('action', 'starter_refresh_chapter_token');
        formData.append('nonce', nonce);
        formData.append('chapter_id', config.chapterId);
        formData.append('token', currentToken);

        fetch(ajaxUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(function(response) {
            if (!response.ok) throw new Error('Token refresh failed');
            return response.json();
        })
        .then(function(result) {
            if (result.success && result.data.token) {
                currentToken = result.data.token;
                tokenExpiry = result.data.tokenExpiry || 0;

                if (tokenExpiry > 0) {
                    scheduleTokenRefresh();
                }
            } else {
                console.warn('Token refresh failed, will retry in 30s');
                setTimeout(refreshToken, 30000);
            }
        })
        .catch(function(error) {
            console.error('Token refresh error:', error);
            setTimeout(refreshToken, 30000);
        });
    }

    /**
     * Protection: disable right-click
     */
    function disableRightClick() {
        const container = document.querySelector('.reader-container');
        if (!container) return;

        container.addEventListener('contextmenu', function(e) {
            e.preventDefault();
            return false;
        });
    }

    /**
     * Protection: disable save keyboard shortcuts
     */
    function disableKeyboardSave() {
        document.addEventListener('keydown', function(e) {
            // Ctrl+S or Ctrl+Shift+S
            if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
                e.preventDefault();
                return false;
            }
        });
    }

    /**
     * Protection: disable image drag
     */
    function disableImageDrag() {
        document.addEventListener('dragstart', function(e) {
            if (e.target.closest('.reader-container')) {
                e.preventDefault();
                return false;
            }
        });
    }

    /**
     * Show error state for chapter
     */
    function showChapterError(message) {
        const container = document.querySelector('.reader-container');
        if (!container) return;

        container.innerHTML = '<div class="chapter-error">'
            + '<p>' + escapeHtml(message) + '</p>'
            + '<button class="retry-btn" onclick="location.reload()">Retry</button>'
            + '</div>';
    }

    /**
     * Show error state for individual image
     */
    function showImageError(wrapper, message) {
        wrapper.classList.remove('loading');
        wrapper.classList.add('error');

        const placeholder = wrapper.querySelector('.canvas-placeholder');
        if (placeholder) {
            placeholder.innerHTML = '<div class="image-error">' + escapeHtml(message) + '</div>';
        }
    }

    /**
     * Utility: escape HTML
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
})();
