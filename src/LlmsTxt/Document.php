<?php

namespace GeneroWP\Llms\LlmsTxt;

use GeneroWP\Llms\Format;
use GeneroWP\Llms\Integrations\Polylang;
use GeneroWP\Llms\Integrations\RankMath;
use GeneroWP\Llms\Integrations\WooCommerce;
use GeneroWP\Llms\Integrations\Yoast;
use GeneroWP\Llms\Markdown\Endpoint as Markdown;

/**
 * What llms.txt says.
 *
 * Yoast picks five recently-modified entries per post type and five terms from
 * every taxonomy, which surfaces whatever was edited last rather than what
 * matters. Here the page list comes from the site's menus -- the client's own,
 * already-maintained information architecture, in the right language, with
 * headings they control -- and a webshop catalogue is described by the top of
 * its category tree.
 *
 * llms.txt is an orientation document, not a second sitemap. It earns its place
 * by being small enough to sit in a model's context alongside the actual
 * question, so anything countable is left to the sitemap it links.
 *
 * @phpstan-type Section array{heading: string, lines: string[]}
 * @phpstan-type Link array{title: string, url: string, description: string}
 * @phpstan-type DocumentArray array{title: string, summary: string, intro: array<string, string>, links: string[], sections: Section[]}
 */
class Document
{
    /**
     * Posts the document was derived from besides those Menus and Catalogue
     * record, for its cache tags.
     *
     * @var int[]
     */
    protected array $posts = [];

    protected Menus $menus;

    protected Catalogue $catalogue;

    public function __construct()
    {
        $this->menus = new Menus;
        $this->catalogue = new Catalogue;
    }

    /**
     * Built in the language's own locale, so /sv/llms.txt reads Swedish
     * whichever request builds it -- a flush followed by a Finnish visitor's
     * fetch, a test, a cache warm-up.
     */
    public function build(string $language): string
    {
        $switched = switch_to_locale(Polylang::locale($language));

        try {
            return $this->buildInLocale($language);
        } finally {
            if ($switched) {
                restore_previous_locale();
            }
        }
    }

    protected function buildInLocale(string $language): string
    {
        /**
         * The whole document before it is rendered, for anything a site needs
         * to say that cannot be derived: what the business does (prepend an
         * `about` entry to `intro`), an intro sentence dropped by its key
         * (`language`, `translations`, `markdown`, `commerce`, `catalogue`,
         * `network`), a section renamed, reordered, added or dropped.
         *
         * @param  DocumentArray  $document
         * @param  string  $language  Polylang slug, or '' without Polylang.
         */
        $document = apply_filters('gds/llms_txt/document', $this->document($language), $language);

        return $this->render($document);
    }

    /**
     * Every post the document was built from.
     *
     * @return int[]
     */
    public function posts(): array
    {
        return array_values(array_unique([...$this->posts, ...$this->menus->posts(), ...$this->catalogue->posts()]));
    }

    /**
     * @return DocumentArray
     */
    public function document(string $language): array
    {
        $summary = $this->summary();
        $links = [];
        $sections = [];

        foreach ($this->menus->sections($summary) as $index => [$loose, $groups]) {
            // The first menu's loose links go in the header rather than under an
            // invented heading: llms.txt allows free markdown between the summary
            // and the first `##`, and a heading made up for them would be
            // untranslated English among the client's own labels.
            if ($index === 0) {
                $links = $loose['lines'];
            } elseif ($loose['lines']) {
                $sections[] = $loose;
            }

            $sections = array_merge($sections, $groups);
        }

        return [
            'title' => Format::text(get_bloginfo('name')),
            'summary' => $summary,
            'intro' => $this->intro($language),
            'links' => $links,
            'sections' => array_merge($this->mergeSections($sections), $this->catalogue->sections($language), $this->optionalSections()),
        ];
    }

    /**
     * @param  DocumentArray  $document
     */
    public function render(array $document): string
    {
        $header = ['# '.$document['title']];

        if ($document['summary']) {
            $header[] = '';
            $header[] = '> '.$document['summary'];
        }

        if ($intro = array_filter($document['intro'])) {
            $header[] = '';
            $header[] = implode(' ', $intro);
        }

        if ($document['links']) {
            $header[] = '';
            $header = array_merge($header, $document['links']);
        }

        $sections = array_map(
            fn (array $section) => Format::section($section['heading'], $section['lines']),
            array_filter($document['sections'], fn (array $section) => $section['lines'] !== []),
        );

        return implode("\n", [implode("\n", $header)."\n", ...$sections]);
    }

    /**
     * The description shown for a post, preferring the one an editor wrote, and
     * none for a post Yoast keeps out of search.
     *
     * Without $generated, an excerpt WordPress manufactures from the body copy is
     * not accepted: on a block-built page it is the first few headings run
     * together, which is tolerable as a link's gloss but not as the site summary.
     */
    public static function description(int $postId, bool $generated = true): string
    {
        $description = Yoast::description($postId);

        if ($description === null) {
            return '';
        }

        if ($description !== '') {
            return Format::truncate(Format::text($description));
        }

        if (! $generated && ! has_excerpt($postId)) {
            return '';
        }

        return Format::truncate(Format::text(get_the_excerpt($postId)));
    }

