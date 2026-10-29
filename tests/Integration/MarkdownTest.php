<?php

namespace GeneroWP\Llms\Tests\Integration;

use GeneroWP\Llms\Markdown;
use WP_UnitTestCase;

class MarkdownTest extends WP_UnitTestCase
{
    /** @var array<string, mixed> */
    protected array $server;

    public function set_up(): void
    {
        parent::set_up();

        $this->server = $_SERVER;
        $this->set_permalink_structure('/%postname%/');
    }

    public function tear_down(): void
    {
        $_SERVER = $this->server;

        parent::tear_down();
    }

    /**
     * The form the llms.txt proposal specifies for URLs without a file name.
     */
    public function test_url_appends_index_html_md_to_a_trailing_slash(): void
    {
        $this->assertSame('https://example.com/about/index.html.md', Markdown::url('https://example.com/about/'));
    }

    public function test_url_appends_md_without_a_trailing_slash(): void
    {
        $this->assertSame('https://example.com/about.md', Markdown::url('https://example.com/about'));
    }

    public function test_plain_permalinks_have_no_markdown_url(): void
    {
        $this->assertSame('', Markdown::url('https://example.com/?page_id=2'));
        $this->assertSame('', Markdown::url(false));
    }

    public function test_html_path_reverses_both_forms(): void
    {
        $this->assertSame('/about/', Markdown::htmlPath('/about/index.html.md'));
        $this->assertSame('/', Markdown::htmlPath('/index.html.md'));
        $this->assertSame('/about', Markdown::htmlPath('/about.md'));
        $this->assertNull(Markdown::htmlPath('/about/'));
        $this->assertNull(Markdown::htmlPath('/.md'));
    }

    public function test_request_uri_is_rewritten_and_keeps_its_query_string(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/sv/about/index.html.md?utm_source=x';

        $this->assertTrue((new Markdown)->rewriteRequestUri());
        $this->assertSame('/sv/about/?utm_source=x', $_SERVER['REQUEST_URI']);
    }

    public function test_html_requests_are_left_alone(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/about/';

        $this->assertFalse((new Markdown)->rewriteRequestUri());
        $this->assertSame('/about/', $_SERVER['REQUEST_URI']);
    }

    public function test_post_requests_are_left_alone(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/about/index.html.md';

        $this->assertFalse((new Markdown)->rewriteRequestUri());
    }

    public function test_render_converts_the_content_to_markdown(): void
    {
        $post = self::factory()->post->create_and_get([
            'post_title' => 'Pumps &amp; valves',
            'post_content' => implode('', [
                '<!-- wp:heading --><h2 class="wp-block-heading">Hello</h2><!-- /wp:heading -->',
                '<!-- wp:paragraph --><p>Some <strong>bold</strong> text and a <a href="https://example.com/x">link</a>.</p><!-- /wp:paragraph -->',
                '<!-- wp:group --><div class="wp-block-group"><!-- wp:list --><ul><li>One</li><li>Two</li></ul><!-- /wp:list --></div><!-- /wp:group -->',
            ]),
        ]);

        $markdown = (new Markdown)->render($post);

        $this->assertStringStartsWith("# Pumps & valves\n\n", $markdown);
        $this->assertStringContainsString('## Hello', $markdown);
        $this->assertStringContainsString('**bold**', $markdown);
        $this->assertStringContainsString('[link](https://example.com/x)', $markdown);
        $this->assertStringContainsString('- One', $markdown);
        $this->assertStringNotContainsString('wp-block-group', $markdown);
        $this->assertStringNotContainsString('<', $markdown);
    }

    /**
     * the_content is where blocks and shortcodes are rendered, so what reaches
     * the converter is whatever the site's filters produce, scripts included.
     */
    public function test_render_drops_scripts_and_forms_added_by_filters(): void
    {
        $post = self::factory()->post->create_and_get(['post_content' => '<p>Body</p>']);
        $filter = fn (string $content) => $content.'<script>alert(1)</script><form><label>Email</label><input name="e"></form>';

        add_filter('the_content', $filter);
        $markdown = (new Markdown)->render($post);
        remove_filter('the_content', $filter);

        $this->assertStringContainsString('Body', $markdown);
        $this->assertStringNotContainsString('alert', $markdown);
        $this->assertStringNotContainsString('Email', $markdown);
    }

