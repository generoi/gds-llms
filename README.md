# gds-llms

Serves `/llms.txt` and a markdown version of every page for language models. An mu-plugin with no settings: install it and both are live.

## What it serves

**`/llms.txt`** follows the [llms.txt proposal](https://llmstxt.org/): the site name, a one-line summary, and a short orientation, then the site's own navigation as sections. Primary-menu items that link to `#` become headings and their children the links under them, footer menus become one section each, and a webshop's top-level product categories are listed. It ends with a link to the sitemap. The body is cached in a transient and rebuilt when posts, terms or menus change.

**Markdown pages** are served at the URL the proposal specifies: the permalink followed by `index.html.md` (`/about/` → `/about/index.html.md`), or `.md` where permalinks have no trailing slash. Only the post content is converted, not the theme around it. HTML pages advertise their markdown version with a `<link rel="alternate" type="text/markdown">` tag and a matching `Link` header.

Both responses carry `X-Robots-Tag: noindex`. With [sage-cachetags](https://github.com/generoi/sage-cachetags) installed they also carry surrogate keys, so the CDN is purged when the content changes; without it they are cached like any other page.

Content that a block draws with JavaScript (maps, iframes, API-fed carousels) has no HTML to convert, so it is missing from the markdown.

## Filters

| Filter | Default |
|---|---|
| `gds/llms_txt/about` | `''`: a sentence on what the business does, placed first in the intro |
| `gds/llms_txt/intro` | the generated intro |
| `gds/llms_txt/summary` | the front page's description, else the tagline |
| `gds/llms_txt/primary_menu` | `primary_navigation` |
| `gds/llms_txt/secondary_menus` | every registered location containing `footer` |
| `gds/llms_txt/catalogue_taxonomies` | `['product_cat']` |
| `gds/llms_txt/section_label` | the catalogue taxonomy's landing page title, else its label |
| `gds/llms/markdown_post_types` | every public post type except `attachment` |

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