    /**
     * Sections with the same heading as one, where the first appears.
     *
     * Sites that split their audience (private / business) usually repeat that
     * split in every menu -- primary, tools, contact, footer -- and four
     * "## Business" sections read as four different things.
     *
     * @param  Section[]  $sections
     * @return Section[]
     */
    protected function mergeSections(array $sections): array
    {
        $merged = [];
        $positions = [];

        foreach ($sections as $section) {
            $position = $positions[$section['heading']] ?? null;

            if ($position === null) {
                $positions[$section['heading']] = count($merged);
                $merged[] = $section;
            } else {
                $merged[$position]['lines'] = array_merge($merged[$position]['lines'], $section['lines']);
            }
        }

        return $merged;
    }

    /**
     * How to read the rest of the document, one sentence per entry, keyed so a
     * site can drop one.
     *
     * Everything here is derived from what is actually configured rather than
     * described by hand, so it cannot quietly become untrue as the site changes.
     *
     * @return array<string, string>
     */
    protected function intro(string $language): array
    {
        return array_filter([
            /* translators: %s: language name */
            'language' => sprintf(__('Written in %s.', 'gds-llms'), Polylang::languageName($language)),
            'translations' => $this->translationsSentence($language),
            'markdown' => $this->markdownSentence(),
            'commerce' => WooCommerce::commerceSentence(),
            'catalogue' => $this->catalogue->sentence(),
            /**
             * Whether to list the other sites on the network.
             *
             * Off by default: a network is as often a set of unrelated shops,
             * or a main site nobody visits, as it is a family of sister sites
             * a reader would want pointed at.
             */
            'network' => apply_filters('gds/llms_txt/network', false) ? $this->networkSentence() : '',
        ]);
    }

    /**
     * The same document in the site's other languages, each built from that
     * language's own menus.
     */
    protected function translationsSentence(string $language): string
    {
        $links = [];

        foreach (Polylang::languages() as $other) {
            if ($other !== '' && $other !== $language) {
                $links[] = sprintf('[%s](%s)', Polylang::languageName($other), Format::linkUrl(Endpoint::url($other)));
            }
        }

        /* translators: %s: list of language links */
        return $links ? sprintf(__('Also available in %s.', 'gds-llms'), wp_sprintf('%l', $links)) : '';
    }

    protected function markdownSentence(): string
    {
        $structure = (string) get_option('permalink_structure');

        if ($structure === '') {
            return '';
        }

        return sprintf(
            /* translators: %s: URL suffix, e.g. `index.html.md` */
            __('Every page is also available as markdown at its URL followed by `%s`.', 'gds-llms'),
            str_ends_with($structure, '/') ? Markdown::SUFFIX : '.md',
        );
    }

    /**
     * Sibling sites on the same network.
     *
     * Asked whether the company sells in another country, a model reading only
     * the sections below would say no; the network knows otherwise. Read through
     * get_blog_details()/get_blog_option() rather than switch_to_blog(), which
     * would drag Polylang's per-blog static-page state into this request.
     */
    protected function networkSentence(): string
    {
        if (! is_multisite()) {
            return '';
        }

        $links = [];

        foreach (get_sites(['archived' => 0, 'deleted' => 0, 'spam' => 0]) as $site) {
            $blogId = (int) $site->blog_id;

            if ($blogId === get_current_blog_id()) {
                continue;
            }

            $details = get_blog_details($blogId);
            $name = $details ? $details->blogname : '';

            if (! $name) {
                continue;
            }

            $locale = (string) get_blog_option($blogId, 'WPLANG');

            $link = sprintf('[%s](%s)', Format::linkLabel($name), Format::linkUrl(get_home_url($blogId)));
            /* translators: 1: site link, 2: language name */
            $links[] = $locale ? sprintf(__('%1$s in %2$s', 'gds-llms'), $link, Polylang::languageName($locale)) : $link;
        }

        /* translators: %s: list of site links */
        return $links ? sprintf(__('Related sites: %s.', 'gds-llms'), wp_sprintf('%l', $links)) : '';
    }

    /**
     * Where the exhaustive lists live. The `Optional` heading stays English: the
     * llms.txt format gives that word its meaning.
     *
     * @return Section[]
     */
    protected function optionalSections(): array
    {
        $url = $this->sitemapUrl();

        return $url ? [[
            'heading' => 'Optional',
            'lines' => [Format::linkLine(__('Sitemap', 'gds-llms'), $url, __('Every indexable URL, split by content type.', 'gds-llms'))],
        ]] : [];
    }

    protected function sitemapUrl(): string
    {
        if (Yoast::isActive() || RankMath::isActive()) {
            return home_url('/sitemap_index.xml');
        }

        return (string) get_sitemap_url('index');
    }

    protected function summary(): string
    {
        $frontPage = (int) get_option('page_on_front');

        if ($frontPage > 0) {
            $this->posts[] = $frontPage;
        }

        $summary = $frontPage > 0 ? self::description($frontPage, false) : '';

        return $summary ?: $this->tagline();
    }

    /**
     * The tagline, unless it is still the one WordPress installs with.
     */
    protected function tagline(): string
    {
        $tagline = Format::truncate(Format::text(get_bloginfo('description')));
        $defaults = [__('Just another WordPress site')];

        if (is_multisite()) {
            /* translators: %s: Network title. */
            $defaults[] = sprintf(__('Just another %s site'), get_network()->site_name ?? '');
        }

        return in_array($tagline, $defaults, true) || preg_match('/^Just another .+ site$/', $tagline) ? '' : $tagline;
    }
}
