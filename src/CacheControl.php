<?php

namespace GeneroWP\Llms;

/**
 * Cache-Control for llms.txt and the markdown pages.
 *
 * Hosts set this for HTML themselves -- Kinsta adds the policy below to HTML
 * pages and feeds, and its edge caches whatever carries it -- but not for
 * text/plain or text/markdown, which then bypass the edge entirely. So the
 * same policy is sent here, unless something has already set one: a theme's
 * own, or WordPress' no-cache headers.
 */
class CacheControl
{
    /**
     * Kinsta's own for HTML: browsers revalidate, shared caches keep it an hour.
     */
    public const DEFAULT = 'public, max-age=0, s-maxage=3600';

    public static function send(): void
    {
        if (($value = self::value()) !== '' && ! self::isSet()) {
            header('Cache-Control: '.$value);
        }
    }

    /**
     * The policy to send, '' for a response that must not be shared.
     */
    public static function value(): string
    {
        if (is_user_logged_in() || is_preview()) {
            return '';
        }

        /**
         * Cache-Control for llms.txt and markdown responses that have none.
         * Return '' to send none.
         *
         * @param  string  $value
         */
        return (string) apply_filters('gds/llms/cache_control', self::DEFAULT);
    }

    protected static function isSet(): bool
    {
        foreach (headers_list() as $header) {
            if (stripos($header, 'cache-control:') === 0) {
                return true;
            }
        }

        return false;
    }
}
