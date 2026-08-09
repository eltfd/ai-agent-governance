<?php
/**
 * Uninstall: drop tables, delete options.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aiag_audit" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aiag_hold" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aiag_snapshots" );

delete_option( 'aiag_kill_switch' );
delete_option( 'aiag_default_action' );
delete_option( 'aiag_blocked_abilities' );
delete_option( 'aiag_policies' );
