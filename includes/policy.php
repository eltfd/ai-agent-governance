<?php
/**
 * Policy: default rules, pattern helpers, notification.
 */

defined( 'ABSPATH' ) || exit;

function aiag_default_policies(): array {
    return array(
        array(
            'id'         => 1,
            'label'      => 'Block bulk deletes',
            'pattern'    => '*/bulk-delete*',
            'action'     => 'deny',
            'enabled'    => true,
            'conditions' => array(),
        ),
        array(
            'id'         => 2,
            'label'      => 'Hold plugin/theme changes',
            'pattern'    => '*/install*',
            'action'     => 'hold',
            'enabled'    => true,
            'conditions' => array(),
        ),
        array(
            'id'         => 3,
            'label'      => 'Hold settings updates',
            'pattern'    => 'core/update-settings',
            'action'     => 'hold',
            'enabled'    => true,
            'conditions' => array(),
        ),
        array(
            'id'         => 4,
            'label'      => 'Allow read-only abilities',
            'pattern'    => 'core/read-*',
            'action'     => 'allow',
            'enabled'    => true,
            'conditions' => array(),
        ),
        array(
            'id'         => 5,
            'label'      => 'Allow safe content creation',
            'pattern'    => 'core/create-post',
            'action'     => 'allow',
            'enabled'    => true,
            'conditions' => array(),
        ),
    );
}

function aiag_notify_admin( string $ability_name, int $hold_id, string $reason ): void {
    if ( ! aiag_is_pro() ) {
        return;
    }
    $admin_email = get_option( 'admin_email' );
    $site_name   = get_bloginfo( 'name' );
    $admin_url   = admin_url( 'admin.php?page=aiag-governance&tab=approvals' );

    wp_mail(
        $admin_email,
        sprintf( '[%s] AI Agent Action Pending Approval', $site_name ),
        sprintf(
            "An AI agent action requires your approval.\n\nAbility: %s\nReason: %s\nEntry: #%d\n\nApprove/reject: %s\n\n— AI Agent Governance v%s",
            $ability_name,
            $reason,
            $hold_id,
            $admin_url,
            AIAG_VERSION
        ),
        array( 'Content-Type: text/plain; charset=UTF-8' )
    );

    aiag_send_telegram( sprintf(
        "🤖 *AI Agent Action Pending Approval*\n\nAbility: `%s`\nReason: %s\nEntry: #%d\n\nApprove/reject: %s",
        $ability_name,
        $reason,
        $hold_id,
            $admin_url
    ) );
}

/**
 * Send pending-approval alert to Telegram (Pro feature).
 * Requires enabled option + bot token + chat ID. Silently no-ops when unset.
 */
function aiag_send_telegram( string $message ): bool {
    if ( ! aiag_is_pro() || ! get_option( AIAG_OPTION_PREFIX . 'telegram_enabled', 0 ) ) {
        return false;
    }
    $token = get_option( AIAG_OPTION_PREFIX . 'telegram_bot_token', '' );
    $chat  = get_option( AIAG_OPTION_PREFIX . 'telegram_chat_id', '' );
    if ( ! $token || ! $chat ) {
        return false;
    }

    $res = wp_remote_post(
        'https://api.telegram.org/bot' . $token . '/sendMessage',
        array(
            'timeout' => 10,
            'body'    => array(
                'chat_id'    => $chat,
                'text'       => $message,
                'parse_mode' => 'Markdown',
            ),
        )
    );
    if ( is_wp_error( $res ) ) {
        return false;
    }
    $code = (int) wp_remote_retrieve_response_code( $res );
    return 200 === $code;
}
