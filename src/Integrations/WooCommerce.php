<?php

namespace GeneroWP\Llms\Integrations;

use Dom\Element;
use GeneroWP\Llms\Format;
use GeneroWP\Llms\Markdown\Html;
use WP_Post;

class WooCommerce
{
    public static function register(): void
    {
        add_action('gds/llms/markdown_dom', [self::class, 'clean']);
        add_filter('gds/llms/markdown_content', [self::class, 'productDetails'], 10, 2);
    }

    /**
     * The links that put the product in the cart, keyed by what tells them
     * apart: '' for a simple product, a variation's attributes, a grouped
     * product's child. Only what can go in as it is: in stock, and for a
     * variation, with no "any" attribute left to choose.
     *
     * Built on the permalink, as add_to_cart_url() would build them on the
     * markdown URL being served.
     *
     * @return array<string, string>
     */
    protected static function addToCartLinks(\WC_Product $product): array
    {
        $buyable = fn ($item) => $item instanceof \WC_Product && $item->is_purchasable() && $item->is_in_stock();
        $url = fn (int $id) => add_query_arg('add-to-cart', $id, $product->get_permalink());
        $links = [];

        if ($product->is_type('variable')) {
            foreach ($product->get_children() as $id) {
                $variation = wc_get_product($id);
                if ($variation instanceof \WC_Product_Variation && $buyable($variation) && ! in_array('', $variation->get_variation_attributes(), true)) {
                    $links[Format::text(wc_get_formatted_variation($variation, true))] = $url($id);
                }
            }
        } elseif ($product->is_type('grouped')) {
            foreach ($product->get_children() as $id) {
                $child = wc_get_product($id);
                if ($buyable($child) && $child->supports('ajax_add_to_cart')) {
                    $links[Format::text($child->get_name())] = $url($id);
                }
            }
        } elseif ($buyable($product) && $product->supports('ajax_add_to_cart')) {
            $links[''] = $url($product->get_id());
        }

        return $links;
    }

    /**
     * A listing's add-to-cart buttons, one per product; the product's own
     * markdown has its links in the frontmatter.
     */
    public static function clean(Element $main): void
    {
        Html::remove($main, 'a.add_to_cart_button');
    }

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
     * A product's visible attributes ("Material: Bronze"), which WooCommerce
     * shows in the product page's Additional information tab rather than in
     * the post content, appended to the content. Unhook it to drop or replace
     * the list.
     */
    public static function productDetails(string $content, WP_Post $post): string
    {
        if ($post->post_type !== 'product' || ! function_exists('wc_get_product') || ! ($product = wc_get_product($post))) {
            return $content;
        }

        $items = '';
        foreach ($product->get_attributes() as $attribute) {
            $value = $attribute->get_visible() ? $product->get_attribute($attribute->get_name()) : '';

            if ($value !== '') {
                $items .= sprintf('<li><strong>%s:</strong> %s</li>', esc_html(wc_attribute_label($attribute->get_name(), $product)), esc_html($value));
            }
        }

        return $items ? $content.sprintf('<h2>%s</h2><ul>%s</ul>', esc_html__('Additional information', 'woocommerce'), $items) : $content;
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

        foreach (self::addToCartLinks($product) as $label => $url) {
            $head .= sprintf('<meta name="gds-llms:add-to-cart" content="%s" data-label="%s">', esc_url($url), esc_attr((string) $label));
        }

        return $head;
    }
}
