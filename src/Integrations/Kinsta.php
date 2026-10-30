<?php

namespace GeneroWP\Llms\Integrations;

class Kinsta
{
    /**
     * Kinsta's mu-plugin purges a post's own URLs when it is saved, which covers
     * a page's md/ URL (it sits under the page) but never /llms.txt: that changes
     * with menus, options and any post the document lists, none of which Kinsta
     * maps to it. Purging it ourselves goes through the same endpoint Kinsta's
     * own purge uses.
     *
     * The mu-plugin also loads in local development, where the endpoint does not
     * exist, so those environments are skipped rather than warned about on every
     * save. Kinsta's autopurge switch is honoured.
     *
     * @param  string[]  $urls
     */
    public static function purge(array $urls): void
    {
        global $kinsta_muplugin;

        if (! $urls || ! isset($kinsta_muplugin->kinsta_cache_purge, $kinsta_muplugin->kinsta_cache->config['immediate_path'])) {
            return;
        }

        if (in_array(wp_get_environment_type(), ['local', 'development'], true)) {
            return;
        }

        if ((defined('KINSTAMU_DISABLE_AUTOPURGE') && KINSTAMU_DISABLE_AUTOPURGE === true) || get_option('kinsta-autopurge-status') === 'disabled') {
            return;
        }

        $response = $kinsta_muplugin->kinsta_cache_purge->send_cache_purge_request($kinsta_muplugin->kinsta_cache->config['immediate_path'], self::request($urls));

        // A failed purge leaves a stale llms.txt behind until the page cache
        // expires; not worth failing the save that triggered it, but not silent.
        if ((int) ($response['response_code'] ?? 0) !== 200) {
            trigger_error(sprintf('gds-llms: Kinsta cache purge failed (HTTP %d): %s', (int) ($response['response_code'] ?? 0), (string) ($response['error_message'] ?? '')), E_USER_WARNING);
        }
    }

    /**
     * Kinsta's purge body: `single|<key>` purges exactly that URL, written
     * without its scheme, as Kinsta's own convert_purge_list_to_request() does.
     *
     * @param  string[]  $urls
     * @return array<string, string>
     */
    public static function request(array $urls): array
    {
        $request = [];
        foreach (array_values($urls) as $i => $url) {
            $request['single|gds-llms-'.$i] = (string) preg_replace('#^https?://#', '', $url);
        }

        return $request;
    }
}
