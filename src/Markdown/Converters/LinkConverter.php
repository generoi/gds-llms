<?php

namespace GeneroWP\Llms\Markdown\Converters;

use League\HTMLToMarkdown\Converter\LinkConverter as BaseConverter;
use League\HTMLToMarkdown\ElementInterface;

/**
 * Link text on one line, without the whitespace around it.
 *
 * A card link wrapping an image and a title in <div>s would otherwise break
 * across paragraphs, which ends the link, and an indented template's
 * `<a> Read </a>` would read `[ Read ](url)`. Whitespace at the edges moves
 * outside the link, where it still separates it from the text around it.
 */
class LinkConverter extends BaseConverter
{
    public function convert(ElementInterface $element): string
    {
        $markdown = parent::convert($element);
        $end = strrpos($markdown, '](');

        if (! str_starts_with($markdown, '[') || $end === false) {
            return $markdown;
        }

        $text = substr($markdown, 1, $end - 1);
        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($collapsed === $text) {
            return $markdown;
        }

        return (ltrim($text) !== $text ? ' ' : '').'['.$collapsed.substr($markdown, $end).(rtrim($text) !== $text ? ' ' : '');
    }
}
