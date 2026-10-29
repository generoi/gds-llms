<?php

namespace GeneroWP\Llms\Integrations;

/**
 * The site's languages, from Polylang where it runs. Without it the site has
 * one language, identified by '' throughout.
 */
class Polylang
{
    public static function isActive(): bool
    {
        return function_exists('pll_languages_list');
    }

    /**
     * Polylang's language slugs, none without it.
     *
     * @return string[]
     */
    public static function slugs(): array
    {
        return self::isActive() ? (pll_languages_list() ?: []) : [];
    }

    /**
     * Every language, [''] for a site with one.
     *
     * @return string[]
     */
    public static function languages(): array
    {
        return self::slugs() ?: [''];
    }

    public static function current(): string
    {
        if (! function_exists('pll_current_language')) {
            return '';
        }

        return (string) (pll_current_language() ?: pll_default_language());
    }

    /**
     * A language's root URL, with its trailing slash.
     *
     * From the language's root rather than pll_home_url(), which returns the
     * language's static front page (`/fi/etusivu/`) where one is set.
     */
    public static function homeUrl(string $language): string
    {
        $object = self::language($language);

        return $object ? PLL()->links_model->home_url($object) : home_url('/');
    }

    /**
     * A language's WordPress locale, from a Polylang slug or a locale; the
     * site's own for ''.
     */
    public static function locale(string $language): string
    {
        $object = self::language($language);

        return $object ? $object->locale : ($language ?: get_locale());
    }

    /**
     * The name of a language in the current locale, from a Polylang slug or a
     * locale: "Swedish (Sweden)" in English, "ruotsi (Ruotsi)" in Finnish.
     *
     * Named from the locale rather than the slug: slugs are often country codes
     * (`se`, `ch`, `us`) that intl would read as Northern Sami, Chamorro or
     * nothing, and one language commonly appears once per market, so the region
     * is kept -- "German (Switzerland)", "German (Austria)". Polylang's own
     * `name` field is the endonym, which reads as a mistake inside a sentence
     * in another language. Without the intl extension the locale itself is a
     * tolerable fallback.
     */
    public static function languageName(string $language): string
    {
        $locale = self::locale($language);

        if (function_exists('locale_get_display_name')) {
            return locale_get_display_name($locale, get_locale()) ?: $locale;
        }

        return $locale;
    }

    /**
     * The page being viewed in each of its other languages, as the
     * `<link rel="alternate" hreflang>` tags Polylang prints in an HTML page's
     * head -- from the same get_translation_url(), so a post, an archive and
     * a term all resolve as they do there, and a missing translation is left
     * out.
     */
    public static function alternateLinks(): string
    {
        if (! self::isActive() || ! isset(PLL()->links)) {
            return '';
        }

        $html = '';
        foreach (PLL()->model->get_languages_list() as $language) {
            if ($language->slug !== self::current() && ($url = PLL()->links->get_translation_url($language))) {
                $html .= sprintf('<link rel="alternate" hreflang="%s" href="%s">', esc_attr(str_replace('_', '-', $language->locale)), esc_url($url));
            }
        }

        return $html;
    }

    protected static function language(string $language): \PLL_Language|false
    {
        return $language && function_exists('PLL') ? PLL()->model->get_language($language) : false;
    }
}
