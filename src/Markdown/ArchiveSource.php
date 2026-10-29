<?php

namespace GeneroWP\Llms\Markdown;

use GeneroWP\Llms\Integrations\WooCommerce;
use GeneroWP\Llms\LlmsTxt\Document;
use WP_Post;
use WP_Query;

/**
 * `content` mode for a listing -- the blog, a category or tag, a post type
 * archive, a shop -- inside the minimal page the converter reads. No template renders, so the listing is built here:
 * the title and introduction, the result count, each post as a link with
 * its description (and a product's price), then the pages.
 */
class ArchiveSource
{
    public function html(WP_Query $query, string $url): string
    {
        $object = get_queried_object();

        // The posts page in front of the blog carries the title and
        // introduction an editor wrote.
        if ($object instanceof WP_Post) {
            $title = get_the_title($object);
            $intro = (string) apply_filters('the_content', $object->post_content);
        } elseif (is_home()) {
            $title = get_bloginfo('name');
            $intro = '';
        } else {
            $title = wp_strip_all_tags(get_the_archive_title());
            $intro = (string) get_the_archive_description();
        }

        $page = max(1, (int) $query->get('paged'));
        $pages = (int) $query->max_num_pages;

        $items = '';
        foreach ($query->posts as $post) {
            if (! $post instanceof WP_Post) {
                continue;
            }

            $line = sprintf('<a href="%s">%s</a>', esc_url((string) get_permalink($post)), esc_html(get_the_title($post)));
            if ($price = WooCommerce::priceText($post)) {
                $line .= ' — '.esc_html($price);
            }
            // A password-protected post's excerpt is WordPress' "There is no excerpt" notice.
            if (! post_password_required($post) && ($description = Document::description($post->ID))) {
                $line .= ': '.esc_html($description);
            }
            $items .= '<li>'.$line.'</li>';
        }

        // The title goes in <title>: the converter makes it the H1 unless the
        // introduction already has one.
        $main = sprintf('%s<p>%s</p>', $intro, esc_html(Listings::countText((int) $query->found_posts, $page, $pages)));
        $main .= $items ? '<ul>'.$items.'</ul>' : '';

        if ($pages > 1) {
            $main .= '<p><strong>Pages</strong></p><ul>';
            for ($n = 1; $n <= $pages; $n++) {
                $main .= sprintf('<li><a href="%s">%d</a></li>', esc_url(get_pagenum_link($n)), $n);
            }
            $main .= '</ul>';
        }

        return sprintf(
            '<html lang="%s"><head><title>%s</title><link rel="canonical" href="%s"></head><body><main>%s</main></body></html>',
            esc_attr(get_bloginfo('language')),
            esc_html($title),
            esc_url($url),
            $main
        );
    }
}
