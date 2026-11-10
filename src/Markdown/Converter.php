<?php

namespace GeneroWP\Llms\Markdown;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;
use GeneroWP\Llms\Format;
use GeneroWP\Llms\Markdown\Converters\DivConverter;
use GeneroWP\Llms\Markdown\Converters\EmphasisConverter;
use GeneroWP\Llms\Markdown\Converters\LinkConverter;
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
 *
 * Markup of particular plugins is handled by their integrations, on the
 * `gds/llms/markdown_dom` action, before these rules run.
 */
class Converter
{
    /**
     * Elements that start a line of their own, and end one.
     */
    protected const BLOCKS = 'address, article, aside, blockquote, dd, details, div, dl, dt, fieldset, figcaption, figure, footer, h1, h2, h3, h4, h5, h6, header, hr, li, main, ol, p, pre, section, summary, table, td, th, tr, ul';

    /**
     * HTML's collapsible whitespace; a no-break space is not.
     */
    protected const WHITESPACE = " \t\n\r\f";

    protected HTMLDocument $doc;

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
        $this->doc = Html::parse($html);

        $main = $this->doc->querySelector('main') ?? $this->doc->querySelector('[role="main"]') ?? $this->doc->body;

        if (! $main) {
            return '';
        }

        // Read before clean() drops every script.
        $frontmatter = new Frontmatter($this->doc, $this->base);
        $yaml = $frontmatter->yaml();
        $jsonLd = $frontmatter->jsonLd();

        $markdown = $this->toMarkdown($this->clean($main));

        if (! preg_match('/^# /m', $markdown) && ($title = $this->doc->querySelector('title'))) {
            $markdown = trim('# '.Format::text($title->textContent)."\n\n".$markdown);
        }

        if ($jsonLd) {
            $markdown .= "\n\n```json\n".implode("\n", $jsonLd)."\n```";
        }

        $markdown = trim($markdown);

