# gds-llms

Serves `/llms.txt` and a markdown version of every page for language models. An mu-plugin with no settings: install it and both are live.

## What it serves

**`/llms.txt`** follows the [llms.txt proposal](https://llmstxt.org/): the site name, a one-line summary, and a short orientation, then the site's own menus as sections. Every menu assigned to a location is listed once, the primary navigation first. Menu items that link to `#` become headings with everything beneath them as links; the primary menu's other top-level links go in the header, other menus' under the menu's name. A webshop's top-level product categories follow, then a link to the sitemap. With Polylang each language has its own file (`/fi/llms.txt`) built from that language's menus. The body is cached in a transient and rebuilt when posts, terms or menus change.

**Markdown pages** are served at the URL the proposal specifies: the page URL followed by `index.html.md` (`/about/` → `/about/index.html.md`), or `.md` where permalinks have no trailing slash. HTML pages advertise their markdown version with a `<link rel="alternate" type="text/markdown">` tag and a matching `Link` header. Two modes, set with `gds/llms/markdown_mode`:

- **`content`** (default): the post content of a singular page, about two thirds the cost of rendering the page. Anything a template draws itself -- recipe ingredients from post meta, WooCommerce prices -- is missing. A listing (blog, category or tag, post type archive, shop) is built from its query: title and introduction, result count, each post as a link with its description and a product's price, then the pages. So is a page that declares a listing through `gds/llms/markdown_listing` -- sage-archive-pages does for the pages it maps to a post type -- unless its content already links the posts it would list (a FacetWP or Query Loop block).

Either way a markdown listing has up to 100 results per page, so most come whole: archives' main queries, FacetWP listings, and Query Loop blocks that have a pager (one without is a fixed selection and keeps its size).
- **`page`**: the whole page rendered as a visitor sees it, its `<main>` converted. Costs what the page costs, and includes template content and archives.

Either way the markdown is answered after every `template_redirect` hook, so a login wall that redirects there stops it as it stops the page. Conversion follows screen-reader semantics: `aria-hidden` content and `alt=""` images are dropped, accordion questions and tab names kept, forms described by their field labels, iframes turned into links.

The output follows Cloudflare's [Markdown for Agents](https://developers.cloudflare.com/fundamentals/reference/markdown-for-agents/) layout: YAML frontmatter (`title`, `description`, `image`, `url`, `language`, `translations`, `modified` -- only those the page has; `translations` maps each other language's hreflang to its markdown URL, from Polylang), the content, then the page's JSON-LD in a fenced `json` block, with an `X-Markdown-Tokens` estimate header. In `content` mode the metadata and schema come from Yoast where it is active.

**`robots.txt`** states the site's AI usage policy in both emerging vocabularies, in a `User-agent: *` group of its own at the end (crawlers merge it with the site's own `*` group): Cloudflare's [Content Signals](https://blog.cloudflare.com/content-signals-policy/) (`Content-Signal: search=yes, ai-input=yes, ai-train=yes`) and the IETF [aipref](https://datatracker.ietf.org/wg/aipref/about/) draft (`Content-Usage: train-ai=y, ai-use=y, search=y`), with Cloudflare's policy notice, which makes a declined use an express reservation of rights under EU copyright law. No crawler honours either yet, so a declined use also gets a `Disallow: /` group for the crawlers known to do it. Everything is allowed by default, and everything declined on a site that discourages search engines (Settings → Reading). Only WordPress' virtual robots.txt is changed; a physical `robots.txt` in the web root is served as is.

