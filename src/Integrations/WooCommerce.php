<?php

namespace GeneroWP\Llms\Integrations;

use GeneroWP\Llms\Format;
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
            wc_prices_include_tax()
                /* translators: %s: currency code, e.g. EUR */
                ? __('Products can be bought online. Prices are in %s and include VAT.', 'gds-llms')
                /* translators: %s: currency code, e.g. EUR */
                : __('Products can be bought online. Prices are in %s and exclude VAT.', 'gds-llms'),
            get_woocommerce_currency(),
        );
    }

    /**
     * A product's price as the shop displays it (with or without tax, as it
     * is set up) for a listing line: "129,00 €", or WooCommerce's own range
     * for a variable product. '' for anything else, and for a product sold on
     * quote.
     *
     * Built from WooCommerce's formatters rather than get_price_html(), whose
     * sale markup adds "Original price was: … Current price is: …" for screen
     * readers.
     */
    public static function priceText(WP_Post $post): string
    {
        if ($post->post_type !== 'product' || ! function_exists('wc_get_product') || ! ($product = wc_get_product($post)) || $product->get_price() === '') {
            return '';
        }

        if ($product instanceof \WC_Product_Variable) {
            $min = (string) $product->get_variation_price('min', true);
            $max = (string) $product->get_variation_price('max', true);

            return Format::text($min === $max ? wc_price((float) $min) : wc_format_price_range($min, $max));
        }

        return Format::text(wc_price(wc_get_price_to_display($product)));
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
