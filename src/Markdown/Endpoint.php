<?php

namespace GeneroWP\Llms\Markdown;

use GeneroWP\Llms\CacheControl;
use GeneroWP\Llms\Integrations\CacheTags;
use GeneroWP\Llms\Integrations\FacetWP;
use GeneroWP\Llms\Robots;
use WP_Post;

/**
 * A markdown version of every page, at the page URL followed by `md/`:
 * `/about/` → `/about/md/`, the front page at `/md/`.
 *
 * A separate URL rather than `Accept: text/markdown` negotiation, because host
 * page caches key on the URL alone -- negotiating on a header they ignore would
 * hand the cached markdown to browsers. Extensionless rather than the llms.txt
 * proposal's `index.html.md`: edge caches route anything with a file extension
 * as a static file -- Kinsta's skips it, its CDN keeps it a year. It also sits
 * underneath the page's own path, so a host cache that purges a page as a
 * prefix (Kinsta's "group" purge does) clears the markdown along with it. A
 * page whose own slug is `md` is shadowed by its parent's markdown.
 *
 * Two ways to produce it, chosen per site with `gds/llms/markdown_mode`:
 *
 * - `content` (default, ContentSource): the post content of a singular page,
 *   rendered on its own, about two thirds the cost of the page. A listing --
 *   blog, category, post type archive, shop -- is built from its query
 *   (ArchiveSource) rather than its template.
 * - `page` (PageSource): the whole page rendered as the visitor would see it,
 *   its `<main>` converted. Costs what the page costs, but includes what
 *   templates draw from post meta (recipe ingredients, product prices) and
 *   covers archives.
 *
 * Both answer last on template_redirect, so a login wall that redirects there
 * stops the markdown as it stops the page. A template that hides content
 * behind a login form only does so in `page` mode.
 */
class Endpoint
{
    public const SUFFIX = 'md';

    public const MODE_CONTENT = 'content';

    public const MODE_PAGE = 'page';

    protected bool $requested = false;

    protected Listings $listings;

    protected PageListing $pageListing;

    public function __construct()
    {
        $this->listings = new Listings;
        $this->pageListing = new PageListing($this->listings);
    }

    public function register(): void
    {
        $this->requested = $this->rewriteRequestUri();

        if ($this->requested) {
            // On a site without trailing slashes `/about/md` is rewritten to
            // `/about`; canonical redirect must not bounce it to the HTML page.
            add_filter('redirect_canonical', '__return_false');
            add_action('wp_loaded', [$this->listings, 'register']);
            // Last, so every gate on template_redirect has had its say.
            add_action('template_redirect', [$this, 'start'], PHP_INT_MAX);
        } else {
            add_action('wp_head', [$this, 'printAlternateLink']);
            add_action('template_redirect', [$this, 'sendAlternateHeader']);
        }
    }

    /**
     * The markdown URL for a page URL, or '' where there is none to give.
     *
     * A query string has no path to append to.
     */
    public static function url(string|false $url): string
    {
        if (! $url || str_contains($url, '?') || ! self::isHtmlPath((string) parse_url($url, PHP_URL_PATH))) {
            return '';
        }

        return str_ends_with($url, '/') ? $url.self::SUFFIX.'/' : $url.'/'.self::SUFFIX;
    }

