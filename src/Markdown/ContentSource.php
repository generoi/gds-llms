<?php

namespace GeneroWP\Llms\Markdown;

use GeneroWP\Llms\Format;
use GeneroWP\Llms\Integrations\WooCommerce;
use GeneroWP\Llms\Integrations\Yoast;
use WP_Post;

/**
 * `content` mode: a singular page's post content rendered on its own, inside
 * the minimal page the converter reads -- a `<head>` with the metadata the
 * template would have printed, the content as its `<main>`.
 */
class ContentSource
{
    public function html(WP_Post $post): string
    {
        setup_postdata($post);
        $content = (string) apply_filters('the_content', get_the_content(null, false, $post));

        /**
         * The HTML converted in `content` mode, for adding what the site's
         * template renders outside the content -- a product's price, a
         * recipe's ingredients from post meta -- without touching the HTML page.
         *
         * @param  string  $content  the rendered post content
         * @param  WP_Post  $post
         */
        $content = (string) apply_filters('gds/llms/markdown_content', $content, $post);

        return sprintf(
            '<html lang="%s"><head>%s</head><body><main>%s</main></body></html>',
            esc_attr(get_bloginfo('language')),
            $this->head($post),
            $content
        );
    }

    /**
     * The metadata a page's `<head>` would carry, for the frontmatter and
     * JSON-LD: Yoast's where it is active, otherwise the post's own excerpt and
     * featured image.
     */
    protected function head(WP_Post $post): string
    {
        $yoast = Yoast::head($post);
        $description = ($yoast['description'] ?? '') ?: (has_excerpt($post) ? get_the_excerpt($post) : '');
        $image = ($yoast['image'] ?? '') ?: (string) get_the_post_thumbnail_url($post, 'full');
        $schema = $yoast['schema'] ?? [];

        $head = sprintf('<title>%s</title>', esc_html(get_the_title($post)));
        $head .= sprintf('<link rel="canonical" href="%s">', esc_url((string) get_permalink($post)));
        $head .= sprintf('<meta property="article:modified_time" content="%s">', esc_attr((string) get_post_modified_time('c', true, $post)));

        if ($description !== '') {
            $head .= sprintf('<meta name="description" content="%s">', esc_attr(Format::text($description)));
        }
        if ($image !== '') {
            $head .= sprintf('<meta property="og:image" content="%s">', esc_url($image));
        }
        $head .= WooCommerce::productMeta($post);
        if ($schema) {
            $head .= '<script type="application/ld+json">'.wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG).'</script>';
        }

        return $head;
    }
}
