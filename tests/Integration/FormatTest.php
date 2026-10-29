<?php

namespace GeneroWP\Llms\Tests\Integration;

use GeneroWP\Llms\Format;
use WP_UnitTestCase;

class FormatTest extends WP_UnitTestCase
{
    /**
     * A newline inside a description would split the surrounding list in two.
     */
    public function test_text_collapses_whitespace_and_decodes_entities(): void
    {
        $this->assertSame('Tom & Jerry — together', Format::text("<p>Tom &amp; Jerry\n\u{00A0}&mdash; together</p>"));
    }

    public function test_link_label_escapes_only_square_brackets(): void
    {
        $this->assertSame('Pumps \[new\] & more.', Format::linkLabel('Pumps [new] & more.'));
    }

    public function test_link_url_encodes_characters_that_end_a_markdown_link(): void
    {
        $this->assertSame('https://example.com/a%20b%28c%29', Format::linkUrl('https://example.com/a b(c)'));
    }

    public function test_link_url_is_empty_for_errors(): void
    {
        $this->assertSame('', Format::linkUrl(new \WP_Error('nope')));
        $this->assertSame('', Format::linkUrl(false));
    }

    public function test_truncate_prefers_a_sentence_boundary(): void
    {
        $value = str_repeat('word ', 25).'ends here. '.str_repeat('more ', 20);

        $this->assertStringEndsWith('ends here.', Format::truncate($value));
    }

    public function test_truncate_falls_back_to_a_word_boundary(): void
    {
        $truncated = Format::truncate(str_repeat('word ', 50));

        $this->assertStringEndsWith('word…', $truncated);
        $this->assertLessThanOrEqual(Format::MAX_DESCRIPTION + 1, mb_strlen($truncated));
    }

    public function test_section_renders_a_heading_and_its_lines(): void
    {
        $this->assertSame("## Help\n- [FAQ](https://example.com/faq)\n", Format::section('Help', [
            Format::linkLine('FAQ', 'https://example.com/faq'),
        ]));
    }
}
