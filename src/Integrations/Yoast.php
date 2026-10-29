<?php

namespace GeneroWP\Llms\Integrations;

use WP_Post;

class Yoast
{
    public static function isActive(): bool
    {
        return function_exists('YoastSEO');
    }

    /**
     * The meta description an editor set in Yoast, '' without one, or null when
     * Yoast keeps the post out of search.
     *
     * Yoast returns false for anything set to noindex, which conveniently doubles
     * as a filter -- content excluded from search should not be advertised to
     * models either.
     */
    public static function description(int $postId): ?string
    {
        if (! self::isActive()) {
            return '';
        }

        $meta = YoastSEO()->meta->for_post($postId);

        if ($meta === false) {
            return null;
        }

        return empty($meta->description) ? '' : (string) $meta->description;
    }

    /**
     * What Yoast would print in a post's `<head>`, or null without Yoast:
     * description, first Open Graph image and schema graph.
     *
     * @return array{description: string, image: string, schema: array<mixed>}|null
     */
    public static function head(WP_Post $post): ?array
    {
        $meta = self::isActive() ? YoastSEO()->meta->for_post($post->ID) : false;

        if (! $meta) {
            return null;
        }

        $images = $meta->open_graph_images;

        return [
            'description' => (string) ($meta->description ?: ''),
            'image' => $images ? (string) (reset($images)['url'] ?? '') : '',
            'schema' => (array) $meta->schema,
        ];
    }

    /**
     * Keep Yoast's own llms.txt switched off.
     *
     * Not just tidiness: nginx resolves `try_files $uri` before reaching
     * index.php, so a physical `web/llms.txt` would shadow the rewrite rule and
     * silently serve Yoast's version instead. Flipping the option also makes Yoast
     * unschedule its cron and delete its own file, since its option watcher
     * listens on `update_option_wpseo`. Admin only, where get_home_path()
     * resolves correctly and Yoast can therefore find the file it wrote.
     */
    public static function disableLlmsTxt(): void
    {
        if (! self::isActive() || RankMath::handlesLlmsTxt()) {
            return;
        }

        $options = YoastSEO()->helpers->options;

        if ($options->get('enable_llms_txt') === true) {
            $options->set('enable_llms_txt', false);
        }
    }
}
