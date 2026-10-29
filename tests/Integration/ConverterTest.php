<?php

namespace GeneroWP\Llms\Tests\Integration;

use GeneroWP\Llms\Markdown\Converter;
use WP_UnitTestCase;

class ConverterTest extends WP_UnitTestCase
{
    protected function page(string $main, string $head = '<title>Page title</title>', string $lang = ''): string
    {
        return (new Converter('https://example.com/page/'))->page(
            "<!doctype html><html lang=\"$lang\"><head>$head</head><body><header><a href=\"/\">Logo</a></header><main>$main</main><footer>Footer text</footer></body></html>"
        );
    }

    /**
     * The converted body, without the frontmatter.
     */
    protected function convert(string $main, string $head = '<title>Page title</title>'): string
    {
        return (string) preg_replace('/\A---\n.*?\n---\n\n?/s', '', $this->page($main, $head));
    }

    /**
     * FacetWP prints an empty placeholder per facet and fills it with
     * JavaScript; a list of them is a list of bullets with nothing to say.
     */
    public function test_empty_list_items_are_dropped(): void
    {
        $markdown = $this->convert('<ul class="filters"><li class="filter"><div class="facetwp-facet facetwp-type-dropdown"></div></li><li class="filter"> </li></ul><ul><li>Pumps</li><li><img src="/a.jpg" alt="Valve"></li><li><select><option>Any</option></select></li></ul>');

        $this->assertDoesNotMatchRegularExpression('/^[-*]\s*$/m', $markdown);
        $this->assertStringContainsString("- Pumps\n- ![Valve](https://example.com/a.jpg)", $markdown);
    }

    /**
     * Polylang's hreflang links, as page mode finds them and content mode's
     * sources print them: each other language's markdown URL, not itself, not
     * x-default.
     */
    public function test_frontmatter_links_the_translations_markdown(): void
    {
        $markdown = $this->page(
            '<p>Hei</p>',
            '<title>Meistä</title>'
            .'<link rel="alternate" hreflang="fi" href="https://example.com/fi/meista/">'
            .'<link rel="alternate" hreflang="sv-SE" href="https://example.com/sv/om-oss/">'
            .'<link rel="alternate" hreflang="en-GB" href="https://example.com/about/">'
            .'<link rel="alternate" hreflang="x-default" href="https://example.com/about/">',
            'fi',
        );

        $this->assertStringContainsString(
            'translations: {"sv-SE":"https://example.com/sv/om-oss/md/","en-GB":"https://example.com/about/md/"}',
            $markdown,
        );
    }

    public function test_frontmatter_carries_the_page_metadata(): void
    {
        $markdown = $this->page(
            '<h1>Hello</h1>',
            '<title>Pumps: the guide</title><meta name="description" content="All about &quot;pumps&quot;."><meta property="og:image" content="https://example.com/a.jpg"><link rel="canonical" href="https://example.com/pumps/"><meta property="article:modified_time" content="2026-10-01T10:00:00+00:00">',
            'fi'
        );

        $this->assertStringStartsWith(implode("\n", [
            '---',
            'title: "Pumps: the guide"',
            'description: "All about \"pumps\"."',
            'image: "https://example.com/a.jpg"',
            'url: "https://example.com/pumps/"',
            'language: "fi"',
            'modified: "2026-10-01T10:00:00+00:00"',
            '---',
            '',
            '# Hello',
        ]), $markdown);
    }

    public function test_frontmatter_has_only_the_fields_the_page_has(): void
    {
        $markdown = $this->page('<h1>Hello</h1>');

        $this->assertStringStartsWith("---\ntitle: \"Page title\"\nurl: \"https://example.com/page/\"\n---\n", $markdown);
    }

    public function test_frontmatter_has_product_fields_from_open_graph(): void
    {
        $markdown = $this->page('<h1>Pump</h1>', '<title>Pump</title><meta property="product:price:amount" content="129.00"><meta property="product:price:currency" content="EUR"><meta property="product:availability" content="in stock"><meta property="product:retailer_item_id" content="P-1">');

        $this->assertStringContainsString("price: \"129.00\"\ncurrency: \"EUR\"\navailability: \"in stock\"\nsku: \"P-1\"\n---", $markdown);
    }

    /**
     * WooCommerce's own structured data, as Yoast merges it into one graph.
     */
    public function test_frontmatter_falls_back_to_the_schema_org_offer(): void
    {
        $graph = wp_json_encode(['@context' => 'https://schema.org', '@graph' => [
            ['@type' => 'WebPage', 'name' => 'Pump'],
            ['@type' => 'Product', 'sku' => 'P-1', 'offers' => [[
                '@type' => 'Offer', 'price' => '129.00', 'priceCurrency' => 'EUR', 'availability' => 'https://schema.org/InStock',
            ]]],
        ]]);
        $markdown = $this->page('<h1>Pump</h1>', '<title>Pump</title><script type="application/ld+json">'.$graph.'</script>');

        $this->assertStringContainsString("price: \"129.00\"\ncurrency: \"EUR\"\navailability: \"InStock\"\nsku: \"P-1\"\n---", $markdown);
    }

