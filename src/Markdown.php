<?php

namespace GeneroWP\Llms;

use Genero\Sage\CacheTags\Tags\CoreTags;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;
use WP_Post;

/**
 * A markdown version of every singular page, at the URL the llms.txt proposal
 * specifies: the page URL with `.md` appended, or `index.html.md` when the URL
 * ends in a slash.
 *
 * A separate URL rather than `Accept: text/markdown` negotiation, because host
 * page caches key on the URL alone -- negotiating on a header they ignore would
 * hand the cached markdown to browsers. The `index.html.md` form also sits
 * underneath the page's own path, so a host cache that purges a page as a prefix
 * (Kinsta's "group" purge does) clears the markdown along with it.
 *
 * Only the post content is converted, not the rendered template: navigation,
 * footer and cookie banners are the same on every page and are what llms.txt is
 * for. Content a block draws with JavaScript -- maps, iframes, carousels fed by
 * an API -- has no text to convert and is simply absent.
 */
class Markdown
{
    public const SUFFIX = 'index.html.md';

    protected bool $requested = false;

    public function register(): void
    {
        $this->requested = $this->rewriteRequestUri();

        add_action('wp_head', [$this, 'printAlternateLink']);
        add_action('template_redirect', [$this, 'sendAlternateHeader']);
        add_action('template_redirect', [$this, 'maybeSend'], 0);
    }

    /**
     * The markdown URL for a permalink, or '' where there is none to give.
     *
     * Plain `?p=123` permalinks have no path to append to.
     */
    public static function url(string|false $permalink): string
    {
        if (! $permalink || str_contains($permalink, '?')) {
            return '';
        }

        return str_ends_with($permalink, '/') ? $permalink.self::SUFFIX : $permalink.'.md';
    }

    /**
     * The HTML path a markdown path stands for, or null when it is not one.
     */
    public static function htmlPath(string $path): ?string
    {
        if (str_ends_with($path, '/'.self::SUFFIX)) {
            return substr($path, 0, -strlen(self::SUFFIX));
        }

        if (str_ends_with($path, '.md') && strlen($path) > strlen('/.md')) {
            return substr($path, 0, -strlen('.md'));
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
        $post = get_queried_object();

        if ($this->requested || ! is_singular() || ! $post instanceof WP_Post || ! $this->isAvailable($post)) {
            return '';
        }

        return self::url(get_permalink($post));
    }

    /**
     * Priority 0 so this answers before redirect_canonical (10), which would
     * otherwise bounce `/about.md`'s rewritten `/about` to `/about/`.
     */
    public function maybeSend(): void
    {
        if (! $this->requested) {
            return;
        }

        $post = get_queried_object();

        if (! is_singular() || ! $post instanceof WP_Post || ! $this->isAvailable($post)) {
            status_header(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Not found\n";
            exit;
        }

        $permalink = (string) get_permalink($post);

        status_header(200);
        header('Content-Type: text/markdown; charset=utf-8');
        // The body is decoded text, so markup quoted in a post must never be sniffed as HTML.
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex');
        header(sprintf('Link: <%s>; rel="canonical"', esc_url_raw($permalink)));

        CacheTags::send(fn () => CoreTags::posts([$post]), self::url($permalink));

        echo $this->render($post);
        exit;
    }

    public function isAvailable(WP_Post $post): bool
    {
        $available = $post->post_status === 'publish'
            && ! post_password_required($post)
            && $post->post_type !== 'attachment'
            && is_post_type_viewable($post->post_type);

        /**
         * Whether a post has a markdown version.
         *
         * The markdown is the post content, rendered outside the theme, so any
         * content a template hides -- behind a login form on an intranet page,
         * say -- is served here in full unless the site returns false for it.
         */
        return (bool) apply_filters('gds/llms/markdown_available', $available, $post);
    }

    public function render(WP_Post $post): string
    {
        $previous = $GLOBALS['post'] ?? null;
        $GLOBALS['post'] = $post;
        setup_postdata($post);

        $html = (string) apply_filters('the_content', get_the_content(null, false, $post));

        $GLOBALS['post'] = $previous;
        if ($previous instanceof WP_Post) {
            setup_postdata($previous);
        }

        // The converter leaves entities encoded, so `&amp;` would reach the reader verbatim.
        $markdown = html_entity_decode($this->converter()->convert($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Spacer paragraphs (`<p>&nbsp;</p>`) leave lines of nothing but whitespace.
        $markdown = (string) preg_replace('/^[\h\x{00A0}]+$/mu', '', $markdown);
        $markdown = trim((string) preg_replace("/\n{3,}/", "\n\n", $markdown));

        return '# '.Format::text(get_the_title($post))."\n\n".($markdown ? $markdown."\n" : '');
    }

    protected function converter(): HtmlConverter
    {
        $converter = new HtmlConverter([
            'header_style' => 'atx',
            'hard_break' => true,
            // Layout wrappers (div, figure, section) dissolve into their text.
            'strip_tags' => true,
            'strip_placeholder_links' => true,
            // Nothing in these reads as content once it is out of a browser.
            'remove_nodes' => 'script style noscript template svg form button iframe',
        ]);
        $converter->getEnvironment()->addConverter(new TableConverter);

        return $converter;
    }
}
