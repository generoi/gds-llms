<?php

namespace GeneroWP\Llms\Markdown;

use DOMDocument;
use DOMElement;
use DOMNode;
use GeneroWP\Llms\Format;
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
    protected Dom $dom;

    protected DOMDocument $doc;

    /**
     * @param  string  $base  absolute URL of the page, which relative links resolve against
     */
    public function __construct(protected string $base) {}

    /**
     * Convert a whole page: its `<main>` (or `role="main"`), else its `<body>`,
     * between the page's frontmatter and its JSON-LD. The page's `<title>`
     * becomes the H1 when the main content has none.
     */
    public function page(string $html): string
    {
        $this->dom = new Dom($html);
        $this->doc = $this->dom->doc;

        $main = $this->dom->first('//main') ?? $this->dom->first('//*[@role="main"]') ?? $this->dom->first('//body');

        if (! $main) {
            return '';
        }

        // Read before clean() drops every script.
        $frontmatter = new Frontmatter($this->dom, $this->base);
        $yaml = $frontmatter->yaml();
        $jsonLd = $frontmatter->jsonLd();

        $markdown = $this->toMarkdown($this->clean($main));

        if (! preg_match('/^# /m', $markdown) && ($title = $this->dom->first('//title'))) {
            $markdown = trim('# '.Format::text($title->textContent)."\n\n".$markdown);
        }

        if ($jsonLd) {
            $markdown .= "\n\n```json\n".implode("\n", $jsonLd)."\n```";
        }

        $markdown = trim($markdown);

        return ($yaml ? $yaml."\n" : '').($markdown === '' ? '' : $markdown."\n");
    }

    protected function clean(DOMElement $root): string
    {
        $this->dom->remove('.//script|.//style|.//noscript|.//template|.//svg|.//canvas|.//nav|.//*[@aria-hidden="true"]|.//form[@role="search"]', $root);
        $this->labelTabPanels($root);
        $this->replaceDisclosureButtons($root);
        $this->dom->remove('.//button', $root);
        $this->describeForms($root);
        $this->dom->remove('.//input|.//select|.//textarea', $root);
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
        foreach ($this->dom->query('.//*[@role="tabpanel"][@aria-labelledby]', $root) as $panel) {
            $tab = $this->dom->byId($panel->getAttribute('aria-labelledby'));
            $label = $tab ? Format::text($tab->textContent) : '';

            if ($label !== '') {
                $panel->insertBefore($this->paragraph($this->strong($label)), $panel->firstChild);
            }
        }

        $this->dom->remove('.//*[@role="tablist"]', $root);
    }

    /**
     * An accordion toggle is the question its panel answers. Kept when it
     * controls a panel with text; any other button is an action, which
     * markdown has no use for.
     */
    protected function replaceDisclosureButtons(DOMElement $root): void
    {
        foreach ($this->dom->query('.//button[@aria-controls]', $root) as $button) {
            $panel = $this->dom->byId($button->getAttribute('aria-controls'));
            $label = Format::text($button->textContent);

            if ($label === '' || ! $panel || Format::text($panel->textContent) === '') {
                continue;
            }

            $inHeading = (bool) $this->dom->first('ancestor::*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]', $button);
            $button->parentNode?->replaceChild($inHeading ? $this->doc->createTextNode($label) : $this->paragraph($this->strong($label)), $button);
        }
    }

    /**
     * A form cannot be filled in from markdown, but a reader should know it is
     * there and what it asks for.
     */
    protected function describeForms(DOMElement $root): void
    {
        foreach ($this->dom->query('.//form', $root) as $form) {
            $name = Format::text($form->getAttribute('aria-label'))
                ?: Format::text($this->dom->byId($form->getAttribute('aria-labelledby'))?->textContent);

            // Gravity Forms' honeypot is a labelled field hidden from people by CSS alone.
            $labels = $this->dom->query('.//label[not(ancestor::*[contains(concat(" ", @class, " "), " gform_validation_container ")])]|.//legend', $form);
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
        foreach ($this->dom->query('.//iframe', $root) as $iframe) {
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
        foreach ($this->dom->query('.//img', $root) as $img) {
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
        foreach ($this->dom->query('.//a[@href]', $root) as $link) {
            $href = trim($link->getAttribute('href'));

            // In-page anchors and `#` placeholders lead nowhere outside the page.
            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) {
                $this->unwrap($link);

                continue;
            }

            $link->setAttribute('href', $this->absolute($href));

            if (Format::text($link->textContent) === '' && ! $this->dom->first('.//img', $link)) {
                $label = Format::text($link->getAttribute('aria-label'));
                $label === '' ? $link->parentNode?->removeChild($link) : $link->appendChild($this->doc->createTextNode($label));

                continue;
            }

            // A heading inside a link renders as `[ ## Title ](url)`: link the
            // heading's text instead.
            $headings = $this->dom->query('.//h1|.//h2|.//h3|.//h4|.//h5|.//h6', $link);
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
}
