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

    public function test_alternate_link_is_printed_on_a_page(): void
    {
        $page = self::factory()->post->create(['post_type' => 'page', 'post_name' => 'about']);
        $this->go_to(get_permalink($page));

        $output = get_echo([new Markdown, 'printAlternateLink']);

        $this->assertStringContainsString('rel="alternate" type="text/markdown"', $output);
        $this->assertStringContainsString('/about/index.html.md', $output);
    }

    protected function goToCategory(): void
    {
        // Taxonomies registered before the permalink structure was set have no pretty URLs.
        create_initial_taxonomies();
        flush_rewrite_rules();
        $category = self::factory()->category->create(['slug' => 'news']);
        self::factory()->post->create(['post_category' => [$category]]);
        $this->go_to(get_category_link($category));
    }

    public function test_content_mode_is_the_default(): void
    {
        $this->assertSame(Markdown::MODE_CONTENT, Markdown::mode());
    }

    /**
     * An archive has no post content of its own.
     */
    public function test_archives_have_no_markdown_in_content_mode(): void
    {
        $this->goToCategory();

        $this->assertFalse((new Markdown)->isAvailable());
        $this->assertSame('', get_echo([new Markdown, 'printAlternateLink']));
    }

    /**
     * The whole page is converted, so archives and term pages have a markdown
     * version as much as single posts do.
     */
    public function test_archives_have_markdown_in_page_mode(): void
    {
        $this->goToCategory();
        $mode = fn () => Markdown::MODE_PAGE;

        add_filter('gds/llms/markdown_mode', $mode);
        $link = get_echo([new Markdown, 'printAlternateLink']);
        remove_filter('gds/llms/markdown_mode', $mode);

        $this->assertStringContainsString('/category/news/index.html.md', $link);
    }

    public function test_password_protected_posts_have_no_markdown_in_content_mode(): void
    {
        $post = self::factory()->post->create(['post_password' => 'secret']);
        $this->go_to(get_permalink($post));

        $this->assertFalse((new Markdown)->isAvailable());
    }

    public function test_content_mode_converts_the_post_content_under_its_title(): void
    {
        $post = self::factory()->post->create_and_get([
            'post_title' => 'Pumps &amp; valves',
            'post_content' => '<!-- wp:heading --><h2 class="wp-block-heading">Hello</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Some <a href="/x/">link</a>.</p><!-- /wp:paragraph -->',
        ]);

        $markdown = (new Markdown)->renderContent($post);

        $this->assertStringContainsString("---\n\n# Pumps & valves\n\n## Hello", $markdown);
        $this->assertStringContainsString('url: "'.get_permalink($post).'"', $markdown);
        $this->assertMatchesRegularExpression('/^modified: "\d{4}-\d\d-\d\dT/m', $markdown);
        $this->assertStringContainsString('[link]('.home_url('/x/').')', $markdown);
    }

    /**
     * What a template renders outside the content, added for the markdown only.
     */
    public function test_content_is_filterable_before_conversion(): void
    {
        $post = self::factory()->post->create_and_get(['post_title' => 'Pump', 'post_content' => '<p>Body</p>']);
        $filter = fn (string $html, \WP_Post $post) => '<p>Price: <strong>129 €</strong></p>'.$html;

        add_filter('gds/llms/markdown_content', $filter, 10, 2);
        $markdown = (new Markdown)->renderContent($post);
        remove_filter('gds/llms/markdown_content', $filter);

        $this->assertStringContainsString("---\n\n# Pump\n\nPrice: **129 €**\n\nBody", $markdown);
    }

    /**
     * A page identified by its query string has no path to append to -- and
     * must not be given the front page's markdown URL either.
     */
    public function test_query_string_pages_have_no_alternate_link(): void
    {
        $category = self::factory()->category->create();
        self::factory()->post->create(['post_category' => [$category]]);
        $this->go_to(home_url('/?cat='.$category));

        $this->assertTrue(is_category());
        $this->assertSame('', get_echo([new Markdown, 'printAlternateLink']));
    }

    public function test_not_found_pages_have_no_markdown(): void
    {
        $this->go_to(home_url('/no-such-page/'));

        $this->assertFalse((new Markdown)->isAvailable());
        $this->assertSame('', get_echo([new Markdown, 'printAlternateLink']));
    }

    public function test_feeds_have_no_markdown(): void
    {
        self::factory()->post->create();
        $this->go_to(get_feed_link());

        $this->assertFalse((new Markdown)->isAvailable());
    }

    public function test_availability_is_filterable(): void
    {
        $page = self::factory()->post->create(['post_type' => 'page']);
        $this->go_to(get_permalink($page));
        $filter = fn (bool $available, mixed $object) => $available && ! ($object instanceof \WP_Post && $object->ID === $page);

        add_filter('gds/llms/markdown_available', $filter, 10, 2);
        $available = (new Markdown)->isAvailable();
        remove_filter('gds/llms/markdown_available', $filter);

        $this->assertFalse($available);
    }
}
