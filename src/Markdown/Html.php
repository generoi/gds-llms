<?php

namespace GeneroWP\Llms\Markdown;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\ParentNode;

/**
 * Parsing and the few tree edits CSS selectors don't cover.
 */
class Html
{
    public static function parse(string $html): HTMLDocument
    {
        // An invalid byte sequence would take its whole element with it.
        return HTMLDocument::createFromString(wp_check_invalid_utf8($html, true), LIBXML_NOERROR, 'UTF-8');
    }

    public static function remove(ParentNode $root, string $selector): void
    {
        foreach ($root->querySelectorAll($selector) as $element) {
            $element->remove();
        }
    }

    /**
     * The element's children in its place.
     */
    public static function unwrap(Element $element): void
    {
        $element->replaceWith(...iterator_to_array($element->childNodes));
    }

    /**
     * The element an `aria-labelledby` or `aria-controls` points at; of
     * several ids, the first names it.
     */
    public static function referenced(HTMLDocument $doc, string $ids): ?Element
    {
        $id = strtok($ids, ' ');

        return $id === false ? null : $doc->getElementById($id);
    }
}
