<?php

namespace GeneroWP\Llms\Integrations;

use WP_Post;

class WooCommerce
{
    /**
     * What a visitor can do with a product, and on what terms -- the part an
     * assistant needs and cannot infer from a list of links.
     */
    public static function commerceSentence(): string
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
     * Open Graph's product tags for a product: the price the shop displays
     * (with or without tax, as it is set up), its currency, stock status and
     * SKU. A product with no price -- sold on quote -- has none, and anything
     * that is not a product has no tags at all.
     */
    public static function productMeta(WP_Post $post): string
    {
        if ($post->post_type !== 'product' || ! function_exists('wc_get_product') || ! ($product = wc_get_product($post))) {
            return '';
        }

        $tags = [
            'product:price:amount' => $product->get_price() === '' ? '' : wc_format_decimal(wc_get_price_to_display($product), wc_get_price_decimals()),
            'product:price:currency' => $product->get_price() === '' ? '' : get_woocommerce_currency(),
            'product:availability' => match ($product->get_stock_status()) {
                'instock' => 'in stock',
                'outofstock' => 'out of stock',
                'onbackorder' => 'available for order',
                default => '',
            },
            'product:retailer_item_id' => $product->get_sku(),
        ];

        $head = '';
        foreach (array_filter($tags, fn ($value) => $value !== '') as $property => $value) {
            $head .= sprintf('<meta property="%s" content="%s">', esc_attr($property), esc_attr((string) $value));
        }

        return $head;
    }
}