    public function test_a_product_without_a_price_has_no_price_fields(): void
    {
        $graph = wp_json_encode(['@type' => 'Product', 'name' => 'Window', 'offers' => []]);
        $markdown = $this->page('<h1>Window</h1>', '<title>Window</title><script type="application/ld+json">'.$graph.'</script>');

        $this->assertStringNotContainsString('price:', $markdown);
    }

    public function test_json_ld_is_appended_in_a_fenced_block(): void
    {
        $markdown = $this->page(
            '<p>Body</p><script type="application/ld+json">{"@type": "Product", "name": "Pump"}</script>',
            '<title>T</title><script type="application/ld+json">{"@context":"https://schema.org","@type":"Organization"}</script><script type="application/ld+json">not json</script>'
        );

        $this->assertStringEndsWith("Body\n\n```json\n{\"@context\":\"https://schema.org\",\"@type\":\"Organization\"}\n{\"@type\":\"Product\",\"name\":\"Pump\"}\n```\n", $markdown);
    }

    public function test_only_the_main_content_is_converted(): void
    {
        $markdown = $this->convert('<h1>Hello</h1><p>Some <strong>bold</strong> text and a <a href="https://example.com/x">link</a>.</p><ul><li>One</li></ul>');

        $this->assertStringStartsWith("# Hello\n\n", $markdown);
        $this->assertStringContainsString('**bold**', $markdown);
        $this->assertStringContainsString('[link](https://example.com/x)', $markdown);
        $this->assertStringContainsString('- One', $markdown);
        $this->assertStringNotContainsString('Logo', $markdown);
        $this->assertStringNotContainsString('Footer text', $markdown);
    }

    public function test_the_document_title_stands_in_for_a_missing_h1(): void
    {
        $this->assertStringStartsWith("# Page title\n\n## Section", $this->convert('<h2>Section</h2>'));
    }

    public function test_role_main_and_then_the_body_are_fallbacks(): void
    {
        $converter = new Converter('https://example.com/');

        $roleMain = $converter->page('<body><div role="main"><p>Inside</p></div><p>Outside</p></body>');
        $this->assertStringContainsString('Inside', $roleMain);
        $this->assertStringNotContainsString('Outside', $roleMain);
        $this->assertStringContainsString('Outside', $converter->page('<body><p>Outside</p></body>'));
    }

    /**
     * The converter throws on a fragment that is only a comment, and leaks its
     * XML prologue for one that is only a script.
     */
    public function test_content_without_text_converts_to_nothing(): void
    {
        $this->assertSame("# Page title\n", $this->convert('<!-- just a comment -->'));
        $this->assertSame("# Page title\n", $this->convert('<script>var x;</script>'));
    }

    public function test_navigation_scripts_and_hidden_decoration_are_dropped(): void
    {
        $markdown = $this->convert('<nav aria-label="Breadcrumb"><a href="/">Home</a></nav><p>Body<span aria-hidden="true">→</span></p><script>alert(1)</script>');

        $this->assertStringContainsString('Body', $markdown);
        $this->assertStringNotContainsString('Home', $markdown);
        $this->assertStringNotContainsString('→', $markdown);
        $this->assertStringNotContainsString('alert', $markdown);
    }

    /**
     * core/accordion: the question is the toggle button inside the heading.
     */
    public function test_accordion_questions_are_kept(): void
    {
        $markdown = $this->convert(
            '<h3><button aria-expanded="false" aria-controls="p1">What is it?<span aria-hidden="true">+</span></button></h3>'
            .'<div id="p1" role="region" hidden><p>The answer.</p></div>'
            .'<div><button aria-expanded="false" aria-controls="p2">Not in a heading?</button><div id="p2"><p>Second answer.</p></div></div>'
        );

        $this->assertStringContainsString("### What is it?\n\nThe answer.", $markdown);
        $this->assertStringContainsString("**Not in a heading?**\n\nSecond answer.", $markdown);
    }

    public function test_buttons_that_disclose_nothing_are_dropped(): void
    {
        $markdown = $this->convert('<p>Body</p><button>Previous slide</button><button aria-controls="qr">Share</button><div id="qr"><canvas></canvas></div>');

        $this->assertStringNotContainsString('Previous slide', $markdown);
        $this->assertStringNotContainsString('Share', $markdown);
    }