    public function test_render_decodes_entities_and_drops_spacer_lines(): void
    {
        $post = self::factory()->post->create_and_get([
            'post_content' => "<p>Tech &amp; design</p>\n<p>&nbsp;</p>\n<p>Co-founder &amp; CGO</p>",
        ]);

        $markdown = (new Markdown)->render($post);

        $this->assertStringContainsString("Tech & design\n\nCo-founder & CGO", $markdown);
    }

    public function test_invalid_utf8_does_not_blank_the_page(): void
    {
        $post = self::factory()->post->create_and_get(['post_content' => '<p>Body</p>']);
        $filter = fn (string $content) => $content."<p>Bad \xC3 byte</p>";

        add_filter('the_content', $filter);
        $markdown = (new Markdown)->render($post);
        remove_filter('the_content', $filter);

        $this->assertStringContainsString('Body', $markdown);
        $this->assertStringContainsString('byte', $markdown);
    }

    public function test_render_converts_tables(): void
    {
        $post = self::factory()->post->create_and_get([
            'post_content' => '<!-- wp:table --><figure class="wp-block-table"><table><thead><tr><th>Size</th><th>Price</th></tr></thead><tbody><tr><td>S</td><td>10</td></tr></tbody></table></figure><!-- /wp:table -->',
        ]);

        $markdown = (new Markdown)->render($post);

        $this->assertStringContainsString('| Size | Price |', $markdown);
        $this->assertStringContainsString('| S | 10 |', $markdown);
    }

    public function test_render_restores_the_global_post(): void
    {
        $outer = self::factory()->post->create_and_get();
        $inner = self::factory()->post->create_and_get();
        $GLOBALS['post'] = $outer;

        (new Markdown)->render($inner);

        $this->assertSame($outer->ID, $GLOBALS['post']->ID);
    }

    public function test_alternate_link_is_printed_on_a_published_page(): void
    {
        $page = self::factory()->post->create(['post_type' => 'page', 'post_name' => 'about']);
        $this->go_to(get_permalink($page));

        $output = get_echo([new Markdown, 'printAlternateLink']);

        $this->assertStringContainsString('rel="alternate" type="text/markdown"', $output);
        $this->assertStringContainsString('/about/index.html.md', $output);
    }

    public function test_alternate_link_is_not_printed_on_archives(): void
    {
        self::factory()->post->create();
        $this->go_to(home_url('/'));

        $this->assertSame('', get_echo([new Markdown, 'printAlternateLink']));
    }

    public function test_password_protected_posts_are_not_available(): void
    {
        $post = self::factory()->post->create_and_get(['post_password' => 'secret']);

        $this->assertFalse((new Markdown)->isAvailable($post));
    }

    public function test_drafts_are_not_available(): void
    {
        $post = self::factory()->post->create_and_get(['post_status' => 'draft']);

        $this->assertFalse((new Markdown)->isAvailable($post));
    }

    public function test_attachments_are_not_available(): void
    {
        $attachment = get_post(self::factory()->attachment->create(['post_status' => 'publish']));

        $this->assertFalse((new Markdown)->isAvailable($attachment));
    }

    /**
     * Template-gated content -- an intranet page behind a login form -- is a
     * published post as far as WordPress knows, so the site has to say so.
     */
    public function test_availability_is_filterable_per_post(): void
    {
        $gated = self::factory()->post->create_and_get(['post_type' => 'page']);
        $open = self::factory()->post->create_and_get(['post_type' => 'page']);
        update_post_meta($gated->ID, '_wp_page_template', 'template-intra.php');
        $filter = fn (bool $available, \WP_Post $post) => $available && get_page_template_slug($post) !== 'template-intra.php';

        add_filter('gds/llms/markdown_available', $filter, 10, 2);
        $markdown = new Markdown;
        $gatedAvailable = $markdown->isAvailable($gated);
        $openAvailable = $markdown->isAvailable($open);
        remove_filter('gds/llms/markdown_available', $filter);

        $this->assertFalse($gatedAvailable);
        $this->assertTrue($openAvailable);
    }

    public function test_non_public_post_types_are_not_available(): void
    {
        register_post_type('internal_note', ['public' => false]);
        $post = self::factory()->post->create_and_get(['post_type' => 'internal_note']);

        $available = (new Markdown)->isAvailable($post);
        unregister_post_type('internal_note');

        $this->assertFalse($available);
    }
}
