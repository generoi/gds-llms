<?php

namespace GeneroWP\Llms\Tests\Integration;

use GeneroWP\Llms\Markdown\Endpoint as Markdown;
use GeneroWP\Llms\Markdown\Listings;
use WP_UnitTestCase;

class ListingsTest extends WP_UnitTestCase
{
    protected int $category;

    public function set_up(): void
    {
        parent::set_up();

        $this->set_permalink_structure('/%postname%/');
        update_option('posts_per_page', 3);
        $this->category = self::factory()->category->create(['name' => 'News']);
    }

    public function tear_down(): void
    {
        remove_action('pre_get_posts', [Listings::class, 'expandMainQuery']);
        remove_filter('render_block_data', [Listings::class, 'expandQueryBlock']);

        parent::tear_down();
    }

    /**
     * @return int[]
     */
    protected function posts(int $count): array
    {
        return self::factory()->post->create_many($count, ['post_category' => [$this->category]]);
    }

    protected function queryLoop(bool $pager): string
    {
        return '<!-- wp:query {"queryId":1,"query":{"perPage":3,"postType":"post","inherit":false}} --><div class="wp-block-query">'
            .'<!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template -->'
            .($pager ? '<!-- wp:query-pagination --><!-- wp:query-pagination-next /--><!-- /wp:query-pagination -->' : '')
            .'</div><!-- /wp:query -->';
    }

    public function test_an_archive_lists_up_to_a_hundred_per_page(): void
    {
        $this->posts(12);
        Listings::register();

        $this->go_to(get_category_link($this->category));

        $this->assertCount(12, $GLOBALS['wp_query']->posts);
    }

    public function test_a_paged_query_loop_lists_up_to_a_hundred_per_page(): void
    {
        $this->posts(12);
        Listings::register();

        $this->assertSame(12, substr_count(do_blocks($this->queryLoop(true)), 'wp-block-post-title'));
    }

    /**
     * Without a pager a Query Loop is a selection -- "the three latest" --
     * not a listing to complete.
     */
    public function test_a_query_loop_without_a_pager_keeps_its_size(): void
    {
        $this->posts(12);
        Listings::register();

        $this->assertSame(3, substr_count(do_blocks($this->queryLoop(false)), 'wp-block-post-title'));
    }

    public function test_an_archive_has_markdown_in_content_mode(): void
    {
        $first = self::factory()->post->create(['post_title' => 'Pumps arrive', 'post_excerpt' => 'New hand pumps in stock.', 'post_category' => [$this->category]]);
        $this->posts(1);
        $url = get_category_link($this->category);

        $this->go_to($url);
        $markdown = (new Markdown)->renderListing($GLOBALS['wp_query'], $url);

        $this->assertTrue((new Markdown)->isAvailable());
        $this->assertStringContainsString('# Category: News', $markdown);
        $this->assertStringContainsString('2 results.', $markdown);
        $this->assertStringContainsString('- [Pumps arrive]('.get_permalink($first).'): New hand pumps in stock.', $markdown);
    }

    public function test_a_long_archive_links_its_pages(): void
    {
        $this->posts(5);
        $url = get_category_link($this->category);

        $this->go_to($url);
        $markdown = (new Markdown)->renderListing($GLOBALS['wp_query'], $url);

        $this->assertStringContainsString('5 results, page 1 of 2.', $markdown);
        $this->assertStringContainsString('- [2]('.get_pagenum_link(2).')', $markdown);
    }

    /**
     * The blog's posts page carries its title and introduction.
     */
    public function test_the_blog_is_listed_under_its_posts_page(): void
    {
        $page = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Stories', 'post_content' => '<p>What we have done.</p>']);
        update_option('show_on_front', 'page');
        update_option('page_for_posts', $page);
        $this->posts(2);

        $this->go_to(get_permalink($page));
        $markdown = (new Markdown)->renderListing($GLOBALS['wp_query'], (string) get_permalink($page));

        $this->assertStringContainsString("# Stories\n\nWhat we have done.\n\n2 results.", $markdown);
    }

    /**
     * sage-archive-pages maps a page to a post type and lists it from the
     * page's template; the markdown lists it under the page's content.
     */
    public function test_a_page_standing_in_for_an_archive_is_listed(): void
    {
        $page = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Stories', 'post_content' => '<p>What we have done.</p>']);
        $this->posts(4);

        $this->go_to(get_permalink($page));
        set_query_var('mapped_post_archive', 'post');

        $this->assertNull((new Markdown)->listingQuery(), 'Still a page, not an archive.');
        $this->assertStringContainsString("# Stories\n\nWhat we have done.\n\n4 results.", (new Markdown)->renderContent(get_post($page)));
    }

    /**
     * Content that already lists the posts -- a FacetWP or Query Loop block --
     * is kept as it is; a second listing would repeat it.
     */
    public function test_a_page_whose_content_lists_its_posts_is_kept(): void
    {
        $post = $this->posts(1)[0];
        $page = self::factory()->post->create(['post_type' => 'page', 'post_content' => '<h3><a href="'.get_permalink($post).'">Our story</a></h3>']);

        $this->go_to(get_permalink($page));
        set_query_var('mapped_post_archive', 'post');
        $markdown = (new Markdown)->renderContent(get_post($page));

        $this->assertStringContainsString('[Our story]', $markdown);
        $this->assertStringNotContainsString('results.', $markdown);
    }

    public function test_search_results_have_no_markdown(): void
    {
        $this->go_to(home_url('/?s=pumps'));

        $this->assertFalse((new Markdown)->isAvailable());
    }
}
