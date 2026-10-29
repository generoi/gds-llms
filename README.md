# gds-llms

Serves `/llms.txt` and a markdown version of every page for language models. An mu-plugin with no settings: install it and both are live.

## What it serves

**`/llms.txt`** follows the [llms.txt proposal](https://llmstxt.org/): the site name, a one-line summary, and a short orientation, then the site's own menus as sections. Every menu assigned to a location is listed once, the primary navigation first. Menu items that link to `#` become headings with everything beneath them as links; the primary menu's other top-level links go in the header, other menus' under the menu's name. A webshop's top-level product categories follow, then a link to the sitemap. With Polylang each language has its own file (`/fi/llms.txt`) built from that language's menus. The body is cached in a transient and rebuilt when posts, terms or menus change.

**Markdown pages** are served at the URL the proposal specifies: the permalink followed by `index.html.md` (`/about/` → `/about/index.html.md`), or `.md` where permalinks have no trailing slash. Only the post content is converted, not the theme around it. HTML pages advertise their markdown version with a `<link rel="alternate" type="text/markdown">` tag and a matching `Link` header.

Both responses carry `X-Robots-Tag: noindex`. With [sage-cachetags](https://github.com/generoi/sage-cachetags) installed they also carry surrogate keys, so the CDN is purged when the content changes; without it they are cached like any other page.

Content that a block draws with JavaScript (maps, iframes, API-fed carousels) has no HTML to convert, so it is missing from the markdown.

## Filters

| Filter | Default | Use |
|---|---|---|
| `gds/llms_txt/menus` | every location with a menu assigned, `primary_navigation` first | Drop a location that should not be listed, such as an intranet menu. The first entry is treated as the primary navigation. |
| `gds/llms_txt/taxonomies` | `['product_cat']` | Add a site's own product taxonomy. |
| `gds/llms_txt/document` | the built document | Everything else: `title`, `summary`, `intro` (sentences), `links` (header links) and `sections` (`heading`, `lines`). |
| `gds/llms/markdown_available` | published, viewable, not password-protected | Return `false` for content the theme hides behind a login. |

```php
// What the business does, which nothing on the site states in one sentence.
add_filter('gds/llms_txt/document', function (array $document) {
    array_unshift($document['intro'], 'Acme makes industrial pumps for the Nordic market.');

    return $document;
});

// Intranet pages are gated by their template, not by WordPress.
add_filter('gds/llms/markdown_available', fn (bool $available, WP_Post $post) => $available
    && get_page_template_slug($post) !== 'template-intra.php', 10, 2);
```

The markdown is the post content rendered outside the theme, so anything a template hides from logged-out visitors is served in full unless the site excludes it.

## Coexistence

- **Yoast SEO**: its own llms.txt is switched off, since a physical file it writes would shadow the rewrite rule.
- **Rank Math**: where its llms.txt module is enabled, this package's llms.txt stands down entirely.

## Installation

```bash
composer require generoi/gds-llms
```

Requires the Genero Satis repository (`https://generoi.github.io/packagist`) in the project's `composer.json`.

## Development

```bash
composer install
npm install
npm run env:start   # wp-env, needs Docker
npm run test:php    # PHPUnit integration suite
composer lint
composer stan
```

## Releasing

```bash
gh release create vX.Y.Z --title "vX.Y.Z" --notes "..."
```

Publishing the release triggers `.github/workflows/release.yml`, which rebuilds the Satis index.
