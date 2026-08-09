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
}

