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
     * On plugins_loaded at priority 0: after every plugin file is loaded,
     * whatever order they load in, and before Polylang's init at priority 1
     * reads the request URI that Markdown\Endpoint rewrites.
     */
    public function __construct()
    {
        add_action('plugins_loaded', [$this, 'boot'], 0);
    }

    public function boot(): void
    {
        // Loading the entry file twice must not register every hook twice.
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        add_action('init', [$this, 'loadTextdomain'], 0);

        (new LlmsTxt\Endpoint)->register();
        (new Markdown\Endpoint)->register();
        (new Robots)->register();
        (new WebMcp)->register();
    }

    /**
     * Network-wide, every other site is cleaned up from here too: deactivation
     * runs in one site's request only.
     */
    public static function deactivate(bool $networkWide = false): void
    {
        if ($networkWide && is_multisite()) {
            $urls = [];
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                if ((int) $siteId !== get_current_blog_id()) {
                    switch_to_blog((int) $siteId);
                    $urls[] = LlmsTxt\Endpoint::forgetSite();
                    restore_current_blog();
                }
            }
            Integrations\Kinsta::purge($urls);
        }

        (new LlmsTxt\Endpoint)->deactivate();
    }

    /**
     * The bundled translations, loaded just in time -- switch_to_locale()
     * included. A site's own translation in languages/plugins/ wins.
     */
    public function loadTextdomain(): void
    {
        load_plugin_textdomain('gds-llms', false, basename(dirname(__DIR__)).'/resources/lang');
    }
}
