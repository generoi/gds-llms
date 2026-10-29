<?php

namespace GeneroWP\Llms\Tests\Integration;

use GeneroWP\Llms\CacheControl;
use WP_UnitTestCase;

class CacheControlTest extends WP_UnitTestCase
{
    public function test_anonymous_responses_get_the_edge_policy(): void
    {
        $this->assertSame('public, max-age=0, s-maxage=3600', CacheControl::value());
    }

    /**
     * A logged-in user's llms.txt can list items only they see; it must never
     * reach a shared cache.
     */
    public function test_logged_in_responses_get_none(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $this->assertSame('', CacheControl::value());
    }

    public function test_sites_can_change_or_drop_it(): void
    {
        add_filter('gds/llms/cache_control', fn () => 'public, max-age=60');
        $this->assertSame('public, max-age=60', CacheControl::value());

        add_filter('gds/llms/cache_control', '__return_empty_string', 20);
        $this->assertSame('', CacheControl::value());
    }
}
