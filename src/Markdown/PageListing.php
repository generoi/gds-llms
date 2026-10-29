<?php

namespace GeneroWP\Llms\Markdown;

use WP_Post;
use WP_Query;

/**
 * A page whose template lists posts under its content -- one standing in for
 * a post type archive, say. Content mode renders no template, so a page that
 * says what it lists (`gds/llms/markdown_listing`) becomes that listing
 * instead, unless its content already lists them with a FacetWP or Query Loop
 * block, which a second listing would repeat.
 */
class PageListing
{
    public function __construct(protected Listings $listings) {}

    public function source(ContentSource $page, WP_Post $post): Source
    {
        /**
         * What a page lists beneath its content, as WP_Query arguments, when
         * its template renders a listing that `content` mode would miss.
         * sage-archive-pages answers this for the pages it maps to a post
         * type: `['post_type' => 'case']`.
         *
         * Paged like the page itself, and sized by `gds/llms/markdown_per_page`.
         *
         * @param  array<string, mixed>|null  $args  null: the page lists nothing
         * @param  WP_Post  $post  the page
         */
        $args = apply_filters('gds/llms/markdown_listing', null, $post);

        if (! is_array($args)) {
            return $page;
        }

        $listing = new WP_Query;
        $listing->parse_query($args + ['paged' => max(1, (int) get_query_var('paged'))]);
        $listing->set('posts_per_page', $this->listings->perPage((int) ($args['posts_per_page'] ?? get_option('posts_per_page')), $listing));
        $listing->get_posts();

        return $this->linksAny($page->html(), array_slice($listing->posts, 0, 5)) ? $page : new ArchiveSource($listing, $page->url());
    }

    /**
     * @param  array<int, WP_Post|int>  $posts
     */
    protected function linksAny(string $html, array $posts): bool
    {
        foreach ($posts as $post) {
            if (str_contains($html, 'href="'.esc_url((string) get_permalink($post)).'"')) {
                return true;
            }
        }

        return false;
    }
}
