<?php

namespace GeneroWP\Llms\Integrations;

use Genero\Sage\CacheTags\CacheTags as SageCacheTags;
use Genero\Sage\CacheTags\Tags\CoreTags;
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
     * A markdown page is a full render, so sage-cachetags collects its tags and
     * sends the header as for any page -- but saves them under the HTML URL the
     * request was rewritten to. The markdown URL needs its own row, or a purge
     * by URL would leave it in the cache.
     */
    public static function saveUrl(string $url): void
    {
        $cacheTags = class_exists(SageCacheTags::class) ? SageCacheTags::getInstance() : null;

        if ($cacheTags && $url !== '' && self::isCacheableRequest()) {
            $cacheTags->save($url);
        }
    }

    /**
     * llms.txt exits before `wp_footer`, which is where sage-cachetags saves the
     * URL and writes the header for a normal page, so both happen here.
     *
     * It also exits before `template_redirect:10`, which is where the Site action
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
        if (! self::isCacheableRequest()) {
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

    /**
     * Option tags, which sage-cachetags only has after v2.5.3.
     *
     * @param  string[]  $options
     * @return array<mixed>
     */
    public static function options(array $options): array
    {
        return method_exists(CoreTags::class, 'option') ? CoreTags::option($options) : [];
    }

    /**
     * sage-cachetags' own verdict where it has one; it arrived after v2.5.3,
     * which the fleet runs and which tags every request. Without it, the two
     * cases that must never reach the edge.
     */
    protected static function isCacheableRequest(): bool
    {
        return method_exists(Util::class, 'isCacheableRequest')
            ? Util::isCacheableRequest()
            : ! is_user_logged_in() && ! is_preview();
    }
}
