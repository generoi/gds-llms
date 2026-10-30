<?php

namespace GeneroWP\Llms;

use GeneroWP\Llms\Integrations\FacetWP;
use WP_HTML_Tag_Processor;

/**
 * WebMCP: lets an AI agent in the browser use a page's forms and filters as
 * tools instead of guessing which inputs to fill.
 *
 * - Gravity Forms and search forms get the declarative attributes Chrome
 *   reads (`toolname`, `tooldescription` on the form, `toolparamdescription`
 *   on fields). Chrome derives the parameters from the fields' names and
 *   labels, fills them in, and leaves the submit to the visitor: there is no
 *   `toolautosubmit`.
 * - FacetWP has no form, so its facets are registered as one tool through
 *   `document.modelContext.registerTool()`.
 *
 * The declarative API is Chrome's (origin trial from Chrome 149) and not yet
 * in the WebMCP spec; the imperative one follows the spec's CG draft. Browsers
 * without WebMCP ignore both. Off with `gds/llms/webmcp`.
 */
class WebMcp
{
    /**
     * Facet types that are navigation rather than a filter.
     */
    public const SKIPPED_FACETS = ['pager', 'reset', 'user_selections'];

    /** @var array<string, int> */
    protected array $toolNames = [];

    protected bool $search = false;

    public function register(): void
    {
        if (! apply_filters('gds/llms/webmcp', true)) {
            return;
        }

        add_filter('gform_get_form_filter', [$this, 'gravityForm'], 10, 2);
        add_filter('get_search_form', [$this, 'searchForm']);
        add_filter('render_block_core/search', [$this, 'searchForm']);
        add_action('wp_footer', [$this, 'printFacetWpTool'], 100);
    }

    /**
     * @param  array<string, mixed>  $form
     */
    public function gravityForm(string $html, array $form): string
    {
        /**
         * Whether a Gravity Form is offered to agents. Forms with a captcha
         * are not by default: an agent can fill them in but never submit.
         *
         * @param  bool  $enabled
         * @param  array<string, mixed>  $form
         */
        if (! apply_filters('gds/llms/webmcp_form', ! self::hasCaptcha($form), $form)) {
            return $html;
        }

        $descriptions = [];
        foreach ($form['fields'] ?? [] as $field) {
            $description = Format::text((string) ($field->description ?? ''));
            if ($description !== '') {
                $descriptions[(string) $field->id] = $description;
            }
        }

        $title = Format::text((string) ($form['title'] ?? ''));
        $description = Format::text((string) ($form['description'] ?? ''));
        /* translators: %s: form title */
        $toolDescription = $description !== '' ? $description : sprintf(__('Fill in the "%s" form for the visitor to send.', 'gds-llms'), $title);

        $tags = new WP_HTML_Tag_Processor($html);
        if (! $tags->next_tag(['tag_name' => 'form'])) {
            return $html;
        }
        $tags->set_attribute('toolname', $this->toolName($title !== '' ? $title : 'form'));
        $tags->set_attribute('tooldescription', $toolDescription);

        $honeypot = false;
        while ($tags->next_tag()) {
            $tag = $tags->get_tag();

            // GF's honeypot is a visually hidden field filled in only by bots.
            if ($tag === 'DIV' && $tags->has_class('gform_validation_container')) {
                $honeypot = true;

                continue;
            }

            if (! in_array($tag, ['INPUT', 'SELECT', 'TEXTAREA'], true)) {
                continue;
            }

            if ($honeypot) {
                $tags->set_attribute('toolparamdescription', __('Leave this empty.', 'gds-llms'));
                $honeypot = false;

                continue;
            }

            if (! preg_match('/^input_(\d+)(?:[._]\d+)?$/', (string) $tags->get_attribute('name'), $match) || $tags->get_attribute('toolparamdescription') !== null) {
                continue;
            }

            // GF marks required fields with aria-required, not `required`, so
            // Chrome's schema would list them as optional. Adding `required`
            // would change how the form looks and validates for visitors.
            $parts = array_filter([
                $tags->get_attribute('aria-required') === 'true' ? __('Required.', 'gds-llms') : '',
                $descriptions[$match[1]] ?? '',
            ]);
            if ($parts) {
                $tags->set_attribute('toolparamdescription', implode(' ', $parts));
            }
        }

        return $tags->get_updated_html();
    }

    /**
     * The first search form on the page; a second tool with the same name
     * would be refused, and one is enough.
     */
    public function searchForm(string $html): string
    {
        if ($this->search) {
            return $html;
        }

        $tags = new WP_HTML_Tag_Processor($html);
        if (! $tags->next_tag(['tag_name' => 'form'])) {
            return $html;
        }
        $tags->set_bookmark('form');

        while ($tags->next_tag(['tag_name' => 'input'])) {
            if ($tags->get_attribute('name') === 's') {
                $tags->set_attribute('toolparamdescription', __('What to search for.', 'gds-llms'));
                $tags->seek('form');
                $tags->set_attribute('toolname', $this->toolName('search'));
                $tags->set_attribute('tooldescription', __('Search this site and open the results page.', 'gds-llms'));
                $this->search = true;

                return $tags->get_updated_html();
            }
        }

        return $html;
    }

