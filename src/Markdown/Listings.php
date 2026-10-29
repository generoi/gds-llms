<?php

namespace GeneroWP\Llms\Markdown;

use GeneroWP\Llms\Integrations\FacetWP;
use WP_Query;

/**
 * Listings on markdown requests come in pages of up to PER_PAGE. A reader
 * gets one page and rarely follows a pager, so a listing short enough to fit
 * comes whole and a longer one in far fewer pages. The HTML page keeps its
 * own page size: these hooks are only added for a markdown request.
 */
class Listings
{
    public const PER_PAGE = 100;

    /**
     * On wp_loaded: FacetWP builds FWP()->request on init, and the queries
     * run before then -- ACF loading its post types, say -- are no listing.
     */
    public function register(): void
    {
        add_action('pre_get_posts', [$this, 'expandQuery']);
        add_filter('render_block_data', [$this, 'expandQueryBlock']);
    }

    /**
     * An archive's main query -- the blog, a category, a post type archive, a
     * shop -- or the listing FacetWP filters, which may be a block's own.
     * Before FacetWP's own pre_get_posts (999) captures it, so the result
     * count and pager FacetWP renders follow.
     */
    public function expandQuery(WP_Query $query): void
    {
        $archive = $query->is_main_query() && ($query->is_home() || $query->is_archive()) && ! $query->is_feed();

        if ($archive || FacetWP::isListing($query)) {
            $query->set('posts_per_page', $this->perPage((int) ($query->get('posts_per_page') ?: get_option('posts_per_page')), $query));
        }
    }

    /**
     * A Query Loop with a pager. One without is a fixed selection -- "the
     * three latest news" -- and keeps its size; one inheriting the main query
     * grows with it.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    public function expandQueryBlock(array $block): array
    {
        if (($block['blockName'] ?? '') === 'core/query' && empty($block['attrs']['query']['inherit']) && $this->hasPagination($block['innerBlocks'] ?? [])) {
            $block['attrs']['query']['perPage'] = $this->perPage((int) ($block['attrs']['query']['perPage'] ?? get_option('posts_per_page')), $block);
        }

        return $block;
    }

    /**
     * @param  WP_Query|array<string, mixed>  $listing
     */
    public function perPage(int $perPage, WP_Query|array $listing): int
    {
        /**
         * How many results a markdown page of a listing has: PER_PAGE, or the
         * listing's own size where that is larger or unlimited (-1). Return
         * the listing's own size to leave it as the HTML page has it.
         *
         * @param  int  $perPage
         * @param  WP_Query|array  $listing  the query, or the Query Loop block
         */
        return (int) apply_filters('gds/llms/markdown_per_page', $perPage > 0 ? max($perPage, self::PER_PAGE) : $perPage, $listing);
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    protected function hasPagination(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if (($block['blockName'] ?? '') === 'core/query-pagination' || $this->hasPagination($block['innerBlocks'] ?? [])) {
                return true;
            }
        }

        return false;
    }
}
