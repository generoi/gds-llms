<?php

/**
 * Removes the rewrite-rule version and every cached llms.txt body. The cache
 * keys carry a code version, so they are matched by prefix rather than rebuilt.
 * Deactivation has already taken the rewrite rule out.
 */
if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$cleanup = function (): void {
    global $wpdb;

    delete_option('gds_llms_txt_rewrite_version');

    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like('_transient_gds_llms_txt:').'%',
        $wpdb->esc_like('_transient_timeout_gds_llms_txt:').'%',
    ));
};

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
        switch_to_blog($siteId);
        $cleanup();
        restore_current_blog();
    }
} else {
    $cleanup();
}
