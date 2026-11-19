<?php

namespace GeneroWP\Llms\Markdown;

use GeneroWP\Llms\Integrations\FacetWP;
use WP_Query;

/**
 * A reader gets one page and rarely follows a pager, so a markdown listing
 * of up to PER_PAGE results comes whole.
 *
 * An archive pages by its path (`/page/2/`), and each HTML page advertises
 * its markdown at that path plus `md/`, so an archive's markdown keeps the
 * HTML page size past its first page: page N is the same results either way,
 * and none is missing. Its first page lists everything when that fits.
 * Listings that page by query string -- one FacetWP filters, a Query Loop --
 * have no markdown URL per page and come in pages of up to PER_PAGE.
 *
 * The HTML page keeps its own page size: these hooks are only added for a
 * markdown request.
 */
class Listings
{
    public const PER_PAGE = 100;

    /**
     * The query var holding the size an archive's first page is re-run with.
     */
    protected const WHOLE = 'gds_llms_whole';

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
        $size = (int) ($query->get('posts_per_page') ?: get_option('posts_per_page'));

        if ($archive) {
            // The re-run of a first page that fits: after the site's own
            // pre_get_posts, which may set its size again.
            if ($whole = (int) $query->get(self::WHOLE)) {
                $query->set('posts_per_page', $whole);
            } elseif ((int) $query->get('paged') <= 1) {
                $this->wholeIfItFits($query, $size);
            }
        } elseif (FacetWP::isListing($query)) {
            $query->set('posts_per_page', $this->perPage($size, $query));
        }
    }

    /**
     * Once the first page has run at the HTML size, which counts the
     * results, run it again at perPage() when they all fit: two queries for
     * a short listing, one for a long one, whose count isn't known before.
     */
    protected function wholeIfItFits(WP_Query $query, int $size): void
    {
        $whole = $this->perPage($size, $query);

        if ($size <= 0 || $whole <= $size) {
            return;
        }

        $rerun = function (array $posts, WP_Query $ran) use ($query, $whole, &$rerun): array {
            if ($ran !== $query) {
                return $posts;
            }
            remove_filter('the_posts', $rerun);

            if ($query->max_num_pages <= 1 || $query->found_posts > $whole) {
                return $posts;
            }
            $query->set(self::WHOLE, $whole);

            return $query->get_posts();
        };

        add_filter('the_posts', $rerun, 10, 2);
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
         * listing's own size where that is larger or unlimited (-1). For an
         * archive, how many it may have to come whole on one page; a longer
         * one keeps the HTML page size. Return the listing's own size to
         * leave it as the HTML page has it.
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
