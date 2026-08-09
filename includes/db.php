<?php
/**
 * Database: table creation, CRUD for audit log, hold entries, snapshots.
 */

defined( 'ABSPATH' ) || exit;

function aiag_create_tables(): void {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $prefix  = $wpdb->prefix;

    $sql = "CREATE TABLE {$prefix}aiag_audit (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ability_name VARCHAR(255) NOT NULL,
        input LONGTEXT,
        status VARCHAR(32) NOT NULL DEFAULT 'pending',
        reason TEXT,
        context LONGTEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_ability (ability_name),
        KEY idx_status (status),
        KEY idx_created (created_at)
    ) {$charset};";

    $sql .= "CREATE TABLE {$prefix}aiag_hold (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ability_name VARCHAR(255) NOT NULL,
        input LONGTEXT,
        reason TEXT,
        context LONGTEXT,
        status VARCHAR(32) NOT NULL DEFAULT 'pending',
        reviewer_id BIGINT UNSIGNED DEFAULT NULL,
        reviewed_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_status (status),
        KEY idx_created (created_at)
    ) {$charset};";

    $sql .= "CREATE TABLE {$prefix}aiag_snapshots (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        hold_id BIGINT UNSIGNED NOT NULL,
        entity_type VARCHAR(64) NOT NULL DEFAULT 'post',
        entity_id BIGINT UNSIGNED NOT NULL,
        snapshot LONGTEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_hold (hold_id)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

function aiag_log_audit( string $ability_name, mixed $input, string $status, string $reason = '', array $context = array() ): int {
    global $wpdb;
    $ok = $wpdb->insert(
        "{$wpdb->prefix}aiag_audit",
        array(
            'ability_name' => $ability_name,
            'input'        => is_array( $input ) ? wp_json_encode( $input ) : (string) $input,
            'status'       => $status,
            'reason'       => $reason,
            'context'      => wp_json_encode( $context ),
        ),
        array( '%s', '%s', '%s', '%s', '%s' )
    );
    return $ok ? (int) $wpdb->insert_id : 0;
}

function aiag_create_hold_entry( string $ability_name, mixed $input, string $reason = '', array $context = array() ): int {
    global $wpdb;
    $wpdb->insert(
        "{$wpdb->prefix}aiag_hold",
        array(
            'ability_name' => $ability_name,
            'input'        => is_array( $input ) ? wp_json_encode( $input ) : (string) $input,
            'reason'       => $reason,
            'context'      => wp_json_encode( $context ),
            'status'       => 'pending',
        ),
        array( '%s', '%s', '%s', '%s', '%s' )
    );
    return (int) $wpdb->insert_id;
}

function aiag_approve_hold( int $hold_id, int $reviewer_id = 0 ): bool {
    global $wpdb;
    $reviewer_id = $reviewer_id ?: get_current_user_id();
    $wpdb->update(
        "{$wpdb->prefix}aiag_hold",
        array( 'status' => 'approved', 'reviewer_id' => $reviewer_id, 'reviewed_at' => current_time( 'mysql' ) ),
        array( 'id' => $hold_id ),
        array( '%s', '%d', '%s' ),
        array( '%d' )
    );
    $entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aiag_hold WHERE id=%d", $hold_id ) );
    if ( $entry ) {
        aiag_log_audit( $entry->ability_name, json_decode( $entry->input, true ), 'approved', sprintf( 'Approved by #%d.', $reviewer_id ), array( 'hold_id' => $hold_id ) );
    }
    return true;
}

function aiag_reject_hold( int $hold_id, int $reviewer_id = 0 ): bool {
    global $wpdb;
    $reviewer_id = $reviewer_id ?: get_current_user_id();
    $wpdb->update(
        "{$wpdb->prefix}aiag_hold",
        array( 'status' => 'rejected', 'reviewer_id' => $reviewer_id, 'reviewed_at' => current_time( 'mysql' ) ),
        array( 'id' => $hold_id ),
        array( '%s', '%d', '%s' ),
        array( '%d' )
    );
    $entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aiag_hold WHERE id=%d", $hold_id ) );
    if ( $entry ) {
        aiag_log_audit( $entry->ability_name, json_decode( $entry->input, true ), 'rejected', sprintf( 'Rejected by #%d.', $reviewer_id ), array( 'hold_id' => $hold_id ) );
    }
    return true;
}

function aiag_get_pending_holds( int $limit = 50, int $offset = 0 ): array {
    global $wpdb;
    return $wpdb->get_results(
        $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aiag_hold WHERE status='pending' ORDER BY created_at DESC LIMIT %d OFFSET %d", $limit, $offset ),
        ARRAY_A
    );
}

function aiag_get_audit_log( int $limit = 100, int $offset = 0, string $status = '' ): array {
    global $wpdb;
    $table = $wpdb->prefix . 'aiag_audit';
    if ( $status ) {
        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE status=%s ORDER BY created_at DESC LIMIT %d OFFSET %d", $status, $limit, $offset ),
            ARRAY_A
        );
    }
    return $wpdb->get_results(
        $wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d", $limit, $offset ),
        ARRAY_A
    );
}

function aiag_get_audit_stats(): array {
    global $wpdb;
    return array(
        'total'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}aiag_audit" ),
        'allowed'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}aiag_audit WHERE status='allowed'" ),
        'denied'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}aiag_audit WHERE status='denied'" ),
        'pending'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}aiag_hold WHERE status='pending'" ),
        'approved' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}aiag_hold WHERE status='approved'" ),
        'rejected' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}aiag_hold WHERE status='rejected'" ),
    );
}
