<?php

namespace GeneroWP\Llms\Tests\Integration;

use GeneroWP\Llms\Format;
use GeneroWP\Llms\LlmsTxt\Document;
use GeneroWP\Llms\Markdown\Converter;
use WP_Locale_Switcher;
use WP_UnitTestCase;

/**
 * The bundled fi and sv_SE translations, loaded just in time from
 * resources/lang/.
 */
class TranslationsTest extends WP_UnitTestCase
{
    protected WP_Locale_Switcher $originalSwitcher;

    /**
     * switch_to_locale() only switches to a locale whose core language files
     * are installed, and the test install has none; the switcher reads the
     * list once, so it is rebuilt with the two added.
     */
    public function set_up(): void
    {
        parent::set_up();

        global $wp_locale_switcher;
        $this->originalSwitcher = $wp_locale_switcher;

        add_filter('get_available_languages', fn (array $languages) => [...$languages, 'fi', 'sv_SE']);
        $wp_locale_switcher = new WP_Locale_Switcher;
        $wp_locale_switcher->init();
    }

    public function tear_down(): void
    {
        restore_current_locale();

        global $wp_locale_switcher;
        $wp_locale_switcher = $this->originalSwitcher;

        parent::tear_down();
    }

    public function test_result_count_is_translated_with_plurals(): void
    {
        switch_to_locale('fi');

        $this->assertSame('64 tulosta, sivu 1/8.', Format::resultCount(64, 1, 8));
        $this->assertSame('1 tulos.', Format::resultCount(1, 1, 1));

        switch_to_locale('sv_SE');

        $this->assertSame('64 träffar, sida 1 av 8.', Format::resultCount(64, 1, 8));
    }

    public function test_form_note_keeps_its_link_in_translation(): void
    {
        switch_to_locale('fi');

        $markdown = (new Converter('https://example.com/page/'))->page(
            '<html><head><title>Page</title></head><body><main><form aria-label="Contact us"><label for="e">Email</label><input id="e"></form></main></body></html>'
        );

        $this->assertStringContainsString('**Lomake: Contact us** (täytä se [verkkosivulla](https://example.com/page/)). Kentät:', $markdown);
    }

    /**
     * Built in the requested language whatever locale the request runs in,
     * and the request's own locale is back afterwards.
     */
    public function test_llms_txt_is_built_in_its_own_locale(): void
    {
        $body = (new Document)->build('sv_SE');

        $this->assertStringContainsString('Språk: ', $body);
        $this->assertStringNotContainsString('Written in', $body);
        $this->assertSame('en_US', get_locale());
    }
}