    /**
     * Whether a path can be an HTML page: no extension, or `.html`/`.htm`.
     *
     * A post type whose permalink is its file -- a PDF resource, an SVG
     * material -- would otherwise be advertised as `file.pdf.md`, a
     * conversion of nothing. WordPress turns dots in slugs into dashes, so an
     * extension on a permalink means a file.
     */
    protected static function isHtmlPath(string $path): bool
    {
        $extension = str_ends_with($path, '/') ? '' : strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, ['', 'html', 'htm'], true);
    }

    /**
     * The HTML path a markdown path stands for, or null when it is not one.
     */
    public static function htmlPath(string $path): ?string
    {
        // `/about/md/` stands for `/about/`; `/about/md`, on a site without
        // trailing slashes, for `/about`.
        foreach (['/'.self::SUFFIX.'/' => '/', '/'.self::SUFFIX => ''] as $suffix => $keep) {
            if (str_ends_with($path, $suffix)) {
                return substr($path, 0, -strlen($suffix)).$keep ?: '/';
            }
        }

        return null;
    }

    /**
     * Strip the markdown suffix before anything parses the request.
     *
     * Rewriting REQUEST_URI rather than adding rewrite rules means every plugin
     * that reads the URL -- Polylang's language detection, WP::parse_request(),
     * WooCommerce's endpoints -- resolves the page exactly as it would the HTML
     * one, with no rule per post type and per language to keep in step.
     */
    public function rewriteRequestUri(): bool
    {
        if (is_admin() || wp_doing_ajax() || (defined('WP_CLI') && WP_CLI)) {
            return false;
        }

        if (! in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
            return false;
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $htmlPath = self::htmlPath($path);

        if ($htmlPath === null) {
            return false;
        }

        $_SERVER['REQUEST_URI'] = $htmlPath.substr($uri, strlen($path));

        return true;
    }

    public function printAlternateLink(): void
    {
        if ($url = $this->alternateUrl()) {
            printf('<link rel="alternate" type="text/markdown" href="%s" />'."\n", esc_url($url));
        }
    }

    /**
     * The same link as an HTTP header, for clients that only look at a HEAD
     * response rather than parse the HTML.
     */
    public function sendAlternateHeader(): void
    {
        if ($url = $this->alternateUrl()) {
            header(sprintf('Link: <%s>; rel="alternate"; type="text/markdown"', esc_url_raw($url)), false);
        }
    }

    /**
     * The markdown URL of the page being viewed, or '' when it has none.
     */
    public function alternateUrl(): string
    {
        return $this->isAvailable() ? self::url($this->pageUrl()) : '';
    }

    /**
     * The URL of the page being viewed.
     *
     * The query string stays: on `?cat=2` or `?s=pumps` it is what identifies
     * the page, and url() gives such a page no markdown URL rather than the
     * front page's.
     */
    public function pageUrl(): string
    {
        global $wp;

        $url = user_trailingslashit(home_url($wp->request));
        $query = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);

        return $query === '' ? $url : $url.'?'.$query;
    }

    public static function mode(): string
    {
        /**
         * How the markdown is produced: `content` or `page` (see the class docblock).
         */
        return apply_filters('gds/llms/markdown_mode', self::MODE_CONTENT) === self::MODE_PAGE ? self::MODE_PAGE : self::MODE_CONTENT;
    }

    public function isAvailable(): bool
    {
        $object = get_queried_object();
        $available = ! is_404() && ! is_feed() && ! is_robots() && ! is_favicon() && ! is_embed() && ! is_trackback();

        // A single page or a listing (see source()). Search results are
        // neither: their URL is all query string, which has no markdown URL.
        if (self::mode() === self::MODE_CONTENT) {
            $available = $available && (is_singular() || is_home() || is_archive());
        }

        // Including a protected page in front of a listing: the posts page, or
        // one standing in for an archive, whose content is its introduction.
        if ($object instanceof WP_Post && post_password_required($object)) {
            $available = false;
        }

        // A post whose permalink is a file (see isHtmlPath()).
        if (is_singular() && $object instanceof WP_Post && self::url(get_permalink($object)) === '') {
            $available = false;
        }

        /**
         * Whether the page being viewed has a markdown version.
         *
         * In `content` mode the markdown skips the template, so content a
         * template hides -- an intranet page behind a login form -- is served
         * unless the site returns false here, or switches to `page` mode.
         *
         * @param  bool  $available
         * @param  mixed  $object  the queried object
         */
        return (bool) apply_filters('gds/llms/markdown_available', $available, $object);
    }

    public function start(): void
    {
        if (! $this->isAvailable()) {
            $this->notFound();
        }

        if (self::mode() === self::MODE_CONTENT) {
            $source = $this->source();

            // Plain permalinks (`/?page_id=2`) have no path to give a markdown URL.
            if (self::url($source->url()) === '') {
                $this->notFound();
            }

            $markdown = $this->markdown($source);
            $this->sendHeaders($markdown, $source->url());
            CacheTags::send(fn () => $source->cacheTags(), self::url($source->url()));
            echo $markdown;
            exit;
        }

        $path = strtok($this->pageUrl(), '?') ?: $this->pageUrl();

        // Decided here, not inside the output buffer, where an error could only
        // truncate the response.
        if (self::url($path) === '') {
            $this->notFound();
        }

        (new PageSource)->start(function (string $html) use ($path): string {
            $html = FacetWP::fill($html, $path);
            $markdown = (new Converter($this->pageUrl()))->page($html);
            $this->sendHeaders($markdown, $this->pageUrl());
            CacheTags::saveUrl(self::storedUrl(CacheTags::currentUrl($path)));

            return $markdown;
        });
    }

    /**
     * The markdown URL a page-mode response's tags are stored under: the URL
     * sage-cachetags stores for the HTML page -- its query string sorted, with
     * tracking parameters dropped, so a purge by URL matches what is cached --
     * with `md/` inserted before the query string.
     */
    protected static function storedUrl(string $pageUrl): string
    {
        [$path, $query] = array_pad(explode('?', $pageUrl, 2), 2, '');
        $url = self::url($path);

        return $query === '' || $url === '' ? $url : $url.'?'.$query;
    }

    /**
     * What `content` mode converts for the page being viewed: a listing is
     * built from its query, a single page from its content -- or, for a page
     * that lists something beneath it (PageListing), as that listing.
     */
    public function source(): Source
    {
        global $wp_query;

        if (! is_singular()) {
            // Without the query string, like a post's permalink: the path is
            // what identifies the listing.
            return new ArchiveSource($wp_query, strtok($this->pageUrl(), '?') ?: $this->pageUrl());
        }

        $post = get_queried_object();
        if (! $post instanceof WP_Post) {
            throw new \LogicException('gds/llms/markdown_available allowed a page without post content in content mode.');
        }

        return $this->pageListing->source(new ContentSource($post), $post);
    }

    public function markdown(Source $source): string
    {
        return (new Converter($source->url()))->page($source->html());
    }

    protected function notFound(): never
    {
        status_header(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo __('Page not found')."\n";
        exit;
    }

    protected function sendHeaders(string $markdown, string $canonical): void
    {
        status_header(200);
        header('Content-Type: text/markdown; charset=utf-8');
        // The body is decoded text, so markup quoted in a page must never be sniffed as HTML.
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex');
        header(sprintf('Link: <%s>; rel="canonical"', esc_url_raw($canonical)));
        header('Content-Signal: '.Robots::contentSignal());
        header('Content-Usage: '.Robots::contentUsage());
        CacheControl::send();
        // Cloudflare's estimate of what the response costs a model to read,
        // which agents can use to decide whether to fetch it in full.
        header(sprintf('X-Markdown-Tokens: %d', (int) ceil(mb_strlen($markdown) / 4)));
    }
}
