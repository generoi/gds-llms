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
     * A top-level item with children -- a dropdown, a mega menu, a footer column
     * -- becomes a heading, with its own link (unless it is a `#` placeholder)
     * and its children as the links under it. Deeper levels keep the menu's
     * nesting: a mega menu column is a line of its own with its links indented
     * beneath it, as a link when it has one, else as the label it is on the page.
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

        foreach ($this->locations() as $location => $menuId) {
            $object = wp_get_nav_menu_object($menuId);
            $children = [];

            /**
             * A menu's items as llms.txt reads them: replace one that a menu
             * plugin renders itself -- a mega menu panel standing in for a
             * page list -- with the items it stands for.
             *
             * @param  object[]  $items  wp_get_nav_menu_items() objects
             * @param  string  $location  the menu location
             */
            $items = (array) apply_filters('gds/llms_txt/menu_items', wp_get_nav_menu_items($menuId) ?: [], $location);

            foreach ($items as $item) {
                // Polylang's language switcher: the intro already links every
                // language's llms.txt. Its dropdown's languages hang off the
                // parent, so skipping that skips them too.
                if (array_intersect(['lang-item', 'pll-parent-menu-item'], (array) $item->classes)) {
                    continue;
                }

                $children[(int) $item->menu_item_parent][] = $item;
            }

            $loose = [];
            $groups = [];

            foreach ($children[0] ?? [] as $item) {
                if (! isset($children[$item->ID])) {
                    $loose = array_merge($loose, $this->links($item, $children, $seen, 0));

                    continue;
                }

                // The heading's own link, then its children at the section's top level.
                $links = array_filter([$this->link($item, $seen, 0)]);
                foreach ($children[$item->ID] as $child) {
                    $links = array_merge($links, $this->links($child, $children, $seen, 0));
                }

                $groups[] = ['heading' => Format::text($item->title), 'links' => array_values($links)];
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
            'lines' => array_map(fn (array $link) => str_repeat('  ', $link['depth']).($link['url'] === ''
                ? '- '.Format::text($link['title'])
                : Format::linkLine(
                    $link['title'],
                    $link['url'],
                    ($descriptions[$link['description']] ?? 0) > 1 ? '' : $link['description'],
                )), $section['links']),
        ];

        return array_map(fn (array $menu) => [
            $format($menu[0]),
            array_values(array_filter(array_map($format, $menu[1]), fn (array $group) => $group['lines'] !== [])),
        ], $menus);
    }

    /**
     * An item's own link followed by its descendants', depth first and one level
     * deeper, skipping placeholders and URLs already listed. An item without a
     * link of its own that still has links beneath it stays as a label, so its
     * children keep their group.
     *
     * @param  array<int, object[]>  $children
     * @param  array<string, bool>  $seen
     * @return Link[]
     */
    protected function links(object $item, array $children, array &$seen, int $depth): array
    {
        $link = $this->link($item, $seen, $depth);

        $descendants = [];
        foreach ($children[$item->ID] ?? [] as $child) {
            $descendants = array_merge($descendants, $this->links($child, $children, $seen, $depth + 1));
        }

        if (! $link && $descendants && ($title = Format::text($item->title)) !== '') {
            $link = ['title' => $title, 'url' => '', 'description' => '', 'depth' => $depth];
        }

        return $link ? [$link, ...$descendants] : $descendants;
    }

    /**
     * The item's own link, or null for a placeholder, a page that is not
     * public, or a URL already listed.
     *
     * @param  array<string, bool>  $seen
     * @return Link|null
     */
    protected function link(object $item, array &$seen, int $depth): ?array
    {
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

        if ($url === '' || ! $public || isset($seen[$key])) {
            return null;
        }

        $seen[$key] = true;
        $title = Format::text($item->title);

        return [
            // A label of only symbols (an `@` icon for an email link) reads as nothing.
            'title' => preg_match('/[\p{L}\p{N}]/u', $title) ? $title : Format::replace('#^(mailto:|tel:|https?://)#', '', $url),
            'url' => $url,
            // A password-protected page's excerpt is WordPress' "There is no excerpt" notice.
            'description' => $postId && ! post_password_required($postId) ? Document::description($postId) : '',
            'depth' => $depth,
        ];
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
