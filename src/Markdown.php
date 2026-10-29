<?php

namespace GeneroWP\Llms;

use Genero\Sage\CacheTags\Tags\CoreTags;
use WP_Post;

/**
 * A markdown version of every page, at the URL the llms.txt proposal
 * specifies: the page URL with `.md` appended, or `index.html.md` when the URL
 * ends in a slash.
 *
 * A separate URL rather than `Accept: text/markdown` negotiation, because host
 * page caches key on the URL alone -- negotiating on a header they ignore would
 * hand the cached markdown to browsers. The `index.html.md` form also sits
 * underneath the page's own path, so a host cache that purges a page as a prefix
 * (Kinsta's "group" purge does) clears the markdown along with it.
 *
 * Two ways to produce it, chosen per site with `gds/llms/markdown_mode`:
 *
 * - `content` (default): the post content of a singular page, rendered on its
 *   own. About two thirds the cost of the page; archives have no markdown.
 * - `page`: the whole page rendered as the visitor would see it, its `<main>`
 *   converted. Costs what the page costs, but includes what templates draw from
 *   post meta (recipe ingredients, product prices) and covers archives.
 *
 * Both answer last on template_redirect, so a login wall that redirects there
 * stops the markdown as it stops the page. A template that hides content
 * behind a login form only does so in `page` mode.
 */
class Markdown
{
    public const SUFFIX = 'index.html.md';

    public const MODE_CONTENT = 'content';

    public const MODE_PAGE = 'page';

    protected bool $requested = false;

    protected string $buffer = '';

