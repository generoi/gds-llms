<?php

namespace GeneroWP\Llms\LlmsTxt;

use GeneroWP\Llms\Format;
use GeneroWP\Llms\Integrations\Polylang;
use WP_Term;

/**
 * A webshop's catalogue as llms.txt sections: the top level of each catalogue
 * taxonomy.
 *
 * @phpstan-import-type Section from Document
 */
class Catalogue
{
    public const MAX_LISTED = 100;

    /**
     * Landing pages linked from the intro, for the document's cache tags.
     *
     * @var int[]
     */
    protected array $posts = [];

    /**
     * @return int[]
     */
    public function posts(): array
    {
        return $this->posts;
    }

    /**
     * @return string[]
     */
    public function taxonomies(): array
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
    public function sections(string $language): array
    {
        $sections = [];

        foreach ($this->taxonomies() as $taxonomy) {
            $sections[] = [
                'heading' => $this->label($taxonomy),
                'lines' => array_map(
                    fn (WP_Term $term) => Format::linkLine($term->name, $this->termLink($term)),
                    $this->topLevelTerms($taxonomy, $language),
                ),
            ];
        }

        return $sections;
    }

    /**
     * The catalogue trees, named and linked, where a page carries the
     * taxonomy's rewrite slug. Their landing pages are otherwise only section
     * headings further down, so without this there is no link to either root.
     */
    public function sentence(): string
    {
        $links = [];
        $types = [];

        foreach ($this->taxonomies() as $taxonomy) {
            if ($page = $this->landingPage($taxonomy)) {
                $this->posts[] = $page->ID;
                $links[] = sprintf('[%s](%s)', Format::linkLabel($page->post_title), Format::linkUrl((string) get_permalink($page)));
                $types = array_merge($types, (array) get_taxonomy($taxonomy)->object_type);
            }
        }

        if (! $links) {
            return '';
        }

        $last = array_pop($links);
        // What the trees hold: WooCommerce products by default, a site's own
        // product post type when it lists its own taxonomy.
        $count = array_sum(array_map(fn (string $type) => (int) (wp_count_posts($type)->publish ?? 0), array_unique($types)));

        return sprintf(
            'The %s products are organised under %s.',
            number_format_i18n($count),
            $links ? implode(', ', $links).' and '.$last : $last,
        );
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

        if ($language && Polylang::isActive()) {
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
    protected function landingPage(string $taxonomy): ?\WP_Post
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
    protected function label(string $taxonomy): string
    {
        $object = get_taxonomy($taxonomy);

        return Format::text($this->landingPage($taxonomy)->post_title ?? ($object ? $object->labels->name : $taxonomy));
    }
}
