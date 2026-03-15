<?php

declare(strict_types=1);

namespace Alpha\Services;

use Alpha\Core\{Config, View};

/**
 * SEO: meta tags, Open Graph, Twitter Cards, canonical, robots,
 * breadcrumbs, JSON-LD schema, and XML sitemap.
 * Ported from class-seo-manager.php + class-schema-markup.php.
 */
class SEO
{
    private array $meta = [];

    public function setFromManga(array $manga): void
    {
        $siteName = Config::get('APP_NAME', 'Project Alpha');
        $appUrl   = rtrim(Config::get('APP_URL', ''), '/');

        $this->meta = [
            'title'       => $manga['title'] . ' | ' . $siteName,
            'description' => $this->truncate($manga['synopsis'] ?? $manga['description'] ?? '', 160),
            'keywords'    => $this->buildKeywords($manga),
            'canonical'   => $appUrl . '/manga/' . $manga['slug'],
            'og_type'     => 'article',
            'og_image'    => $manga['cover'] ?? '',
            'robots'      => ($manga['adult'] ?? false) ? 'noindex,nofollow' : 'index,follow',
            'schema'      => $this->comicSeriesSchema($manga),
        ];
    }

    public function setFromChapter(array $manga, array $chapter): void
    {
        $siteName = Config::get('APP_NAME', 'Project Alpha');
        $appUrl   = rtrim(Config::get('APP_URL', ''), '/');

        $this->meta = [
            'title'       => $manga['title'] . ' Chapter ' . $chapter['chapter_number'] . ' | ' . $siteName,
            'description' => 'Read ' . $manga['title'] . ' Chapter ' . $chapter['chapter_number'] . ' online free.',
            'canonical'   => $appUrl . '/manga/' . $manga['slug'] . '/chapter/' . $chapter['chapter_number'],
            'robots'      => 'noindex,follow', // chapters are usually not indexed
            'og_type'     => 'article',
            'og_image'    => $manga['cover'] ?? '',
            'schema'      => null,
        ];
    }

    public function setFromArchive(string $title = '', string $desc = ''): void
    {
        $siteName = Config::get('APP_NAME', 'Project Alpha');
        $appUrl   = rtrim(Config::get('APP_URL', ''), '/');

        $this->meta = [
            'title'       => ($title ? $title . ' | ' : '') . $siteName,
            'description' => $desc ?: 'Read manga, novels, and watch anime online for free.',
            'canonical'   => $appUrl,
            'robots'      => 'index,follow',
            'og_type'     => 'website',
            'og_image'    => '',
            'schema'      => null,
        ];
    }

