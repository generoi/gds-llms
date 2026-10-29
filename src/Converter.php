<?php

namespace GeneroWP\Llms;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;
use WP_Http;

/**
 * Turns a rendered page into markdown, keeping what a screen reader would
 * announce and dropping what it would skip.
 *
 * Accessibility markup is the one convention every site already has to get
 * right, so it is what decides: an `aria-hidden` icon or an `alt=""` image is
 * decorative, an accordion toggle names the panel it controls, a tab names its
 * panel. A block whose markdown comes out wrong is usually failing screen
 * readers the same way, and the fix belongs in the block.
 */
class Converter
{
    protected DOMDocument $doc;

    protected DOMXPath $xpath;

    /**
     * @param  string  $base  absolute URL of the page, which relative links resolve against
     */
    public function __construct(protected string $base) {}

    /**
     * Convert a whole page: its `<main>` (or `role="main"`), else its `<body>`.
     *
     * Laid out as Cloudflare's Markdown for Agents lays out a page: YAML
     * frontmatter from the page's metadata, the content, then the page's JSON-LD
     * in a fenced `json` block. The page's `<title>` becomes the H1 when the
     * main content has none.
     */
    public function page(string $html): string
    {
        $this->load($html);

        $main = $this->first('//main') ?? $this->first('//*[@role="main"]') ?? $this->first('//body');

        if (! $main) {
            return '';
        }

        // Read before clean() drops every script.
        $frontmatter = $this->frontmatter();
        $jsonLd = $this->jsonLd();

        $markdown = $this->toMarkdown($this->clean($main));

        if (! preg_match('/^# /m', $markdown) && ($title = $this->first('//title'))) {
            $markdown = trim('# '.Format::text($title->textContent)."\n\n".$markdown);
        }

        if ($jsonLd) {
            $markdown .= "\n\n```json\n".implode("\n", $jsonLd)."\n```";
        }

        $markdown = trim($markdown);

        return ($frontmatter ? $frontmatter."\n" : '').($markdown === '' ? '' : $markdown."\n");
    }