    public function register(): void
    {
        $this->requested = $this->rewriteRequestUri();

        if ($this->requested) {
            // `/about.md` is rewritten to `/about`, which canonical redirect
            // would bounce to `/about/` -- the HTML page.
            add_filter('redirect_canonical', '__return_false');
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
        if (! $url || str_contains($url, '?')) {
            return '';
        }

        return str_ends_with($url, '/') ? $url.self::SUFFIX : $url.'.md';
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

        // Only a singular page has post content of its own to convert.
        if (self::mode() === self::MODE_CONTENT) {
            $available = $available && is_singular() && $object instanceof WP_Post && ! post_password_required($object);
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
            status_header(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Not found\n";
            exit;
        }

        if (self::mode() === self::MODE_CONTENT) {
            $post = get_queried_object();
            if (! $post instanceof WP_Post) {
                throw new \LogicException('gds/llms/markdown_available allowed a page without post content in content mode.');
            }

            $markdown = $this->renderContent($post);
            $this->sendHeaders();
            $this->sendTokenEstimate($markdown);
            CacheTags::send(fn () => CoreTags::posts([$post]), self::url($this->pageUrl()));
            echo $markdown;
            exit;
        }

        // A handler rather than collecting the buffer on shutdown: Acorn sends
        // its response through Symfony, which closes every output buffer itself
        // before shutdown. The handler runs whoever closes it, before anything
        // reaches the client.
        ob_start([$this, 'handleBuffer']);
    }

    /**
     * Swap the rendered page for its markdown once the buffer closes.
     */
    public function handleBuffer(string $chunk, int $phase): string
    {
        $this->buffer .= $chunk;

        // An early flush() mid-render only hands over part of the page.
        if (! ($phase & PHP_OUTPUT_HANDLER_FINAL)) {
            return '';
        }

        // A redirect from inside the template, or a template that answered
        // with something other than a page: pass it through untouched.
        if (! $this->isHtmlResponse()) {
            return $this->buffer;
        }

        $markdown = (new Converter($this->pageUrl()))->page($this->buffer);
        $this->sendHeaders();
        $this->sendTokenEstimate($markdown);
        CacheTags::saveUrl(self::url($this->pageUrl()));

        return $markdown;
    }

    /**
     * The post content, converted as if it were a page's main content titled
     * with the post title.
     */
    public function renderContent(WP_Post $post): string
    {
        setup_postdata($post);
        $content = (string) apply_filters('the_content', get_the_content(null, false, $post));

        /**
         * The HTML converted in `content` mode, for adding what the site's
         * template renders outside the content -- a product's price, a
         * recipe's ingredients from post meta -- without touching the HTML page.
         *
         * @param  string  $content  the rendered post content
         * @param  WP_Post  $post
         */
        $content = (string) apply_filters('gds/llms/markdown_content', $content, $post);

        $html = sprintf(
            '<html lang="%s"><head>%s</head><body><main>%s</main></body></html>',
            esc_attr(get_bloginfo('language')),
            $this->contentHead($post),
            $content
        );

        return (new Converter((string) get_permalink($post)))->page($html);
    }

    /**
     * The metadata a page's `<head>` would carry, for the frontmatter and
     * JSON-LD of content mode, which renders no template: Yoast's where it is
     * active, otherwise the post's own excerpt and featured image.
     */
    protected function contentHead(WP_Post $post): string
    {
        $description = has_excerpt($post) ? get_the_excerpt($post) : '';
        $image = (string) get_the_post_thumbnail_url($post, 'full');
        $schema = [];

        if (function_exists('YoastSEO') && ($meta = YoastSEO()->meta->for_post($post->ID))) {
            $description = $meta->description ?: $description;
            $image = $meta->open_graph_images ? (string) (reset($meta->open_graph_images)['url'] ?? $image) : $image;
            $schema = $meta->schema;
        }

        $head = sprintf('<title>%s</title>', esc_html(get_the_title($post)));
        $head .= sprintf('<link rel="canonical" href="%s">', esc_url((string) get_permalink($post)));
        $head .= sprintf('<meta property="article:modified_time" content="%s">', esc_attr((string) get_post_modified_time('c', true, $post)));

        if ($description !== '') {
            $head .= sprintf('<meta name="description" content="%s">', esc_attr(Format::text($description)));
        }
        if ($image !== '') {
            $head .= sprintf('<meta property="og:image" content="%s">', esc_url($image));
        }
        if (function_exists('wc_get_product') && $post->post_type === 'product' && ($product = wc_get_product($post))) {
            $head .= $this->productMeta($product);
        }
        if ($schema) {
            $head .= '<script type="application/ld+json">'.wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG).'</script>';
        }

        return $head;
    }

    /**
     * Open Graph's product tags for a WooCommerce product: the price the shop
     * displays (with or without tax, as it is set up), its currency, stock
     * status and SKU. A product with no price -- sold on quote -- has none.
     */
    protected function productMeta(\WC_Product $product): string
    {
        $tags = [
            'product:price:amount' => $product->get_price() === '' ? '' : wc_format_decimal(wc_get_price_to_display($product), wc_get_price_decimals()),
            'product:price:currency' => $product->get_price() === '' ? '' : get_woocommerce_currency(),
            'product:availability' => match ($product->get_stock_status()) {
                'instock' => 'in stock',
                'outofstock' => 'out of stock',
                'onbackorder' => 'available for order',
                default => '',
            },
            'product:retailer_item_id' => $product->get_sku(),
        ];

        $head = '';
        foreach (array_filter($tags, fn ($value) => $value !== '') as $property => $value) {
            $head .= sprintf('<meta property="%s" content="%s">', esc_attr($property), esc_attr((string) $value));
        }

        return $head;
    }

    protected function sendHeaders(): void
    {
        status_header(200);
        header('Content-Type: text/markdown; charset=utf-8');
        // The body is decoded text, so markup quoted in a page must never be sniffed as HTML.
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex');
        header(sprintf('Link: <%s>; rel="canonical"', esc_url_raw($this->pageUrl())));
        header('Content-Signal: '.Robots::contentSignal());
        header('Content-Usage: '.Robots::contentUsage());
    }

    /**
     * Cloudflare's estimate of what the response costs a model to read, which
     * agents can use to decide whether to fetch it in full.
     */
    protected function sendTokenEstimate(string $markdown): void
    {
        header(sprintf('X-Markdown-Tokens: %d', (int) ceil(mb_strlen($markdown) / 4)));
    }

    protected function isHtmlResponse(): bool
    {
        $status = http_response_code();
        if (is_int($status) && $status >= 300 && $status < 400) {
            return false;
        }

        foreach (headers_list() as $header) {
            if (stripos($header, 'content-type:') === 0) {
                return stripos($header, 'text/html') !== false;
            }
        }

        return true;
    }
}