        return ($yaml ? $yaml."\n" : '').($markdown === '' ? '' : $markdown."\n");
    }

    protected function clean(Element $main): string
    {
        /**
         * The page's main content before conversion, for an integration or a
         * site to adjust markup a screen reader rule cannot see through:
         * remove what is hidden by CSS alone, turn a custom element into the
         * element it stands for.
         *
         * @param  Element  $main
         */
        do_action('gds/llms/markdown_dom', $main);

        Html::remove($main, 'script, style, noscript, template, svg, canvas, nav, [aria-hidden="true"], form[role="search"]');
        $this->labelTabPanels($main);
        $this->replaceDisclosureButtons($main);
        Html::remove($main, 'button');
        $this->describeForms($main);
        Html::remove($main, 'input, select, textarea');
        $this->replaceIframes($main);
        $this->cleanImages($main);
        $this->cleanLinks($main);
        $this->removeEmptyListItems($main);
        $this->trimLineStarts($main);

        return $this->doc->saveHtml($main);
    }

    protected function toMarkdown(string $html): string
    {
        $converter = new HtmlConverter([
            'header_style' => 'atx',
            'hard_break' => true,
            // Layout wrappers (figure, section) dissolve into their text.
            'strip_tags' => true,
            'strip_placeholder_links' => true,
        ]);
        $environment = $converter->getEnvironment();
        $environment->addConverter(new TableConverter);
        $environment->addConverter(new DivConverter);
        $environment->addConverter(new LinkConverter);
        $environment->addConverter(new EmphasisConverter);

        // The converter throws on a fragment that is only a comment and leaks
        // its own XML prologue for one that is only a script; the <main> or
        // <body> serialized above wraps it.
        $markdown = $converter->convert($html);
        // The converter leaves entities encoded, so `&amp;` would reach the reader verbatim.
        $markdown = html_entity_decode($markdown, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Trailing whitespace means nothing here (hard breaks are newlines):
        // spacer paragraphs (`<p>&nbsp;</p>`) leave lines of nothing else, and
        // template whitespace moved out of a link can end a line.
        $markdown = Format::replace('/[\h\x{00A0}]+$/mu', '', $markdown);
        // A heading's template whitespace (`<h3> <a>Title</a></h3>`) widens the gap after the `#`s.
        $markdown = Format::replace('/^(#{1,6})\h{2,}/mu', '$1 ', $markdown);

        return trim(Format::replace("/\n{3,}/", "\n\n", $markdown));
    }

    /**
     * A tab panel is announced by its tab's name; the tab list itself is only
     * controls once every panel carries its name.
     */
    protected function labelTabPanels(Element $main): void
    {
        foreach ($main->querySelectorAll('[role="tabpanel"][aria-labelledby]') as $panel) {
            $label = Format::text(Html::referenced($this->doc, $panel->getAttribute('aria-labelledby'))?->textContent);

            if ($label !== '') {
                $panel->prepend($this->paragraph($this->strong($label)));
            }
        }

        Html::remove($main, '[role="tablist"]');
    }

    /**
     * An accordion toggle is the question its panel answers. Kept when it
     * controls a panel with text; any other button is an action, which
     * markdown has no use for.
     */
    protected function replaceDisclosureButtons(Element $main): void
    {
        foreach ($main->querySelectorAll('button[aria-controls]') as $button) {
            $panel = Html::referenced($this->doc, $button->getAttribute('aria-controls'));
            $label = Format::text($button->textContent);

            if ($label === '' || ! $panel || Format::text($panel->textContent) === '') {
                continue;
            }

            $button->replaceWith($button->closest('h1, h2, h3, h4, h5, h6') ? $label : $this->paragraph($this->strong($label)));
        }
    }

    /**
     * A form cannot be filled in from markdown, but a reader should know it is
     * there and what it asks for.
     */
    protected function describeForms(Element $main): void
    {
        foreach ($main->querySelectorAll('form') as $form) {
            $name = Format::text($form->getAttribute('aria-label'))
                ?: Format::text(Html::referenced($this->doc, (string) $form->getAttribute('aria-labelledby'))?->textContent);

            $fields = [];
            foreach ($form->querySelectorAll('label, legend') as $label) {
                $text = Format::text($label->textContent);
                if ($text !== '' && ! in_array($text, $fields, true)) {
                    $fields[] = $text;
                }
            }

            /* translators: %s: form name */
            $note = $this->paragraph($this->strong($name ? sprintf(__('Form: %s', 'gds-llms'), $name) : __('Form', 'gds-llms')));
            // One sentence for translators, with the link wherever their language puts it.
            /* translators: %s: a link reading "the web page" */
            [$before, $after] = explode('%s', __('(fill it in on %s)', 'gds-llms'), 2) + ['', ''];
            $link = $this->doc->createElement('a');
            $link->setAttribute('href', $this->base);
            $link->append(__('the web page', 'gds-llms'));
            $note->append(' '.$before, $link, $after.($fields ? '. '.__('Fields:', 'gds-llms') : ''));

            $list = null;
            if ($fields) {
                $list = $this->doc->createElement('ul');
                foreach ($fields as $field) {
                    $item = $this->doc->createElement('li');
                    $item->append($field);
                    $list->append($item);
                }
            }

            $form->replaceWith(...array_filter([$note, $list]));
        }
    }

    /**
     * An embed's content is out of reach, but its title and address are not.
     */
    protected function replaceIframes(Element $main): void
    {
        foreach ($main->querySelectorAll('iframe') as $iframe) {
            $src = $iframe->getAttribute('src') ?: $iframe->getAttribute('data-src');

            if (! $src || str_starts_with($src, 'about:')) {
                $iframe->remove();

                continue;
            }

            $title = Format::text($iframe->getAttribute('title'));
            $link = $this->doc->createElement('a');
            $link->setAttribute('href', $this->absolute($src));
            /* translators: %s: title of an embedded map, video or app */
            $link->append($title ? sprintf(__('Embedded: %s', 'gds-llms'), $title) : __('Embedded content', 'gds-llms'));
            $iframe->replaceWith($this->paragraph($link));
        }
    }

    /**
     * `alt=""` marks an image decorative; one without alt has no name to
     * announce either. Lazy loaders keep the real address in `data-src`.
     */
    protected function cleanImages(Element $main): void
    {
        foreach ($main->querySelectorAll('img') as $img) {
            $src = (string) $img->getAttribute('src');
            if ($src === '' || str_starts_with($src, 'data:')) {
                $src = (string) $img->getAttribute('data-src');
            }

            if (Format::text($img->getAttribute('alt')) === '' || $src === '') {
                $img->remove();

                continue;
            }

            $img->setAttribute('src', $this->absolute($src));
        }
    }

    protected function cleanLinks(Element $main): void
    {
        foreach ($main->querySelectorAll('a[href]') as $link) {
            $href = trim((string) $link->getAttribute('href'));

            // In-page anchors and `#` placeholders lead nowhere outside the page.
            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) {
                Html::unwrap($link);

                continue;
            }

            $link->setAttribute('href', $this->absolute($href));

            if (Format::text($link->textContent) === '' && ! $link->querySelector('img')) {
                $label = Format::text($link->getAttribute('aria-label'));
                $label === '' ? $link->remove() : $link->append($label);

                continue;
            }

            // A heading inside a link renders as `[ ## Title ](url)`: link the
            // heading's text instead.
            $headings = $link->querySelectorAll('h1, h2, h3, h4, h5, h6');
            foreach ($headings as $heading) {
                $inner = $this->doc->createElement('a');
                $inner->setAttribute('href', (string) $link->getAttribute('href'));
                $inner->append(...iterator_to_array($heading->childNodes));
                $heading->append($inner);
            }
            if ($headings->length) {
                Html::unwrap($link);
            }
        }
    }

    /**
     * A list item left with nothing to announce -- a placeholder a script
     * fills in (FacetWP's facets), or one that only held a control removed
     * above -- reads as a bare bullet; a list of nothing but those, as nothing.
     */
    protected function removeEmptyListItems(Element $main): void
    {
        foreach ($main->querySelectorAll('li') as $item) {
            if (Format::text($item->textContent) === '' && ! $item->querySelector('img')) {
                $item->remove();
            }
        }

        foreach ($main->querySelectorAll('ul, ol') as $list) {
            if (! $list->firstElementChild) {
                $list->remove();
            }
        }
    }

    /**
     * A browser renders no whitespace where a line starts: the template's
     * indentation before a card's price, or after a `<br>`, is not on the
     * page. The converter collapses it to a space but keeps it, which indents
     * the line in the markdown.
     */
    protected function trimLineStarts(Element $main): void
    {
        foreach ($main->querySelectorAll(self::BLOCKS.', br') as $element) {
            if ($element->closest('pre')) {
                continue;
            }
            if ($element->localName !== 'br') {
                $this->trimLeading($element->firstChild);
            }
            $this->trimLeading($element->nextSibling);
        }
    }

    /**
     * Strips whitespace from `$node` on, into inline elements, up to the
     * first text or image that renders.
     */
    protected function trimLeading(?Node $node): void
    {
        for (; $node; $node = $node->nextSibling) {
            if ($node instanceof Text) {
                $node->data = ltrim($node->data, self::WHITESPACE);
                if ($node->data !== '') {
                    return;
                }
            } elseif ($node instanceof Element) {
                if ($node->matches(self::BLOCKS.', br, img')) {
                    return;
                }
                $this->trimLeading($node->firstChild);
                if (trim($node->textContent, self::WHITESPACE) !== '' || $node->querySelector('img')) {
                    return;
                }
            }
        }
    }

    protected function absolute(string $url): string
    {
        return WP_Http::make_absolute_url($url, $this->base);
    }

    protected function strong(string $text): Element
    {
        $strong = $this->doc->createElement('strong');
        $strong->append($text);

        return $strong;
    }

    protected function paragraph(Node|string $child): Element
    {
        $p = $this->doc->createElement('p');
        $p->append($child);

        return $p;
    }
}
