<?php
/**
 * Audit: snapshot, undo, execute-approved. PRO-ONLY (gated by aiag_is_pro()).
 */

defined( 'ABSPATH' ) || exit;

function aiag_snapshot_post( int $post_id, int $hold_id ): bool {
    global $wpdb;
    $post = get_post( $post_id );
    if ( ! $post ) {
        return false;
    }
    $snapshot = array(
        'post_title'   => $post->post_title,
        'post_content' => $post->post_content,
        'post_excerpt' => $post->post_excerpt,
        'post_status'  => $post->post_status,
        'post_type'    => $post->post_type,
        'meta_input'   => get_post_meta( $post_id ),
    );
    return (bool) $wpdb->insert(
        "{$wpdb->prefix}aiag_snapshots",
        array( 'hold_id' => $hold_id, 'entity_type' => 'post', 'entity_id' => $post_id, 'snapshot' => wp_json_encode( $snapshot ) ),
        array( '%d', '%s', '%d', '%s' )
    );
}

function aiag_undo_snapshot( int $hold_id ): bool {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aiag_snapshots WHERE hold_id=%d ORDER BY id DESC LIMIT 1", $hold_id ) );
    if ( ! $row ) {
        return false;
    }
    $snapshot = json_decode( $row->snapshot, true );
    if ( empty( $snapshot['post_content'] ) ) {
        return false;
    }
    $post_id = (int) $row->entity_id;
    $update  = wp_update_post(
        array(
            'ID'           => $post_id,
            'post_title'   => $snapshot['post_title'],
            'post_content' => $snapshot['post_content'],
            'post_excerpt' => $snapshot['post_excerpt'],
            'post_status'  => $snapshot['post_status'],
        ),
        true
    );
    if ( is_wp_error( $update ) ) {
        return false;
    }
    if ( ! empty( $snapshot['meta_input'] ) ) {
        foreach ( $snapshot['meta_input'] as $k => $v ) {
            update_post_meta( $post_id, $k, $v );
        }
    }
    aiag_log_audit( 'undo', array( 'hold_id' => $hold_id, 'post_id' => $post_id ), 'completed', sprintf( 'Restored post #%d.', $post_id ) );
    return true;
}

function aiag_execute_approved( int $hold_id ): array {
    global $wpdb;
    $entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aiag_hold WHERE id=%d AND status='approved'", $hold_id ), ARRAY_A );
    if ( ! $entry ) {
        return array( 'success' => false, 'error' => 'Entry not found or not approved.' );
    }
    if ( ! function_exists( 'wp_get_ability' ) ) {
        return array( 'success' => false, 'error' => 'Abilities API not available.' );
    }
    $ability = wp_get_ability( $entry['ability_name'] );
    if ( ! $ability ) {
        return array( 'success' => false, 'error' => sprintf( 'Ability "%s" not found.', $entry['ability_name'] ) );
    }
    $input = json_decode( $entry['input'], true );

    // Temporarily remove interception.
    remove_filter( 'wp_pre_execute_ability', 'aiag_pre_execute', 5 );
    $result = $ability->execute( $input );
    add_filter( 'wp_pre_execute_ability', 'aiag_pre_execute', 5, 3 );

    if ( is_wp_error( $result ) ) {
        aiag_log_audit( $entry['ability_name'], $input, 'failed', $result->get_error_message(), array( 'hold_id' => $hold_id ) );
        return array( 'success' => false, 'error' => $result->get_error_message() );
    }
    aiag_log_audit( $entry['ability_name'], $input, 'completed', sprintf( 'Executed after approval (hold #%d).', $hold_id ), array( 'hold_id' => $hold_id ) );
    return array( 'success' => true, 'result' => $result );
}
