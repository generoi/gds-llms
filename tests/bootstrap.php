<?php

/**
 * Integration only: both documents are built from menus, permalinks, the_content
 * and rewrite rules, none of which mean anything against mocks.
 */
$autoload = dirname(__DIR__).'/vendor/autoload.php';

if (! file_exists($autoload)) {
    exit("Run `composer install` first.\n");
}

require_once $autoload;

$wpPhpunit = getenv('WP_PHPUNIT__DIR') ?: dirname(__DIR__).'/vendor/wp-phpunit/wp-phpunit';

require_once $wpPhpunit.'/includes/functions.php';

tests_add_filter('muplugins_loaded', function (): void {
    require dirname(__DIR__).'/gds-llms.php';
});

require $wpPhpunit.'/includes/bootstrap.php';
