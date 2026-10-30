<?php

namespace GeneroWP\Llms\Tests\Integration;

use GeneroWP\Llms\WebMcp;
use WP_UnitTestCase;

class WebMcpTest extends WP_UnitTestCase
{
    /**
     * The shape Gravity Forms renders: fields named input_{id} (input_{id}.{n}
     * for sub-inputs), the honeypot inside .gform_validation_container.
     */
    protected const FORM_HTML = <<<'HTML'
<div class="gform_wrapper"><form method="post" enctype="multipart/form-data" id="gform_3" action="/contact/">
<div class="gfield"><label for="input_3_1">Name</label><input name="input_1" id="input_3_1" type="text"></div>
<div class="gfield"><label for="input_3_2">Email</label><input name="input_2" id="input_3_2" type="email" aria-required="true"><div class="gfield_description">We reply within a day.</div></div>
<div class="gfield"><label for="input_3_7">Company</label><input name="input_7" id="input_3_7" type="text" aria-required="true"></div>
<div class="gfield"><label>First</label><input name="input_4.3" type="text"></div>
<div class="gfield gform_validation_container"><label>Comments</label><input name="input_9" type="text"></div>
<input type="submit" value="Send">
</form></div>
HTML;

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    protected function form(array $fields = [], string $description = ''): array
    {
        return [
            'id' => 3,
            'title' => 'Contact us',
            'description' => $description,
            'fields' => array_map(fn (array $field) => (object) ($field + ['description' => '', 'type' => 'text']), $fields ?: [
                ['id' => 1],
                ['id' => 2, 'type' => 'email', 'description' => 'We reply within a day.'],
                ['id' => 4, 'type' => 'name', 'description' => 'Your full name.'],
            ]),
        ];
    }

    public function test_a_gravity_form_becomes_a_declarative_tool(): void
    {
        $html = (new WebMcp)->gravityForm(self::FORM_HTML, $this->form());

        $this->assertStringContainsString('toolname="contact_us"', $html);
        $this->assertStringContainsString('tooldescription="Fill in the &quot;Contact us&quot; form for the visitor to send."', $html);
        $this->assertStringNotContainsString('toolautosubmit', $html, 'The visitor submits.');
        $this->assertStringContainsString('toolparamdescription="Required. We reply within a day."', $this->tag($html, 'input_2'));
        $this->assertStringContainsString('toolparamdescription="Required."', $this->tag($html, 'input_7'), 'GF marks required fields with aria-required only.');
        $this->assertStringContainsString('toolparamdescription="Your full name."', $this->tag($html, 'input_4.3'));
        $this->assertStringNotContainsString('toolparamdescription', $this->tag($html, 'input_1'), 'A field without a description keeps its label.');
    }

    /**
     * The tag of the control named $name (attribute order is the tag
     * processor's).
     */
    protected function tag(string $html, string $name): string
    {
        $this->assertSame(1, preg_match('/<(?:input|select|textarea)\b[^>]*\bname="'.preg_quote($name, '/').'"[^>]*>/', $html, $match), "No control named {$name}.");

        return $match[0];
    }

    public function test_the_form_description_is_the_tool_description(): void
    {
        $html = (new WebMcp)->gravityForm(self::FORM_HTML, $this->form([], '<p>Ask us <em>anything</em>.</p>'));

        $this->assertStringContainsString('tooldescription="Ask us anything."', $html);
    }

    public function test_the_honeypot_is_described_as_left_empty(): void
    {
        $html = (new WebMcp)->gravityForm(self::FORM_HTML, $this->form());

        $this->assertStringContainsString('toolparamdescription="Leave this empty."', $this->tag($html, 'input_9'));
    }

    public function test_forms_with_a_captcha_are_not_offered(): void
    {
        $form = $this->form([['id' => 1], ['id' => 5, 'type' => 'captcha']]);

        $this->assertSame(self::FORM_HTML, (new WebMcp)->gravityForm(self::FORM_HTML, $form));
    }

    public function test_a_form_can_be_opted_out(): void
    {
        $filter = fn () => false;
        add_filter('gds/llms/webmcp_form', $filter);
        $html = (new WebMcp)->gravityForm(self::FORM_HTML, $this->form());
        remove_filter('gds/llms/webmcp_form', $filter);

        $this->assertSame(self::FORM_HTML, $html);
    }

    /**
     * Two tools of one name: the second registration would be refused.
     */
    public function test_two_forms_with_one_title_get_distinct_names(): void
    {
        $webMcp = new WebMcp;
        $webMcp->gravityForm(self::FORM_HTML, $this->form());

        $this->assertStringContainsString('toolname="contact_us_2"', $webMcp->gravityForm(self::FORM_HTML, $this->form()));
    }

    public function test_the_first_search_form_becomes_a_tool(): void
    {
        $webMcp = new WebMcp;
        $form = '<form role="search" method="get" action="/"><label>Search for:<input type="search" name="s"></label><input type="submit" value="Search"></form>';

        $html = $webMcp->searchForm($form);

        $this->assertStringContainsString('toolname="search"', $html);
        $this->assertStringContainsString('tooldescription="Search this site and open the results page."', $html);
        $this->assertStringContainsString('toolparamdescription="What to search for."', $this->tag($html, 's'));
        $this->assertSame($form, $webMcp->searchForm($form), 'Only the first search form on a page.');
    }

    public function test_a_form_without_a_search_field_is_left_alone(): void
    {
        $form = '<form method="get" action="/"><input name="q"></form>';

        $this->assertSame($form, (new WebMcp)->searchForm($form));
    }

    public function test_core_search_form_is_a_tool(): void
    {
        add_filter('get_search_form', [$webMcp = new WebMcp, 'searchForm']);
        $html = get_search_form(['echo' => false]);
        remove_filter('get_search_form', [$webMcp, 'searchForm']);

        $this->assertStringContainsString('toolname="search"', $html);
    }

    public function test_webmcp_can_be_switched_off(): void
    {
        $webMcp = new WebMcp;
        add_filter('gds/llms/webmcp', '__return_false');
        $webMcp->register();
        remove_filter('gds/llms/webmcp', '__return_false');

        $this->assertFalse(has_filter('get_search_form', [$webMcp, 'searchForm']));
    }
}
