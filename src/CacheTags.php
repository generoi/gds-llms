<?php

namespace GeneroWP\Llms;

use Genero\Sage\CacheTags\CacheTags as SageCacheTags;
use Genero\Sage\CacheTags\Tags\SiteTags;

/**
 * Surrogate keys for the responses this package sends, when the site runs
 * generoi/sage-cachetags. Without it the responses are left to whatever page
 * cache the host has, and may lag an edit until that cache expires.
 */
class CacheTags
{
    /**
     * Both responses exit before `wp_footer`, so the HttpHeader cachetags action
     * never runs and the header has to be written here, alongside an explicit
     * save() of the URL.
     *
     * They also exit before `template_redirect:10`, which is where the Site action
     * would otherwise attach the bare `site:N` key. That key is the only one
     * `cachetags:flush` purges, and deploys run a flush after every release --
     * without it a code change here would sit in the CDN until s-maxage lapsed.
     *
     * @param  callable(): string[]  $tags  only called when sage-cachetags is installed
     */
    public static function send(callable $tags, string $url): void
    {
        $cacheTags = self::instance();

        if (! $cacheTags || ! $cacheTags->httpHeader) {
            return;
        }

        $cacheTags->add(array_merge(SiteTags::sites(), $tags()));
        $cacheTags->save($url);

        header(sprintf('%s: %s', $cacheTags->httpHeader, implode(' ', $cacheTags->get())));
        header(sprintf(
            'Cache-Control: public, max-age=%d, s-maxage=%d, stale-while-revalidate=%d, stale-if-error=%d',
            HOUR_IN_SECONDS,
            DAY_IN_SECONDS,
            MONTH_IN_SECONDS,
            MONTH_IN_SECONDS,
        ));
    }

    protected static function instance(): ?SageCacheTags
    {
        if (! function_exists('app') || ! class_exists(SageCacheTags::class)) {
            return null;
        }

        try {
            return app(SageCacheTags::class);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
