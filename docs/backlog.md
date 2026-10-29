# Backlog

From the block-to-markdown audit (2026-10-29) across jnt, beamex, pellervo,
snellmanpetfood, solarplexius, kaskipuu, friendsandbrgrs (local) and
snellmanrecipes, op-hippo, feelia, ffjaro, medihealth (live HTML).

## Blockers before tagging v0.1.0

- [ ] **Markdown bypasses login walls.** `maybeSend()` answers at
  `template_redirect:0`, before gates that redirect there. Verified on
  snellmanpetfood: the HTML redirects to /my-account/, the `.md` returns 200
  with reseller-only content (wp-snellman-m3 `RestrictedContentHandler`).
  Template-level gates (ekorosk intranet) leak the same way.
- [ ] **Comment-only content throws** `InvalidArgumentException: Invalid HTML`
  (500 on maatilanpellervo); script/style-only content emits
  `<!--?xml encoding="UTF-8"-->`. Wrapping the HTML in a `<div>` fixes both.
- [ ] **File permalinks get a `.md` URL**: post types whose permalink is the
  file (beamex `resource` PDFs, kaskipuu `material` SVGs) advertise
  `file.pdf.md`.

## Proposed: convert the rendered page's `<main>`, not `the_content`

Let the request run normally, buffer the output after every
`template_redirect` gate, convert `<main>` (selector filterable), fall back to
`the_content` when there is none. Fixes the gate bypass by construction, and
picks up template-rendered content the content-only render misses:
snellmanrecipes ingredients/method (post meta, `partials/content-recipe`),
WooCommerce price/short description (medihealth, solarplexius, petfood),
kaskipuu `person`, ffjaro match data. Gives archives, term pages and the front
page a markdown version too. Cost: one normal page render.

## Converter: follow screen-reader semantics

- [ ] Keep the text of disclosure/tab buttons (`aria-expanded`,
  `aria-controls`, `role="tab"`); drop other buttons. Today the FAQ questions
  vanish from core/accordion (kaskipuu, pellervo) and beamex
  `gds/faq-accordion` (152 blocks), and tab names from core/tabs (kaskipuu)
  and beamex `gds/tabs`.
- [ ] Drop `aria-hidden="true"` subtrees.
- [ ] Drop images with `alt=""`; fall back to `data-src` for lazy images
  instead of emitting `![]()` (beamex, snellmanrecipes, medihealth).
- [ ] `<iframe title src>` → `[title](src)` instead of dropping it (JNT Google
  Maps, beamex 3D model).
- [ ] Treat non-`<a>` elements with `href` as links (`<gds-media-card href>`
  on hippo: every card title loses its link).
- [ ] Empty-text links: use `aria-label`, else drop (`[](url)` on medihealth
  cards). Drop `#`/`#comments` placeholder hrefs, keep the text.
- [ ] Heading inside a link renders `[ ## Title ](url)`; emit
  `## [Title](url)` (beamex video slideshow).
- [ ] Block-level text glued to a preceding link inside list items
  (`[title](url)Excerpt…` in core/latest-posts).

## Block fixes the audit surfaced (also screen-reader bugs)

- friendsandbrgrs `gds/info-table`: allergen checkmarks are images without
  alt text, so the table reads as empty.
- ffjaro `gds/match-table`: goal/card/substitution icons have no text
  ("Strömbäck 46′ 8′").
- medihealth product cards: card link has no accessible name.
- hippo `gds-media-card`: link exists only in shadow DOM.

## Will not convert (JS- or third-party-rendered)

HubSpot/Cognito/Leadoo forms, Gravity Forms (removed by design), hippo
wheel-game, solarplexius car-finder, kaskipuu person-search and
product-configurator, beamex partner-finder and unit converters, Woo
cart/checkout. Markdown can only say they exist.
