<?php

/*
Plugin Name:  LLMs loader
Description:  Loads the package in a plain WordPress install, the way Bedrock's autoloader does in production
Version:      1.0.0
*/

/*
 * WordPress only loads mu-plugins that sit directly in WPMU_PLUGIN_DIR -- a
 * package installed into a subdirectory needs something to require it. Bedrock's
 * autoloader does that in production; plain installs, including wp-env, need
 * this stub.
 */
require_once WPMU_PLUGIN_DIR.'/gds-llms/gds-llms.php';
