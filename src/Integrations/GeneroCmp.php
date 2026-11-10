<?php

namespace GeneroWP\Llms\Integrations;

use Dom\Element;

class GeneroCmp
{
    /**
     * genero-cmp holds third-party iframes back until consent as
     * `<gds-cmp-embed as="iframe">` with the iframe's attributes; as the
     * iframe it stands for, it becomes a link like any other.
     */
    public static function clean(Element $main): void
    {
        foreach ($main->querySelectorAll('gds-cmp-embed[as="iframe"]') as $embed) {
            $iframe = $embed->ownerDocument->createElement('iframe');
            foreach (['src', 'data-src', 'title'] as $name) {
                if ($embed->hasAttribute($name)) {
                    $iframe->setAttribute($name, (string) $embed->getAttribute($name));
                }
            }
            $embed->replaceWith($iframe);
        }
    }
}
