<?php

namespace GeneroWP\Llms\LlmsTxt;

use GeneroWP\Llms\Format;

/**
 * The site's menus as llms.txt sections.
 *
 * @phpstan-import-type Section from Document
 * @phpstan-import-type Link from Document
 */
class Menus
{
    /**
     * Posts the listed links point at, for the document's cache tags.
     *
     * @var int[]
     */
    protected array $posts = [];

    /**
     * @return int[]
     */
    public function posts(): array
    {
        return $this->posts;
    }

    /**
     * Every menu assigned to a location, keyed by location, primary first.
     *
     * Locations are named differently on nearly every site, so rather than
     * guessing which ones matter all assigned ones are used and a site removes
     * what it does not want listed. A menu assigned to two locations -- the
     * mobile navigation usually reuses the primary one -- appears once.
     *
     * @return array<string, int>
     */
    public function locations(): array
    {
        $assigned = array_filter(get_nav_menu_locations());
        $locations = array_keys(array_intersect_key(get_registered_nav_menus(), $assigned));
        usort($locations, fn (string $a, string $b) => ($b === 'primary_navigation') <=> ($a === 'primary_navigation'));

        /**
         * The menu locations listed, in order. The first is treated as the
         * primary navigation.
         *
         * @param  string[]  $locations
         */
        $locations = (array) apply_filters('gds/llms_txt/menus', $locations);

        $menus = [];

        foreach ($locations as $location) {
            $menuId = (int) ($assigned[$location] ?? 0);

            if ($menuId && ! in_array($menuId, $menus, true)) {
                $menus[$location] = $menuId;
            }
        }

        return $menus;
    }

    /**
     * Each menu as its loose links plus one section per dropdown.
     *
     * A top-level item with children -- a dropdown, a mega menu column, a footer
     * column -- becomes a heading, with its own link (unless it is a `#`
     * placeholder) and everything beneath it at any depth as the links under it.
     * Childless top-level items are loose links, filed under the menu's own name,
     * which is what the client called it.
     *
     * Each URL is listed once, where it first appears: footers mostly repeat the
     * primary navigation, and a model gains nothing from reading it twice. A
     * description shared by several links -- typically a Yoast template, or the
     * front page's text inherited by every page without its own -- says nothing
     * about any of them and is dropped, as is one that repeats the summary.
     *
     * @return list<array{Section, Section[]}>
     */
    public function sections(string $summary): array
    {
        $menus = [];
        $seen = [];

        foreach ($this->locations() as $menuId) {
            $object = wp_get_nav_menu_object($menuId);
            $children = [];

            foreach (wp_get_nav_menu_items($menuId) ?: [] as $item) {
                $children[(int) $item->menu_item_parent][] = $item;
            }

            $loose = [];
            $groups = [];

            foreach ($children[0] ?? [] as $item) {
                $links = $this->links($item, $children, $seen);

                if (isset($children[$item->ID])) {
                    $groups[] = ['heading' => Format::text($item->title), 'links' => $links];
                } else {
                    $loose = array_merge($loose, $links);
                }
            }

            $menus[] = [['heading' => $object ? Format::text($object->name) : '', 'links' => $loose], $groups];
        }

        // The summary has already said its text once.
        $descriptions = $summary !== '' ? [$summary => 1] : [];

        foreach ($menus as [$loose, $groups]) {
            foreach (array_merge([$loose], $groups) as $section) {
                foreach ($section['links'] as $link) {
                    if ($link['description'] !== '') {
                        $descriptions[$link['description']] = ($descriptions[$link['description']] ?? 0) + 1;
                    }
                }
            }
        }

        $format = fn (array $section) => [
            'heading' => $section['heading'],
            'lines' => array_map(fn (array $link) => Format::linkLine(
                $link['title'],
                $link['url'],
                ($descriptions[$link['description']] ?? 0) > 1 ? '' : $link['description'],
            ), $section['links']),
        ];

        return array_map(fn (array $menu) => [
            $format($menu[0]),
            array_values(array_filter(array_map($format, $menu[1]), fn (array $group) => $group['lines'] !== [])),
        ], $menus);
    }

    /**
     * An item's own link followed by its descendants', depth first, skipping
     * placeholders and URLs already listed.
     *
     * @param  array<int, object[]>  $children
     * @param  array<string, bool>  $seen
     * @return Link[]
     */
    protected function links(object $item, array $children, array &$seen): array
    {
        $links = [];
        $url = $this->absoluteUrl((string) $item->url);
        $key = untrailingslashit($url);
        $postId = $item->type === 'post_type' ? (int) $item->object_id : 0;

        if ($postId) {
            $this->posts[] = $postId;
        }

        // WordPress only drops a menu item whose page is trashed. A draft,
        // private or scheduled one stays in the menu, and its excerpt would end
        // up in a document cached for every visitor.
        $public = ! $postId || is_post_publicly_viewable($postId);

        if ($url !== '' && $public && ! isset($seen[$key])) {
            $seen[$key] = true;
            $title = Format::text($item->title);

            $links[] = [
                // A label of only symbols (an `@` icon for an email link) reads as nothing.
                'title' => preg_match('/[\p{L}\p{N}]/u', $title) ? $title : Format::replace('#^(mailto:|tel:|https?://)#', '', $url),
                'url' => $url,
                // A password-protected page's excerpt is WordPress' "There is no excerpt" notice.
                'description' => $postId && ! post_password_required($postId) ? Document::description($postId) : '',
            ];
        }

        foreach ($children[$item->ID] ?? [] as $child) {
            $links = array_merge($links, $this->links($child, $children, $seen));
        }

        return $links;
    }

    /**
     * A menu item's URL made absolute, or '' when it is not a link at all: `#`
     * placeholders, in-page anchors that open a cookie dialog, empty custom links.
     */
    protected function absoluteUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '' || str_starts_with($url, '#') || str_starts_with(strtolower($url), 'javascript:')) {
            return '';
        }

        return \WP_Http::make_absolute_url($url, home_url('/'));
    }
}
