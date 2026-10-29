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
            'top_navigation' => 'Top',
            'primary_navigation' => 'Primary',
            'mobile_navigation' => 'Mobile',
            'footer_navigation' => 'Footer',
            'intra_navigation' => 'Intranet',
        ]);
        update_option('blogname', 'Acme');
        update_option('blogdescription', 'We make things.');
    }

    public function tear_down(): void
    {
        foreach (['top_navigation', 'primary_navigation', 'mobile_navigation', 'footer_navigation', 'intra_navigation'] as $location) {
            unregister_nav_menu($location);
        }

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
    public function test_summary_prefers_a_hand_written_front_page_excerpt(): void
    {
        $front = self::factory()->post->create(['post_type' => 'page', 'post_excerpt' => 'Industrial pumps since 1950.']);
        update_option('show_on_front', 'page');
        update_option('page_on_front', $front);

        $this->assertStringContainsString('> Industrial pumps since 1950.', (new LlmsTxt)->build(''));
    }

    /**
     * An excerpt generated from a block-built front page is its headings run
     * together; the tagline is a better summary than that.
     */
    public function test_summary_ignores_a_generated_front_page_excerpt(): void
    {
        $front = self::factory()->post->create(['post_type' => 'page', 'post_excerpt' => '', 'post_content' => '<h2>CLIENTS WE PARTNERED WITH</h2><p>Ready to start?</p>']);
        update_option('show_on_front', 'page');
        update_option('page_on_front', $front);

        $this->assertStringContainsString('> We make things.', (new LlmsTxt)->build(''));
    }

    public function test_intro_states_the_language_and_the_markdown_urls(): void
    {
        $body = (new LlmsTxt)->build('');

        $this->assertStringContainsString('Written in English (United States).', $body);
        $this->assertStringContainsString('followed by `index.html.md`', $body);
    }

    public function test_the_default_tagline_is_not_a_summary(): void
    {
        update_option('blogdescription', 'Just another WordPress site');

        $this->assertStringStartsWith("# Acme\n\nWritten in", (new LlmsTxt)->build(''));
    }

    /**
     * `#` items are how a menu groups pages, so they become headings and their
     * children the links under them. The primary menu's loose links stay in the
     * header rather than under a heading this package would have to invent.
     */
    public function test_primary_menu_groups_become_sections(): void
    {
        $page = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Repairs', 'post_excerpt' => 'We fix pumps.']);

        $menu = wp_create_nav_menu('Main');
        $group = $this->customItem($menu, 'Services', '#');
        $this->menuItem($menu, ['menu-item-object-id' => $page, 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-parent-id' => $group]);
        $this->customItem($menu, 'Contact', 'https://example.com/contact');
        set_theme_mod('nav_menu_locations', ['primary_navigation' => $menu]);

        $body = (new LlmsTxt)->build('');

        $this->assertStringContainsString("## Services\n- [Repairs](".get_permalink($page).'): We fix pumps.', $body);
        $this->assertLessThan(
            strpos($body, '## '),
            strpos($body, '- [Contact](https://example.com/contact)'),
            'Loose links belong in the header, before the first section.',
        );
    }

    /**
     * Mega menus nest a third level under a group's links; those belong to the
     * group too, not to the header.
     */
    public function test_items_nested_below_a_group_stay_in_that_group(): void
    {
        $menu = wp_create_nav_menu('Main');
        $group = $this->customItem($menu, 'Products', '#');
        $child = $this->customItem($menu, 'Pumps', 'https://example.com/pumps', $group);
        $this->customItem($menu, 'Hand pumps', 'https://example.com/pumps/hand', $child);
        set_theme_mod('nav_menu_locations', ['primary_navigation' => $menu]);

        $body = (new LlmsTxt)->build('');

        $this->assertStringContainsString("## Products\n- [Pumps](https://example.com/pumps)\n- [Hand pumps](https://example.com/pumps/hand)", $body);
    }

    /**
     * Mega menus usually hang their columns off a real page ("Products") rather
     * than a `#` placeholder; that page heads the section and leads its links.
     */
    public function test_a_top_level_page_with_children_becomes_a_section(): void
    {
        $menu = wp_create_nav_menu('Main');
        $parent = $this->customItem($menu, 'Products', 'https://example.com/products/');
        $this->customItem($menu, 'Pumps', 'https://example.com/products/pumps/', $parent);
        $this->customItem($menu, 'Contact', 'https://example.com/contact/');
        set_theme_mod('nav_menu_locations', ['primary_navigation' => $menu]);

        $body = (new LlmsTxt)->build('');

        $this->assertStringContainsString("## Products\n- [Products](https://example.com/products/)\n- [Pumps](https://example.com/products/pumps/)", $body);
        $this->assertLessThan(strpos($body, '## Products'), strpos($body, '- [Contact]'));
    }

    public function test_menu_links_are_absolute_and_placeholders_skipped(): void
    {
        $menu = wp_create_nav_menu('Main');
        $this->customItem($menu, 'All stories', '/stories/');
        $this->customItem($menu, 'Cookie settings', '#cookies');
        $this->customItem($menu, '@', 'mailto:hello@example.com');
        set_theme_mod('nav_menu_locations', ['primary_navigation' => $menu]);

        $body = (new LlmsTxt)->build('');

        $this->assertStringContainsString('- [All stories]('.home_url('/stories/').')', $body);
        $this->assertStringNotContainsString('Cookie settings', $body);
        $this->assertStringContainsString('- [hello@example.com](mailto:hello@example.com)', $body);
    }

    /**
     * A description every page shares -- a Yoast template, or the front page's
     * text inherited by pages without their own -- tells a model nothing.
     */
    public function test_a_repeated_description_is_dropped(): void
    {
        $shared = 'Welcome to our farm, where the horses greet every visitor.';
        $first = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Archive', 'post_excerpt' => $shared]);
        $second = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Subscribe', 'post_excerpt' => $shared]);
        $third = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Contact', 'post_excerpt' => 'Phone and email for the editors.']);

        $menu = wp_create_nav_menu('Main');
        foreach ([$first, $second, $third] as $page) {
            $this->menuItem($menu, ['menu-item-object-id' => $page, 'menu-item-object' => 'page', 'menu-item-type' => 'post_type']);
        }
        set_theme_mod('nav_menu_locations', ['primary_navigation' => $menu]);

        $body = (new LlmsTxt)->build('');

        $this->assertStringNotContainsString($shared, $body);
        $this->assertStringContainsString('[Contact]('.get_permalink($third).'): Phone and email for the editors.', $body);
    }

    /**
     * Location names differ on every site, so every assigned menu is listed,
     * the primary navigation first wherever it is registered.
     */
    public function test_every_assigned_menu_is_listed_primary_first(): void
    {
        $top = wp_create_nav_menu('Quick links');
        $this->customItem($top, 'Careers', 'https://example.com/careers');
        $primary = wp_create_nav_menu('Main');
        $this->customItem($primary, 'About', 'https://example.com/about');
        set_theme_mod('nav_menu_locations', ['top_navigation' => $top, 'primary_navigation' => $primary]);

        $body = (new LlmsTxt)->build('');

        $this->assertStringContainsString("\n- [About](https://example.com/about)\n\n## Quick links\n- [Careers](https://example.com/careers)", $body);
    }

    public function test_a_menu_assigned_twice_is_listed_once(): void
    {
        $primary = wp_create_nav_menu('Main');
        $this->customItem($primary, 'About', 'https://example.com/about');
        set_theme_mod('nav_menu_locations', ['primary_navigation' => $primary, 'mobile_navigation' => $primary]);

        $this->assertSame(1, substr_count((new LlmsTxt)->build(''), '[About]'));
    }

    public function test_a_url_is_listed_once_across_menus(): void
    {
        $primary = wp_create_nav_menu('Main');
        $this->customItem($primary, 'About', 'https://example.com/about/');
        $footer = wp_create_nav_menu('Footer');
        $this->customItem($footer, 'About us', 'https://example.com/about');
        $this->customItem($footer, 'Privacy', 'https://example.com/privacy');
        set_theme_mod('nav_menu_locations', ['primary_navigation' => $primary, 'footer_navigation' => $footer]);

        $body = (new LlmsTxt)->build('');

        $this->assertStringNotContainsString('About us', $body);
        $this->assertStringContainsString("## Footer\n- [Privacy](https://example.com/privacy)", $body);
    }

    /**
     * Footer columns are usually `#` items with links under them, so they get
     * the column headings rather than the menu's name.
     */
    public function test_groups_in_other_menus_become_sections_too(): void
    {
        $footer = wp_create_nav_menu('Footer EN');
        $column = $this->customItem($footer, 'Customer service', '#');
        $this->customItem($footer, 'Delivery terms', 'https://example.com/delivery', $column);
        set_theme_mod('nav_menu_locations', ['footer_navigation' => $footer]);

        $body = (new LlmsTxt)->build('');

        $this->assertStringContainsString("## Customer service\n- [Delivery terms](https://example.com/delivery)", $body);
        $this->assertStringNotContainsString('Footer EN', $body);
    }

    public function test_menus_are_excluded_by_location(): void
    {
        $intra = wp_create_nav_menu('Intranet');
        $this->customItem($intra, 'Staff handbook', 'https://example.com/intra/handbook');
        set_theme_mod('nav_menu_locations', ['intra_navigation' => $intra]);
        $filter = fn (array $locations) => array_diff($locations, ['intra_navigation']);

        add_filter('gds/llms_txt/menus', $filter);
        $body = (new LlmsTxt)->build('');
        remove_filter('gds/llms_txt/menus', $filter);

        $this->assertStringNotContainsString('Staff handbook', $body);
    }

    public function test_catalogue_lists_the_top_level_of_a_taxonomy(): void
    {
        register_taxonomy('catalogue', 'post', ['public' => true, 'label' => 'Catalogue']);
        $parent = self::factory()->term->create(['taxonomy' => 'catalogue', 'name' => 'Pumps']);
        $child = self::factory()->term->create(['taxonomy' => 'catalogue', 'name' => 'Hand pumps', 'parent' => $parent]);
        wp_set_object_terms(self::factory()->post->create(), [$parent, $child], 'catalogue');
        $filter = fn () => ['catalogue'];

        add_filter('gds/llms_txt/taxonomies', $filter);
        $body = (new LlmsTxt)->build('');
        remove_filter('gds/llms_txt/taxonomies', $filter);
        unregister_taxonomy('catalogue');

        $this->assertStringContainsString("## Catalogue\n- [Pumps](", $body);
        $this->assertStringNotContainsString('Hand pumps', $body);
    }

    public function test_optional_section_links_the_sitemap(): void
    {
        $this->assertStringContainsString("## Optional\n- [Sitemap](".home_url('/wp-sitemap.xml').')', (new LlmsTxt)->build(''));
    }

    /**
     * One filter for everything that cannot be derived: what the business does,
     * a section renamed or dropped.
     */
    public function test_the_document_is_filterable(): void
    {
        $filter = function (array $document) {
            $document['intro'] = ['about' => 'Acme sells pumps.'] + $document['intro'];
            unset($document['intro']['markdown']);
            $document['sections'] = array_filter($document['sections'], fn ($section) => $section['heading'] !== 'Optional');

            return $document;
        };

        add_filter('gds/llms_txt/document', $filter);
        $body = (new LlmsTxt)->build('');
        remove_filter('gds/llms_txt/document', $filter);

        $this->assertStringContainsString("\nAcme sells pumps. Written in English", $body);
        $this->assertStringNotContainsString('index.html.md', $body);
        $this->assertStringNotContainsString('## Optional', $body);
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

    protected function customItem(int $menu, string $title, string $url, int $parent = 0): int
    {
        return $this->menuItem($menu, [
            'menu-item-title' => $title,
            'menu-item-url' => $url,
            'menu-item-type' => 'custom',
            'menu-item-parent-id' => $parent,
        ]);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    protected function menuItem(int $menu, array $args): int
    {
        return (int) wp_update_nav_menu_item($menu, 0, $args + ['menu-item-status' => 'publish']);
    }
}
