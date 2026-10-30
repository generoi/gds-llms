<?php

namespace GeneroWP\Llms\LlmsTxt;

use Genero\Sage\CacheTags\Tags\CoreTags;
use GeneroWP\Llms\CacheControl;
use GeneroWP\Llms\Integrations\CacheTags;
use GeneroWP\Llms\Integrations\Kinsta;
use GeneroWP\Llms\Integrations\Polylang;
use GeneroWP\Llms\Integrations\RankMath;
use GeneroWP\Llms\Integrations\Yoast;
use GeneroWP\Llms\Robots;

/**
 * Serves /llms.txt from a rewrite rule instead of a generated file on disk.
 *
 * Yoast's built-in llms.txt writes a physical file to get_home_path() from a
 * weekly cron, which breaks on our installs three ways: multisite blogs share
 * one docroot and so one file, atomic deploys drop anything written into the
 * release directory, and with DISABLE_WP_CRON the WP-CLI cron run resolves the
 * home path to inside WordPress core. A rewrite rule has none of those
 * problems: each blog answers on its own domain and nothing lives on disk.
 *
 * What the document says is Document's business; this class serves and caches it.
 */
class Endpoint
{
    public const QUERY_VAR = 'gds_llms_txt';

    public const REWRITE_VERSION = '1';

    public const REWRITE_VERSION_OPTION = 'gds_llms_txt_rewrite_version';

    public const CACHE_KEY = 'gds_llms_txt';

    /**
     * Every input is hooked (see register()), so this only bounds how long an
     * unhooked change -- a plugin rewriting a menu item's URL on the fly, say --
     * can survive. Finite on purpose: a transient without an expiry is
     * autoloaded into every page view, one body per language.
     */
    public const CACHE_TTL = WEEK_IN_SECONDS;

    /**
     * Options the document is built from; changing one flushes it.
     */
    public const OPTIONS = [
        'blogname',
        'blogdescription',
        'show_on_front',
        'page_on_front',
        'permalink_structure',
        'polylang',
        'woocommerce_currency',
        'woocommerce_prices_include_tax',
        'default_product_cat',
    ];

    /**
     * Whether something this request changed requires a flush on shutdown.
     * Static because the hooks are bound to the instance Plugin creates.
     */
    protected static bool $pendingFlush = false;

    protected static ?int $codeVersion = null;

    public function register(): void
    {
        // After Rank Math's own init:10 registration, so handlesLlmsTxt() can see it.
        add_action('init', [$this, 'addRewriteRule'], 20);
        add_action('wp_loaded', [$this, 'maybeFlushRewriteRules']);
        add_filter('query_vars', [$this, 'addQueryVar']);
        add_action('template_redirect', [$this, 'maybeSend'], 0);
        add_action('admin_init', [Yoast::class, 'disableLlmsTxt']);

        add_action('save_post', [$this, 'flushForNewPost'], 10, 3);
        add_action('post_updated', [$this, 'flushForUpdatedPost'], 10, 3);
        add_action('deleted_post', [$this, 'flushForPost'], 10, 2);
        add_action('added_post_meta', [$this, 'flushForPostMeta'], 10, 3);
        add_action('updated_post_meta', [$this, 'flushForPostMeta'], 10, 3);
        add_action('deleted_post_meta', [$this, 'flushForPostMeta'], 10, 3);
        add_action('saved_term', [$this, 'flushForTerm'], 10, 3);
        add_action('delete_term', [$this, 'flushForTerm'], 10, 3);
        add_action('wp_update_nav_menu', [$this, 'scheduleFlush']);
        // update_option() on an option that does not exist yet -- the first menu
        // assigned under a new theme -- goes through add_option() instead.
        add_action('added_option', [$this, 'flushForOption']);
        add_action('updated_option', [$this, 'flushForOption']);
        add_action('shutdown', [$this, 'flushPending']);
    }

    /**
     * The llms.txt URL for a language.
     */
    public static function url(string $language): string
    {
        return Polylang::homeUrl($language).'llms.txt';
    }

    public function addRewriteRule(): void
    {
        if (RankMath::handlesLlmsTxt()) {
            return;
        }

        // Polylang reads the language from the URL prefix on its own, so the rule
        // only has to accept one for /fi/llms.txt to be built in Finnish.
        $prefixes = Polylang::slugs();
        $prefix = $prefixes ? '(?:(?:'.implode('|', array_map(fn ($slug) => preg_quote($slug, '#'), $prefixes)).')/)?' : '';

        add_rewrite_rule('^'.$prefix.'llms\.txt$', 'index.php?'.self::QUERY_VAR.'=1', 'top');
    }

