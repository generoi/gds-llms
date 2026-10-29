<?php

namespace GeneroWP\Llms\Tests\Integration;

use GeneroWP\Llms\Robots;
use WP_UnitTestCase;

class RobotsTest extends WP_UnitTestCase
{
    protected function robots(): string
    {
        update_option('blog_public', '1');

        return (string) apply_filters('robots_txt', "User-agent: *\nDisallow: /wp/wp-admin/\n", '1');
    }

    public function test_every_use_is_allowed_by_default(): void
    {
        $this->assertSame('search=yes, ai-input=yes, ai-train=yes', Robots::contentSignal());
        $this->assertSame('train-ai=y, ai-use=y, search=y', Robots::contentUsage());
    }

    /**
     * Both drafts define these as group rules, read by crawlers without a
     * group of their own.
     */
    public function test_signals_are_added_to_the_wildcard_group(): void
    {
        $robots = $this->robots();

        $this->assertStringContainsString(
            "User-agent: *\nContent-Signal: search=yes, ai-input=yes, ai-train=yes\nContent-Usage: train-ai=y, ai-use=y, search=y\nDisallow: /wp/wp-admin/\n",
            $robots
        );
        $this->assertStringContainsString('ARTICLE 4 OF THE EUROPEAN UNION DIRECTIVE 2019/790', $robots);
    }

    /**
     * Allowed crawlers get no group of their own: a crawler with a named group
     * ignores the `*` group, and with it the site's own Disallow rules.
     */
    public function test_allowed_crawlers_get_no_group_of_their_own(): void
    {
        $this->assertStringNotContainsString('User-agent: GPTBot', $this->robots());
    }

    public function test_declining_training_blocks_training_crawlers_only(): void
    {
        $policy = fn (array $policy) => ['ai-train' => false] + $policy;
        add_filter('gds/llms/ai_policy', $policy);
        $robots = $this->robots();
        remove_filter('gds/llms/ai_policy', $policy);

        $this->assertStringContainsString('Content-Signal: search=yes, ai-input=yes, ai-train=no', $robots);
        $this->assertStringContainsString('Content-Usage: train-ai=n, ai-use=y, search=y', $robots);
        $this->assertMatchesRegularExpression('/User-agent: GPTBot\n(User-agent: [^\n]+\n)*Disallow: \/\n$/', $robots);
        $this->assertStringNotContainsString('User-agent: ChatGPT-User', $robots);
    }

    /**
     * A theme appending to the `*` group must not have its rule land in the
     * declined crawlers' group, so the policy filter runs after it.
     */
    public function test_groups_go_after_rules_other_filters_append(): void
    {
        $theme = fn (string $output) => $output."Disallow: /wp-content/\n";
        $policy = fn (array $policy) => ['ai-train' => false] + $policy;
        add_filter('robots_txt', $theme);
        add_filter('gds/llms/ai_policy', $policy);
        $robots = $this->robots();
        remove_filter('robots_txt', $theme);
        remove_filter('gds/llms/ai_policy', $policy);

        $this->assertLessThan(strpos($robots, 'User-agent: GPTBot'), strpos($robots, 'Disallow: /wp-content/'));
    }

    public function test_a_wildcard_group_is_created_when_there_is_none(): void
    {
        update_option('blog_public', '1');
        $robots = (string) apply_filters('robots_txt', "User-agent: Googlebot\nDisallow: /x/\n", '1');

        $this->assertMatchesRegularExpression('/User-agent: \*\nContent-Signal: [^\n]+\nContent-Usage: [^\n]+\n\nUser-agent: Googlebot/', $robots);
    }
}
