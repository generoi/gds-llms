<?php

namespace GeneroWP\Llms\Integrations;

use GeneroWP\Llms\Markdown\ArchiveSource;
use GeneroWP\Llms\Markdown\Listings;
use WP_Post;
use WP_Query;

/**
 * generoi/sage-archive-pages maps a page to a post type, and the page's
 * template lists that post type under the page's content. Content mode
 * renders no template, so the listing is built here instead -- unless the
 * content already lists, with a FacetWP or Query Loop block, which a second
 * listing would repeat.
 */
class ArchivePages
{
    public function __construct(protected Listings $listings) {}

    /**
     * The post type the page being viewed stands in for as its archive, or ''.
     */
    public function postType(): string
    {
        return is_singular() ? (string) get_query_var('mapped_post_archive') : '';
    }

    /**
     * $html -- the page's content, wrapped for the converter -- or, for a page
     * standing in for an archive whose content does not list, the listing.
     */
    public function withListing(string $html, string $url): string
    {
        if (! $postType = $this->postType()) {
            return $html;
        }

        $listing = new WP_Query;
        $listing->parse_query(['post_type' => $postType, 'paged' => max(1, (int) get_query_var('paged'))]);
        $listing->set('posts_per_page', $this->listings->perPage((int) get_option('posts_per_page'), $listing));
        $listing->get_posts();

        return $this->linksAny($html, array_slice($listing->posts, 0, 5)) ? $html : (new ArchiveSource)->html($listing, $url);
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