    /**
     * Flushed once per rule revision rather than on activation: a Composer
     * update brings new rules without activating anything.
     *
     * On wp_loaded rather than alongside add_rewrite_rule() on init: a flush
     * regenerates every rule from what has been registered so far, so flushing
     * mid-init would drop the rules of any plugin that registers later on init
     * until something flushed again.
     */
    public function maybeFlushRewriteRules(): void
    {
        if (RankMath::handlesLlmsTxt()) {
            return;
        }

        $version = self::REWRITE_VERSION.':'.implode(',', Polylang::slugs());

        if (get_option(self::REWRITE_VERSION_OPTION) !== $version) {
            flush_rewrite_rules(false);
            update_option(self::REWRITE_VERSION_OPTION, $version);
        }
    }

    /**
     * @param  string[]  $vars
     * @return string[]
     */
    public function addQueryVar(array $vars): array
    {
        $vars[] = self::QUERY_VAR;

        return $vars;
    }

    public function maybeSend(): void
    {
        if (! $this->isRequested() || RankMath::handlesLlmsTxt()) {
            return;
        }

        $language = Polylang::current();
        $response = $this->response($language);

        status_header(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex');
        header('Content-Signal: '.Robots::contentSignal());
        header('Content-Usage: '.Robots::contentUsage());
        CacheControl::send();

        CacheTags::send(fn () => [
            CoreTags::posts($response['posts']),
            CoreTags::anyTerm((new Catalogue)->taxonomies()),
            array_map([CoreTags::class, 'navigation'], array_keys((new Menus)->locations())),
            // The only two of OPTIONS sage-cachetags purges by tag; the rest are
            // rare enough to leave to the CDN's own expiry or a deploy's flush.
            CacheTags::options(['blogname', 'blogdescription']),
        ], self::url($language));

        echo $response['body'];
        exit;
    }

    /**
     * Whether the request is for llms.txt itself. The query var has to be
     * public for the rewrite rule to set it, which also lets any URL set it in
     * its query string (`/about/?gds_llms_txt=1`); only the rule's own match
     * counts.
     */
    public function isRequested(): bool
    {
        global $wp;

        return get_query_var(self::QUERY_VAR) && str_starts_with((string) $wp->matched_query, self::QUERY_VAR.'=');
    }

    public function body(string $language): string
    {
        return $this->response($language)['body'];
    }

    /**
     * The document and the posts it was built from, cached for anonymous
     * visitors.
     *
     * A logged-in request is built fresh and never stored: menus can carry items
     * only some users see -- private pages, items a menu-visibility plugin
     * restricts by role -- and caching an editor's view would publish it.
     *
     * @return array{body: string, posts: int[]}
     */
    public function response(string $language): array
    {
        $key = $this->cacheKey($language);
        $cached = is_user_logged_in() ? false : get_transient($key);

        if (is_array($cached) && isset($cached['body'], $cached['posts'])) {
            return $cached;
        }

        $document = new Document;
        $response = ['body' => $document->build($language), 'posts' => $document->posts()];

        if (! is_user_logged_in()) {
            set_transient($key, $response, self::CACHE_TTL);
        }

        return $response;
    }

    /**
     * One fixed key per language, so a flush leaves nothing behind -- a key that
     * changed on every flush would strand a body per language per edit in
     * wp_options until it expired. Keyed on the code that builds the document
     * too, so a deploy that changes it takes effect at once; that strands one
     * set per deploy, which expiry clears.
     */
    protected function cacheKey(string $language): string
    {
        return implode(':', [self::CACHE_KEY, self::codeVersion(), $language]);
    }

    /**
     * The newest modification time among the files the document is built by,
     * its bundled translations included.
     */
    protected static function codeVersion(): int
    {
        if (self::$codeVersion === null) {
            $src = dirname(__DIR__);
            $files = [
                ...(glob(__DIR__.'/*.php') ?: []),
                ...(glob($src.'/Integrations/*.php') ?: []),
                ...(glob(dirname($src).'/resources/lang/*') ?: []),
                $src.'/Format.php',
            ];
            self::$codeVersion = max(array_map(fn (string $file) => (int) filemtime($file), $files));
        }

        return self::$codeVersion;
    }

    /**
     * Drop the rendered document in every language, and Kinsta's page-cache copy
     * of it. A CDN in front is purged separately through cache tags.
     */
    public function flushCache(): void
    {
        $urls = [];
        foreach (Polylang::languages() as $language) {
            delete_transient($this->cacheKey($language));
            $urls[] = self::url($language);
        }

        Kinsta::purge($urls);
    }

    /**
     * Take /llms.txt down with the plugin. The rule is still registered for this
     * request, so it is removed before flushing or the flush would write it back;
     * forgetting the rule version makes a later reactivation flush it in again.
     * The page cache is purged too, or it would keep serving the last document.
     */
    public function deactivate(): void
    {
        global $wp_rewrite;

        foreach ($wp_rewrite->extra_rules_top as $regex => $query) {
            if (str_starts_with($query, 'index.php?'.self::QUERY_VAR.'=')) {
                unset($wp_rewrite->extra_rules_top[$regex]);
            }
        }

        flush_rewrite_rules(false);
        delete_option(self::REWRITE_VERSION_OPTION);
        $this->flushCache();
    }

    /**
     * Deactivation for a site other than the one handling the request, on a
     * network-wide deactivation. Its rules can't be rebuilt from here -- this
     * request registered its own -- so they are dropped, and WordPress
     * regenerates them, without llms.txt, on that site's next request. Its
     * languages aren't loaded here either: only the root llms.txt is purged,
     * language copies expire with the page cache.
     */
    public static function forgetSite(): void
    {
        global $wpdb;

        delete_option('rewrite_rules');
        delete_option(self::REWRITE_VERSION_OPTION);
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_'.self::CACHE_KEY.':').'%',
            $wpdb->esc_like('_transient_timeout_'.self::CACHE_KEY.':').'%',
        ));
        Kinsta::purge([home_url('/llms.txt')]);
    }

    /**
     * Flush once on shutdown however many changes the request made: a product
     * import saves thousands of posts per batch, and each flush is two deletes
     * per language.
     */
    public function scheduleFlush(): void
    {
        self::$pendingFlush = true;
    }

    public function flushPending(): void
    {
        if (self::$pendingFlush) {
            self::$pendingFlush = false;
            $this->flushCache();
        }
    }

    /**
     * Post fields the document reads: link labels and URLs, whether an item is
     * listed, and descriptions (a generated excerpt comes from the content).
     */
    public const POST_FIELDS = ['post_title', 'post_excerpt', 'post_content', 'post_status', 'post_name', 'post_parent', 'post_password', 'menu_order'];

    /**
     * Meta the document reads: Yoast's hand-written description.
     */
    public const POST_META = ['_yoast_wpseo_metadesc'];

    /**
     * Only content that can appear in the document: not revisions, autosaves
     * (every minute while someone edits) or orders and other non-public types,
     * which would otherwise flush it on every checkout.
     */
    public function flushForPost(int $postId, ?\WP_Post $post = null): void
    {
        if (! $post || wp_is_post_revision($post) || wp_is_post_autosave($post) || ! is_post_type_viewable($post->post_type)) {
            return;
        }

        $this->scheduleFlush();
    }

    /**
     * Updates are post_updated's, which knows what changed.
     */
    public function flushForNewPost(int $postId, \WP_Post $post, bool $update): void
    {
        if (! $update) {
            $this->flushForPost($postId, $post);
        }
    }

    /**
     * Only when a field the document reads changed. WooCommerce re-saves a
     * product on every sale to record its stock and modified date; that must
     * not rebuild and purge llms.txt each time.
     */
    public function flushForUpdatedPost(int $postId, \WP_Post $after, \WP_Post $before): void
    {
        foreach (self::POST_FIELDS as $field) {
            if ($after->$field !== $before->$field) {
                $this->flushForPost($postId, $after);

                return;
            }
        }
    }

    /**
     * @param  int|int[]  $metaId
     */
    public function flushForPostMeta(int|array $metaId, int $postId, string $key): void
    {
        if (in_array($key, self::POST_META, true)) {
            $this->flushForPost($postId, get_post($postId));
        }
    }

    /**
     * Only the catalogue taxonomies; menus are flushed by wp_update_nav_menu, and
     * a product import creating thousands of attribute terms should not flush.
     */
    public function flushForTerm(int $termId, int $termTaxonomyId, string $taxonomy): void
    {
        if (in_array($taxonomy, (new Catalogue)->taxonomies(), true)) {
            $this->scheduleFlush();
        }
    }

    /**
     * The options the document reads, plus the theme mods that hold which menu
     * is assigned to which location.
     */
    public function flushForOption(string $option): void
    {
        if (in_array($option, self::OPTIONS, true) || $option === 'theme_mods_'.get_stylesheet()) {
            $this->scheduleFlush();
        }
    }
}