    /**
     * YAML frontmatter from the page's metadata, only for the fields it has.
     *
     * Values are JSON strings, which YAML reads as double-quoted scalars, so a
     * colon or quote in a title cannot break the block.
     */
    protected function frontmatter(): string
    {
        $offer = $this->offer();
        $fields = [
            'title' => $this->meta('og:title') ?: Format::text($this->first('//title')?->textContent),
            'description' => $this->meta('description') ?: $this->meta('og:description'),
            'image' => $this->meta('og:image'),
            'url' => $this->first('//link[@rel="canonical"]')?->getAttribute('href') ?: $this->base,
            'language' => Format::text($this->first('//html')?->getAttribute('lang')),
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
     * The content of a `<meta>` named by `name` or `property`.
     */
    protected function meta(string $name): string
    {
        $meta = $this->first(sprintf('//meta[@name=%1$s or @property=%1$s]', $this->literal($name)));

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

        foreach ($this->query('//script[@type="application/ld+json"]') as $script) {
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

    /**
     * Every JSON-LD block on the page, re-encoded on one line each.
     *
     * @return string[]
     */
    protected function jsonLd(): array
    {
        $blocks = [];
        foreach ($this->query('//script[@type="application/ld+json"]') as $script) {
            $data = json_decode(trim($script->textContent), true);
            if ($data !== null) {
                $blocks[] = (string) wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        return $blocks;
    }

    protected function load(string $html): void
    {
        $this->doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // DOMDocument silently drops a whole element containing an invalid byte sequence.
        $this->doc->loadHTML('<?xml encoding="utf-8"?>'.wp_check_invalid_utf8($html, true), LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->xpath = new DOMXPath($this->doc);
    }

    protected function clean(DOMElement $root): string
    {
        $this->remove('.//script|.//style|.//noscript|.//template|.//svg|.//canvas|.//nav|.//*[@aria-hidden="true"]|.//form[@role="search"]', $root);
        $this->labelTabPanels($root);
        $this->replaceDisclosureButtons($root);
        $this->remove('.//button', $root);
        $this->describeForms($root);
        $this->remove('.//input|.//select|.//textarea', $root);
        $this->replaceIframes($root);
        $this->cleanImages($root);
        $this->cleanLinks($root);

        $html = '';
        foreach ($root->childNodes as $child) {
            $html .= $this->doc->saveHTML($child);
        }

        return $html;
    }

    protected function toMarkdown(string $html): string
    {
        $converter = new HtmlConverter([
            'header_style' => 'atx',
            'hard_break' => true,
            // Layout wrappers (div, figure, section) dissolve into their text.
            'strip_tags' => true,
            'strip_placeholder_links' => true,
        ]);
        $converter->getEnvironment()->addConverter(new TableConverter);

        // Wrapped, because the converter throws on a fragment that is only a
        // comment and leaks its own XML prologue for one that is only a script.
        $markdown = $converter->convert('<div>'.$html.'</div>');
        // The converter leaves entities encoded, so `&amp;` would reach the reader verbatim.
        $markdown = html_entity_decode($markdown, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Spacer paragraphs (`<p>&nbsp;</p>`) leave lines of nothing but whitespace.
        $markdown = Format::replace('/^[\h\x{00A0}]+$/mu', '', $markdown);

        return trim(Format::replace("/\n{3,}/", "\n\n", $markdown));
    }

    /**
     * A tab panel is announced by its tab's name; the tab list itself is only
     * controls once every panel carries its name.
     */
    protected function labelTabPanels(DOMElement $root): void
    {
        foreach ($this->query('.//*[@role="tabpanel"][@aria-labelledby]', $root) as $panel) {
            $tab = $this->byId($panel->getAttribute('aria-labelledby'));
            $label = $tab ? Format::text($tab->textContent) : '';

            if ($label !== '') {
                $panel->insertBefore($this->paragraph($this->strong($label)), $panel->firstChild);
            }
        }

        $this->remove('.//*[@role="tablist"]', $root);
    }

    /**
     * An accordion toggle is the question its panel answers. Kept when it
     * controls a panel with text; any other button is an action, which
     * markdown has no use for.
     */
    protected function replaceDisclosureButtons(DOMElement $root): void
    {
        foreach ($this->query('.//button[@aria-controls]', $root) as $button) {
            $panel = $this->byId($button->getAttribute('aria-controls'));
            $label = Format::text($button->textContent);

            if ($label === '' || ! $panel || Format::text($panel->textContent) === '') {
                continue;
            }

            $inHeading = (bool) $this->first('ancestor::*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]', $button);
            $button->parentNode?->replaceChild($inHeading ? $this->doc->createTextNode($label) : $this->paragraph($this->strong($label)), $button);
        }
    }

    /**
     * A form cannot be filled in from markdown, but a reader should know it is
     * there and what it asks for.
     */
    protected function describeForms(DOMElement $root): void
    {
        foreach ($this->query('.//form', $root) as $form) {
            $name = Format::text($form->getAttribute('aria-label'))
                ?: Format::text($this->byId($form->getAttribute('aria-labelledby'))?->textContent);

            // Gravity Forms' honeypot is a labelled field hidden from people by CSS alone.
            $labels = $this->query('.//label[not(ancestor::*[contains(concat(" ", @class, " "), " gform_validation_container ")])]|.//legend', $form);
            $fields = [];
            foreach ($labels as $label) {
                $text = Format::text($label->textContent);
                if ($text !== '' && ! in_array($text, $fields, true)) {
                    $fields[] = $text;
                }
            }

            $note = $this->paragraph($this->strong($name ? sprintf('Form: %s', $name) : 'Form'));
            $note->appendChild($this->doc->createTextNode(' (fill it in on '));
            $link = $this->doc->createElement('a', 'the web page');
            $link->setAttribute('href', $this->base);
            $note->appendChild($link);
            $note->appendChild($this->doc->createTextNode($fields ? '). Fields:' : ')'));

            $replacement = $this->doc->createDocumentFragment();
            $replacement->appendChild($note);

            if ($fields) {
                $list = $this->doc->createElement('ul');
                foreach ($fields as $field) {
                    $list->appendChild($this->doc->createElement('li'))->appendChild($this->doc->createTextNode($field));
                }
                $replacement->appendChild($list);
            }

            $form->parentNode?->replaceChild($replacement, $form);
        }
    }

    /**
     * An embed's content is out of reach, but its title and address are not.
     */
    protected function replaceIframes(DOMElement $root): void
    {
        foreach ($this->query('.//iframe', $root) as $iframe) {
            $src = $iframe->getAttribute('src') ?: $iframe->getAttribute('data-src');
            $replacement = null;

            if ($src !== '' && ! str_starts_with($src, 'about:')) {
                $title = Format::text($iframe->getAttribute('title'));
                $link = $this->doc->createElement('a');
                $link->setAttribute('href', $this->absolute($src));
                $link->appendChild($this->doc->createTextNode($title ? sprintf('Embedded: %s', $title) : 'Embedded content'));
                $replacement = $this->paragraph($link);
            }

            $replacement ? $iframe->parentNode?->replaceChild($replacement, $iframe) : $iframe->parentNode?->removeChild($iframe);
        }
    }

    /**
     * `alt=""` marks an image decorative; one without alt has no name to
     * announce either. Lazy loaders keep the real address in `data-src`.
     */
    protected function cleanImages(DOMElement $root): void
    {
        foreach ($this->query('.//img', $root) as $img) {
            $src = $img->getAttribute('src');
            if ($src === '' || str_starts_with($src, 'data:')) {
                $src = $img->getAttribute('data-src');
            }

            if (Format::text($img->getAttribute('alt')) === '' || $src === '') {
                $img->parentNode?->removeChild($img);

                continue;
            }

            $img->setAttribute('src', $this->absolute($src));
        }
    }

    protected function cleanLinks(DOMElement $root): void
    {
        foreach ($this->query('.//a[@href]', $root) as $link) {
            $href = trim($link->getAttribute('href'));

            // In-page anchors and `#` placeholders lead nowhere outside the page.
            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) {
                $this->unwrap($link);

                continue;
            }

            $link->setAttribute('href', $this->absolute($href));

            if (Format::text($link->textContent) === '' && ! $this->first('.//img', $link)) {
                $label = Format::text($link->getAttribute('aria-label'));
                $label === '' ? $link->parentNode?->removeChild($link) : $link->appendChild($this->doc->createTextNode($label));

                continue;
            }

            // A heading inside a link renders as `[ ## Title ](url)`: link the
            // heading's text instead.
            $headings = $this->query('.//h1|.//h2|.//h3|.//h4|.//h5|.//h6', $link);
            foreach ($headings as $heading) {
                $inner = $this->doc->createElement('a');
                $inner->setAttribute('href', $link->getAttribute('href'));
                while ($heading->firstChild) {
                    $inner->appendChild($heading->firstChild);
                }
                $heading->appendChild($inner);
            }
            if ($headings) {
                $this->unwrap($link);
            }
        }
    }

    protected function absolute(string $url): string
    {
        return WP_Http::make_absolute_url($url, $this->base);
    }

    protected function strong(string $text): DOMElement
    {
        $strong = $this->doc->createElement('strong');
        $strong->appendChild($this->doc->createTextNode($text));

        return $strong;
    }

    protected function paragraph(DOMNode $child): DOMElement
    {
        $p = $this->doc->createElement('p');
        $p->appendChild($child);

        return $p;
    }

    protected function unwrap(DOMNode $node): void
    {
        $parent = $node->parentNode;
        if (! $parent) {
            return;
        }

        while ($node->firstChild) {
            $parent->insertBefore($node->firstChild, $node);
        }
        $parent->removeChild($node);
    }

    protected function byId(string $id): ?DOMElement
    {
        if ($id === '') {
            return null;
        }

        // aria-labelledby may list several ids; the first names the element.
        $id = strtok($id, ' ');

        return $this->first(sprintf('//*[@id=%s]', $this->literal((string) $id)));
    }

    /**
     * An XPath string literal for any value, quotes included.
     */
    protected function literal(string $value): string
    {
        if (! str_contains($value, "'")) {
            return "'".$value."'";
        }

        return 'concat(\''.str_replace("'", "',\"'\",'", $value).'\')';
    }

    protected function first(string $expression, ?DOMNode $context = null): ?DOMElement
    {
        $node = $this->query($expression, $context)[0] ?? null;

        return $node instanceof DOMElement ? $node : null;
    }

    /**
     * Matching elements, as a list detached from the live result so the tree
     * can be changed while iterating.
     *
     * @return DOMElement[]
     */
    protected function query(string $expression, ?DOMNode $context = null): array
    {
        $nodes = $this->xpath->query($expression, $context);

        if ($nodes === false) {
            throw new \RuntimeException(sprintf('Invalid XPath expression: %s', $expression));
        }

        return array_values(array_filter(iterator_to_array($nodes), fn ($node) => $node instanceof DOMElement));
    }

    protected function remove(string $expression, DOMElement $root): void
    {
        foreach ($this->query($expression, $root) as $node) {
            $node->parentNode?->removeChild($node);
        }
    }
}
