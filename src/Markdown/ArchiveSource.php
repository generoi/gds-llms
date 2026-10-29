<?php

namespace GeneroWP\Llms\Markdown;

use Genero\Sage\CacheTags\Tags\CoreTags;
use GeneroWP\Llms\Format;
use GeneroWP\Llms\Integrations\Polylang;
use GeneroWP\Llms\Integrations\WooCommerce;
use GeneroWP\Llms\LlmsTxt\Document;
use WP_Post;
use WP_Query;

/**
 * A listing -- the blog, a category or tag, a post type archive, a shop, a
 * page standing in for one -- built from its query, since no template
 * renders: the title and introduction, the result count, each post as a link
 * with its description (and a product's price), then the pages.
 */
class ArchiveSource implements Source
{
    /**
     * @param  string  $url  the listing's own URL
     */
    public function __construct(protected WP_Query $query, protected string $url) {}

    public function url(): string
    {
        return $this->url;
    }

    public function cacheTags(): array
    {
        return [CoreTags::query($this->query), CoreTags::queriedObject()];
    }

    public function html(): string
    {
        [$title, $intro] = $this->heading();
        $page = max(1, (int) $this->query->get('paged'));
        $pages = (int) $this->query->max_num_pages;

        // The title goes in <title>: the converter makes it the H1 unless the
        // introduction already has one.
        $main = $intro.'<p>'.esc_html(Format::resultCount((int) $this->query->found_posts, $page, $pages)).'</p>';
        $main .= $this->list(array_map(fn (WP_Post $post) => $this->item($post), array_filter($this->query->posts, fn ($post) => $post instanceof WP_Post)));

        if ($pages > 1) {
            $main .= '<p><strong>'.esc_html__('Pages').'</strong></p>'.$this->list(array_map(
                fn (int $n) => sprintf('<a href="%s">%d</a>', esc_url(get_pagenum_link($n)), $n),
                range(1, $pages),
            ));
        }

        return sprintf(
            '<html lang="%s"><head><title>%s</title><link rel="canonical" href="%s">%s</head><body><main>%s</main></body></html>',
            esc_attr(get_bloginfo('language')),
            esc_html($title),
            esc_url($this->url),
            Polylang::alternateLinks(),
            $main
        );
    }

    /**
     * The title and introduction: a page in front of the listing -- the posts
     * page, a page standing in for an archive -- carries what an editor
     * wrote; an archive has its own title and description.
     *
     * @return array{string, string}
     */
    protected function heading(): array
    {
        $object = get_queried_object();

        if ($object instanceof WP_Post) {
            return [get_the_title($object), (string) apply_filters('the_content', $object->post_content)];
        }

        return is_home()
            ? [get_bloginfo('name'), '']
            : [wp_strip_all_tags(get_the_archive_title()), (string) get_the_archive_description()];
    }

    protected function item(WP_Post $post): string
    {
        $item = sprintf('<a href="%s">%s</a>', esc_url((string) get_permalink($post)), esc_html(get_the_title($post)));

        if ($price = WooCommerce::priceText($post)) {
            $item .= ' — '.esc_html($price);
        }
        // A password-protected post's excerpt is WordPress' "There is no excerpt" notice.
        if (! post_password_required($post) && ($description = Document::description($post->ID))) {
            $item .= ': '.esc_html($description);
        }

        /**
         * One post of a listing, as HTML the converter reads: its link, a
         * product's price and its description. For adding what a reader
         * should know of every post -- its date, its author, a field.
         *
         * @param  string  $item
         * @param  WP_Post  $post
         */
        return (string) apply_filters('gds/llms/markdown_listing_item', $item, $post);
    }

    /**
     * @param  string[]  $items
     */
    protected function list(array $items): string
    {
        return $items ? '<ul><li>'.implode('</li><li>', $items).'</li></ul>' : '';
    }
}
