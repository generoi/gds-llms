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
 * from the site's navigation -- the client's own, already-maintained
 * information architecture, in the right language, with headings they
 * control -- and a webshop catalogue is described by the top of its category
 * tree.
 *
 * llms.txt is an orientation document, not a second sitemap. It earns its place
 * by being small enough to sit in a model's context alongside the actual
 * question, so anything countable is left to the sitemap it links.
 */
class LlmsTxt
{
    public const QUERY_VAR = 'gds_llms_txt';

    public const REWRITE_VERSION = '1';

    public const REWRITE_VERSION_OPTION = 'gds_llms_txt_rewrite_version';

    public const CACHE_KEY = 'gds_llms_txt';

    public const CACHE_TTL = 12 * HOUR_IN_SECONDS;

    public const MAX_LISTED = 100;

    /**
     * @var array<string, array{groups: array<int, array{title: string, links: string[]}>, ungrouped: string[]}>
     */
    protected array $menus = [];

    public function register(): void
    {
        // After Rank Math's own init:10 registration, so handledElsewhere() can see it.
        add_action('init', [$this, 'addRewriteRule'], 20);
        add_filter('query_vars', [$this, 'addQueryVar']);
        add_action('template_redirect', [$this, 'maybeSend'], 0);
        add_action('admin_init', [$this, 'disableYoast']);

        foreach (['save_post', 'deleted_post', 'saved_term', 'delete_term', 'wp_update_nav_menu'] as $hook) {
            add_action($hook, [$this, 'flushCache']);
        }
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

        add_rewrite_rule('^llms\.txt$', 'index.php?'.self::QUERY_VAR.'=1', 'top');

        // mu-plugins have no activation hook, so flush once per rule revision instead.
        if (get_option(self::REWRITE_VERSION_OPTION) !== self::REWRITE_VERSION) {
            flush_rewrite_rules(false);
            update_option(self::REWRITE_VERSION_OPTION, self::REWRITE_VERSION);
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

        $body = $this->body($this->currentLanguage());

        status_header(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex');

        CacheTags::send(fn () => array_merge(
            CoreTags::archive(['page', 'post', 'product']),
            CoreTags::anyTerm($this->catalogueTaxonomies()),
            ...array_map([CoreTags::class, 'navigation'], $this->menuLocations()),
        ), home_url('/llms.txt'));

        echo $body;
        exit;
    }

    public function body(string $language): string
    {
        $body = get_transient(self::CACHE_KEY.':'.$language);

        if (! is_string($body) || $body === '') {
            $body = $this->build($language);
            set_transient(self::CACHE_KEY.':'.$language, $body, self::CACHE_TTL);
        }

        return $body;
    }

    /**
     * Drop the rendered body whenever anything it is built from changes.
     *
     * A CDN in front is purged separately through cache tags; this only stops the
     * origin handing back a stale body on the fetch that follows a purge.
     */
    public function flushCache(): void
    {
        foreach ($this->languages() as $language) {
            delete_transient(self::CACHE_KEY.':'.$language);
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
        $this->menus = [];

        $sections = array_merge(
            [$this->headerSection($language)],
            $this->navigationSections(),
            $this->catalogueSections($language),
            [$this->optionalSection()],
        );

        return implode("\n", array_filter($sections));
    }

    /**
     * Title, summary, orientation, and any primary nav links outside a group.
     *
     * The stray top-level links go here rather than under an invented heading:
     * llms.txt allows free markdown between the summary and the first `##`, and
     * any heading made up for them would be untranslated English sitting among
     * the client's own labels.
     */
    protected function headerSection(string $language): string
    {
        $lines = ['# '.Format::text(get_bloginfo('name'))];

        if ($summary = $this->summary()) {
            $lines[] = '';
            $lines[] = '> '.$summary;
        }

        $lines[] = '';
        $lines[] = Format::text(apply_filters('gds/llms_txt/intro', $this->intro($language), $language));

        if ($links = $this->menu($this->primaryMenuLocation())['ungrouped']) {
            $lines[] = '';
            $lines = array_merge($lines, $links);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * How to read the rest of the document.
     *
     * Everything here is derived from what is actually configured rather than
     * described by hand, so it cannot quietly become untrue as the site changes.
     * The one thing that cannot be derived -- what the business does -- is left
     * to the `gds/llms_txt/about` filter and omitted by default, because the
     * summary above already answers it from the front page.
     */
    protected function intro(string $language): string
    {
        return implode(' ', array_filter([
            (string) apply_filters('gds/llms_txt/about', '', $language),
            sprintf('Written in %s.', $this->languageName($language)),
            $this->markdownSentence(),
            $this->commerceSentence(),
            $this->catalogueSentence(),
            $this->networkSentence(),
        ]));
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

        foreach ($this->catalogueTaxonomies() as $taxonomy) {
            if ($page = $this->taxonomyPage($taxonomy)) {
                $links[] = sprintf('[%s](%s)', Format::linkLabel($page->post_title), Format::linkUrl(get_permalink($page)));
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
                $locale ? sprintf(' (in %s)', $this->languageName($locale)) : '',
            );
        }

        return $links ? 'Related sites: '.implode(', ', $links).'.' : '';
    }

    /*
    |--------------------------------------------------------------------------
    | Navigation
    |--------------------------------------------------------------------------
    */

    protected function primaryMenuLocation(): string
    {
        return (string) apply_filters('gds/llms_txt/primary_menu', 'primary_navigation');
    }

    /**
     * Menus rendered after the primary navigation, each as one section.
     *
     * Footer menus are where the answers to most customer questions live --
     * delivery terms, privacy, support -- and none of it is reachable from the
     * primary navigation, so every registered footer location is included.
     *
     * @return string[]
     */
    protected function secondaryMenuLocations(): array
    {
        $locations = array_filter(
            array_keys(get_registered_nav_menus()),
            fn (string $location) => str_contains($location, 'footer'),
        );

        return (array) apply_filters('gds/llms_txt/secondary_menus', array_values($locations));
    }

    /**
     * @return string[]
     */
    protected function menuLocations(): array
    {
        return array_merge([$this->primaryMenuLocation()], $this->secondaryMenuLocations());
    }

    /**
     * The primary navigation, one section per top-level group, then the
     * secondary menus.
     *
     * The menu is the only place on the site where somebody has already decided
     * which pages matter and how they relate. Groups are `custom` items pointing
     * at `#`, so they become headings; anything with a real URL becomes a link.
     *
     * @return string[]
     */
    protected function navigationSections(): array
    {
        $sections = [];

        foreach ($this->menu($this->primaryMenuLocation())['groups'] as $group) {
            if ($group['links']) {
                $sections[] = Format::section($group['title'], $group['links']);
            }
        }

        foreach ($this->secondaryMenuLocations() as $location) {
            if ($section = $this->secondaryMenuSection($location)) {
                $sections[] = $section;
            }
        }

        return $sections;
    }

    /**
     * A whole menu as one section, named after the menu itself -- footer menus
     * are usually flat, so there are no group items to take headings from, and
     * the menu's own name is what the client called it.
     */
    protected function secondaryMenuSection(string $location): string
    {
        $menuId = get_nav_menu_locations()[$location] ?? 0;
        $object = $menuId ? wp_get_nav_menu_object($menuId) : null;

        if (! $object) {
            return '';
        }

        $parsed = $this->menu($location);
        $links = array_merge($parsed['ungrouped'], ...array_column($parsed['groups'], 'links'));

        return $links ? Format::section($object->name, $links) : '';
    }

    /**
     * @return array{groups: array<int, array{title: string, links: string[]}>, ungrouped: string[]}
     */
    protected function menu(string $location): array
    {
        if (isset($this->menus[$location])) {
            return $this->menus[$location];
        }

        $menu = ['groups' => [], 'ungrouped' => []];
        $menuId = get_nav_menu_locations()[$location] ?? 0;
        $items = $menuId ? (wp_get_nav_menu_items($menuId) ?: []) : [];

        foreach ($items as $item) {
            $isGroup = in_array(trim($item->url), ['#', ''], true);

            if (! $item->menu_item_parent && $isGroup) {
                $menu['groups'][$item->ID] = ['title' => $item->title, 'links' => []];

                continue;
            }

            $link = Format::linkLine($item->title, $item->url, $this->menuItemDescription($item));

            if ($item->menu_item_parent && isset($menu['groups'][$item->menu_item_parent])) {
                $menu['groups'][$item->menu_item_parent]['links'][] = $link;

                continue;
            }

            $menu['ungrouped'][] = $link;
        }

        return $this->menus[$location] = $menu;
    }

    protected function menuItemDescription(object $item): string
    {
        return $item->type === 'post_type' && $item->object_id
            ? $this->description((int) $item->object_id)
            : '';
    }

    /*
    |--------------------------------------------------------------------------
    | Catalogue
    |--------------------------------------------------------------------------
    */

    /**
     * @return string[]
     */
    protected function catalogueTaxonomies(): array
    {
        $taxonomies = (array) apply_filters('gds/llms_txt/catalogue_taxonomies', ['product_cat']);

        return array_values(array_filter($taxonomies, 'taxonomy_exists'));
    }

    /**
     * Each catalogue as its top level of categories.
     *
     * A model asked about a webshop almost always wants to know what it sells,
     * and the top level of the category tree answers that in a couple of dozen
     * lines. Going deeper is sitemap territory. Term descriptions are left out
     * too -- they usually hold landing-page marketing copy, which buries the
     * structure the tree exists to convey.
     *
     * @return string[]
     */
    protected function catalogueSections(string $language): array
    {
        $sections = [];

        foreach ($this->catalogueTaxonomies() as $taxonomy) {
            $terms = $this->topLevelTerms($taxonomy, $language);

            if ($terms) {
                $sections[] = Format::section($this->taxonomyLabel($taxonomy), array_map(
                    fn (WP_Term $term) => Format::linkLine($term->name, get_term_link($term)),
                    $terms,
                ));
            }
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
        ];

        if ($language && function_exists('pll_languages_list')) {
            $args['lang'] = $language;
        }

        $terms = get_terms($args);

        return is_array($terms) ? $terms : [];
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
     * Section heading for a taxonomy.
     *
     * Prefers the title of the landing page the taxonomy is rewritten under,
     * because that is the name the site actually calls the section; registered
     * labels are often untranslated developer strings.
     */
    protected function taxonomyLabel(string $taxonomy): string
    {
        $object = get_taxonomy($taxonomy);
        $label = $this->taxonomyPage($taxonomy)->post_title ?? ($object ? $object->labels->name : $taxonomy);

        return (string) apply_filters('gds/llms_txt/section_label', $label, $taxonomy);
    }

    /*
    |--------------------------------------------------------------------------
    | Content
    |--------------------------------------------------------------------------
    */

    /**
     * Where the exhaustive lists live. English throughout because the `Optional`
     * heading is fixed by the llms.txt format.
     */
    protected function optionalSection(): string
    {
        $url = $this->sitemapUrl();

        if (! $url) {
            return '';
        }

        return Format::section('Optional', [
            Format::linkLine('Sitemap', $url, 'Every indexable URL, split by content type.'),
        ]);
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

        $summary = $frontPage > 0 ? $this->description($frontPage, false) : '';
        $summary = $summary ?: Format::truncate(Format::text(get_bloginfo('description')));

        return (string) apply_filters('gds/llms_txt/summary', $summary);
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
     * Polylang's own `name` field is the endonym -- "Suomi", "Svenska" -- which
     * reads as a mistake inside the English sentences above. Both `fi` and
     * `sv_SE` are valid language tags, so intl resolves either; without the
     * extension the tag itself is a tolerable fallback.
     */
    protected function languageName(string $language): string
    {
        $language = $language ?: (string) get_bloginfo('language');

        if (function_exists('locale_get_display_language')) {
            return locale_get_display_language($language, 'en') ?: $language;
        }

        return $language;
    }
}
