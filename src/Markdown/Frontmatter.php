<?php

namespace GeneroWP\Llms\Markdown;

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
    public function __construct(protected Dom $dom, protected string $base) {}

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
            'title' => $this->meta('og:title') ?: Format::text($this->dom->first('//title')?->textContent),
            'description' => $this->meta('description') ?: $this->meta('og:description'),
            'image' => $this->meta('og:image'),
            'url' => $this->dom->first('//link[@rel="canonical"]')?->getAttribute('href') ?: $this->base,
            'language' => Format::text($this->dom->first('//html')?->getAttribute('lang')),
            'modified' => $this->meta('article:modified_time'),
            // Open Graph's product tags, else the page's schema.org Offer.
            'price' => $this->meta('product:price:amount') ?: $offer['price'],
            'currency' => $this->meta('product:price:currency') ?: $offer['priceCurrency'],
            'availability' => $this->meta('product:availability') ?: $offer['availability'],
            'sku' => $this->meta('product:retailer_item_id') ?: $offer['sku'],
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
        foreach ($this->dom->query('//script[@type="application/ld+json"]') as $script) {
            $data = json_decode(trim($script->textContent), true);
            if ($data !== null) {
                $blocks[] = (string) wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        return $blocks;
    }

    /**
     * The content of a `<meta>` named by `name` or `property`.
     */
    protected function meta(string $name): string
    {
        $meta = $this->dom->first(sprintf('//meta[@name=%1$s or @property=%1$s]', $this->dom->literal($name)));

        return Format::text($meta?->getAttribute('content'));
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

        foreach ($this->dom->query('//script[@type="application/ld+json"]') as $script) {
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
