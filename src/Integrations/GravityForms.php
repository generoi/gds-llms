<?php

namespace GeneroWP\Llms\Integrations;

use Dom\Element;
use GeneroWP\Llms\Markdown\Html;

class GravityForms
{
    public static function register(): void
    {
        add_action('gds/llms/markdown_dom', [self::class, 'clean']);
    }

    /**
     * The honeypot and fields set to "Hidden" visibility are labelled fields
     * hidden from people by CSS alone; they are not what the form asks for.
     */
    public static function clean(Element $main): void
    {
        Html::remove($main, '.gform_validation_container, .gfield_visibility_hidden');
    }
}
