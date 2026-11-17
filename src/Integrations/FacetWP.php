<?php

namespace GeneroWP\Llms\Integrations;

use Dom\Element;
use GeneroWP\Llms\Format;
use GeneroWP\Llms\Markdown\Html;

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
     * Whether FacetWP filters this query: the main one, or one a listing
     * block flags. Only once FacetWP has initialised (see Listings::register()).
     */
    public static function isListing(\WP_Query $query): bool
    {
        return self::isActive() && FWP()->request->is_main_query($query);
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
            $label = $isPager ? __('Pages') : (string) ($output['settings']['labels'][$name] ?? $name);
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
            $count = '<p>'.esc_html(Format::resultCount((int) $pager['total_rows'], (int) ($pager['page'] ?? 1), (int) ($pager['total_pages'] ?? 1))).'</p>';
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
     * A hierarchical checkbox facet nests its children in `.facetwp-depth`
     * wrappers and gives a parent a `[+]` toggle to open them; the nesting
     * is kept as the choice's depth and the toggle dropped.
     *
     * @return list<array{string, string, int}> label, URL and depth
     */
    protected static function choices(string $name, string $facetHtml, string $base): array
    {
        $vars = FWP()->request->url_vars;
        unset($vars['paged']);
        $links = [];

        foreach (Html::parse($facetHtml)->querySelectorAll('option[value]:not([value=""]), [data-value]:not([data-value=""])') as $choice) {
            $value = (string) ($choice->getAttribute('value') ?: $choice->getAttribute('data-value'));
            // A checkbox's label and count are separate spans, run together in textContent.
            $parts = [];
            foreach ($choice->childNodes as $child) {
                if (! ($child instanceof Element && $child->classList->contains('facetwp-expand'))) {
                    $parts[] = $child->textContent;
                }
            }

            $depth = 0;
            for ($parent = $choice->parentElement; $parent; $parent = $parent->parentElement) {
                $depth += (int) $parent->classList->contains('facetwp-depth');
            }

            $links[] = [Format::text(implode(' ', $parts)), self::url($base, [$name => [$value]] + $vars), $depth];
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
     * @param  list<array{0: string, 1: string, 2?: int}>  $links  label, URL and depth
     */
    protected static function listHtml(string $label, array $links): string
    {
        if (! $links) {
            return '';
        }

        $html = '<ul>';
        $depth = 0;

        foreach ($links as $index => $link) {
            [$text, $url] = $link;
            $level = min($link[2] ?? 0, $depth + 1);

            if ($index > 0) {
                // A deeper choice opens a list inside the previous item; a
                // shallower one closes the lists it was in.
                $html .= $level > $depth ? '<ul>' : str_repeat('</li></ul>', $depth - $level).'</li>';
            }

            $html .= sprintf('<li><a href="%s">%s</a>', esc_url($url), esc_html($text));
            $depth = $level;
        }

        $html .= str_repeat('</li></ul>', $depth).'</li></ul>';

        return sprintf('<p><strong>%s</strong></p>%s', esc_html($label), $html);
    }
}
