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
 * @phpstan-type Document array{title: string, summary: string, intro: string[], links: string[], sections: Section[]}
 */
class LlmsTxt
{
    public const QUERY_VAR = 'gds_llms_txt';

    public const REWRITE_VERSION = '1';

    public const REWRITE_VERSION_OPTION = 'gds_llms_txt_rewrite_version';

    public const CACHE_KEY = 'gds_llms_txt';

    public const CACHE_TTL = 12 * HOUR_IN_SECONDS;

    public const MAX_LISTED = 100;

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

        // Polylang reads the language from the URL prefix on its own, so the rule
        // only has to accept one for /fi/llms.txt to be built in Finnish.
        $prefixes = function_exists('pll_languages_list') ? (pll_languages_list() ?: []) : [];
        $prefix = $prefixes ? '(?:(?:'.implode('|', array_map(fn ($slug) => preg_quote($slug, '#'), $prefixes)).')/)?' : '';

        add_rewrite_rule('^'.$prefix.'llms\.txt$', 'index.php?'.self::QUERY_VAR.'=1', 'top');

        // mu-plugins have no activation hook, so flush once per rule revision instead.
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
        $body = $this->body($language);

        status_header(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex');

        CacheTags::send(fn () => array_merge(
            CoreTags::archive(['page', 'post', 'product']),
            CoreTags::anyTerm($this->taxonomies()),
            ...array_map([CoreTags::class, 'navigation'], array_keys($this->menus())),
        ), $this->url($language));

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
        /**
         * The whole document before it is rendered, for anything a site needs
         * to say that cannot be derived: what the business does (prepend to
         * `intro`), a section renamed, reordered, added or dropped.
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
        $links = [];
        $sections = [];

        foreach ($this->menuSections() as $index => [$loose, $groups]) {
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
            'summary' => $this->summary(),
            'intro' => $this->intro($language),
            'links' => $links,
            'sections' => array_merge($sections, $this->catalogueSections($language), $this->optionalSections()),
        ];
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
     * How to read the rest of the document, one sentence per entry.
     *
     * Everything here is derived from what is actually configured rather than
     * described by hand, so it cannot quietly become untrue as the site changes.
     *
     * @return string[]
     */
    protected function intro(string $language): array
    {
        return array_values(array_filter([
            sprintf('Written in %s.', $this->languageName($language)),
            $this->translationsSentence($language),
            $this->markdownSentence(),
            $this->commerceSentence(),
            $this->catalogueSentence(),
            $this->networkSentence(),
        ]));
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
     * Each menu as its loose links plus one section per group.
     *
     * Groups are top-level items pointing at `#` -- how a menu gives a dropdown
     * or a footer column a heading without a page behind it -- so they become
     * headings, and everything beneath them, at any depth, the links under that
     * heading. Loose links are filed under the menu's own name, which is what the
     * client called it.
     *
     * @return list<array{Section, Section[]}>
     */
    protected function menuSections(): array
    {
        $sections = [];

        foreach ($this->menus() as $menuId) {
            $object = wp_get_nav_menu_object($menuId);
            $items = wp_get_nav_menu_items($menuId) ?: [];
            $parents = array_column(array_map(fn ($item) => ['id' => $item->ID, 'parent' => (int) $item->menu_item_parent], $items), 'parent', 'id');

            $loose = [];
            $groups = [];

            foreach ($items as $item) {
                if (! $item->menu_item_parent && $this->isGroup($item)) {
                    $groups[$item->ID] = ['heading' => Format::text($item->title), 'lines' => []];

                    continue;
                }

                $line = Format::linkLine($item->title, $item->url, $this->menuItemDescription($item));
                $root = $this->rootItem((int) $item->ID, $parents);

                if (isset($groups[$root])) {
                    $groups[$root]['lines'][] = $line;
                } else {
                    $loose[] = $line;
                }
            }

            $sections[] = [
                ['heading' => $object ? Format::text($object->name) : '', 'lines' => $loose],
                array_values(array_filter($groups, fn (array $group) => $group['lines'] !== [])),
            ];
        }

        return $sections;
    }

    protected function isGroup(object $item): bool
    {
        return in_array(trim((string) $item->url), ['#', ''], true);
    }

    /**
     * @param  array<int, int>  $parents
     */
    protected function rootItem(int $itemId, array $parents): int
    {
        while (($parents[$itemId] ?? 0) !== 0) {
            $itemId = $parents[$itemId];
        }

        return $itemId;
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

        return array_values(array_filter($taxonomies, 'taxonomy_exists'));
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
                    fn (WP_Term $term) => Format::linkLine($term->name, get_term_link($term)),
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

        $summary = $frontPage > 0 ? $this->description($frontPage, false) : '';

        return $summary ?: Format::truncate(Format::text(get_bloginfo('description')));
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
