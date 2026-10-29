<?php

namespace GeneroWP\Llms;

use Genero\Sage\CacheTags\Tags\CoreTags;
use WP_Term;

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
 * The content differs from Yoast's too. Yoast picks five recently-modified
 * entries per post type and five terms from every taxonomy, which surfaces
 * whatever was edited last rather than what matters. Here the page list comes
 * from the site's menus -- the client's own, already-maintained information
 * architecture, in the right language, with headings they control -- and a
 * webshop catalogue is described by the top of its category tree.
 *
 * llms.txt is an orientation document, not a second sitemap. It earns its place
 * by being small enough to sit in a model's context alongside the actual
 * question, so anything countable is left to the sitemap it links.
 *
 * @phpstan-type Section array{heading: string, lines: string[]}
 * @phpstan-type Link array{title: string, url: string, description: string}
 * @phpstan-type Document array{title: string, summary: string, intro: array<string, string>, links: string[], sections: Section[]}
 */
class LlmsTxt
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

    public const MAX_LISTED = 100;

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
     * Posts the document being built was derived from, for its cache tags.
     *
     * @var int[]
     */
    protected array $posts = [];

    /**
     * Whether something this request changed requires a flush on shutdown.
     * Static because the hooks are bound to the instance Plugin creates.
     */
    protected static bool $pendingFlush = false;

    public function register(): void
    {
        // After Rank Math's own init:10 registration, so handledElsewhere() can see it.
        add_action('init', [$this, 'addRewriteRule'], 20);
        add_action('wp_loaded', [$this, 'maybeFlushRewriteRules']);
        add_filter('query_vars', [$this, 'addQueryVar']);
        add_action('template_redirect', [$this, 'maybeSend'], 0);
        add_action('admin_init', [$this, 'disableYoast']);

        add_action('save_post', [$this, 'flushForPost'], 10, 2);
        add_action('deleted_post', [$this, 'flushForPost'], 10, 2);
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
     * Rank Math instantiates RankMath\LLMS\LLMS_Txt only when its `llms-txt`
     * module is switched on, and registers the same `^llms\.txt$` rule. Where it
     * does, this stands down entirely rather than race it for the rule.
     */
    public function handledElsewhere(): bool
    {
        return class_exists('RankMath\\LLMS\\LLMS_Txt', false);
    }

    public function addRewriteRule(): void
    {
        if ($this->handledElsewhere()) {
            return;
        }

        // Polylang reads the language from the URL prefix on its own, so the rule
        // only has to accept one for /fi/llms.txt to be built in Finnish.
        $prefixes = function_exists('pll_languages_list') ? (pll_languages_list() ?: []) : [];
        $prefix = $prefixes ? '(?:(?:'.implode('|', array_map(fn ($slug) => preg_quote($slug, '#'), $prefixes)).')/)?' : '';

        add_rewrite_rule('^'.$prefix.'llms\.txt$', 'index.php?'.self::QUERY_VAR.'=1', 'top');
    }

    /**
     * mu-plugins have no activation hook, so flush once per rule revision instead.
     *
     * On wp_loaded rather than alongside add_rewrite_rule() on init: a flush
     * regenerates every rule from what has been registered so far, so flushing
     * mid-init would drop the rules of any plugin that registers later on init
     * until something flushed again.
     */
    public function maybeFlushRewriteRules(): void
    {
        if ($this->handledElsewhere()) {
            return;
        }

        $prefixes = function_exists('pll_languages_list') ? (pll_languages_list() ?: []) : [];
        $version = self::REWRITE_VERSION.':'.implode(',', $prefixes);

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
        if (! get_query_var(self::QUERY_VAR) || $this->handledElsewhere()) {
            return;
        }

        $language = $this->currentLanguage();
        $response = $this->response($language);

        status_header(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex');

        CacheTags::send(fn () => [
            CoreTags::posts($response['posts']),
            CoreTags::anyTerm($this->taxonomies()),
            array_map([CoreTags::class, 'navigation'], array_keys($this->menus())),
            // The only two of OPTIONS sage-cachetags purges by tag; the rest are
            // rare enough to leave to the CDN's own expiry or a deploy's flush.
            CacheTags::options(['blogname', 'blogdescription']),
        ], $this->url($language));

        echo $response['body'];
        exit;
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

        $this->posts = [];
        $response = ['body' => $this->build($language), 'posts' => array_values(array_unique($this->posts))];

        if (! is_user_logged_in()) {
            set_transient($key, $response, self::CACHE_TTL);
        }

        return $response;
    }

    /**
     * One fixed key per language, so a flush leaves nothing behind -- a key that
     * changed on every flush would strand a body per language per edit in
     * wp_options until it expired. Keyed on this file's mtime too, so a deploy
     * that changes how the document is built takes effect at once; that strands
     * one set per deploy, which expiry clears.
     */
    protected function cacheKey(string $language): string
    {
        return implode(':', [self::CACHE_KEY, filemtime(__FILE__), $language]);
    }

    /**
     * Drop the rendered document in every language.
     *
     * A CDN in front is purged separately through cache tags; this only stops the
     * origin handing back a stale body on the fetch that follows a purge.
     */
    public function flushCache(): void
    {
        foreach ($this->languages() as $language) {
            delete_transient($this->cacheKey($language));
        }
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
     * Only the catalogue taxonomies; menus are flushed by wp_update_nav_menu, and
     * a product import creating thousands of attribute terms should not flush.
     */
    public function flushForTerm(int $termId, int $termTaxonomyId, string $taxonomy): void
    {
        if (in_array($taxonomy, $this->taxonomies(), true)) {
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

    /**
     * Keep Yoast's own llms.txt switched off.
     *
     * Not just tidiness: nginx resolves `try_files $uri` before reaching
     * index.php, so a physical `web/llms.txt` would shadow the rewrite rule and
     * silently serve Yoast's version instead. Flipping the option also makes Yoast
     * unschedule its cron and delete its own file, since its option watcher
     * listens on `update_option_wpseo`. Admin only, where get_home_path()
     * resolves correctly and Yoast can therefore find the file it wrote.
     */
    public function disableYoast(): void
    {
        if (! function_exists('YoastSEO') || $this->handledElsewhere()) {
            return;
        }

        $options = YoastSEO()->helpers->options;

        if ($options->get('enable_llms_txt') === true) {
            $options->set('enable_llms_txt', false);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Document
    |--------------------------------------------------------------------------
    */

    public function build(string $language): string
    {
        /**
         * The whole document before it is rendered, for anything a site needs
         * to say that cannot be derived: what the business does (prepend an
         * `about` entry to `intro`), an intro sentence dropped by its key
         * (`language`, `translations`, `markdown`, `commerce`, `catalogue`,
         * `network`), a section renamed, reordered, added or dropped.
         *
         * @param  Document  $document
         * @param  string  $language  Polylang slug, or '' without Polylang.
         */
        $document = apply_filters('gds/llms_txt/document', $this->document($language), $language);

        return $this->render($document);
    }

    /**
     * @return Document
     */
    public function document(string $language): array
    {
        $summary = $this->summary();
        $links = [];
        $sections = [];

        foreach ($this->menuSections($summary) as $index => [$loose, $groups]) {
            // The first menu's loose links go in the header rather than under an
            // invented heading: llms.txt allows free markdown between the summary
            // and the first `##`, and a heading made up for them would be
            // untranslated English among the client's own labels.
            if ($index === 0) {
                $links = $loose['lines'];
            } elseif ($loose['lines']) {
                $sections[] = $loose;
            }

            $sections = array_merge($sections, $groups);
        }

        return [
            'title' => Format::text(get_bloginfo('name')),
            'summary' => $summary,
            'intro' => $this->intro($language),
            'links' => $links,
            'sections' => array_merge($this->mergeSections($sections), $this->catalogueSections($language), $this->optionalSections()),
        ];
    }

    /**
     * Sections with the same heading as one, where the first appears.
     *
     * Sites that split their audience (private / business) usually repeat that
     * split in every menu -- primary, tools, contact, footer -- and four
     * "## Business" sections read as four different things.
     *
     * @param  Section[]  $sections
     * @return Section[]
     */
    protected function mergeSections(array $sections): array
    {
        $merged = [];
        $positions = [];

        foreach ($sections as $section) {
            $position = $positions[$section['heading']] ?? null;

            if ($position === null) {
                $positions[$section['heading']] = count($merged);
                $merged[] = $section;
            } else {
                $merged[$position]['lines'] = array_merge($merged[$position]['lines'], $section['lines']);
            }
        }

        return $merged;
    }

    /**
     * @param  Document  $document
     */
    public function render(array $document): string
    {
        $header = ['# '.$document['title']];

        if ($document['summary']) {
            $header[] = '';
            $header[] = '> '.$document['summary'];
        }

        if ($intro = array_filter($document['intro'])) {
            $header[] = '';
            $header[] = implode(' ', $intro);
        }

        if ($document['links']) {
            $header[] = '';
            $header = array_merge($header, $document['links']);
        }

        $sections = array_map(
            fn (array $section) => Format::section($section['heading'], $section['lines']),
            array_filter($document['sections'], fn (array $section) => $section['lines'] !== []),
        );

        return implode("\n", [implode("\n", $header)."\n", ...$sections]);
    }

    /**
     * How to read the rest of the document, one sentence per entry, keyed so a
     * site can drop one.
     *
     * Everything here is derived from what is actually configured rather than
     * described by hand, so it cannot quietly become untrue as the site changes.
     *
     * @return array<string, string>
     */
    protected function intro(string $language): array
    {
        return array_filter([
            'language' => sprintf('Written in %s.', $this->languageName($language)),
            'translations' => $this->translationsSentence($language),
            'markdown' => $this->markdownSentence(),
            'commerce' => $this->commerceSentence(),
            'catalogue' => $this->catalogueSentence(),
            /**
             * Whether to list the other sites on the network.
             *
             * Off by default: a network is as often a set of unrelated shops,
             * or a main site nobody visits, as it is a family of sister sites
             * a reader would want pointed at.
             */
            'network' => apply_filters('gds/llms_txt/network', false) ? $this->networkSentence() : '',
        ]);
    }

    /**
     * The same document in the site's other languages, each built from that
     * language's own menus.
     */
    protected function translationsSentence(string $language): string
    {
        $links = [];

        foreach ($this->languages() as $other) {
            if ($other !== '' && $other !== $language) {
                $links[] = sprintf('[%s](%s)', $this->languageName($other), Format::linkUrl($this->url($other)));
            }
        }

        return $links ? 'Also available in '.implode(', ', $links).'.' : '';
    }

    /**
     * The llms.txt URL for a language.
     *
     * From the language's root rather than pll_home_url(), which returns the
     * language's static front page (`/fi/etusivu/`) where one is set.
     */
    protected function url(string $language): string
    {
        $object = $language && function_exists('PLL') ? PLL()->model->get_language($language) : false;

        return $object ? PLL()->links_model->home_url($object).'llms.txt' : home_url('/llms.txt');
    }

    protected function markdownSentence(): string
    {
        $structure = (string) get_option('permalink_structure');

        if ($structure === '') {
            return '';
        }

        return sprintf(
            'Every page is also available as markdown at its URL followed by `%s`.',
            str_ends_with($structure, '/') ? Markdown::SUFFIX : '.md',
        );
    }

    /**
     * What a visitor can do with a product, and on what terms -- the part an
     * assistant needs and cannot infer from a list of links.
     */
    protected function commerceSentence(): string
    {
        if (! function_exists('WC')) {
            return '';
        }

        return sprintf(
            'Products can be bought online. Prices are in %s and %s VAT.',
            get_woocommerce_currency(),
            wc_prices_include_tax() ? 'include' : 'exclude',
        );
    }

    /**
     * The catalogue trees, named and linked, where a page carries the
     * taxonomy's rewrite slug. Their landing pages are otherwise only section
     * headings further down, so without this there is no link to either root.
     */
    protected function catalogueSentence(): string
    {
        $links = [];

        foreach ($this->taxonomies() as $taxonomy) {
            if ($page = $this->taxonomyPage($taxonomy)) {
                $this->posts[] = $page->ID;
                $links[] = sprintf('[%s](%s)', Format::linkLabel($page->post_title), Format::linkUrl((string) get_permalink($page)));
            }
        }

        if (! $links) {
            return '';
        }

        $last = array_pop($links);

        return sprintf(
            'The %s products are organised under %s.',
            number_format_i18n((int) (wp_count_posts('product')->publish ?? 0)),
            $links ? implode(', ', $links).' and '.$last : $last,
        );
    }

    /**
     * Sibling sites on the same network.
     *
     * Asked whether the company sells in another country, a model reading only
     * the sections below would say no; the network knows otherwise. Read through
     * get_blog_details()/get_blog_option() rather than switch_to_blog(), which
     * would drag Polylang's per-blog static-page state into this request.
     */
    protected function networkSentence(): string
    {
        if (! is_multisite()) {
            return '';
        }

        $links = [];

        foreach (get_sites(['archived' => 0, 'deleted' => 0, 'spam' => 0]) as $site) {
            $blogId = (int) $site->blog_id;

            if ($blogId === get_current_blog_id()) {
                continue;
            }

            $details = get_blog_details($blogId);
            $name = $details ? $details->blogname : '';

            if (! $name) {
                continue;
            }

            $locale = (string) get_blog_option($blogId, 'WPLANG');

            $links[] = sprintf(
                '[%s](%s)%s',
                Format::linkLabel($name),
                Format::linkUrl(get_home_url($blogId)),
                $locale ? ' in '.$this->languageName($locale) : '',
            );
        }

        return $links ? 'Related sites: '.implode(', ', $links).'.' : '';
    }

    /*
    |--------------------------------------------------------------------------
    | Menus
    |--------------------------------------------------------------------------
    */

    /**
     * Every menu assigned to a location, keyed by location, primary first.
     *
     * Locations are named differently on nearly every site, so rather than
     * guessing which ones matter all assigned ones are used and a site removes
     * what it does not want listed. A menu assigned to two locations -- the
     * mobile navigation usually reuses the primary one -- appears once.
     *
     * @return array<string, int>
     */
    protected function menus(): array
    {
        $assigned = array_filter(get_nav_menu_locations());
        $locations = array_keys(array_intersect_key(get_registered_nav_menus(), $assigned));
        usort($locations, fn (string $a, string $b) => ($b === 'primary_navigation') <=> ($a === 'primary_navigation'));

        /**
         * The menu locations listed, in order. The first is treated as the
         * primary navigation.
         *
         * @param  string[]  $locations
         */
        $locations = (array) apply_filters('gds/llms_txt/menus', $locations);

        $menus = [];

        foreach ($locations as $location) {
            $menuId = (int) ($assigned[$location] ?? 0);

            if ($menuId && ! in_array($menuId, $menus, true)) {
                $menus[$location] = $menuId;
            }
        }

        return $menus;
    }

    /**
     * Each menu as its loose links plus one section per dropdown.
     *
     * A top-level item with children -- a dropdown, a mega menu column, a footer
     * column -- becomes a heading, with its own link (unless it is a `#`
     * placeholder) and everything beneath it at any depth as the links under it.
     * Childless top-level items are loose links, filed under the menu's own name,
     * which is what the client called it.
     *
     * Each URL is listed once, where it first appears: footers mostly repeat the
     * primary navigation, and a model gains nothing from reading it twice. A
     * description shared by several links -- typically a Yoast template, or the
     * front page's text inherited by every page without its own -- says nothing
     * about any of them and is dropped, as is one that repeats the summary.
     *
     * @return list<array{Section, Section[]}>
     */
    protected function menuSections(string $summary): array
    {
        $menus = [];
        $seen = [];

        foreach ($this->menus() as $menuId) {
            $object = wp_get_nav_menu_object($menuId);
            $children = [];

            foreach (wp_get_nav_menu_items($menuId) ?: [] as $item) {
                $children[(int) $item->menu_item_parent][] = $item;
            }

            $loose = [];
            $groups = [];

            foreach ($children[0] ?? [] as $item) {
                $links = $this->menuLinks($item, $children, $seen);

                if (isset($children[$item->ID])) {
                    $groups[] = ['heading' => Format::text($item->title), 'links' => $links];
                } else {
                    $loose = array_merge($loose, $links);
                }
            }

            $menus[] = [['heading' => $object ? Format::text($object->name) : '', 'links' => $loose], $groups];
        }

        // The summary has already said its text once.
        $descriptions = $summary !== '' ? [$summary => 1] : [];

        foreach ($menus as [$loose, $groups]) {
            foreach (array_merge([$loose], $groups) as $section) {
                foreach ($section['links'] as $link) {
                    if ($link['description'] !== '') {
                        $descriptions[$link['description']] = ($descriptions[$link['description']] ?? 0) + 1;
                    }
                }
            }
        }

        $format = fn (array $section) => [
            'heading' => $section['heading'],
            'lines' => array_map(fn (array $link) => Format::linkLine(
                $link['title'],
                $link['url'],
                ($descriptions[$link['description']] ?? 0) > 1 ? '' : $link['description'],
            ), $section['links']),
        ];

        return array_map(fn (array $menu) => [
            $format($menu[0]),
            array_values(array_filter(array_map($format, $menu[1]), fn (array $group) => $group['lines'] !== [])),
        ], $menus);
    }

    /**
     * An item's own link followed by its descendants', depth first, skipping
     * placeholders and URLs already listed.
     *
     * @param  array<int, object[]>  $children
     * @param  array<string, bool>  $seen
     * @return Link[]
     */
    protected function menuLinks(object $item, array $children, array &$seen): array
    {
        $links = [];
        $url = $this->absoluteUrl((string) $item->url);
        $key = untrailingslashit($url);

        if ($url !== '' && ! isset($seen[$key])) {
            $seen[$key] = true;
            $title = Format::text($item->title);
            $postId = $item->type === 'post_type' ? (int) $item->object_id : 0;

            if ($postId) {
                $this->posts[] = $postId;
            }

            $links[] = [
                // A label of only symbols (an `@` icon for an email link) reads as nothing.
                'title' => preg_match('/[\p{L}\p{N}]/u', $title) ? $title : Format::replace('#^(mailto:|tel:|https?://)#', '', $url),
                'url' => $url,
                'description' => $postId ? $this->description($postId) : '',
            ];
        }

        foreach ($children[$item->ID] ?? [] as $child) {
            $links = array_merge($links, $this->menuLinks($child, $children, $seen));
        }

        return $links;
    }

    /**
     * A menu item's URL made absolute, or '' when it is not a link at all: `#`
     * placeholders, in-page anchors that open a cookie dialog, empty custom links.
     */
    protected function absoluteUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '' || str_starts_with($url, '#') || str_starts_with(strtolower($url), 'javascript:')) {
            return '';
        }

        return \WP_Http::make_absolute_url($url, home_url('/'));
    }

    /*
    |--------------------------------------------------------------------------
    | Catalogue
    |--------------------------------------------------------------------------
    */

    /**
     * @return string[]
     */
    protected function taxonomies(): array
    {
        /**
         * Taxonomies listed as a catalogue, by their top level of terms.
         *
         * Only product categories by default: most sites' other public
         * taxonomies group staff, news or references, which is noise in an
         * orientation document. A site with its own product taxonomy adds it.
         *
         * @param  string[]  $taxonomies
         */
        $taxonomies = (array) apply_filters('gds/llms_txt/taxonomies', ['product_cat']);

        // A taxonomy registered without public archives (`publicly_queryable`
        // off) would be a list of links that all 404.
        return array_values(array_filter(
            $taxonomies,
            fn (string $taxonomy) => taxonomy_exists($taxonomy) && is_taxonomy_viewable($taxonomy),
        ));
    }

    /**
     * Each catalogue as its top level of terms.
     *
     * A model asked about a webshop almost always wants to know what it sells,
     * and the top level of the category tree answers that in a couple of dozen
     * lines. Going deeper is sitemap territory. Term descriptions are left out
     * too -- they usually hold landing-page marketing copy, which buries the
     * structure the tree exists to convey.
     *
     * @return Section[]
     */
    protected function catalogueSections(string $language): array
    {
        $sections = [];

        foreach ($this->taxonomies() as $taxonomy) {
            $sections[] = [
                'heading' => $this->taxonomyLabel($taxonomy),
                'lines' => array_map(
                    fn (WP_Term $term) => Format::linkLine($term->name, $this->termLink($term)),
                    $this->topLevelTerms($taxonomy, $language),
                ),
            ];
        }

        return $sections;
    }

    /**
     * @return WP_Term[]
     */
    protected function topLevelTerms(string $taxonomy, string $language): array
    {
        $args = [
            'taxonomy' => $taxonomy,
            'parent' => 0,
            'hide_empty' => true,
            'orderby' => 'name',
            'order' => 'ASC',
            'number' => self::MAX_LISTED,
            // WooCommerce's catch-all "Uncategorized" says nothing about the catalogue.
            'exclude' => $taxonomy === 'product_cat' ? [(int) get_option('default_product_cat')] : [],
        ];

        if ($language && function_exists('pll_languages_list')) {
            $args['lang'] = $language;
        }

        $terms = get_terms($args);

        if (is_wp_error($terms)) {
            throw new \RuntimeException(sprintf('Listing %s terms failed: %s', $taxonomy, $terms->get_error_message()));
        }

        return $terms;
    }

    protected function termLink(WP_Term $term): string
    {
        $link = get_term_link($term);

        if (is_wp_error($link)) {
            throw new \RuntimeException(sprintf('No link for %s term %d: %s', $term->taxonomy, $term->term_id, $link->get_error_message()));
        }

        return $link;
    }

    /**
     * The page a taxonomy is rewritten under, if the site has one.
     */
    protected function taxonomyPage(string $taxonomy): ?\WP_Post
    {
        $object = get_taxonomy($taxonomy);
        $slug = $object && is_array($object->rewrite) ? ($object->rewrite['slug'] ?? '') : '';

        return $slug ? get_page_by_path($slug) : null;
    }

    /**
     * Section heading for a taxonomy: the title of the landing page it is
     * rewritten under where there is one, because that is the name the site
     * actually uses; registered labels are often untranslated developer strings.
     */
    protected function taxonomyLabel(string $taxonomy): string
    {
        $object = get_taxonomy($taxonomy);

        return Format::text($this->taxonomyPage($taxonomy)->post_title ?? ($object ? $object->labels->name : $taxonomy));
    }

    /*
    |--------------------------------------------------------------------------
    | Content
    |--------------------------------------------------------------------------
    */

    /**
     * Where the exhaustive lists live. English throughout because the `Optional`
     * heading is fixed by the llms.txt format.
     *
     * @return Section[]
     */
    protected function optionalSections(): array
    {
        $url = $this->sitemapUrl();

        return $url ? [[
            'heading' => 'Optional',
            'lines' => [Format::linkLine('Sitemap', $url, 'Every indexable URL, split by content type.')],
        ]] : [];
    }

    protected function sitemapUrl(): string
    {
        if (defined('WPSEO_VERSION') || class_exists('RankMath', false)) {
            return home_url('/sitemap_index.xml');
        }

        return (string) get_sitemap_url('index');
    }

    /**
     * The description shown for a post, preferring the one an editor wrote.
     *
     * Yoast returns false for anything set to noindex, which conveniently doubles
     * as a filter -- content excluded from search should not be advertised here
     * either.
     *
     * Without $generated, an excerpt WordPress manufactures from the body copy is
     * not accepted: on a block-built page it is the first few headings run
     * together, which is tolerable as a link's gloss but not as the site summary.
     */
    protected function description(int $postId, bool $generated = true): string
    {
        if (function_exists('YoastSEO')) {
            $meta = YoastSEO()->meta->for_post($postId);

            if ($meta === false) {
                return '';
            }

            if (! empty($meta->description)) {
                return Format::truncate(Format::text($meta->description));
            }
        }

        if (! $generated && ! has_excerpt($postId)) {
            return '';
        }

        return Format::truncate(Format::text(get_the_excerpt($postId)));
    }

    protected function summary(): string
    {
        $frontPage = (int) get_option('page_on_front');

        if ($frontPage > 0) {
            $this->posts[] = $frontPage;
        }

        $summary = $frontPage > 0 ? $this->description($frontPage, false) : '';

        return $summary ?: $this->tagline();
    }

    /**
     * The tagline, unless it is still the one WordPress installs with.
     */
    protected function tagline(): string
    {
        $tagline = Format::truncate(Format::text(get_bloginfo('description')));
        $defaults = [__('Just another WordPress site')];

        if (is_multisite()) {
            /* translators: %s: Network title. */
            $defaults[] = sprintf(__('Just another %s site'), get_network()->site_name ?? '');
        }

        return in_array($tagline, $defaults, true) || preg_match('/^Just another .+ site$/', $tagline) ? '' : $tagline;
    }

    /*
    |--------------------------------------------------------------------------
    | Language
    |--------------------------------------------------------------------------
    */

    protected function currentLanguage(): string
    {
        if (! function_exists('pll_current_language')) {
            return '';
        }

        return (string) (pll_current_language() ?: pll_default_language());
    }

    /**
     * @return string[]
     */
    protected function languages(): array
    {
        if (! function_exists('pll_languages_list')) {
            return [''];
        }

        return pll_languages_list() ?: [''];
    }

    /**
     * The English name of a language, from a Polylang slug or a locale.
     *
     * Named from the locale rather than the slug: slugs are often country codes
     * (`se`, `ch`, `us`) that intl would read as Northern Sami, Chamorro or
     * nothing, and one language commonly appears once per market, so the region
     * is kept -- "German (Switzerland)", "German (Austria)". Polylang's own
     * `name` field is the endonym, which reads as a mistake inside the English
     * sentences above. Without the intl extension the locale itself is a
     * tolerable fallback.
     */
    protected function languageName(string $language): string
    {
        $object = $language && function_exists('PLL') ? PLL()->model->get_language($language) : false;
        $locale = $object ? $object->locale : ($language ?: get_locale());

        if (function_exists('locale_get_display_name')) {
            return locale_get_display_name($locale, 'en') ?: $locale;
        }

        return $locale;
    }
}