    public function test_tab_panels_are_named_after_their_tabs(): void
    {
        $markdown = $this->convert(
            '<div role="tablist"><button role="tab" id="t1" aria-controls="a">Model A</button><button role="tab" id="t2" aria-controls="b">Model B</button></div>'
            .'<div role="tabpanel" id="a" aria-labelledby="t1"><p>U-value 1.0</p></div>'
            .'<div role="tabpanel" id="b" aria-labelledby="t2" hidden><p>U-value 0.8</p></div>'
        );

        $this->assertStringContainsString("**Model A**\n\nU-value 1.0", $markdown);
        $this->assertStringContainsString("**Model B**\n\nU-value 0.8", $markdown);
        $this->assertSame(1, substr_count($markdown, 'Model A'));
    }

    public function test_forms_are_described_by_their_fields(): void
    {
        $markdown = $this->convert(
            '<form aria-label="Contact us"><label for="n">Name (Required)</label><input id="n">'
            .'<fieldset><legend>Topic</legend><label><input type="radio">Sales</label></fieldset>'
            .'<div class="gform_validation_container"><label>Comments</label><input></div>'
            .'<button type="submit">Send</button></form>'
        );

        $this->assertStringContainsString('**Form: Contact us** (fill it in on [the web page](https://example.com/page/)). Fields:', $markdown);
        $this->assertStringContainsString("- Name (Required)\n- Topic\n- Sales", $markdown);
        $this->assertStringNotContainsString('Comments', $markdown);
        $this->assertStringNotContainsString('Send', $markdown);
    }

    public function test_search_forms_are_dropped(): void
    {
        $this->assertStringNotContainsString('Form', $this->convert('<p>Body</p><form role="search"><label>Search</label><input></form>'));
    }

    public function test_iframes_become_links(): void
    {
        $markdown = $this->convert('<iframe title="Office location" src="https://maps.example.com/embed?q=1"></iframe><iframe src="/app/uploads/model/"></iframe><iframe></iframe>');

        $this->assertStringContainsString('[Embedded: Office location](https://maps.example.com/embed?q=1)', $markdown);
        $this->assertStringContainsString('[Embedded content](https://example.com/app/uploads/model/)', $markdown);
    }

    public function test_decorative_and_unnamed_images_are_dropped(): void
    {
        $markdown = $this->convert(
            '<img src="/a.jpg" alt=""><img src="/b.jpg"><img src="/c.jpg" alt="Pump">'
            .'<img src="data:image/gif;base64,R0lGOD" data-src="/d.jpg" alt="Lazy">'
        );

        $this->assertStringContainsString('![Pump](https://example.com/c.jpg)', $markdown);
        $this->assertStringContainsString('![Lazy](https://example.com/d.jpg)', $markdown);
        $this->assertStringNotContainsString('a.jpg', $markdown);
        $this->assertStringNotContainsString('b.jpg', $markdown);
    }

    public function test_links_are_made_absolute_and_dead_ones_dropped(): void
    {
        $markdown = $this->convert(
            '<p><a href="/contact/">Contact</a> <a href="#comments">Comments</a> <a href="/empty/"></a> <a href="/labelled/" aria-label="Read the story"></a></p>'
        );

        $this->assertStringContainsString('[Contact](https://example.com/contact/)', $markdown);
        $this->assertStringContainsString('Comments', $markdown);
        $this->assertStringNotContainsString('#comments', $markdown);
        $this->assertStringNotContainsString('/empty/', $markdown);
        $this->assertStringContainsString('[Read the story](https://example.com/labelled/)', $markdown);
    }

    public function test_a_heading_inside_a_link_links_the_heading_text(): void
    {
        $markdown = $this->convert('<a href="https://youtube.com/watch?v=1"><h2>Calibration day</h2></a>');

        $this->assertStringContainsString('## [Calibration day](https://youtube.com/watch?v=1)', $markdown);
    }

    public function test_tables_are_converted(): void
    {
        $markdown = $this->convert('<table><thead><tr><th>Size</th><th>Price</th></tr></thead><tbody><tr><td>S</td><td>10</td></tr></tbody></table>');

        $this->assertStringContainsString('| Size | Price |', $markdown);
        $this->assertStringContainsString('| S | 10 |', $markdown);
    }

    public function test_entities_are_decoded_and_spacer_lines_dropped(): void
    {
        $markdown = $this->convert("<p>Tech &amp; design</p>\n<p>&nbsp;</p>\n<p>Co-founder &amp; CGO</p>");

        $this->assertStringContainsString("Tech & design\n\nCo-founder & CGO", $markdown);
    }

    public function test_invalid_utf8_does_not_blank_the_page(): void
    {
        $markdown = $this->convert("<p>Body</p><p>Bad \xC3 byte</p>");

        $this->assertStringContainsString('Body', $markdown);
        $this->assertStringContainsString('byte', $markdown);
    }
}
