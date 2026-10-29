<?php

namespace GeneroWP\Llms;

use Genero\Sage\CacheTags\CacheTags as SageCacheTags;
use Genero\Sage\CacheTags\Tags\SiteTags;
use Genero\Sage\CacheTags\Util;

/**
 * Cache tags for the responses this package sends, when the site runs
 * generoi/sage-cachetags. Without it the responses are left to whatever page
 * cache the host has, and may lag an edit until that cache expires.
 */
class CacheTags
{
    /**
     * Both responses exit before `wp_footer`, which is where sage-cachetags
     * saves the URL and writes the header for a normal page, so both happen here.
     *
     * They also exit before `template_redirect:10`, which is where the Site action
     * would otherwise attach the bare `site:N` key. That key is the only one
     * `cachetags:flush` purges, and deploys run a flush after every release --
     * without it a code change here would sit in the CDN until it expired.
     *
     * Cache-Control is left to the theme, as it is for every other page.
     *
     * @param  callable(): array<mixed>  $tags  only called when sage-cachetags is running
     */
    public static function send(callable $tags, string $url): void
    {
        $cacheTags = class_exists(SageCacheTags::class) ? SageCacheTags::getInstance() : null;

        if (! $cacheTags) {
            return;
        }

        // The same verdict sage-cachetags applies to a page: logged-in users,
        // previews and the admin bar are never cached at the edge.
        if (! Util::isCacheableRequest()) {
            if (! defined('DONOTCACHEPAGE')) {
                define('DONOTCACHEPAGE', true);
            }

            return;
        }

        $cacheTags->add(SiteTags::sites(), $tags());
        $cacheTags->save($url);

        if ($cacheTags->httpHeader) {
            header(sprintf('%s: %s', $cacheTags->httpHeader, implode(' ', $cacheTags->get())));
        }
    }
}
