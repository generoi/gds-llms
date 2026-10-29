<?php

namespace GeneroWP\Llms;

class Plugin
{
    protected static ?self $instance = null;

    protected bool $booted = false;

    public static function getInstance(): self
    {
        return self::$instance ??= new self;
    }

    /**
     * Deferred to muplugins_loaded so a site's own mu-plugins can register the
     * `gds/llms*` filters regardless of the order their filenames sort in, while
     * still running before regular plugins -- Polylang included -- read the
     * request URI that Markdown\Endpoint rewrites.
     */
    public function __construct()
    {
        add_action('muplugins_loaded', [$this, 'boot']);
    }

    public function boot(): void
    {
        // Loading the entry file twice must not register every hook twice.
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        add_filter('lang_dir_for_domain', [$this, 'languagesDir'], 10, 2);

        (new LlmsTxt\Endpoint)->register();
        (new Markdown\Endpoint)->register();
        (new Robots)->register();
    }

    /**
     * The bundled translations, for WordPress' just-in-time loading -- which
     * switch_to_locale() also goes through. An mu-plugin installed by Composer
     * has no directory load_plugin_textdomain() can be relative to, so the
     * path is supplied here, and only when the site has no translation of its
     * own in languages/plugins/.
     */
    public function languagesDir(string|false $path, string $domain): string|false
    {
        return $domain === 'gds-llms' && ! $path ? dirname(__DIR__).'/resources/lang/' : $path;
    }
}