    /**
     * One tool for the page's facets, registered once FacetWP has loaded them
     * -- their choices are only in the DOM after that.
     */
    public function printFacetWpTool(): void
    {
        if (! FacetWP::isActive() || ! FWP()->display->active_facets) {
            return;
        }

        $config = [
            'name' => $this->toolName('filter_results'),
            'skip' => self::SKIPPED_FACETS,
            'description' => __('Filter the results listed on this page. Every parameter is optional; omitted filters keep their current value, and an empty list clears one. Returns the filtered page URL, the number of results and the first results.', 'gds-llms'),
            /* translators: %s: comma-separated list of choices */
            'choices' => __('Choices: %s.', 'gds-llms'),
        ];

        wp_print_inline_script_tag(sprintf(self::FACETWP_SCRIPT, wp_json_encode($config)), ['id' => 'gds-llms-webmcp-facetwp']);
    }

    /**
     * A tool name the spec accepts (ASCII letters, digits, `_`, `-`, `.`;
     * at most 128) and unique on the page: two forms with one title would
     * otherwise collide, and the second registration be refused.
     */
    protected function toolName(string $label): string
    {
        $name = trim((string) preg_replace('/[^a-z0-9]+/', '_', remove_accents(strtolower($label))), '_') ?: 'tool';
        $name = substr($name, 0, 120);
        $count = $this->toolNames[$name] = ($this->toolNames[$name] ?? 0) + 1;

        return $count > 1 ? $name.'_'.$count : $name;
    }

    /**
     * @param  array<string, mixed>  $form
     */
    protected static function hasCaptcha(array $form): bool
    {
        foreach ($form['fields'] ?? [] as $field) {
            if (in_array($field->type ?? '', ['captcha', 'turnstile'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sets the facets and refreshes the way FacetWP's own reset does
     * (`is_reset` stops refresh() reading the values back from the UI), then
     * resolves on FacetWP's next `facetwp-loaded`.
     */
    protected const FACETWP_SCRIPT = <<<'JS'
(() => {
  if (!('modelContext' in document)) return;
  const config = %s;
  const facetElements = () => [...document.querySelectorAll('.facetwp-facet[data-name]')]
    .filter((el) => !config.skip.includes(FWP.facet_type[el.dataset.name] || el.dataset.type));
  const choices = (el) => [...el.querySelectorAll('option[value]:not([value=""]), [data-value]:not([data-value=""])')]
    .map((choice) => ({ value: choice.value || choice.dataset.value, label: choice.textContent.trim().replace(/\s+/g, ' ') }));
  const loaded = () => new Promise((resolve) => document.addEventListener('facetwp-loaded', resolve, { once: true }));
  // A card is often one link around its tags and excerpt too: its heading names it.
  const title = (a) => ((a.querySelector('h1, h2, h3, h4, h5, h6') || {}).textContent || a.getAttribute('aria-label') || a.textContent).trim().replace(/\s+/g, ' ');

  let registered = false;
  const register = () => {
    if (registered || !window.FWP) return;
    const properties = {};
    for (const el of facetElements()) {
      const name = el.dataset.name;
      const options = choices(el);
      const label = (FWP.settings.labels && FWP.settings.labels[name]) || name;
      properties[name] = {
        type: 'array',
        items: options.length ? { type: 'string', enum: options.map((o) => o.value) } : { type: 'string' },
        description: options.length ? `${label}. ${config.choices.replace('%%s', options.map((o) => `${o.label} (${o.value})`).join(', '))}` : label,
      };
    }
    if (!Object.keys(properties).length) return;
    registered = true;
    document.modelContext.registerTool({
      name: config.name,
      description: config.description,
      inputSchema: { type: 'object', properties },
      annotations: { readOnlyHint: true },
      execute: async (input) => {
        for (const [name, value] of Object.entries(input || {})) {
          if (name in properties) FWP.facets[name] = (Array.isArray(value) ? value : [value]).map(String);
        }
        const done = loaded();
        FWP.is_reset = true;
        FWP.refresh();
        await done;
        const seen = new Set();
        const results = [...document.querySelectorAll('.facetwp-template a[href]')]
          .filter((a) => title(a) && !seen.has(a.href) && seen.add(a.href))
          .slice(0, 20)
          .map((a) => ({ title: title(a), url: a.href }));
        return { url: location.href, total: FWP.settings.pager ? FWP.settings.pager.total_rows : results.length, results };
      },
    }).catch((error) => console.error('gds-llms WebMCP:', error));
  };
  document.addEventListener('facetwp-loaded', register);
  // FacetWP may have loaded before this script ran.
  if (window.FWP && FWP.loaded) register();
})();
JS;
}
