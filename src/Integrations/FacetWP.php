<?php

namespace GeneroWP\Llms\Integrations;

use GeneroWP\Llms\Format;
use GeneroWP\Llms\Markdown\Dom;
use GeneroWP\Llms\Markdown\Listings;

/**
 * FacetWP prints each facet as an empty placeholder and fills it with
 * JavaScript, from facet HTML it renders on the server for its own preload
 * (FacetWP_Display::front_scripts() on wp_footer). This makes the same call
 * and turns every facet into links to the listing it filters: FacetWP filters
 * on the server from URL parameters (`?_industries=health`, `?_paged=2`), so
 * a reader can follow them where a dropdown would be dead markup.
 */
class FacetWP
{
    public static function isActive(): bool
    {
        return function_exists('FWP');
    }

    /**
     * From here on, see expandListing(). Not before wp_loaded: FacetWP builds
     * FWP()->request on init, and queries run before it -- ACF loading its
     * post types, say -- are not its listing.
     */
    public static function expandListings(): void
    {
        add_action('pre_get_posts', [self::class, 'expandListing']);
    }

    /**
     * The listing FacetWP filters -- which may be a block's own query, not
     * the main one -- in markdown-sized pages (see Listings). Before FacetWP's
     * own pre_get_posts (999) captures the query, so the result count and
     * pager it renders follow.
     */
    public static function expandListing(\WP_Query $query): void
    {
        if (self::isActive() && FWP()->request->is_main_query($query)) {
            Listings::expand($query);
        }
    }

    /**
     * $html with the facet placeholders FacetWP printed while rendering it
     * filled in as links, relative to the listing at $base.
     */
    public static function fill(string $html, string $base): string
    {
        if (! self::isActive() || ! FWP()->display->active_facets) {
            return $html;
        }

        $request = FWP()->request;
        $overrides = $request->process_preload_overrides([
            'facets' => FWP()->display->active_facets,
            'extras' => FWP()->display->active_extras,
        ]);
        $output = FWP()->facet->render($request->process_preload_data(false, $overrides));

        foreach ($output['facets'] ?? [] as $name => $facetHtml) {
            $facet = FWP()->helper->get_facet_by_name($name);
            $isPager = ($facet['type'] ?? '') === 'pager';
            // A pager's label is an admin name ("pagination") no visitor sees.
            $label = $isPager ? 'Pages' : (string) ($output['settings']['labels'][$name] ?? $name);
            $links = $isPager
                ? self::pages($base, (int) ($output['settings']['pager']['total_pages'] ?? 1))
                : self::choices($name, (string) $facetHtml, $base);

            $html = preg_replace_callback(
                '#<div class="facetwp-facet facetwp-facet-'.preg_quote((string) $name, '#').' [^>]*></div>#',
                fn () => self::listHtml($label, $links),
                $html,
            ) ?? throw new \RuntimeException(sprintf('Filling FacetWP facet %s failed: %s', $name, preg_last_error_msg()));
        }

        // How many results the filters give, whether or not the site shows
        // FacetWP's counts widget: a reader of one page cannot know otherwise.
        if (isset($output['settings']['pager']['total_rows'])) {
            $pager = $output['settings']['pager'];
            $count = '<p>'.esc_html(Listings::countText((int) $pager['total_rows'], (int) ($pager['page'] ?? 1), (int) ($pager['total_pages'] ?? 1))).'</p>';
            $html = preg_replace_callback('#<div class="[^"]*\bfacetwp-template\b#', fn (array $match) => $count.$match[0], $html, 1)
                ?? throw new \RuntimeException(sprintf('Adding the FacetWP result count failed: %s', preg_last_error_msg()));
        }

        return $html;
    }

    /**
     * A facet's choices, read from the HTML FacetWP rendered for it:
     * dropdown `<option>`s, and the `data-value` of checkboxes, radios and
     * the like.
     *
     * @return list<array{string, string}> label and URL
     */
    protected static function choices(string $name, string $facetHtml, string $base): array
    {
        $dom = new Dom('<html><body>'.$facetHtml.'</body></html>');
        $vars = FWP()->request->url_vars;
        unset($vars['paged']);
        $links = [];

        foreach ($dom->query('//option[@value!=""]|//*[@data-value!=""]') as $choice) {
            $value = $choice->getAttribute('value') ?: $choice->getAttribute('data-value');
            // A checkbox's label and count are separate spans, run together in textContent.
            $parts = [];
            foreach ($choice->childNodes as $child) {
                $parts[] = $child->textContent;
            }
            $links[] = [Format::text(implode(' ', $parts)), self::url($base, [$name => [$value]] + $vars)];
        }

        return $links;
    }

    /**
     * Every page of the listing; the pager FacetWP renders shows a window of
     * them.
     *
     * @return list<array{string, string}> label and URL
     */
    protected static function pages(string $base, int $total): array
    {
        if ($total <= 1) {
            return [];
        }

        $vars = FWP()->request->url_vars;
        $links = [];

        for ($page = 1; $page <= $total; $page++) {
            $links[] = [(string) $page, self::url($base, $page === 1 ? array_diff_key($vars, ['paged' => 1]) : ['paged' => $page] + $vars)];
        }

        return $links;
    }

    /**
     * @param  array<string, mixed>  $vars  facet name => selected values
     */
    protected static function url(string $base, array $vars): string
    {
        $prefix = (string) FWP()->helper->get_setting('prefix', '_');
        $query = [];

        foreach ($vars as $name => $value) {
            $query[$prefix.$name] = implode(',', (array) $value);
        }

        return $query ? $base.'?'.http_build_query($query) : $base;
    }

    /**
     * @param  list<array{string, string}>  $links  label and URL
     */
    protected static function listHtml(string $label, array $links): string
    {
        if (! $links) {
            return '';
        }

        $items = '';
        foreach ($links as [$text, $url]) {
            $items .= sprintf('<li><a href="%s">%s</a></li>', esc_url($url), esc_html($text));
        }

        return sprintf('<p><strong>%s</strong></p><ul>%s</ul>', esc_html($label), $items);
    }
}
