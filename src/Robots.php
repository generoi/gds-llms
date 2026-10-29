<?php

namespace GeneroWP\Llms;

/**
 * AI usage preferences in robots.txt, in both emerging vocabularies:
 * Cloudflare's Content Signals (`Content-Signal: search=yes, ai-input=yes,
 * ai-train=yes`) and the IETF aipref draft (`Content-Usage: train-ai=y`).
 * Neither is honoured by a crawler yet; robots.txt itself is, so a use the site
 * declines also gets a `Disallow: /` group for the crawlers known to do it.
 *
 * Only WordPress' virtual robots.txt passes through here -- a physical
 * robots.txt in the web root is served by the web server untouched.
 */
class Robots
{
    /**
     * Crawlers that collect content to train models.
     */
    public const TRAINING_CRAWLERS = [
        'GPTBot',
        'ClaudeBot',
        'CCBot',
        'Google-Extended',
        'Applebot-Extended',
        'meta-externalagent',
        'Bytespider',
        'Amazonbot',
        'cohere-training-data-crawler',
    ];

    /**
     * Crawlers and fetchers that feed content to a model answering a user:
     * AI search indexes and fetches triggered by a user's question.
     */
    public const AI_INPUT_CRAWLERS = [
        'OAI-SearchBot',
        'ChatGPT-User',
        'Claude-SearchBot',
        'Claude-User',
        'PerplexityBot',
        'Perplexity-User',
        'DuckAssistBot',
        'meta-externalfetcher',
        'MistralAI-User',
    ];

    public function register(): void
    {
        // Last, after themes that append rules to what they assume is the
        // site's own `User-agent: *` group: the groups added here go after theirs.
        add_filter('robots_txt', [$this, 'filter'], PHP_INT_MAX);
    }

    /**
     * Which uses of the content the site allows: `search`, `ai-input`
     * (answering a user's question with it) and `ai-train`.
     *
     * @return array{search: bool, ai-input: bool, ai-train: bool}
     */
    public static function policy(): array
    {
        $policy = (array) apply_filters('gds/llms/ai_policy', [
            'search' => true,
            'ai-input' => true,
            'ai-train' => true,
        ]);

        return [
            'search' => (bool) ($policy['search'] ?? true),
            'ai-input' => (bool) ($policy['ai-input'] ?? true),
            'ai-train' => (bool) ($policy['ai-train'] ?? true),
        ];
    }

    /**
     * The policy as a Content-Signal value: `search=yes, ai-input=yes, ai-train=no`.
     */
    public static function contentSignal(): string
    {
        $pairs = [];
        foreach (self::policy() as $use => $allowed) {
            $pairs[] = $use.'='.($allowed ? 'yes' : 'no');
        }

        return implode(', ', $pairs);
    }

    /**
     * The policy in the aipref vocabulary: `train-ai=y, ai-use=y, search=y`.
     */
    public static function contentUsage(): string
    {
        $policy = self::policy();

        return sprintf(
            'train-ai=%s, ai-use=%s, search=%s',
            $policy['ai-train'] ? 'y' : 'n',
            $policy['ai-input'] ? 'y' : 'n',
            $policy['search'] ? 'y' : 'n'
        );
    }

    public function filter(string $output): string
    {
        // Both drafts make these group rules. A group of their own rather than
        // lines spliced into the site's: crawlers merge every group naming the
        // same user agent (RFC 9309, 2.2.1), so the `*` rules still apply.
        $output = rtrim($output)."\n\n".sprintf(
            "User-agent: *\nContent-Signal: %s\nContent-Usage: %s\n",
            self::contentSignal(),
            self::contentUsage()
        );

        $policy = self::policy();
        $blocked = array_merge(
            $policy['ai-train'] ? [] : self::TRAINING_CRAWLERS,
            $policy['ai-input'] ? [] : self::AI_INPUT_CRAWLERS,
        );

        if ($blocked) {
            $output .= "\n# Uses of this site's content the owner declines (see Content-Signal above).\n";
            foreach ($blocked as $crawler) {
                $output .= "User-agent: $crawler\n";
            }
            $output .= "Disallow: /\n";
        }

        return self::NOTICE.$output;
    }

    /**
     * Cloudflare's content signals policy text (CC0), which makes a declined
     * use an express reservation of rights under EU copyright law.
     */
    public const NOTICE = <<<'TXT'
        # As a condition of accessing this website, you agree to abide by the following content signals:
        # (a)  If a content-signal = yes, you may collect content for the corresponding use.
        # (b)  If a content-signal = no, you may not collect content for the corresponding use.
        # (c)  If the website operator does not include a content signal for a corresponding use, the website operator neither grants nor restricts permission via content signal with respect to the corresponding use.
        # The content signals and their meanings are:
        # search: building a search index and providing search results (e.g., returning hyperlinks and short excerpts from your website's contents).  Search does not include providing AI-generated search summaries.
        # ai-input: inputting content into one or more AI models (e.g., retrieval augmented generation, grounding, or other real-time taking of content for generative AI search answers).
        # ai-train: training or fine-tuning AI models.
        # ANY RESTRICTIONS EXPRESSED VIA CONTENT SIGNALS ARE EXPRESS RESERVATIONS OF RIGHTS UNDER ARTICLE 4 OF THE EUROPEAN UNION DIRECTIVE 2019/790 ON COPYRIGHT AND RELATED RIGHTS IN THE DIGITAL SINGLE MARKET.


        TXT;
}
