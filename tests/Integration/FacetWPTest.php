<?php

namespace GeneroWP\Llms\Tests\Integration;

use GeneroWP\Llms\Integrations\FacetWP;
use WP_UnitTestCase;

/**
 * Against a fake FWP() (tests/stubs/facetwp.php) returning what FacetWP 4.5's
 * render() returns for generogrowth's references page.
 */
class FacetWPTest extends WP_UnitTestCase
{
    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();

        require_once dirname(__DIR__).'/stubs/facetwp.php';
    }

    public function set_up(): void
    {
        parent::set_up();

        FWP()->display->active_facets = ['industries' => 'industries', 'pagination' => 'pagination'];
        FWP()->request->url_vars = [];
        FWP()->helper->facets = ['industries' => ['type' => 'dropdown'], 'pagination' => ['type' => 'pager']];
        FWP()->facet->output = [
            'facets' => [
                'industries' => '<select class="facetwp-dropdown"><option value="">All industries</option><option value="food-beverage">Food &amp; Beverage (20)</option><option value="health">Health (6)</option></select>',
                'pagination' => '<div class="facetwp-pager"><a class="facetwp-page first active" data-page="1">1</a><a class="facetwp-page last" data-page="3">3</a></div>',
            ],
            'settings' => [
                'labels' => ['industries' => 'Industries', 'pagination' => 'pagination'],
                'pager' => ['page' => 1, 'total_rows' => 24, 'total_pages' => 3],
            ],
        ];
    }

    public function tear_down(): void
    {
        // Other tests render content with FWP() defined; an empty page has no facets to fill.
        FWP()->display->active_facets = [];

        parent::tear_down();
    }

    protected function page(): string
    {
        return '<ul class="filters"><li><div class="facetwp-facet facetwp-facet-industries facetwp-type-dropdown" data-name="industries" data-type="dropdown"></div></li></ul>'
            .'<div class="grid facetwp-template"><h3>A case</h3></div>'
            .'<div class="facetwp-facet facetwp-facet-pagination facetwp-type-pager" data-name="pagination" data-type="pager"></div>';
    }

    public function test_a_facet_becomes_links_to_the_filtered_listing(): void
    {
        $html = FacetWP::fill($this->page(), 'https://example.com/references/');

        $this->assertStringContainsString('<strong>Industries</strong>', $html);
        $this->assertStringContainsString('<a href="https://example.com/references/?_industries=food-beverage">Food &amp; Beverage (20)</a>', $html);
        $this->assertStringContainsString('<a href="https://example.com/references/?_industries=health">Health (6)</a>', $html);
        $this->assertStringNotContainsString('All industries', $html);
    }

    /**
     * FacetWP's pager shows a window of pages; the listing has all of them.
     */
    public function test_the_pager_links_every_page(): void
    {
        $html = FacetWP::fill($this->page(), 'https://example.com/references/');

        $this->assertStringContainsString('<strong>Pages</strong>', $html);
        $this->assertStringContainsString('<a href="https://example.com/references/">1</a>', $html);
        $this->assertStringContainsString('<a href="https://example.com/references/?_paged=2">2</a>', $html);
        $this->assertStringContainsString('<a href="https://example.com/references/?_paged=3">3</a>', $html);
    }

    /**
     * One page of a listing cannot say how long the listing is; FacetWP knows.
     */
    public function test_the_result_count_heads_the_listing(): void
    {
        $html = FacetWP::fill($this->page(), 'https://example.com/references/');

        $this->assertStringContainsString('<p>24 results, page 1 of 3.</p><div class="grid facetwp-template">', $html);
    }

    public function test_links_keep_the_filters_already_applied(): void
    {
        FWP()->request->url_vars = ['industries' => ['health'], 'paged' => 2];

        $html = FacetWP::fill($this->page(), 'https://example.com/references/');

        $this->assertStringContainsString('href="https://example.com/references/?_paged=3&#038;_industries=health"', $html);
        // Choosing another value starts its listing from the first page.
        $this->assertStringContainsString('href="https://example.com/references/?_industries=food-beverage"', $html);
    }

    /**
     * A listing short enough for one markdown page has no pager to follow.
     */
    public function test_a_single_page_has_no_pager(): void
    {
        FWP()->facet->output['settings']['pager'] = ['page' => 1, 'total_rows' => 24, 'total_pages' => 1];

        $html = FacetWP::fill($this->page(), 'https://example.com/references/');

        $this->assertStringContainsString('<p>24 results.</p>', $html);
        $this->assertStringNotContainsString('Pages', $html);
    }

    public function test_markdown_lists_up_to_a_hundred_results_per_page(): void
    {
        $listing = new \WP_Query;
        $listing->set('facetwp', true);
        $listing->set('posts_per_page', 8);
        $long = new \WP_Query;
        $long->set('facetwp', true);
        $long->set('posts_per_page', 200);
        $other = new \WP_Query;
        $other->set('posts_per_page', 8);

        foreach ([$listing, $long, $other] as $query) {
            FacetWP::expandListing($query);
        }

        $this->assertSame(FacetWP::PER_PAGE, $listing->get('posts_per_page'));
        $this->assertSame(200, $long->get('posts_per_page'));
        $this->assertSame(8, $other->get('posts_per_page'), 'Only the listing FacetWP filters.');
    }

    public function test_a_page_without_facets_is_untouched(): void
    {
        FWP()->display->active_facets = [];

        $this->assertSame($this->page(), FacetWP::fill($this->page(), 'https://example.com/references/'));
    }
}
