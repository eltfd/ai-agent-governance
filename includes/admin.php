<?php
/**
 * Admin: dashboard, approvals, policies, audit pages + form handlers.
 */

defined( 'ABSPATH' ) || exit;

function aiag_admin_menu() {
    add_menu_page(
        'AI Agent Governance', 'AI Governance', 'manage_options',
        'aiag-governance', 'aiag_render_dashboard', 'dashicons-shield-alt2', 80
    );
    add_submenu_page( 'aiag-governance', 'Approvals', 'Approvals', 'manage_options', 'aiag-governance-approvals', 'aiag_render_approvals' );
    add_submenu_page( 'aiag-governance', 'Policies', 'Policies', 'manage_options', 'aiag-governance-policies', 'aiag_render_policies' );
    add_submenu_page( 'aiag-governance', 'Audit Log', 'Audit Log', 'manage_options', 'aiag-governance-audit', 'aiag_render_audit' );
}
add_action( 'admin_menu', 'aiag_admin_menu' );

/* ─── FORM HANDLERS ─── */

function aiag_handle_actions() {
    if ( empty( $_POST['aiag_action'] ) || ! wp_verify_nonce( wp_unslash( $_POST['aiag_nonce'] ?? '' ), 'aiag_action' ) ) {
        return;
    }
    $action = sanitize_text_field( wp_unslash( $_POST['aiag_action'] ) );

    switch ( $action ) {
        case 'approve':
            $hold_id = absint( $_POST['hold_id'] ?? 0 );
            if ( $hold_id ) {
                $input = json_decode( wp_unslash( $_POST['input'] ?? '{}' ), true );
                aiag_approve_hold( $hold_id );
            }
            break;
        case 'reject':
            $hold_id = absint( $_POST['hold_id'] ?? 0 );
            if ( $hold_id ) {
                aiag_reject_hold( $hold_id );
                add_settings_error( 'aiag', 'rejected', 'Action rejected.', 'warning' );
            }
            break;
        case 'undo':
            break;
        case 'toggle_kill':
            $cur = get_option( AIAG_OPTION_PREFIX . 'kill_switch', 0 );
            update_option( AIAG_OPTION_PREFIX . 'kill_switch', $cur ? 0 : 1 );
            add_settings_error( 'aiag', 'kill', $cur ? 'Kill switch OFF.' : 'Kill switch ON — all agents blocked.', $cur ? 'success' : 'error' );
            break;
        case 'update_default':
            $d = sanitize_text_field( wp_unslash( $_POST['default_action'] ?? 'deny' ) );
            if ( in_array( $d, array( 'allow', 'deny', 'hold' ), true ) ) {
                update_option( AIAG_OPTION_PREFIX . 'default_action', $d );
                add_settings_error( 'aiag', 'def', 'Default action: ' . $d, 'success' );
            }
            break;
        case 'update_blocked':
            $lines = array_filter( array_map( 'trim', explode( "\n", wp_unslash( $_POST['blocked_list'] ?? '' ) ) ) );
            update_option( AIAG_OPTION_PREFIX . 'blocked_abilities', array_values( $lines ) );
            add_settings_error( 'aiag', 'blk', 'Blocked list updated.', 'success' );
            break;
        case 'update_telegram':
            break;
    }
}
add_action( 'admin_init', 'aiag_handle_actions' );

/* ─── DASHBOARD ─── */

