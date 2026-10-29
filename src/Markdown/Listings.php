<?php

namespace GeneroWP\Llms\Markdown;

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

    public static function register(): void
    {
        add_action('pre_get_posts', [self::class, 'expandMainQuery']);
        add_filter('render_block_data', [self::class, 'expandQueryBlock']);
    }

    /**
     * An archive's main query: the blog, a category or tag, a post type
     * archive, a shop.
     */
    public static function expandMainQuery(WP_Query $query): void
    {
        if ($query->is_main_query() && ($query->is_home() || $query->is_archive()) && ! $query->is_feed()) {
            self::expand($query);
        }
    }

    public static function expand(WP_Query $query): void
    {
        $perPage = (int) ($query->get('posts_per_page') ?: get_option('posts_per_page'));

        if ($perPage > 0 && $perPage < self::PER_PAGE) {
            $query->set('posts_per_page', self::PER_PAGE);
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
    public static function expandQueryBlock(array $block): array
    {
        if (($block['blockName'] ?? '') !== 'core/query' || ! empty($block['attrs']['query']['inherit']) || ! self::hasPagination($block['innerBlocks'] ?? [])) {
            return $block;
        }

        $perPage = (int) ($block['attrs']['query']['perPage'] ?? get_option('posts_per_page'));

        if ($perPage > 0 && $perPage < self::PER_PAGE) {
            $block['attrs']['query']['perPage'] = self::PER_PAGE;
        }

        return $block;
    }

    /**
     * "64 results, page 1 of 8." -- one page of a listing cannot say how long
     * the listing is.
     */
    public static function countText(int $total, int $page, int $pages): string
    {
        $text = sprintf('%d %s', $total, $total === 1 ? 'result' : 'results');

        if ($pages > 1) {
            $text .= sprintf(', page %d of %d', $page, $pages);
        }

        return $text.'.';
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    protected static function hasPagination(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if (($block['blockName'] ?? '') === 'core/query-pagination' || self::hasPagination($block['innerBlocks'] ?? [])) {
                return true;
            }
        }

        return false;
    }
}
