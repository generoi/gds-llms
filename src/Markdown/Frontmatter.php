<?php

namespace GeneroWP\Llms\Markdown;

use Dom\HTMLDocument;
use GeneroWP\Llms\Format;

/**
 * A page's metadata, laid out as Cloudflare's Markdown for Agents lays it out:
 * YAML frontmatter before the content, the page's JSON-LD in a fenced `json`
 * block after it.
 */
class Frontmatter
{
    /**
     * @param  string  $base  absolute URL of the page, the `url` without a canonical link
     */
    public function __construct(protected HTMLDocument $doc, protected string $base) {}

    /**
     * YAML frontmatter from the page's metadata, only for the fields it has.
     *
     * Values are JSON strings, which YAML reads as double-quoted scalars, so a
     * colon or quote in a title cannot break the block.
     */
    public function yaml(): string
    {
        $offer = $this->offer();
        $fields = [
            'title' => $this->meta('og:title') ?: Format::text($this->doc->querySelector('title')?->textContent),
            'description' => $this->meta('description') ?: $this->meta('og:description'),
            'image' => $this->meta('og:image'),
            'url' => $this->doc->querySelector('link[rel="canonical"]')?->getAttribute('href') ?: $this->base,
            'language' => $language = Format::text($this->doc->documentElement?->getAttribute('lang')),
            'translations' => $this->translations($language),
            'modified' => $this->meta('article:modified_time'),
            // Open Graph's product tags, else the page's schema.org Offer.
            'price' => $this->meta('product:price:amount') ?: $offer['price'],
            'currency' => $this->meta('product:price:currency') ?: $offer['priceCurrency'],
            'availability' => $this->meta('product:availability') ?: $offer['availability'],
            'sku' => $this->meta('product:retailer_item_id') ?: $offer['sku'],
            'add_to_cart' => $this->addToCart(),
        ];

        $lines = [];
        foreach (array_filter($fields) as $name => $value) {
            $lines[] = $name.': '.wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $lines ? "---\n".implode("\n", $lines)."\n---\n" : '';
    }

    /**
     * Every JSON-LD block on the page, re-encoded on one line each.
     *
     * @return string[]
     */
    public function jsonLd(): array
    {
        $blocks = [];
        foreach ($this->doc->querySelectorAll('script[type="application/ld+json"]') as $script) {
            $data = json_decode(trim($script->textContent), true);
            if ($data !== null) {
                $blocks[] = (string) wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        return $blocks;
    }

    /**
     * Each other language's markdown version, keyed by its hreflang, from the
     * page's `<link rel="alternate" hreflang>` tags: Polylang's own in `page`
     * mode, the same printed by the sources in `content` mode. A JSON object
     * is a YAML flow mapping, so it stays one line like the other fields.
     *
     * @return array<string, string>
     */
    protected function translations(string $language): array
    {
        $translations = [];

        foreach ($this->doc->querySelectorAll('link[rel="alternate"][hreflang]') as $link) {
            $hreflang = (string) $link->getAttribute('hreflang');
            $url = Endpoint::url((string) $link->getAttribute('href'));

            if ($hreflang !== 'x-default' && strcasecmp($hreflang, $language) !== 0 && $url !== '') {
                $translations[$hreflang] = $url;
            }
        }

        return $translations;
    }

    /**
     * The links that put the product in the cart, from the WooCommerce
     * integration's `gds-llms:add-to-cart` tags: the one URL of a simple
     * product, whose price and availability are the page's own; else each
     * option keyed by what tells it apart (a variation's attributes), with
     * its own price and availability, as a one-line mapping like the
     * translations.
     *
     * @return string|array<string, array<string, string>>
     */
    protected function addToCart(): string|array
    {
        $options = [];
        foreach ($this->doc->querySelectorAll('meta[name="gds-llms:add-to-cart"][content]') as $meta) {
            $options[(string) $meta->getAttribute('data-label')] = array_filter([
                'url' => (string) $meta->getAttribute('content'),
                'price' => (string) $meta->getAttribute('data-price'),
                'availability' => (string) $meta->getAttribute('data-availability'),
            ]);
        }

        return array_keys($options) === [''] ? ($options['']['url'] ?? '') : $options;
    }

    /**
     * The content of a `<meta>` named by `name` or `property`.
     */
    protected function meta(string $name): string
    {
        foreach ($this->doc->querySelectorAll('meta[content]') as $meta) {
            if ($meta->getAttribute('name') === $name || $meta->getAttribute('property') === $name) {
                return Format::text($meta->getAttribute('content'));
            }
        }

        return '';
    }

    /**
     * Price, currency, availability and SKU of the first schema.org Product on
     * the page, as WooCommerce and Yoast describe a product page.
     *
     * @return array{price: string, priceCurrency: string, availability: string, sku: string}
     */
    protected function offer(): array
    {
        $offer = ['price' => '', 'priceCurrency' => '', 'availability' => '', 'sku' => ''];

        foreach ($this->doc->querySelectorAll('script[type="application/ld+json"]') as $script) {
            $data = json_decode(trim($script->textContent), true);
            $nodes = is_array($data) ? ($data['@graph'] ?? (array_is_list($data) ? $data : [$data])) : [];

            foreach ($nodes as $node) {
                if (! is_array($node) || ! in_array('Product', (array) ($node['@type'] ?? []), true)) {
                    continue;
                }

                $offers = $node['offers'] ?? [];
                $first = array_is_list($offers) ? ($offers[0] ?? []) : $offers;
                // A price range (variable product) lists its bounds instead.
                $price = $first['price'] ?? $first['lowPrice'] ?? $first['priceSpecification'][0]['price'] ?? '';

                return [
                    'price' => is_scalar($price) ? (string) $price : '',
                    'priceCurrency' => (string) ($first['priceCurrency'] ?? $first['priceSpecification'][0]['priceCurrency'] ?? ''),
                    // `https://schema.org/InStock` reads better as `InStock`.
                    'availability' => basename((string) ($first['availability'] ?? '')),
                    'sku' => (string) ($node['sku'] ?? ''),
                ];
            }
        }

        return $offer;
    }
}
