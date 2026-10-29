<?php

namespace GeneroWP\Llms\Markdown;

/**
 * What `content` mode converts in place of the page's template: a single
 * post's content (ContentSource), or a listing built from its query
 * (ArchiveSource).
 */
interface Source
{
    /**
     * The page's own URL -- the canonical, and what its markdown URL is built
     * from -- rather than the request's, which agents send with tracking
     * parameters (`?utm_source=chatgpt.com`).
     */
    public function url(): string;

    /**
     * The page for the converter: a `<head>` with its metadata, its content
     * as `<main>`.
     */
    public function html(): string;

    /**
     * sage-cachetags tags for the response.
     *
     * @return array<mixed>
     */
    public function cacheTags(): array;
}
