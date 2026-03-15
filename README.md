# Alpha Manga — WordPress Theme

A standalone, full-featured manga / novel / video reading platform built as a WordPress theme. No Madara or parent theme required. Ships with its own post types, custom database tables, admin panels, monetization system, image protection, and reader engine.

---

## Table of Contents

1. [What Needs a Plugin (Can't Be Done in Theme Alone)](#1-what-needs-a-plugin-cant-be-done-in-theme-alone)
2. [Admin Menu Structure (wp-admin)](#2-admin-menu-structure-wp-admin)
3. [Page Structure](#3-page-structure)
4. [Backend](#4-backend)
5. [Frontend](#5-frontend)

---

## 1. What Needs a Plugin (Can't Be Done in Theme Alone)

WordPress enforces a hard rule: if a theme is deactivated, everything it registered disappears — post types, taxonomies, shortcodes, rewrite rules. The features below survive a theme switch only when housed in a plugin. Everything else in this theme is intentionally self-contained.

### Must Be in a Plugin (Data Persistence)

| Feature | Why It Can't Live in a Theme |
|---|---|
| **`wp-manga` Custom Post Type** | If the theme is deactivated, all `wp-manga` posts become orphaned (`post_type = 'wp-manga'` rows still exist in the DB but are invisible to WordPress). They should be registered by a plugin so data survives theme changes. |
| **Taxonomies** (`genre`, `wp-manga-tag`, `wp-manga-type`, `wp-manga-release`) | Same reason — taxonomy terms and their post relationships are preserved in the DB but inaccessible without the registration code. |
| **Custom Database Tables** (`wp_starter_chapters`, `wp_starter_ratings`, `wp_starter_views_log`, `wp_starter_user_coins`, `wp_starter_coin_transactions`, `wp_starter_withdrawals`, `wp_starter_path_map`) | Table creation is currently triggered on `after_switch_theme`. If the theme is ever changed, these tables remain but no code manages them. A plugin with its own activation hook is the correct owner. |
| **Custom User Roles** (`manga_uploader`, `vip_reader`) | Roles stored in the `wp_options` table under `wp_user_roles`. They persist after deactivation but capabilities assigned inside theme code become dead references. Role registration and capability grants belong in a plugin. |
| **Coin System & Monetization** | Financial transaction records and coin balances must never be tied to an active theme. A billing/monetization plugin owns this data permanently. |
| **Revenue Share & Withdrawals** | Same as above — author earnings and payout records are business-critical data. |
| **Rewrite Rules** | The custom URL patterns (`/manga/{slug}/chapter-{n}/`, `/starter-img/`, `/content/`) break silently when the theme is deactivated because `flush_rewrite_rules()` removes them. A plugin ensures they are re-registered on every request. |

### Should Be a Plugin (Portability / Best Practice)

| Feature | Reason |
|---|---|
| **MangaUpdates API Integration** | External API client that other themes or plugins may want to reuse. |
| **Storage Drivers** (S3, FTP, External CDN) | Infrastructure concerns that should survive a redesign. Site-level, not theme-level. |
| **Webhook Notifications** (Telegram / Discord) | Notification infrastructure belongs at the site level. |
| **SEO — Sitemaps & Feeds** | Custom XML sitemaps and RSS feeds are site-level concerns already covered by dedicated SEO plugins. Duplication causes conflicts. |
| **reCAPTCHA Integration** | Authentication security applies to the whole site, not just this theme's login forms. |
| **Image Encryption & Path Obfuscation** | Content protection middleware that should persist regardless of active theme. |

### Can Safely Stay in the Theme

- All template files (`single.php`, `archive.php`, reader templates, page templates)
- All CSS / JS assets and their enqueue logic
- Admin UI — settings panel, chapter upload pages, review queue (these are presentation, not data)
- Dark mode toggle, glassmorphism styles, hero slider, widget rendering
- Sample data installer (dev/demo tool only)

---

## 2. Admin Menu Structure (wp-admin)

All custom pages live under a single top-level menu item: **Alpha Manga** (book icon, position 5).

```
wp-admin/
│
└── Alpha Manga  ──────────────────────────────────────── (top-level, dashicons-book-alt)
    │
    ├── Dashboard                   Overview stats, quick actions, recent chapters table
    │
    ├── Settings                    8-tab settings panel
    │   ├── General                 Site name, logo, adult content toggle, registration
    │   ├── API Keys                MangaUpdates key, reCAPTCHA site/secret, Telegram token,
    │   │                           Discord webhook URL, S3 credentials
    │   ├── Storage                 Driver selector (Local / Amazon S3 / FTP), credentials,
    │   │                           CDN URL, WebP conversion, lazy load, image quality
    │   ├── Reader                  Default reader mode, preload pages, chapter scroll direction,
    │   │                           image fit mode, reading direction (LTR/RTL)
    │   ├── Coins & Revenue         Coin-to-money rate, minimum withdrawal, payment methods,
    │   │                           revenue share % per chapter unlock
    │   ├── SEO                     Meta description template, Open Graph toggle,
    │   │                           sitemap settings, feed settings
    │   ├── Security                Rate-limit thresholds, allowed upload IPs,
    │   │                           content protection toggles
    │   └── Webhooks                Telegram & Discord live webhook test buttons
    │
    ├── Sample Data                 1-click install / remove demo manga posts and chapters
    │
    ├── ── Chapters ──
    │   ├── All Chapters            Paginated chapter list; filterable by manga, type, status.
    │   │                           Shows type badge (Image/ZIP/PDF/Text/Video), premium lock
    │   │                           icon, coin price, publish date, delete button.
    │   ├── Upload Chapter          5-tab upload form:
    │   │                           • Images — multi-file drag-and-drop, reorder
    │   │                           • ZIP Archive — bulk extract to chapter pages
    │   │                           • PDF — single PDF file upload
    │   │                           • Text — rich text editor for novel chapters
    │   │                           • Video — URL input (YouTube / direct HLS)
    │   │                           Premium toggle + coin price + publish scheduler
    │   ├── MangaUpdates Import     URL input → live preview (cover, title, author, genres,
    │   │                           description) → 1-click import as draft manga post
    │   └── Review Queue            Lists all manga/chapters in `pending` status.
    │                               Approve (publish) or Reject (trash) with one click.
    │
    ├── ── Coins & Revenue ──
    │   ├── Coin Dashboard          Total coins in circulation, total purchases, top spenders
    │   ├── Purchases               Full purchase history with user, package, amount, date
    │   ├── Transactions            All coin movements (earn, spend, refund) per user
    │   └── Rankings                Leaderboard of top coin spenders
    │
    ├── ── Revenue Share ──
    │   ├── Author Earnings         Per-author breakdown of unlock revenue earned
    │   └── Withdrawal Requests     Pending / approved / rejected payout requests from authors
    │
    ├── ── SEO / Feeds ──
    │   └── Manga Feeds             Feed URL display, feed format settings
    │
    └── ── Novel Tools ──
        └── Novel Reading Settings  Default font family, size, line-height, background presets
```

### Standard WordPress Menus Used

| Menu | Used By |
|---|---|
| **Posts** | Not used directly (manga uses `wp-manga` CPT) |
| **Pages** | Upload Manga page, Upload Chapter page, Login page, User Settings page |
| **Appearance → Widgets** | Hero Slider Widget, Manga Slider Widget, Popular Manga Widget |
| **Appearance → Menus** | Primary nav, Secondary nav, Mobile nav, Footer nav |

---

## 3. Page Structure

### URL Map

```
/                               → Homepage (front-page.php)
│
├── /manga/                     → Manga archive (browse + filter)
│   └── /{manga-slug}/          → Single manga (info, chapter list, rating, bookmark)
│       └── /chapter-{n}/       → Chapter reader (images / text / video)
│
├── /upload-manga/              → Manga submission form (logged-in uploaders)
├── /upload-chapter/            → Chapter upload form (logged-in uploaders)
│
├── /login/                     → Login + registration + lost password tabs
├── /profile/                   → User account settings
│   ├── /profile/bookmarks/     → Saved manga list
│   ├── /profile/history/       → Reading history
│   └── /profile/purchases/     → Purchased (coin-unlocked) chapters
│
├── /coins/                     → Coin balance, buy coins, transaction history
├── /earnings/                  → Author revenue dashboard (uploader role only)
│
└── /search/                    → Search results (WordPress native + AJAX live search)
```

### WordPress Pages to Create After Installation

| Page Title | Template to Assign | Shortcode / Notes |
|---|---|---|
| Home | — | Set as static front page in Settings → Reading |
| Upload Manga | **Upload Manga** | Renders `[alpha_upload_manga]` |
| Upload Chapter | **Upload Chapter** | Renders `[alpha_upload_chapter]` |
| Login | Default | Renders `[starter_login]` + `[starter_register]` |
| My Profile | Default | Renders `[starter_user_settings]` |
| Bookmarks | Default | Renders `[starter_bookmarks]` |
| Reading History | Default | Renders `[starter_reading_history]` |
| Coins | Default | Renders `[starter_user_balance]` |
| Author Earnings | Default | Renders `[starter_author_revenue]` |

---

## 4. Backend

### File Structure

```
inc/
│
├── core/
│   ├── class-theme-setup.php         Theme supports, nav menus, image sizes, widget areas
│   ├── class-enqueue.php             All CSS/JS enqueue (front + admin)
│   ├── class-security.php            Security headers, input hardening
│   ├── class-encryption.php          AES encryption helpers for image tokens
│   ├── class-env-loader.php          Reads server-level config before anything else
│   ├── class-admin-settings.php      8-tab admin settings panel + webhook AJAX handler
│   └── class-sample-data.php         Demo data installer (6 manga, 13 genres, chapters)
│
├── storage/
│   ├── interface-storage.php         Contract: upload(), get_url(), delete(), exists()
│   ├── class-storage-manager.php     Reads `starter_storage_driver` option, returns driver
│   ├── class-storage-local.php       wp-content/uploads via WP_Filesystem
│   ├── class-storage-s3.php          AWS SDK v3 wrapper (presigned URLs, multipart)
│   ├── class-storage-ftp.php         PHP ftp_* functions, SFTP fallback
│   └── class-storage-external.php    External URL / CDN passthrough
│
├── manga/
│   ├── class-manga-cpt.php           Registers wp-manga CPT + 4 taxonomies
│   ├── class-manga-chapter.php       Chapter CRUD, view tracking, AJAX load
│   ├── class-manga-reader.php        Reader template logic, page serving, token generation
│   ├── class-manga-search.php        Live search, autocomplete, advanced filter AJAX
│   ├── class-manga-rating.php        1–5 star rating with per-user dedup
│   ├── class-manga-bookmark.php      Add/remove bookmarks (user_meta + cookie fallback)
│   ├── class-manga-history.php       Reading position tracking (chapter + page)
│   ├── class-manga-views.php         Hourly-deduped view logging to wp_starter_views_log
│   ├── class-manga-import.php        ZIP → chapter images with ZIP Slip prevention,
│   │                                 finfo_open() MIME validation, background processing
│   ├── class-manga-admin.php         Admin pages: chapters list, upload, MU import, review queue
│   └── class-manga-updates-api.php   MangaUpdates v1 REST client (fetch_series, fetch_by_url)
│
├── novel/
│   ├── class-novel-reader.php        Novel chapter rendering, prev/next nav, progress save
│   └── class-novel-reading-tools.php Font/size/background preferences stored in user_meta
│
├── video/
│   ├── class-video-player.php        HLS.js / DASH.js player, quality selector, resume
│   └── class-video-embed.php         [starter_video_embed] shortcode, iframe sandbox
│
├── user/
│   ├── class-user-auth.php           Login/register/reset with reCAPTCHA v3 + honeypot +
│   │                                 rate limiting (transient-based, per IP)
│   ├── class-user-roles.php          manga_uploader + vip_reader role registration
│   ├── class-user-settings.php       Profile, reading prefs, notification prefs AJAX
│   └── class-user-upload.php         [alpha_upload_manga] + [alpha_upload_chapter] shortcodes,
│                                     Croppie cover cropper, MangaUpdates quick-fill bar
│
├── monetization/
│   ├── class-coin-system.php         get_balance(), add(), deduct(), transaction log,
│   │                                 purchase packages, admin coin dashboard
│   ├── class-revenue-share.php       Per-unlock author cut calculation, withdrawal requests,
│   │                                 admin approval flow
│   └── class-chapter-permissions.php can_access(), unlock_chapter(), get_access_data(),
│                                     IDOR-safe AJAX with ownership verification
│
├── protection/
│   ├── class-chapter-protector.php   Anti-hotlink headers, rate-limit per IP/user
│   ├── class-image-encryption.php    AES token-gated image proxy (/starter-img/{token}/{hash})
│   └── class-path-obfuscation.php    Real path → hash map in wp_starter_path_map,
│                                     served via /content/{hash} rewrite
│
├── seo/
│   ├── class-seo-manager.php         Open Graph, Twitter Card, canonical, title templates
│   ├── class-auto-keywords.php       Extract keywords from genres, tags, author meta
│   ├── class-schema-markup.php       JSON-LD: MangaSeries, Book, BreadcrumbList schemas
│   ├── class-manga-feed.php          Custom RSS/Atom feed for latest chapters
│   └── class-manga-sitemap.php       XML sitemap for all published manga and chapters
│
└── widgets/
    ├── class-hero-slider-widget.php   Full-width hero with glassmorphism overlay, CTA buttons
    ├── class-manga-slider-widget.php  Swiper.js carousel, configurable count + taxonomy filter
    └── class-popular-manga-widget.php Ranked list (gold/silver/bronze), time-period selector
```

### Custom Database Tables

| Table | Columns | Purpose |
|---|---|---|
| `wp_starter_chapters` | id, manga_id, chapter_number, chapter_title, chapter_slug, chapter_type, chapter_data (JSON), chapter_status, is_premium, coin_price, volume, publish_date, created_at | All chapter records for every manga |
| `wp_starter_ratings` | id, manga_id, user_id, rating (1–5), created_at | One row per user per manga |
| `wp_starter_views_log` | id, manga_id, chapter_id, user_id, ip_address, viewed_at | Raw view events (hourly dedup in query) |
| `wp_starter_user_coins` | id, user_id, balance, updated_at | Current coin balance per user |
| `wp_starter_coin_transactions` | id, user_id, amount, transaction_type, reference_id, description, created_at | Full ledger of every coin movement |
| `wp_starter_withdrawals` | id, user_id, amount, payment_method, payment_details, status, processed_at, created_at | Author payout requests |
| `wp_starter_chapter_unlocks` | id, chapter_id, user_id, unlocked_at | Records which users have paid to unlock which chapters |
| `wp_starter_path_map` | id, hash, real_path, expires_at, created_at | Maps obfuscated URL hashes to real file paths |

### Post Meta (stored on `wp-manga` posts)

| Meta Key | Value | Set By |
|---|---|---|
| `_author` | Author name string | Upload form / MU import |
| `_artist` | Artist name string | Upload form / MU import |
| `_status` | Ongoing / Completed / Hiatus / Cancelled | Upload form |
| `_release_year` | 4-digit year | Upload form / MU import |
| `_content_type` | manga / novel / video / comic | Upload form |
| `_views` | Integer (aggregate) | Views tracker |
| `_featured` | `1` | Admin toggle |
| `_is_sample_data` | `1` | Sample data installer (used to identify demo posts for removal) |
| `_mu_series_id` | MangaUpdates series ID | MU import |

### Security Model

| Layer | Implementation |
|---|---|
| All AJAX handlers | `check_ajax_referer()` + capability check before any data access |
| Admin forms | `settings_fields()` WordPress nonce + `manage_options` cap |
| SQL queries | 100% `$wpdb->prepare()` — no raw string interpolation in WHERE clauses |
| Table existence checks | `INFORMATION_SCHEMA` parameterized query (no `SHOW TABLES LIKE` injection risk) |
| XSS output | `esc_html()`, `esc_attr()`, `esc_url()` on all echo; JS DOM built with `.text()`/`.attr()` |
| Password / secret fields | Never echoed back to HTML; blank submit preserves stored value |
| Ownership / IDOR | `ajax_save_chapter()` verifies post author matches current user for non-admins |
| File uploads | `finfo_open(FILEINFO_MIME_TYPE)` validates real MIME; allowed list checked |
| ZIP extraction | Manual entry-by-entry loop with `realpath()` prefix check (ZIP Slip prevention) |
| Open redirect | Redirect URLs validated against same-origin regex before `window.location` |
| Rate limiting | Login attempts tracked in transients per IP; threshold configurable in settings |

---

## 5. Frontend

### Asset Files

```
assets/
├── css/
│   ├── main.css              Core layout, manga grid, cards, hero, homepage sections,
│   │                         genre chips, popular list, explore tabs, CTA band,
│   │                         upload forms, toggle switches — ~1 400 lines
│   ├── dark-mode.css         CSS custom properties for dark theme (--bg, --text, --accent…)
│   ├── glassmorphism.css     backdrop-filter blur panels, frosted modals, card overlays
│   ├── responsive.css        Mobile-first breakpoints: 1200px / 1024px / 768px / 480px
│   ├── rtl.css               Right-to-left mirror rules (loaded conditionally)
│   ├── reader.css            Manga reader layout (vertical scroll / horizontal page modes),
│   │                         novel reader typography, video player wrapper
│   └── admin.css             Stats cards, quick-actions grid, settings tabs, chapter table
│                             badges, upload admin layout, MU preview styles — ~400 lines
│
└── js/
    ├── main.js               Navigation (hamburger, sticky header, back-to-top),
    │                         dark/light mode toggle with localStorage,
    │                         global event delegation, scroll animations
    ├── reader.js             Image reader: page-by-page / long-strip toggle,
    │                         keyboard shortcuts (←→ arrows, F for fullscreen),
    │                         canvas-based render (prevents right-click save),
    │                         zoom controls, chapter prev/next navigation
    ├── novel-reader.js       Text reader: custom font selector, font size slider,
    │                         background colour presets, line-height, scroll progress bar,
    │                         auto-save reading position via AJAX
    ├── ajax-search.js        Debounced live search with autocomplete dropdown,
    │                         advanced filter panel (genre, type, status, year)
    ├── bookmark.js           Optimistic add/remove bookmark, heart animation,
    │                         guest cookie fallback, sync on login
    ├── dark-toggle.js        data-theme="dark" on <html>, persists to localStorage,
    │                         respects prefers-color-scheme on first visit
    ├── chapter-protector.js  Disables right-click context menu on reader canvas,
    │                         blocks devtools screenshot shortcut, anti-hotlink headers
    ├── image-upload.js       Multi-file drag-and-drop zone, preview grid, reorder via
    │                         drag, per-file progress bars, MIME validation client-side
    ├── thumbnail-cropper.js  Croppie.js integration for manga cover upload:
    │                         crop modal, zoom slider, aspect ratio lock, preview
    └── video-player.js       HLS.js / DASH.js initialisation, quality level menu,
                              resume from last position, fullscreen API
```

### Homepage Sections (`front-page.php`)

```
┌──────────────────────────────────────────────────────────┐
│  HERO SLIDER                                             │
│  5 featured manga — auto-advances every 5 s             │
│  • Cover image (right), gradient overlay (left)         │
│  • Genre badges, star rating, description (120 chars)   │
│  • "Read Now" + "Add to List" CTA buttons               │
│  • Dot navigation + prev/next arrows                    │
├──────────────────────────────────────────────────────────┤
│  GENRE QUICK-NAV CHIPS                                   │
│  Scrollable row of clickable genre pills                │
│  Links to /manga/?genre={slug}                          │
├──────────────────────────────────────────────────────────┤
│  LATEST RELEASES                                         │
│  6-column grid (→ 4 → 3 → 2 on smaller screens)        │
│  Each card: cover, title, latest chapter badge, status  │
├──────────────────────────────────────────────────────────┤
│  POPULAR MANGA                                           │
│  Ranked list — top 3 get gold / silver / bronze medals │
│  Shows rank, cover thumbnail, title, view count         │
├──────────────────────────────────────────────────────────┤
│  EXPLORE TABS  [Manga] [Novel] [Video]                   │
│  4-column grid filtered by content type                 │
│  Tab switch is client-side (no page reload)             │
├──────────────────────────────────────────────────────────┤
│  JOIN BAND / CTA                                         │
│  Full-width banner — "Create Account" + "Browse All"    │
└──────────────────────────────────────────────────────────┘
```

### Single Manga Page (`templates/manga/single-manga.php`)

```
• Cover image + metadata sidebar (author, artist, status, type, year, genres)
• Star rating widget (AJAX submit, shows average + user's own vote)
• Bookmark toggle button
• Description / synopsis (expandable)
• Chapter list table — number, title, type badge, premium lock, publish date, view count
• Pagination for long chapter lists
• Social share buttons (Open Graph meta pre-populated)
```

### Reader Modes

| Mode | Template | Controls |
|---|---|---|
| **Image (manga)** | `templates/manga/reader.php` | ← → arrows, long-strip toggle, zoom, fullscreen, canvas render |
| **Text (novel)** | `templates/manga/reader-novel.php` | Font family/size/bg controls, scroll %, auto-save position |
| **Video** | `templates/manga/reader-video.php` | HLS quality selector, resume from last second, fullscreen |

### Shortcodes Available in Pages

| Shortcode | Renders |
|---|---|
| `[starter_login]` | Login form with reCAPTCHA v3, remember me, social login hooks |
| `[starter_register]` | Registration form with honeypot field |
| `[starter_lost_password]` | Password reset request form |
| `[starter_user_settings]` | Full profile settings (avatar, display name, email, password, reading prefs, notifications) |
| `[starter_bookmarks]` | Grid of bookmarked manga (with reading progress) |
| `[starter_reading_history]` | Timeline of recently read chapters |
| `[starter_user_balance]` | Coin wallet — current balance, buy-coins button, transaction list |
| `[starter_purchased_manga]` | List of coin-unlocked chapters |
| `[starter_coin_rankings]` | Public leaderboard of top coin spenders |
| `[starter_author_revenue]` | Per-manga earnings breakdown for uploaders |
| `[starter_author_withdrawals]` | Uploader's withdrawal request history + submit new request |
| `[alpha_upload_manga]` | Full manga submission form (title, cover, genres, description, MU quick-fill) |
| `[alpha_upload_chapter]` | Chapter upload form (images / ZIP / PDF / text / video) |
| `[starter_video_embed url="…"]` | Sandboxed video embed with HLS fallback |

### Widgets (Appearance → Widgets)

| Widget | Options | Outputs |
|---|---|---|
| **Hero Slider** | Posts per slide (1–10), taxonomy filter, CTA label | Full-width auto-advancing banner |
| **Manga Slider** | Count, genre filter, order (latest / popular / rating), title | Swiper.js cover carousel |
| **Popular Manga** | Count, time period (today / week / month / all), show rank badge | Ranked list with medals |

### Dark Mode

Toggled by clicking the moon/sun icon in the header. Adds/removes `data-theme="dark"` on `<html>`. CSS custom properties (`--bg-primary`, `--text-primary`, `--accent`, etc.) in `dark-mode.css` handle all colour changes. Preference persists to `localStorage` and falls back to `prefers-color-scheme` on first visit.

### Responsive Breakpoints

| Breakpoint | Manga Grid Columns | Layout Changes |
|---|---|---|
| > 1200 px | 6 | Full desktop layout |
| 1024 px | 5 | Sidebar collapses |
| 900 px | 4 | Hero text truncated |
| 768 px | 3 | Hamburger nav active |
| 600 px | 2 | Single-column hero |
| < 480 px | 2 | Reader controls stack |

---

## Quick-Start Checklist

1. **Activate theme** — database tables and roles are created on `after_switch_theme`.
2. **Alpha Manga → Settings → API Keys** — add MangaUpdates key, reCAPTCHA credentials, Telegram / Discord webhook.
3. **Alpha Manga → Settings → Storage** — choose Local (default), S3, or FTP driver.
4. **Alpha Manga → Sample Data → Install** — loads 6 demo manga with chapters so no page is blank.
5. **Create pages** — see the page table in §3, assign the correct page template to Upload Manga and Upload Chapter.
6. **Appearance → Menus** — assign Primary, Secondary, Mobile, and Footer nav menus.
7. **Appearance → Widgets** — add Hero Slider to the homepage widget area.
8. **Settings → Reading** — set the Home page to the static front page you created.
9. Move CPT / table registration to a dedicated plugin before going to production (see §1).
