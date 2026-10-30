<?php

namespace GeneroWP\Llms\Tests\Integration;

use GeneroWP\Llms\Integrations\Kinsta;
use GeneroWP\Llms\LlmsTxt\Document;
use GeneroWP\Llms\LlmsTxt\Endpoint as LlmsTxt;
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
        $body = (new Document)->build('');

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

        $this->assertStringContainsString('> Industrial pumps since 1950.', (new Document)->build(''));
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

        $this->assertStringContainsString('> We make things.', (new Document)->build(''));
    }

    public function test_intro_states_the_language_and_the_markdown_urls(): void
    {
        $body = (new Document)->build('');

        $this->assertStringContainsString('Written in English (United States).', $body);
        $this->assertStringContainsString('followed by `md/`', $body);
    }

    public function test_the_default_tagline_is_not_a_summary(): void
    {
        update_option('blogdescription', 'Just another WordPress site');

        $this->assertStringStartsWith("# Acme\n\nWritten in", (new Document)->build(''));
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

        $body = (new Document)->build('');

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

        $body = (new Document)->build('');

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

        $body = (new Document)->build('');

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

        $body = (new Document)->build('');

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

        $body = (new Document)->build('');

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

        $body = (new Document)->build('');

        $this->assertStringContainsString("\n- [About](https://example.com/about)\n\n## Quick links\n- [Careers](https://example.com/careers)", $body);
    }

    public function test_a_menu_assigned_twice_is_listed_once(): void
    {
        $primary = wp_create_nav_menu('Main');
        $this->customItem($primary, 'About', 'https://example.com/about');
        set_theme_mod('nav_menu_locations', ['primary_navigation' => $primary, 'mobile_navigation' => $primary]);

        $this->assertSame(1, substr_count((new Document)->build(''), '[About]'));
    }

    public function test_a_url_is_listed_once_across_menus(): void
    {
        $primary = wp_create_nav_menu('Main');
        $this->customItem($primary, 'About', 'https://example.com/about/');
        $footer = wp_create_nav_menu('Footer');
        $this->customItem($footer, 'About us', 'https://example.com/about');
        $this->customItem($footer, 'Privacy', 'https://example.com/privacy');
        set_theme_mod('nav_menu_locations', ['primary_navigation' => $primary, 'footer_navigation' => $footer]);

        $body = (new Document)->build('');

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

        $body = (new Document)->build('');

        $this->assertStringContainsString("## Customer service\n- [Delivery terms](https://example.com/delivery)", $body);
        $this->assertStringNotContainsString('Footer EN', $body);
    }

    /**
     * A private / business split repeated in every menu is one section each.
     */
    public function test_sections_with_the_same_heading_are_merged(): void
    {
        $primary = wp_create_nav_menu('Main');
        $business = $this->customItem($primary, 'Business', '#');
        $this->customItem($primary, 'Fibre', 'https://example.com/business/fibre/', $business);
        $footer = wp_create_nav_menu('Footer');
        $column = $this->customItem($footer, 'Business', '#');
        $this->customItem($footer, 'Support', 'https://example.com/business/support/', $column);
        set_theme_mod('nav_menu_locations', ['primary_navigation' => $primary, 'footer_navigation' => $footer]);

        $body = (new Document)->build('');

        $this->assertSame(1, substr_count($body, '## Business'));
        $this->assertStringContainsString("## Business\n- [Fibre](https://example.com/business/fibre/)\n- [Support](https://example.com/business/support/)", $body);
    }

    /**
     * WordPress keeps a draft or private page in its menu; listing it would put
     * its excerpt in a document cached for everyone.
     */
    public function test_unpublished_menu_targets_are_not_listed(): void
    {
        $draft = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Coming soon', 'post_excerpt' => 'Unannounced product.']);
        $private = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'private', 'post_title' => 'Board', 'post_excerpt' => 'Board minutes.']);

        $menu = wp_create_nav_menu('Main');
        foreach ([$draft, $private] as $page) {
            $this->menuItem($menu, ['menu-item-object-id' => $page, 'menu-item-object' => 'page', 'menu-item-type' => 'post_type']);
        }
        $this->customItem($menu, 'About', 'https://example.com/about');
        set_theme_mod('nav_menu_locations', ['primary_navigation' => $menu]);

        $body = (new Document)->build('');

        $this->assertStringContainsString('[About]', $body);
        foreach (['Coming soon', 'Unannounced product.', 'Board', 'Board minutes.'] as $text) {
            $this->assertStringNotContainsString($text, $body);
        }
    }

    public function test_a_password_protected_page_is_listed_without_its_excerpt(): void
    {
        $page = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Partners', 'post_password' => 'secret', 'post_excerpt' => 'Partner price list.']);

        $menu = wp_create_nav_menu('Main');
        $this->menuItem($menu, ['menu-item-object-id' => $page, 'menu-item-object' => 'page', 'menu-item-type' => 'post_type']);
        set_theme_mod('nav_menu_locations', ['primary_navigation' => $menu]);

        $body = (new Document)->build('');

        $this->assertStringContainsString('- [Partners]('.get_permalink($page).")\n", $body);
        $this->assertStringNotContainsString('Partner price list.', $body);
        $this->assertStringNotContainsString('no excerpt', $body);
    }

    public function test_menus_are_excluded_by_location(): void
    {
        $intra = wp_create_nav_menu('Intranet');
        $this->customItem($intra, 'Staff handbook', 'https://example.com/intra/handbook');
        set_theme_mod('nav_menu_locations', ['intra_navigation' => $intra]);
        $filter = fn (array $locations) => array_diff($locations, ['intra_navigation']);

        add_filter('gds/llms_txt/menus', $filter);
        $body = (new Document)->build('');
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
        $body = (new Document)->build('');
        remove_filter('gds/llms_txt/taxonomies', $filter);
        unregister_taxonomy('catalogue');

        $this->assertStringContainsString("## Catalogue\n- [Pumps](", $body);
        $this->assertStringNotContainsString('Hand pumps', $body);
    }

    /**
     * A site's own product taxonomy counts its own post type, not WooCommerce's.
     */
    public function test_catalogue_sentence_counts_the_taxonomy_own_posts(): void
    {
        register_post_type('machine', ['public' => true]);
        register_taxonomy('machine_kind', 'machine', ['public' => true, 'rewrite' => ['slug' => 'machines'], 'label' => 'Kinds']);
        self::factory()->post->create(['post_type' => 'page', 'post_name' => 'machines', 'post_title' => 'Machines']);
        self::factory()->post->create_many(2, ['post_type' => 'machine']);
        $filter = fn () => ['machine_kind'];

        add_filter('gds/llms_txt/taxonomies', $filter);
        $body = (new Document)->build('');
        remove_filter('gds/llms_txt/taxonomies', $filter);
        unregister_taxonomy('machine_kind');
        unregister_post_type('machine');

        $this->assertStringContainsString('The 2 products are organised under [Machines](', $body);
    }

    public function test_a_catalogue_without_public_archives_is_not_listed(): void
    {
        register_taxonomy('catalogue', 'post', ['public' => true, 'publicly_queryable' => false, 'label' => 'Catalogue']);
        $term = self::factory()->term->create(['taxonomy' => 'catalogue', 'name' => 'Pumps']);
        wp_set_object_terms(self::factory()->post->create(), [$term], 'catalogue');
        $filter = fn () => ['catalogue'];

        add_filter('gds/llms_txt/taxonomies', $filter);
        $body = (new Document)->build('');
        remove_filter('gds/llms_txt/taxonomies', $filter);
        unregister_taxonomy('catalogue');

        $this->assertStringNotContainsString('## Catalogue', $body);
    }

    public function test_optional_section_links_the_sitemap(): void
    {
        $this->assertStringContainsString("## Optional\n- [Sitemap](".home_url('/wp-sitemap.xml').')', (new Document)->build(''));
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
        $body = (new Document)->build('');
        remove_filter('gds/llms_txt/document', $filter);

        $this->assertStringContainsString("\nAcme sells pumps. Written in English", $body);
        $this->assertStringNotContainsString('followed by `md/`', $body);
        $this->assertStringNotContainsString('## Optional', $body);
    }

    public function test_publishing_content_flushes(): void
    {
        $this->assertTrue($this->flushesAfter(fn () => self::factory()->post->create()));
    }

    public function test_the_inputs_held_in_options_flush(): void
    {
        $this->assertTrue($this->flushesAfter(fn () => update_option('blogname', 'Renamed')));
        $this->assertTrue($this->flushesAfter(fn () => set_theme_mod('nav_menu_locations', ['primary_navigation' => wp_create_nav_menu('Main')])));
        $this->assertFalse($this->flushesAfter(fn () => update_option('posts_per_page', 3)));
    }

    /**
     * A product import saves thousands of posts per batch; the deletes happen
     * once, on shutdown.
     */
    public function test_changes_are_flushed_once_on_shutdown(): void
    {
        $llmsTxt = new LlmsTxt;
        $llmsTxt->flushPending();
        $first = $llmsTxt->body('');

        update_option('blogname', 'Renamed');
        self::factory()->post->create_many(3);

        $this->assertSame($first, $llmsTxt->body(''), 'Nothing is deleted before shutdown.');

        $llmsTxt->flushPending();
        $this->assertStringStartsWith('# Renamed', $llmsTxt->body(''));
    }

    /**
     * An editor's build can include menu items only they can see; caching it
     * would serve that to everyone.
     */
    public function test_a_logged_in_build_is_not_cached(): void
    {
        $llmsTxt = new LlmsTxt;
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $llmsTxt->body('');

        wp_set_current_user(0);
        update_option('blogname', 'Renamed');

        $this->assertStringStartsWith('# Renamed', $llmsTxt->body(''));
    }

    public function test_revisions_do_not_flush(): void
    {
        $post = self::factory()->post->create();

        $this->assertFalse($this->flushesAfter(fn () => wp_insert_post([
            'post_type' => 'revision',
            'post_status' => 'inherit',
            'post_parent' => $post,
            'post_name' => $post.'-revision-v1',
        ])));
    }

    /**
     * Orders and other non-public types are saved on every checkout.
     */
    public function test_non_public_posts_do_not_flush(): void
    {
        register_post_type('order_like', ['public' => false]);
        $flushed = $this->flushesAfter(fn () => self::factory()->post->create(['post_type' => 'order_like']));
        unregister_post_type('order_like');

        $this->assertFalse($flushed);
    }

    public function test_editing_a_field_the_document_reads_flushes(): void
    {
        $post = self::factory()->post->create();

        $this->assertTrue($this->flushesAfter(fn () => wp_update_post(['ID' => $post, 'post_title' => 'Renamed'])));
    }

    /**
     * WooCommerce re-saves a product on every sale, changing only its modified
     * date and stock meta.
     */
    public function test_a_save_that_changes_nothing_the_document_reads_does_not_flush(): void
    {
        $post = self::factory()->post->create();

        $this->assertFalse($this->flushesAfter(function () use ($post) {
            wp_update_post(['ID' => $post, 'post_modified' => '2030-01-01 00:00:00']);
            update_post_meta($post, '_stock', 3);
        }));
    }

    public function test_yoast_meta_changes_on_a_published_post_flush(): void
    {
        $post = self::factory()->post->create();

        $this->assertTrue($this->flushesAfter(fn () => update_post_meta($post, '_yoast_wpseo_metadesc', 'New description')));
        $this->assertTrue($this->flushesAfter(fn () => update_post_meta($post, '_yoast_wpseo_metadesc', 'Changed again')));
        $this->assertTrue($this->flushesAfter(fn () => delete_post_meta($post, '_yoast_wpseo_metadesc')));
        $this->assertTrue($this->flushesAfter(fn () => update_post_meta($post, '_yoast_wpseo_meta-robots-noindex', '1')));
    }

    /**
     * What a sale, a first render or another plugin writes to a published post.
     */
    public function test_other_meta_does_not_flush(): void
    {
        $post = self::factory()->post->create();

        $this->assertFalse($this->flushesAfter(function () use ($post) {
            update_post_meta($post, '_stock', 2);
            update_post_meta($post, 'total_sales', 5);
            delete_post_meta($post, '_price');
            add_post_meta($post, '_price', '9.90');
            update_post_meta($post, '_oembed_abc', '<iframe></iframe>');
        }));
    }

    /**
     * Every "Add New" click creates an auto-draft; drafts go to review.
     */
    public function test_status_changes_between_non_public_statuses_do_not_flush(): void
    {
        $this->assertFalse($this->flushesAfter(function () {
            $post = self::factory()->post->create(['post_status' => 'auto-draft']);
            wp_update_post(['ID' => $post, 'post_status' => 'draft']);
            wp_update_post(['ID' => $post, 'post_status' => 'pending']);
        }));
    }

    public function test_publishing_and_trashing_flush(): void
    {
        $post = self::factory()->post->create(['post_status' => 'draft']);

        $this->assertTrue($this->flushesAfter(fn () => wp_publish_post($post)));
        $this->assertTrue($this->flushesAfter(fn () => wp_trash_post($post)));
    }

    /**
     * wp_publish_post() fires save_post as an update and never post_updated.
     */
    public function test_a_scheduled_post_going_live_flushes(): void
    {
        $post = self::factory()->post->create(['post_status' => 'future', 'post_date' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS)]);

        $this->assertTrue($this->flushesAfter(fn () => wp_publish_post($post)));
    }

    /**
     * Catalogue categories list only the terms that have products.
     */
    public function test_changing_a_published_post_catalogue_terms_flushes(): void
    {
        register_taxonomy('catalogue', 'post', ['public' => true, 'label' => 'Catalogue']);
        $filter = fn () => ['catalogue'];
        add_filter('gds/llms_txt/taxonomies', $filter);
        $post = self::factory()->post->create();
        $term = self::factory()->term->create(['taxonomy' => 'catalogue']);

        $flushed = $this->flushesAfter(fn () => wp_set_object_terms($post, [$term], 'catalogue'));
        $again = $this->flushesAfter(fn () => wp_set_object_terms($post, [$term], 'catalogue'));

        remove_filter('gds/llms_txt/taxonomies', $filter);
        unregister_taxonomy('catalogue');

        $this->assertTrue($flushed);
        $this->assertFalse($again, 'The same terms again change nothing.');
    }

    public function test_removing_a_catalogue_term_flushes(): void
    {
        register_taxonomy('catalogue', 'post', ['public' => true, 'label' => 'Catalogue']);
        $filter = fn () => ['catalogue'];
        add_filter('gds/llms_txt/taxonomies', $filter);
        $post = self::factory()->post->create();
        $term = self::factory()->term->create(['taxonomy' => 'catalogue']);
        wp_set_object_terms($post, [$term], 'catalogue');

        $flushed = $this->flushesAfter(fn () => wp_remove_object_terms($post, [$term], 'catalogue'));

        remove_filter('gds/llms_txt/taxonomies', $filter);
        unregister_taxonomy('catalogue');

        $this->assertTrue($flushed);
    }

    /**
     * The Customizer, REST, WP-CLI and MCP edit menu items without the Menus
     * screen's wp_update_nav_menu.
     */
    public function test_menu_item_edits_outside_the_menus_screen_flush(): void
    {
        $menu = wp_create_nav_menu('Main');
        $item = 0;

        $this->assertTrue($this->flushesAfter(function () use ($menu, &$item) {
            $item = wp_update_nav_menu_item($menu, 0, ['menu-item-title' => 'Contact', 'menu-item-url' => 'https://example.org/contact/', 'menu-item-status' => 'publish']);
        }));
        $this->assertTrue($this->flushesAfter(fn () => wp_update_nav_menu_item($menu, $item, ['menu-item-title' => 'Reach us', 'menu-item-url' => 'https://example.org/contact/', 'menu-item-status' => 'publish'])));
        $this->assertTrue($this->flushesAfter(fn () => wp_delete_post($item, true)));
    }

    /**
     * WooCommerce re-sets product_visibility (`outofstock`) when a sale sells a
     * product out.
     */
    public function test_terms_outside_the_catalogue_on_a_published_post_do_not_flush(): void
    {
        register_taxonomy('product_visibility', 'post', ['public' => false]);
        $post = self::factory()->post->create();
        $term = self::factory()->term->create(['taxonomy' => 'product_visibility', 'slug' => 'outofstock']);

        $flushed = $this->flushesAfter(fn () => wp_set_object_terms($post, [$term], 'product_visibility'));
        unregister_taxonomy('product_visibility');

        $this->assertFalse($flushed);
    }

    /**
     * A draft is autosaved into the post itself every minute while it is written.
     */
    public function test_editing_a_draft_does_not_flush(): void
    {
        $post = self::factory()->post->create(['post_status' => 'draft']);

        $this->assertFalse($this->flushesAfter(fn () => wp_update_post(['ID' => $post, 'post_content' => 'Work in progress'])));
    }

    public function test_forgetting_a_site_drops_its_rules_and_cache(): void
    {
        $llmsTxt = new LlmsTxt;
        $llmsTxt->flushPending();
        $llmsTxt->body('');
        $cached = fn () => $GLOBALS['wpdb']->get_col("SELECT option_name FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE '\_transient\_gds\_llms\_txt:%'");
        $this->assertNotSame([], $cached(), 'A document must be cached for its removal to be tested.');
        update_option(LlmsTxt::REWRITE_VERSION_OPTION, 'x');
        update_option('rewrite_rules', ['^llms\.txt$' => 'index.php?'.LlmsTxt::QUERY_VAR.'=1']);

        $this->assertSame(home_url('/llms.txt'), LlmsTxt::forgetSite());

        $this->assertFalse(get_option(LlmsTxt::REWRITE_VERSION_OPTION));
        $this->assertFalse(get_option('rewrite_rules'));
        $this->assertSame([], $cached());
    }

    public function test_terms_outside_the_catalogue_do_not_flush(): void
    {
        $this->assertFalse($this->flushesAfter(fn () => self::factory()->term->create(['taxonomy' => 'post_tag'])));
    }

    /**
     * Flushing mid-init would rebuild the rules before later-registering plugins
     * had added theirs.
     */
    public function test_rewrite_rules_are_flushed_after_init(): void
    {
        $llmsTxt = new LlmsTxt;
        delete_option(LlmsTxt::REWRITE_VERSION_OPTION);

        $llmsTxt->addRewriteRule();
        $this->assertFalse(get_option(LlmsTxt::REWRITE_VERSION_OPTION), 'Registering the rule must not flush.');

        $llmsTxt->maybeFlushRewriteRules();
        $this->assertSame(LlmsTxt::REWRITE_VERSION.':', get_option(LlmsTxt::REWRITE_VERSION_OPTION));
    }

    public function test_llms_txt_resolves_to_the_query_var(): void
    {
        flush_rewrite_rules(false);

        $this->go_to(home_url('/llms.txt'));

        $this->assertSame('1', get_query_var(LlmsTxt::QUERY_VAR));
        $this->assertTrue((new LlmsTxt)->isRequested());
    }

    /**
     * The rule is still registered while the deactivation hook runs; a plain
     * flush would write it straight back.
     */
    public function test_deactivating_takes_llms_txt_down(): void
    {
        $llmsTxt = new LlmsTxt;
        $llmsTxt->addRewriteRule();
        $llmsTxt->maybeFlushRewriteRules();

        $llmsTxt->deactivate();

        $this->assertFalse(get_option(LlmsTxt::REWRITE_VERSION_OPTION), 'A reactivation must flush the rule back in.');
        $this->go_to(home_url('/llms.txt'));
        $this->assertFalse((new LlmsTxt)->isRequested());

        $llmsTxt->addRewriteRule();
        $llmsTxt->maybeFlushRewriteRules();
        $this->go_to(home_url('/llms.txt'));
        $this->assertTrue((new LlmsTxt)->isRequested(), 'Reactivating brings it back.');
    }

    public function test_deactivating_drops_the_cached_document(): void
    {
        $llmsTxt = new LlmsTxt;
        $llmsTxt->flushPending();
        $llmsTxt->body('');

        // Pending until shutdown, so only deactivate() can have dropped the cache.
        update_option('blogname', 'Renamed');
        $llmsTxt->deactivate();
        $body = $llmsTxt->body('');
        $llmsTxt->flushPending();

        $this->assertStringStartsWith('# Renamed', $body);
    }

    public function test_kinsta_purges_each_url_without_its_scheme(): void
    {
        $this->assertSame([
            'single|gds-llms-0' => 'example.org/llms.txt',
            'single|gds-llms-1' => 'example.org/fi/llms.txt',
        ], Kinsta::request(['https://example.org/llms.txt', 'http://example.org/fi/llms.txt']));
    }

    /**
     * Kinsta's mu-plugin loads in DDEV too, where its purge endpoint does not
     * exist; a warning on every local save would be noise.
     */
    public function test_kinsta_is_not_purged_in_local_development(): void
    {
        global $kinsta_muplugin;

        $purge = new class
        {
            /** @var array<int, array<string, string>> */
            public array $requests = [];

            /**
             * @param  array<string, string>  $body
             * @return array<string, int>
             */
            public function send_cache_purge_request(string $url, array $body): array
            {
                $this->requests[] = $body;

                return ['response_code' => 200];
            }
        };
        $kinsta_muplugin = (object) [
            'kinsta_cache_purge' => $purge,
            'kinsta_cache' => (object) ['config' => ['immediate_path' => 'https://localhost/kinsta-clear-cache/v2/immediate']],
        ];

        (new LlmsTxt)->flushCache();
        $kinsta_muplugin = null;

        $this->assertSame('local', wp_get_environment_type());
        $this->assertSame([], $purge->requests);
    }

    public function test_the_query_var_alone_does_not_serve_llms_txt(): void
    {
        $page = self::factory()->post->create(['post_type' => 'page']);
        flush_rewrite_rules(false);

        $this->go_to(add_query_arg(LlmsTxt::QUERY_VAR, '1', get_permalink($page)));

        $this->assertFalse((new LlmsTxt)->isRequested());
    }

    /**
     * Whether $change drops the cached document, once the shutdown flush runs.
     *
     * The document filter is not a hooked input, so a body that picks up the
     * marker it adds can only have been rebuilt.
     */
    protected function flushesAfter(callable $change): bool
    {
        $llmsTxt = new LlmsTxt;
        // Start clean: no flush left pending, and no body cached by an earlier
        // call -- including the marked one this helper itself leaves behind.
        $llmsTxt->flushPending();
        $llmsTxt->flushCache();
        $llmsTxt->body('');

        $change();

        $marker = fn (array $document) => ['title' => 'Rebuilt'] + $document;
        add_filter('gds/llms_txt/document', $marker);
        $llmsTxt->flushPending();
        $rebuilt = str_starts_with($llmsTxt->body(''), '# Rebuilt');
        remove_filter('gds/llms_txt/document', $marker);

        return $rebuilt;
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