Both responses carry `X-Robots-Tag: noindex`, and the same policy as `Content-Signal` and `Content-Usage` headers. With [sage-cachetags](https://github.com/generoi/sage-cachetags) installed they also carry surrogate keys, so the CDN is purged when the content changes; without it they are cached like any other page.

Content that a block draws with JavaScript (maps, HubSpot forms, API-fed carousels) has no HTML to convert, so it is missing from the markdown.

## Filters

| Filter | Default | Use |
|---|---|---|
| `gds/llms_txt/menus` | every location with a menu assigned, `primary_navigation` first | Drop a location that should not be listed, such as an intranet menu. The first entry is treated as the primary navigation. |
| `gds/llms_txt/taxonomies` | `['product_cat']` | Add a site's own product taxonomy. |
| `gds/llms_txt/document` | the built document | Everything else: `title`, `summary`, `intro` (sentences keyed `language`, `translations`, `markdown`, `commerce`, `catalogue`, `network`), `links` (header links) and `sections` (`heading`, `lines`). |
| `gds/llms_txt/network` | `false` | On a multisite, return `true` to link the network's other sites ("Related sites: …"), for sister sites a reader should know about. |
| `gds/llms/ai_policy` | `true` for each of `search`, `ai-input`, `ai-train`; `false` when the site discourages search engines | Decline a use: `ai-train` for model training, `ai-input` for AI search and answers to users' questions. |
| `gds/llms/markdown_mode` | `content` | `page` for sites whose templates draw content from post meta, or gate content behind a login form in the template. |
| `gds/llms/markdown_content` | the rendered post content | In `content` mode, add what the template renders outside the content, such as a product's price. |
| `gds/llms/markdown_listing` | `null` | In `content` mode, the `WP_Query` arguments of what a page lists beneath its content when its template renders the listing (`['post_type' => 'case']`). Gets the page's `WP_Post`. |
| `gds/llms/markdown_listing_item` | link, product price, description | In a listing this package builds (archives in `content` mode), one post's line as HTML: add its date, author or a field. Gets the `WP_Post`. |
| `gds/llms/markdown_per_page` | 100, or the listing's own size where larger or unlimited | Results per markdown page of a listing; gets the listing's `WP_Query`, or the Query Loop block. Return the size it has on the HTML page to leave it as it is. |
| `gds/llms/markdown_available` | not a 404, feed or embed; in `content` mode only singular, not password-protected | Return `false` for content a template hides behind a login, in `content` mode. |

```php
// What the business does, which nothing on the site states in one sentence.
add_filter('gds/llms_txt/document', function (array $document) {
    $document['intro'] = ['about' => 'Acme makes industrial pumps for the Nordic market.'] + $document['intro'];

    return $document;
});

// Sister magazines on one network, worth pointing a reader at.
add_filter('gds/llms_txt/network', '__return_true');

// Product price and short description, which the theme's template renders.
add_filter('gds/llms/markdown_content', function (string $html, WP_Post $post) {
    if ($post->post_type !== 'product' || ! $product = wc_get_product($post)) {
        return $html;
    }

    return sprintf('<p>Price: %s</p>%s', $product->get_price_html(), wpautop($product->get_short_description())).$html;
}, 10, 2);

// Or render the whole page instead, template content included.
add_filter('gds/llms/markdown_mode', fn () => 'page');

// Or, staying in content mode: intranet pages are gated by their template.
add_filter('gds/llms/markdown_available', fn (bool $available, mixed $object) => $available
    && ! ($object instanceof WP_Post && get_page_template_slug($object) === 'template-intra.php'), 10, 2);
```

## Coexistence

- **Yoast SEO**: its own llms.txt is switched off, since a physical file it writes would shadow the rewrite rule.
- **Rank Math**: where its llms.txt module is enabled, this package's llms.txt stands down entirely.

## Translations

Finnish and Swedish ship in `resources/lang/` (text domain `gds-llms`); a site's own `languages/plugins/gds-llms-{locale}.mo` takes precedence. Each language's llms.txt is built in that language's locale, markdown in the page's. Where core already has the string -- `Pages`, `Page not found`, list separators -- core's translation is used. The `Optional` heading and robots.txt stay English: their wording is part of the format.

After changing a string:

```bash
npx wp-env run cli wp i18n make-pot wp-content/mu-plugins/gds-llms wp-content/mu-plugins/gds-llms/resources/lang/gds-llms.pot --domain=gds-llms --include=src
# update the .po files, then
npx wp-env run cli wp i18n make-mo wp-content/mu-plugins/gds-llms/resources/lang
```

Only `.mo` files are shipped, not `.l10n.php`: a site's linter (pint) runs over its mu-plugins directory and would fail the generated PHP.

## Installation

```bash
composer require generoi/gds-llms
```

Published on [Packagist](https://packagist.org/packages/generoi/gds-llms).

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

Packagist picks up the new tag through its GitHub hook.
