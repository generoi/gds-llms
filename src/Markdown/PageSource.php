<?php

namespace GeneroWP\Llms\Markdown;

/**
 * `page` mode: the page rendered as a visitor sees it, captured in an output
 * buffer and swapped for the response body once the buffer closes.
 */
class PageSource
{
    protected string $buffer = '';

    /**
     * @var callable(string): string
     */
    protected $respond;

    /**
     * @param  callable(string): string  $respond  turns the rendered page into the response body
     */
    public function start(callable $respond): void
    {
        $this->respond = $respond;

        // A handler rather than collecting the buffer on shutdown: Acorn sends
        // its response through Symfony, which closes every output buffer itself
        // before shutdown. The handler runs whoever closes it, before anything
        // reaches the client.
        ob_start([$this, 'handleBuffer']);
    }

    public function handleBuffer(string $chunk, int $phase): string
    {
        $this->buffer .= $chunk;

        // An early flush() mid-render only hands over part of the page.
        if (! ($phase & PHP_OUTPUT_HANDLER_FINAL)) {
            return '';
        }

        // A redirect from inside the template, or a template that answered
        // with something other than a page: pass it through untouched.
        if (! $this->isHtmlResponse()) {
            return $this->buffer;
        }

        return ($this->respond)($this->buffer);
    }

    protected function isHtmlResponse(): bool
    {
        $status = http_response_code();
        if (is_int($status) && $status >= 300 && $status < 400) {
            return false;
        }

        foreach (headers_list() as $header) {
            if (stripos($header, 'content-type:') === 0) {
                return stripos($header, 'text/html') !== false;
            }
        }

        return true;
    }
}