    /** Render all <head> SEO tags. */
    public function renderHead(): string
    {
        if (empty($this->meta)) {
            return '';
        }

        $m        = $this->meta;
        $appName  = htmlspecialchars(Config::get('APP_NAME', 'Project Alpha'), ENT_QUOTES);
        $title    = htmlspecialchars($m['title'] ?? $appName, ENT_QUOTES);
        $desc     = htmlspecialchars($m['description'] ?? '', ENT_QUOTES);
        $robots   = htmlspecialchars($m['robots'] ?? 'index,follow', ENT_QUOTES);
        $canon    = htmlspecialchars($m['canonical'] ?? '', ENT_QUOTES);
        $ogImage  = htmlspecialchars($m['og_image'] ?? '', ENT_QUOTES);
        $ogType   = htmlspecialchars($m['og_type'] ?? 'website', ENT_QUOTES);
        $keywords = htmlspecialchars($m['keywords'] ?? '', ENT_QUOTES);

        $html  = "<title>{$title}</title>\n";
        $html .= "<meta name=\"description\" content=\"{$desc}\">\n";
        if ($keywords) {
            $html .= "<meta name=\"keywords\" content=\"{$keywords}\">\n";
        }
        $html .= "<meta name=\"robots\" content=\"{$robots}\">\n";
        if ($canon) {
            $html .= "<link rel=\"canonical\" href=\"{$canon}\">\n";
        }
        // OG
        $html .= "<meta property=\"og:type\" content=\"{$ogType}\">\n";
        $html .= "<meta property=\"og:title\" content=\"{$title}\">\n";
        $html .= "<meta property=\"og:description\" content=\"{$desc}\">\n";
        if ($ogImage) {
            $html .= "<meta property=\"og:image\" content=\"{$ogImage}\">\n";
        }
        if ($canon) {
            $html .= "<meta property=\"og:url\" content=\"{$canon}\">\n";
        }
        $html .= "<meta property=\"og:site_name\" content=\"{$appName}\">\n";
        // Twitter
        $html .= "<meta name=\"twitter:card\" content=\"summary_large_image\">\n";
        $html .= "<meta name=\"twitter:title\" content=\"{$title}\">\n";
        $html .= "<meta name=\"twitter:description\" content=\"{$desc}\">\n";
        if ($ogImage) {
            $html .= "<meta name=\"twitter:image\" content=\"{$ogImage}\">\n";
        }
        // JSON-LD
        if (!empty($m['schema'])) {
            $json  = json_encode($m['schema'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $html .= "<script type=\"application/ld+json\">{$json}</script>\n";
        }

        return $html;
    }

    // ── Schema helpers ────────────────────────────────────────────────────────

    private function comicSeriesSchema(array $manga): array
    {
        $appUrl = rtrim(Config::get('APP_URL', ''), '/');
        $type   = match (strtolower($manga['type'] ?? 'manga')) {
            'novel' => 'Book',
            'video' => 'VideoObject',
            default => 'ComicSeries',
        };

        return [
            '@context' => 'https://schema.org',
            '@type'    => $type,
            'name'     => $manga['title'],
            'url'      => $appUrl . '/manga/' . $manga['slug'],
            'image'    => $manga['cover'] ?? '',
            'description' => $manga['synopsis'] ?? '',
            'author'   => ['@type' => 'Person', 'name' => $manga['author'] ?? ''],
            'genre'    => array_column($manga['genres'] ?? [], 'name'),
        ];
    }

    private function buildKeywords(array $manga): string
    {
        $parts = array_filter([
            $manga['title'],
            $manga['author'] ?? '',
            implode(', ', array_column($manga['genres'] ?? [], 'name')),
            $manga['type'] ?? '',
        ]);
        return implode(', ', $parts);
    }

    private function truncate(string $s, int $len): string
    {
        $clean = strip_tags($s);
        return mb_strlen($clean) > $len ? mb_substr($clean, 0, $len - 1) . '…' : $clean;
    }

    // ── Sitemap ───────────────────────────────────────────────────────────────

    public function generateSitemap(): string
    {
        $appUrl = rtrim(Config::get('APP_URL', ''), '/');
        $db     = \Alpha\Core\Database::getInstance();
        $mTbl   = \Alpha\Core\Database::table('manga');

        $mangas = $db->get_results("SELECT slug, updated_at FROM `{$mTbl}` ORDER BY updated_at DESC LIMIT 5000");

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        $xml .= "  <url><loc>{$appUrl}/</loc><changefreq>daily</changefreq><priority>1.0</priority></url>\n";
        $xml .= "  <url><loc>{$appUrl}/manga</loc><changefreq>hourly</changefreq><priority>0.9</priority></url>\n";

        foreach ($mangas as $m) {
            $loc  = htmlspecialchars("{$appUrl}/manga/{$m['slug']}", ENT_XML1);
            $date = substr($m['updated_at'], 0, 10);
            $xml .= "  <url><loc>{$loc}</loc><lastmod>{$date}</lastmod><changefreq>weekly</changefreq><priority>0.8</priority></url>\n";
        }

        $xml .= '</urlset>';
        return $xml;
    }
}
