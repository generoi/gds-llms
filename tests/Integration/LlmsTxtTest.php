<?php

namespace GeneroWP\Llms\Tests\Integration;

use GeneroWP\Llms\LlmsTxt;
use WP_UnitTestCase;

class LlmsTxtTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        $this->set_permalink_structure('/%postname%/');
        register_nav_menus([
            'primary_navigation' => 'Primary',
            'footer_navigation' => 'Footer',
            'social_navigation' => 'Social',
        ]);
        update_option('blogname', 'Acme');
        update_option('blogdescription', 'We make things.');
    }

    public function tear_down(): void
    {
        unregister_nav_menu('primary_navigation');
        unregister_nav_menu('footer_navigation');
        unregister_nav_menu('social_navigation');

        parent::tear_down();
    }

    public function test_starts_with_the_site_name_and_tagline(): void
    {
        $body = (new LlmsTxt)->build('');

        $this->assertStringStartsWith("# Acme\n\n> We make things.\n", $body);
    }

    /**
     * The front page's own description is a better summary than the tagline
     * when an editor has written one.
     */
    public function test_summary_prefers_the_front_page_excerpt(): void
    {
        $front = self::factory()->post->create(['post_type' => 'page', 'post_excerpt' => 'Industrial pumps since 1950.']);
        update_option('show_on_front', 'page');
        update_option('page_on_front', $front);

        $this->assertStringContainsString('> Industrial pumps since 1950.', (new LlmsTxt)->build(''));
    }

    public function test_intro_states_the_language_and_the_markdown_urls(): void
    {
        $body = (new LlmsTxt)->build('');

        $this->assertStringContainsString('Written in English.', $body);
        $this->assertStringContainsString('followed by `index.html.md`', $body);
    }

    public function test_the_about_filter_leads_the_intro(): void
    {
        $filter = fn () => 'Acme sells pumps.';

        add_filter('gds/llms_txt/about', $filter);
        $body = (new LlmsTxt)->build('');
        remove_filter('gds/llms_txt/about', $filter);

        $this->assertStringContainsString("\nAcme sells pumps. Written in English.", $body);
    }

    /**
     * `#` items are how the menu groups pages, so they become headings and their
     * children the links under them. Loose links stay in the header rather than
     * under a heading this package would have to invent.
     */
    public function test_primary_menu_groups_become_sections(): void
    {
        $page = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Repairs', 'post_excerpt' => 'We fix pumps.']);

        $menu = wp_create_nav_menu('Main');
        $group = $this->menuItem($menu, ['menu-item-title' => 'Services', 'menu-item-url' => '#', 'menu-item-type' => 'custom']);
        $this->menuItem($menu, ['menu-item-object-id' => $page, 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-parent-id' => $group]);
        $this->menuItem($menu, ['menu-item-title' => 'Contact', 'menu-item-url' => 'https://example.com/contact', 'menu-item-type' => 'custom']);
        set_theme_mod('nav_menu_locations', ['primary_navigation' => $menu]);

        $body = (new LlmsTxt)->build('');

        $this->assertStringContainsString("## Services\n- [Repairs](".get_permalink($page).'): We fix pumps.', $body);
        $this->assertLessThan(
            strpos($body, '## '),
            strpos($body, '- [Contact](https://example.com/contact)'),
            'Loose links belong in the header, before the first section.',
        );
    }

    public function test_footer_menus_become_sections_named_after_the_menu(): void
    {
        $footer = wp_create_nav_menu('Customer service');
        $this->menuItem($footer, ['menu-item-title' => 'Delivery terms', 'menu-item-url' => 'https://example.com/delivery', 'menu-item-type' => 'custom']);
        $social = wp_create_nav_menu('Follow us');
        $this->menuItem($social, ['menu-item-title' => 'LinkedIn', 'menu-item-url' => 'https://linkedin.com/x', 'menu-item-type' => 'custom']);
        set_theme_mod('nav_menu_locations', ['footer_navigation' => $footer, 'social_navigation' => $social]);

        $body = (new LlmsTxt)->build('');

        $this->assertStringContainsString("## Customer service\n- [Delivery terms](https://example.com/delivery)", $body);
        $this->assertStringNotContainsString('LinkedIn', $body);
    }

    public function test_secondary_menus_are_filterable(): void
    {
        $social = wp_create_nav_menu('Follow us');
        $this->menuItem($social, ['menu-item-title' => 'LinkedIn', 'menu-item-url' => 'https://linkedin.com/x', 'menu-item-type' => 'custom']);
        set_theme_mod('nav_menu_locations', ['social_navigation' => $social]);
        $filter = fn () => ['social_navigation'];

        add_filter('gds/llms_txt/secondary_menus', $filter);
        $body = (new LlmsTxt)->build('');
        remove_filter('gds/llms_txt/secondary_menus', $filter);

        $this->assertStringContainsString("## Follow us\n- [LinkedIn](https://linkedin.com/x)", $body);
    }

    public function test_catalogue_lists_the_top_level_of_a_taxonomy(): void
    {
        register_taxonomy('catalogue', 'post', ['public' => true, 'label' => 'Catalogue']);
        $parent = self::factory()->term->create(['taxonomy' => 'catalogue', 'name' => 'Pumps']);
        $child = self::factory()->term->create(['taxonomy' => 'catalogue', 'name' => 'Hand pumps', 'parent' => $parent]);
        wp_set_object_terms(self::factory()->post->create(), [$parent, $child], 'catalogue');
        $filter = fn () => ['catalogue'];

        add_filter('gds/llms_txt/catalogue_taxonomies', $filter);
        $body = (new LlmsTxt)->build('');
        remove_filter('gds/llms_txt/catalogue_taxonomies', $filter);
        unregister_taxonomy('catalogue');

        $this->assertStringContainsString("## Catalogue\n- [Pumps](", $body);
        $this->assertStringNotContainsString('Hand pumps', $body);
    }

    public function test_optional_section_links_the_sitemap(): void
    {
        $this->assertStringContainsString("## Optional\n- [Sitemap](".home_url('/wp-sitemap.xml').')', (new LlmsTxt)->build(''));
    }

    public function test_body_is_cached_until_content_changes(): void
    {
        $llmsTxt = new LlmsTxt;
        $first = $llmsTxt->body('');

        update_option('blogname', 'Renamed');
        $this->assertSame($first, $llmsTxt->body(''));

        self::factory()->post->create();
        $this->assertStringStartsWith('# Renamed', $llmsTxt->body(''));
    }

    public function test_llms_txt_resolves_to_the_query_var(): void
    {
        flush_rewrite_rules(false);

        $this->go_to(home_url('/llms.txt'));

        $this->assertSame('1', get_query_var(LlmsTxt::QUERY_VAR));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    protected function menuItem(int $menu, array $args): int
    {
        return (int) wp_update_nav_menu_item($menu, 0, $args + ['menu-item-status' => 'publish']);
    }
}
