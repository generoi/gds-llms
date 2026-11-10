<?php

namespace GeneroWP\Llms\Markdown\Converters;

use League\HTMLToMarkdown\Converter\DivConverter as BaseConverter;
use League\HTMLToMarkdown\ElementInterface;

/**
 * A <div> is a block on the page, so it starts a paragraph too: the stock
 * converter only ends one, which glues a product card's price to the link
 * before it (`[Ring](url)120 €`). A table cell keeps its content on the row.
 */
class DivConverter extends BaseConverter
{
    public function convert(ElementInterface $element): string
    {
        $value = $element->getValue();

        return $element->isDescendantOf(['td', 'th']) ? $value : "\n\n".$value."\n\n";
    }
}
