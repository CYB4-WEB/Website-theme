/**
 * Project Alpha - Video Player
 *
 * Auto-detects video type (Pixeldrain, Google Drive, direct) and initializes
 * the appropriate embed or VideoJS player. Supports theater mode, keyboard
 * shortcuts, and playback position persistence.
 *
 * @package starter
 */

(function () {
  'use strict';

  // ---------------------------------------------------------------------------
  // Constants
  // ---------------------------------------------------------------------------

  const ASPECT_RATIO = 9 / 16; // 16:9
  const SEEK_AMOUNT = 10;      // seconds

  // ---------------------------------------------------------------------------
  // State
  // ---------------------------------------------------------------------------

  const state = {
    type: '',            // 'pixeldrain' | 'gdrive' | 'direct'
    url: '',
    videoId: '',
    theaterMode: false,
    player: null,        // VideoJS instance (direct only)
    iframe: null,
    container: null,
  };

  // ---------------------------------------------------------------------------
  // Initialization
  // ---------------------------------------------------------------------------

  document.addEventListener('DOMContentLoaded', () => {
    const containers = document.querySelectorAll('.video-player');
    containers.forEach(initPlayer);
  });

  /**
   * Initialize a single video player container.
   *
   * @param {HTMLElement} container - The .video-player element.
   */
  function initPlayer(container) {
    const url = container.dataset.videoUrl || '';
    if (!url) return;

    const playerState = Object.assign({}, state);
    playerState.url = url;
    playerState.container = container;
    playerState.type = detectVideoType(url);
    playerState.videoId = extractVideoId(url, playerState.type);

    // Set responsive container
    setResponsiveSize(container);
    window.addEventListener('resize', () => setResponsiveSize(container));

    // Build the appropriate player
    switch (playerState.type) {
      case 'pixeldrain':
        initPixeldrain(playerState);
        break;
      case 'gdrive':
        initGoogleDrive(playerState);
        break;
      default:
        initDirectVideo(playerState);
        break;
    }

    // Theater mode button
    initTheaterMode(playerState);

    // Keyboard shortcuts
    initKeyboardShortcuts(playerState);

    // Lazy-load iframes if off-screen
    if (playerState.type !== 'direct') {
      lazyLoadIframe(playerState);
    }
  }

  // ---------------------------------------------------------------------------
  // Video Type Detection
  // ---------------------------------------------------------------------------

  /**
   * Detect video provider from URL.
   *
   * @param {string} url
   * @returns {string}
   */
  function detectVideoType(url) {
    if (/pixeldrain\.com/i.test(url)) return 'pixeldrain';
    if (/drive\.google\.com/i.test(url)) return 'gdrive';
    return 'direct';
  }

  /**
   * Extract video/file ID from URL based on type.
   *
   * @param {string} url
   * @param {string} type
   * @returns {string}
   */
  function extractVideoId(url, type) {
    try {
      if (type === 'pixeldrain') {
        // URLs like https://pixeldrain.com/u/XXXXX
        const match = url.match(/pixeldrain\.com\/[ue]\/([a-zA-Z0-9]+)/);
        return match ? match[1] : '';
      }
      if (type === 'gdrive') {
        // URLs like https://drive.google.com/file/d/XXXXX/view
        const match = url.match(/\/d\/([a-zA-Z0-9_-]+)/);
        return match ? match[1] : '';
      }
    } catch (e) {
      console.error('Failed to extract video ID:', e);
    }
    return '';
  }

  // ---------------------------------------------------------------------------
  // Pixeldrain Embed
  // ---------------------------------------------------------------------------

  function initPixeldrain(ps) {
    // Convert /u/ URL to /e/ embed URL
    const embedUrl = `https://pixeldrain.com/e/${ps.videoId}`;

    const iframe = document.createElement('iframe');
    iframe.className = 'video-player__iframe';
    iframe.setAttribute('allowfullscreen', 'true');
    iframe.setAttribute('allow', 'autoplay; fullscreen');
    iframe.setAttribute('frameborder', '0');
    iframe.dataset.src = embedUrl; // Use data-src for lazy loading
    iframe.title = 'Video Player';

    ps.iframe = iframe;
    ps.container.appendChild(iframe);
  }

  // ---------------------------------------------------------------------------
  // Google Drive Embed
  // ---------------------------------------------------------------------------

  function initGoogleDrive(ps) {
    const embedUrl = `https://drive.google.com/file/d/${ps.videoId}/preview`;

    const iframe = document.createElement('iframe');
    iframe.className = 'video-player__iframe';
    iframe.setAttribute('allowfullscreen', 'true');
    iframe.setAttribute('allow', 'autoplay; fullscreen');
    iframe.setAttribute('frameborder', '0');
    iframe.dataset.src = embedUrl;
    iframe.title = 'Video Player';

    ps.iframe = iframe;
    ps.container.appendChild(iframe);
  }

  // ---------------------------------------------------------------------------
  // Direct Video (VideoJS)
  // ---------------------------------------------------------------------------

  function initDirectVideo(ps) {
    // Create video element
    const video = document.createElement('video');
    video.className = 'video-js vjs-default-skin vjs-big-play-centered';
    video.id = 'starter-video-' + Math.random().toString(36).slice(2, 8);
    video.setAttribute('controls', '');
    video.setAttribute('preload', 'auto');
    video.setAttribute('playsinline', '');

    const source = document.createElement('source');
    source.src = ps.url;
    source.type = guessVideoMimeType(ps.url);
    video.appendChild(source);

    ps.container.appendChild(video);

    // Initialize VideoJS if available
    if (typeof videojs !== 'undefined') {
      try {
        ps.player = videojs(video.id, {
          fluid: true,
          responsive: true,
          playbackRates: [0.5, 1, 1.25, 1.5, 2],
        });

        // Restore saved playback position
        const savedPos = getSavedPlaybackPosition(ps.url);
        if (savedPos > 0) {
          ps.player.one('loadedmetadata', () => {
            ps.player.currentTime(savedPos);
          });
        }

        // Save position periodically
        ps.player.on('timeupdate', window.starterThrottle
          ? window.starterThrottle(() => savePlaybackPosition(ps.url, ps.player.currentTime()), 5000)
          : () => savePlaybackPosition(ps.url, ps.player.currentTime()));

        // Clear saved position when video ends
        ps.player.on('ended', () => clearPlaybackPosition(ps.url));

      } catch (e) {
        console.error('VideoJS initialization failed:', e);
      }
    } else {
      // Fallback: native video element (already has controls)
      video.style.width = '100%';

      // Restore position for native player
      const savedPos = getSavedPlaybackPosition(ps.url);
      if (savedPos > 0) {
        video.addEventListener('loadedmetadata', () => {
          video.currentTime = savedPos;
        }, { once: true });
      }

      // Save position periodically
      video.addEventListener('timeupdate', window.starterThrottle
        ? window.starterThrottle(() => savePlaybackPosition(ps.url, video.currentTime), 5000)
        : () => savePlaybackPosition(ps.url, video.currentTime));

      video.addEventListener('ended', () => clearPlaybackPosition(ps.url));
    }
  }

  /**
   * Guess MIME type from file URL.
   *
   * @param {string} url
   * @returns {string}
   */
  function guessVideoMimeType(url) {
    const ext = url.split('?')[0].split('.').pop().toLowerCase();
    const types = {
      mp4: 'video/mp4',
      webm: 'video/webm',
      ogv: 'video/ogg',
      m3u8: 'application/x-mpegURL',
      mpd: 'application/dash+xml',
    };
    return types[ext] || 'video/mp4';
  }

  // ---------------------------------------------------------------------------
  // Theater Mode
  // ---------------------------------------------------------------------------

  function initTheaterMode(ps) {
    const btn = ps.container.querySelector('.video-player__theater-btn');
    if (!btn) {
      // Create theater mode button if not in markup
      const theaterBtn = document.createElement('button');
      theaterBtn.className = 'video-player__theater-btn';
      theaterBtn.innerHTML = '<span class="screen-reader-text">Theater Mode</span>';
      theaterBtn.setAttribute('aria-label', 'Toggle theater mode');
      theaterBtn.title = 'Theater Mode';
      ps.container.appendChild(theaterBtn);

      theaterBtn.addEventListener('click', () => toggleTheater(ps));
    } else {
      btn.addEventListener('click', () => toggleTheater(ps));
    }
  }

  function toggleTheater(ps) {
    ps.theaterMode = !ps.theaterMode;
    ps.container.classList.toggle('video-player--theater', ps.theaterMode);
    document.body.classList.toggle('theater-mode-active', ps.theaterMode);

    // Recalculate size
    if (ps.theaterMode) {
      ps.container.style.maxWidth = '100vw';
      ps.container.style.width = '100vw';
      ps.container.style.marginLeft = 'calc(-50vw + 50%)';
    } else {
      ps.container.style.maxWidth = '';
      ps.container.style.width = '';
      ps.container.style.marginLeft = '';
    }
  }

  // ---------------------------------------------------------------------------
  // Responsive Container (16:9)
  // ---------------------------------------------------------------------------

  function setResponsiveSize(container) {
    const width = container.offsetWidth;
    container.style.height = Math.round(width * ASPECT_RATIO) + 'px';
  }

  // ---------------------------------------------------------------------------
  // Lazy-Load Iframes
  // ---------------------------------------------------------------------------

  function lazyLoadIframe(ps) {
    if (!ps.iframe) return;

    if ('IntersectionObserver' in window) {
      const observer = new IntersectionObserver(
        (entries) => {
          entries.forEach((entry) => {
            if (entry.isIntersecting) {
              const iframe = entry.target;
              if (iframe.dataset.src) {
                iframe.src = iframe.dataset.src;
                iframe.removeAttribute('data-src');
              }
              observer.unobserve(iframe);
            }
          });
        },
        { rootMargin: '300px 0px' }
      );
      observer.observe(ps.iframe);
    } else {
      // Fallback: load immediately
      if (ps.iframe.dataset.src) {
        ps.iframe.src = ps.iframe.dataset.src;
      }
    }
  }

  // ---------------------------------------------------------------------------
  // Keyboard Shortcuts
  // ---------------------------------------------------------------------------

  function initKeyboardShortcuts(ps) {
    document.addEventListener('keydown', (e) => {
      // Only act when no input is focused
      if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') return;

      // Only for direct video with VideoJS or native
      const video = getVideoElement(ps);
      if (!video) return;

      switch (e.key.toLowerCase()) {
        case ' ':
          e.preventDefault();
          if (ps.player) {
            ps.player.paused() ? ps.player.play() : ps.player.pause();
          } else {
            video.paused ? video.play() : video.pause();
          }
          break;

        case 'f':
          e.preventDefault();
          if (ps.player) {
            ps.player.isFullscreen() ? ps.player.exitFullscreen() : ps.player.requestFullscreen();
          } else if (video.requestFullscreen) {
            document.fullscreenElement ? document.exitFullscreen() : video.requestFullscreen();
          }
          break;

        case 'm':
          e.preventDefault();
          if (ps.player) {
            ps.player.muted(!ps.player.muted());
          } else {
            video.muted = !video.muted;
          }
          break;

        case 'arrowleft':
          e.preventDefault();
          seekVideo(ps, -SEEK_AMOUNT);
          break;

        case 'arrowright':
          e.preventDefault();
          seekVideo(ps, SEEK_AMOUNT);
          break;
      }
    });
  }

  function getVideoElement(ps) {
    if (ps.type !== 'direct') return null;
    if (ps.player) return ps.player.el().querySelector('video');
    return ps.container.querySelector('video');
  }

  function seekVideo(ps, amount) {
    if (ps.player) {
      const t = ps.player.currentTime() + amount;
      ps.player.currentTime(Math.max(0, Math.min(t, ps.player.duration())));
    } else {
      const video = getVideoElement(ps);
      if (video) {
        video.currentTime = Math.max(0, Math.min(video.currentTime + amount, video.duration || 0));
      }
    }
  }

  // ---------------------------------------------------------------------------
  // Playback Position Persistence (localStorage)
  // ---------------------------------------------------------------------------

  function getStorageKey(url) {
    return 'starter_video_pos_' + btoa(url).slice(0, 40);
  }

  function getSavedPlaybackPosition(url) {
    try {
      const val = localStorage.getItem(getStorageKey(url));
      return val ? parseFloat(val) : 0;
    } catch (e) {
      return 0;
    }
  }

  function savePlaybackPosition(url, time) {
    try {
      localStorage.setItem(getStorageKey(url), String(time));
    } catch (e) {
      // Storage full or unavailable
    }
  }

  function clearPlaybackPosition(url) {
    try {
      localStorage.removeItem(getStorageKey(url));
    } catch (e) {
      // Ignore
    }
  }
})();