function aiag_render_dashboard() {
    $stats   = aiag_get_audit_stats();
    $is_kill = get_option( AIAG_OPTION_PREFIX . 'kill_switch', 0 );
    $default = get_option( AIAG_OPTION_PREFIX . 'default_action', 'deny' );
    ?>
    <div class="wrap">
        <h1>AI Agent Governance</h1>
        <?php settings_errors( 'aiag' ); ?>

        <div style="display:flex;gap:16px;margin:16px 0;">
            <div class="card" style="flex:1;padding:16px;">
                <h3>Kill Switch</h3>
                <p><strong style="color:<?php echo esc_attr( $is_kill ? '#dc3232' : '#46b450' ); ?>">
                    <?php echo esc_html( $is_kill ? 'ACTIVE — All blocked' : 'OFF — Agents allowed' ); ?>
                </strong></p>
                <form method="post">
                    <?php wp_nonce_field( 'aiag_action', 'aiag_nonce' ); ?>
                    <input type="hidden" name="aiag_action" value="toggle_kill">
                    <button type="submit" class="button<?php echo $is_kill ? ' button-primary' : ''; ?>" style="<?php echo $is_kill ? 'background:#dc3232;border-color:#dc3232;' : ''; ?>">
                        <?php echo $is_kill ? 'Deactivate' : 'Activate'; ?>
                    </button>
                </form>
            </div>
            <div class="card" style="flex:1;padding:16px;">
                <h3>Default Action</h3>
                <form method="post">
                    <?php wp_nonce_field( 'aiag_action', 'aiag_nonce' ); ?>
                    <input type="hidden" name="aiag_action" value="update_default">
                    <select name="default_action">
                        <?php foreach ( array( 'deny', 'allow', 'hold' ) as $v ) : ?>
                            <option value="<?php echo esc_attr( $v ); ?>" <?php selected( $default, $v ); ?>><?php echo esc_html( ucfirst( $v ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button">Save</button>
                </form>
            </div>
        </div>

        <div style="display:flex;gap:16px;margin:16px 0;">
            <?php
            $cards = array(
                array( $stats['total'], 'Total', '#333' ),
                array( $stats['allowed'], 'Allowed', '#46b450' ),
                array( $stats['denied'], 'Denied', '#dc3232' ),
                array( $stats['pending'], 'Pending', '#ffb900' ),
                array( $stats['approved'], 'Approved', '#00a0d2' ),
            );
            foreach ( $cards as $c ) :
            ?>
                <div class="card" style="flex:1;padding:16px;text-align:center;">
                    <h2 style="color:<?php echo esc_attr( $c[2] ); ?>;"><?php echo esc_html( $c[0] ); ?></h2>
                    <p><?php echo esc_html( $c[1] ); ?></p>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="card" style="padding:16px;">
            <h3>Blocked Abilities</h3>
            <form method="post">
                <?php wp_nonce_field( 'aiag_action', 'aiag_nonce' ); ?>
                <input type="hidden" name="aiag_action" value="update_blocked">
                <textarea name="blocked_list" rows="5" cols="60" placeholder="core/delete-post&#10;*/bulk-delete*&#10;*/trash*"><?php
                    echo esc_textarea( implode( "\n", get_option( AIAG_OPTION_PREFIX . 'blocked_abilities', array() ) ) );
                ?></textarea>
                <p class="description">One per line. Supports * (any) and ? (single char) wildcards.</p>
                <button type="submit" class="button button-primary">Save Blocked List</button>
            </form>
        </div>

        <div class="card" style="padding:16px;margin-top:16px;">
            <h3>🔔 Telegram Notifications <?php if ( ! aiag_is_pro() ) : ?><span class="dashicons dashicons-star-filled" style="color:#f5a623;" title="Pro feature"></span><?php endif; ?></h3>
            <?php if ( aiag_is_pro() ) : ?>
            <?php else : ?>
                <p>Telegram notifications are a <strong>Pro</strong> feature. Upgrade to get instant alerts for pending approvals.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/* ─── APPROVALS ─── */

function aiag_render_approvals() {
    $pending = aiag_get_pending_holds();
    ?>
    <div class="wrap">
        <h1>Pending Approvals</h1>
        <?php settings_errors( 'aiag' ); ?>
        <?php if ( empty( $pending ) ) : ?>
            <div class="card" style="padding:32px;text-align:center;">
                <p style="font-size:16px;color:#666;">No pending approvals.</p>
            </div>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr>
                    <th>ID</th><th>Ability</th><th>Input</th><th>Reason</th><th>Time</th><th>Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ( $pending as $e ) : ?>
                    <tr>
                        <td><?php echo esc_html( $e['id'] ); ?></td>
                        <td><code><?php echo esc_html( $e['ability_name'] ); ?></code></td>
                        <td><pre style="max-width:300px;overflow:auto;margin:0;"><?php echo esc_html( $e['input'] ); ?></pre></td>
                        <td><?php echo esc_html( $e['reason'] ); ?></td>
                        <td><?php echo esc_html( $e['created_at'] ); ?></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field( 'aiag_action', 'aiag_nonce' ); ?>
                                <input type="hidden" name="aiag_action" value="approve">
                                <input type="hidden" name="hold_id" value="<?php echo esc_attr( $e['id'] ); ?>">
                                <input type="hidden" name="input" value="<?php echo esc_attr( $e['input'] ); ?>">
                                <button class="button button-primary" style="color:#fff;background:#46b450;border-color:#46b450;">Approve</button>
                            </form>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field( 'aiag_action', 'aiag_nonce' ); ?>
                                <input type="hidden" name="aiag_action" value="reject">
                                <input type="hidden" name="hold_id" value="<?php echo esc_attr( $e['id'] ); ?>">
                                <button class="button">Reject</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

/* ─── POLICIES ─── */

function aiag_render_policies() {
    $policies = get_option( AIAG_OPTION_PREFIX . 'policies', array() );
    ?>
    <div class="wrap">
        <h1>Governance Policies</h1>
        <p class="description">Rules evaluated in order. First match wins. Destructive annotations always hold.</p>
        <table class="widefat striped" style="margin-top:16px;">
            <thead><tr><th>ID</th><th>Label</th><th>Pattern</th><th>Action</th><th>Enabled</th><th>Conditions</th></tr></thead>
            <tbody>
            <?php foreach ( $policies as $p ) : ?>
                <tr>
                    <td><?php echo esc_html( $p['id'] ); ?></td>
                    <td><?php echo esc_html( $p['label'] ?? '' ); ?></td>
                    <td><code><?php echo esc_html( $p['pattern'] ); ?></code></td>
                    <td style="color:<?php echo esc_attr( $p['action'] === 'allow' ? '#46b450' : ( $p['action'] === 'deny' ? '#dc3232' : '#ffb900' ) ); ?>;"><?php echo esc_html( strtoupper( $p['action'] ) ); ?></td>
                    <td><?php echo ! empty( $p['enabled'] ) ? '✅' : '❌'; ?></td>
                    <td><code><?php echo esc_html( wp_json_encode( $p['conditions'] ?? array() ) ); ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="card" style="margin-top:16px;padding:16px;">
            <h3>Pattern Examples</h3>
            <ul>
                <li><code>core/*</code> — all core abilities</li>
                <li><code>*/delete*</code> — any with "delete"</li>
                <li><code>core/update-settings</code> — exact match + prefix</li>
                <li><code>*/create-?</code> — "create-" + one char</li>
            </ul>
        </div>
    </div>
    <?php
}

/* ─── AUDIT LOG ─── */

function aiag_render_audit() {
    $f = sanitize_text_field( wp_unslash( $_GET['status'] ?? '' ) );
    $audit = aiag_get_audit_log( 100, 0, $f );
    ?>
    <div class="wrap">
        <h1>Audit Log</h1>
        <div style="margin:16px 0;">
            <?php
            $tabs = array( '' => 'All', 'allowed' => 'Allowed', 'denied' => 'Denied', 'hold' => 'Hold', 'approved' => 'Approved' );
            foreach ( $tabs as $k => $v ) {
                printf( '<a href="%s" class="button%s">%s</a> ',
                    esc_url( admin_url( 'admin.php?page=aiag-governance&tab=audit' . ( $k ? '&status=' . rawurlencode( $k ) : '' ) ) ),
                    $f === $k || ( ! $f && ! $k ) ? ' button-primary' : '',
                    esc_html( $v )
                );
            }
            ?>
        </div>
        <?php if ( empty( $audit ) ) : ?>
            <div class="card" style="padding:32px;text-align:center;"><p style="color:#666;">No entries.</p></div>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr><th>ID</th><th>Ability</th><th>Status</th><th>Reason</th><th>Time</th></tr></thead>
                <tbody>
                <?php
                $colors = array( 'allowed' => '#46b450', 'denied' => '#dc3232', 'approved' => '#00a0d2', 'hold' => '#ffb900', 'invoked' => '#999', 'completed' => '#46b450' );
                foreach ( $audit as $a ) :
                ?>
                    <tr>
                        <td><?php echo esc_html( $a['id'] ); ?></td>
                        <td><code><?php echo esc_html( $a['ability_name'] ); ?></code></td>
                        <td style="color:<?php echo esc_attr( $colors[ $a['status'] ] ?? '#333' ); ?>;"><?php echo esc_html( strtoupper( $a['status'] ) ); ?></td>
                        <td><?php echo esc_html( $a['reason'] ); ?></td>
                        <td><?php echo esc_html( $a['created_at'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}
