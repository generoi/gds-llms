<?php

namespace GeneroWP\Llms\Markdown\Converters;

use League\HTMLToMarkdown\Converter\EmphasisConverter as BaseConverter;
use League\HTMLToMarkdown\ElementInterface;

/**
 * Emphasis inside the same emphasis -- a Yoast FAQ question, which is a
 * <strong>, with a bolded word -- is printed once: nested, the inner `**`
 * would close the outer one (`**What is **925** silver?**`).
 */
class EmphasisConverter extends BaseConverter
{
    public function convert(ElementInterface $element): string
    {
        $same = $this->getNormTag($element) === 'strong' ? ['strong', 'b'] : ['em', 'i'];

        return $element->isDescendantOf($same) ? $element->getValue() : parent::convert($element);
    }
}
