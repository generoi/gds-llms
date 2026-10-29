<?php

namespace GeneroWP\Llms\Markdown;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * A parsed page and the XPath lookups Converter and Frontmatter share.
 */
class Dom
{
    public readonly DOMDocument $doc;

    protected DOMXPath $xpath;

    public function __construct(string $html)
    {
        $this->doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // DOMDocument silently drops a whole element containing an invalid byte sequence.
        $this->doc->loadHTML('<?xml encoding="utf-8"?>'.wp_check_invalid_utf8($html, true), LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->xpath = new DOMXPath($this->doc);
    }

    public function first(string $expression, ?DOMNode $context = null): ?DOMElement
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
    public function query(string $expression, ?DOMNode $context = null): array
    {
        $nodes = $this->xpath->query($expression, $context);

        if ($nodes === false) {
            throw new \RuntimeException(sprintf('Invalid XPath expression: %s', $expression));
        }

        return array_values(array_filter(iterator_to_array($nodes), fn ($node) => $node instanceof DOMElement));
    }

    public function remove(string $expression, DOMElement $root): void
    {
        foreach ($this->query($expression, $root) as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    public function byId(string $id): ?DOMElement
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
    public function literal(string $value): string
    {
        if (! str_contains($value, "'")) {
            return "'".$value."'";
        }

        return 'concat(\''.str_replace("'", "',\"'\",'", $value).'\')';
    }
}
