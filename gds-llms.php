<?php

/*
Plugin Name:  LLMs
Plugin URI:   https://github.com/generoi/gds-llms
Description:  Serves /llms.txt and a markdown version of every page for language models
Version:      0.4.2
Author:       Genero
Author URI:   https://genero.fi/
License:      MIT
*/

use GeneroWP\Llms\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

if (file_exists(__DIR__.'/vendor/autoload.php')) {
    require_once __DIR__.'/vendor/autoload.php';
}

if (! function_exists('is_blog_installed') || ! is_blog_installed()) {
    return;
}

register_deactivation_hook(__FILE__, [Plugin::class, 'deactivate']);

Plugin::getInstance();
