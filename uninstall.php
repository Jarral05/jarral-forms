<?php
/**
 * Runs when the plugin is DELETED from the Plugins screen (not deactivated).
 *
 * WordPress loads this file on its own, without the plugin running, which is
 * why the security check below matters: without it, the file could be hit
 * directly over the web and would wipe data on demand.
 *
 * @package JarralForms
 */

// WP_UNINSTALL_PLUGIN is only defined by WordPress during a real uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/*
 * Deliberate choice: enquiries are NOT deleted.
 *
 * They are business records. Someone who deletes a plugin by accident should
 * not lose two years of leads. To remove them, delete them from the Enquiries
 * screen first, then delete the plugin.
 *
 * What is safe to clear: the temporary rate-limit and form-state entries.
 */
global $wpdb;

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_jdf_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_jdf_' ) . '%'
	)
);
