<?php
/**
 * Plugin Name: AI Agent Governance
 * Description: Control, approve, and audit AI agent actions on your WordPress site.
 * Version: 0.1.0
 * Author: Indra A
 * License: GPL v2 or later
 * Requires at least: 6.9
 * Requires PHP: 8.0
 *
 * Governance layer between AI agents and WordPress Abilities API.
 * Decision flow: kill switch → blocked list → destructive annotation → rules → default.
 */

defined( 'ABSPATH' ) || exit;

if ( function_exists( 'wag_fs' ) ) {
    wag_fs()->set_basename( true, __FILE__ );
} else {
    /**
     * DO NOT REMOVE THIS IF, IT IS ESSENTIAL FOR THE
     * `function_exists` CALL ABOVE TO PROPERLY WORK.
     */
    if ( ! function_exists( 'wag_fs' ) ) {
        // Create a helper function for easy SDK access.
        function wag_fs() {
            global $wag_fs;

            if ( ! isset( $wag_fs ) ) {
                // Activate multisite network integration.
                if ( ! defined( 'WP_FS__PRODUCT_36789_MULTISITE' ) ) {
                    define( 'WP_FS__PRODUCT_36789_MULTISITE', true );
                }

                // Include Freemius SDK.
                require_once dirname( __FILE__ ) . '/vendor/freemius/start.php';

                $wag_fs = fs_dynamic_init( array(
                    'id'                  => '36789',
                    'slug'                => 'wp-ai-governance',
                    'type'                => 'plugin',
                    'public_key'          => 'pk_d2c889be8503f956cc7ad680765a2',
                    'is_premium'          => true,
                    'premium_suffix'      => 'Pro',
                    // If your plugin is a serviceware, set this option to false.
                    'has_premium_version' => true,
                    'has_addons'          => false,
                    'has_paid_plans'      => true,
                    'is_org_compliant'    => true,
                    'trial'               => array(
                        'days'               => 7,
                        'is_require_payment' => true,
                    ),
                    'menu'                => array(
                        'slug'           => 'wp-ai-governance',
                        'network'        => true,
                    ),
                ) );
            }

            return $wag_fs;
        }

        // Init Freemius.
        wag_fs();
        // Signal that SDK was initiated.
        do_action( 'wag_fs_loaded' );
    }
}

define( 'AIAG_VERSION', '0.1.0' );
define( 'AIAG_FILE', __FILE__ );
define( 'AIAG_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIAG_URL', plugin_dir_url( __FILE__ ) );
define( 'AIAG_OPTION_PREFIX', 'aiag_' );

// Includes.
require_once AIAG_DIR . 'includes/db.php';
require_once AIAG_DIR . 'includes/policy.php';
require_once AIAG_DIR . 'includes/audit.php';
require_once AIAG_DIR . 'includes/admin.php';

/**
 * Resolve Pro access. A valid premium license (or trial) unlocks Pro
 * features at runtime via Freemius. Uses can_use_premium_code__premium_only()
 * — the recommended gate for a single paid plan.
 */
function aiag_is_pro(): bool {
    static $pro = null;
    if ( null !== $pro ) {
        return $pro;
    }
    $pro = wag_fs()->can_use_premium_code__premium_only();
    return $pro;
}

/**
 * Activation — create tables + seed defaults.
 */
function aiag_activate() {
    aiag_create_tables();
    if ( false === get_option( AIAG_OPTION_PREFIX . 'kill_switch' ) ) {
        update_option( AIAG_OPTION_PREFIX . 'kill_switch', 0 );
    }
    if ( false === get_option( AIAG_OPTION_PREFIX . 'default_action' ) ) {
        update_option( AIAG_OPTION_PREFIX . 'default_action', 'deny' );
    }
    if ( false === get_option( AIAG_OPTION_PREFIX . 'blocked_abilities' ) ) {
        update_option( AIAG_OPTION_PREFIX . 'blocked_abilities', array() );
    }
    if ( false === get_option( AIAG_OPTION_PREFIX . 'policies' ) ) {
        update_option( AIAG_OPTION_PREFIX . 'policies', aiag_default_policies() );
    }
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'aiag_activate' );

function aiag_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'aiag_deactivate' );

/**
 * Uninstall (via Freemius after_uninstall hook) — drop tables, delete options.
 * Fires when the plugin is deleted from the site.
 */
function aiag_uninstall() {
    global $wpdb;
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aiag_audit" );
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aiag_hold" );
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aiag_snapshots" );

    delete_option( 'aiag_kill_switch' );
    delete_option( 'aiag_default_action' );
    delete_option( 'aiag_blocked_abilities' );
    delete_option( 'aiag_policies' );
    delete_option( 'aiag_telegram_enabled' );
    delete_option( 'aiag_telegram_bot_token' );
    delete_option( 'aiag_telegram_chat_id' );
}
wag_fs()->add_action( 'after_uninstall', 'aiag_uninstall' );

/* ─── DECISION ENGINE ─── */

/**
 * Core decision: kill switch → blocked list → destructive → rules → default.
 *
 * @return array{decision: string, reason: string, rule_id?: int}
 */
function aiag_decide( string $ability_name, $input = null, array $annotations = array() ): array {
    // 1. Kill switch.
    if ( get_option( AIAG_OPTION_PREFIX . 'kill_switch', 0 ) ) {
        return array( 'decision' => 'deny', 'reason' => 'Kill switch active.' );
    }

    // 2. Blocked list.
    $blocked = get_option( AIAG_OPTION_PREFIX . 'blocked_abilities', array() );
    if ( in_array( $ability_name, $blocked, true ) ) {
        return array( 'decision' => 'deny', 'reason' => sprintf( 'Ability "%s" is blocked.', $ability_name ) );
    }

    // Also check blocked patterns.
    foreach ( $blocked as $pat ) {
        if ( strpos( $pat, '*' ) !== false && aiag_pattern_matches( $ability_name, $pat ) ) {
            return array( 'decision' => 'deny', 'reason' => sprintf( 'Ability "%s" matches blocked pattern "%s".', $ability_name, $pat ) );
        }
    }

    // 3. Destructive annotation → hold (BEFORE rules).
    if ( ! empty( $annotations['destructive'] ) ) {
        return array( 'decision' => 'hold', 'reason' => sprintf( '"%s" is destructive — requires approval.', $ability_name ) );
    }

    // 4. Rules — first match.
    $matched = aiag_find_matching_rule( $ability_name, $input );
    if ( $matched ) {
        return array(
            'decision' => $matched['action'],
            'reason'   => sprintf( 'Rule #%d "%s" matched.', $matched['id'], $matched['label'] ?? $matched['pattern'] ),
            'rule_id'  => $matched['id'],
        );
    }

    // 5. Default.
    $default = get_option( AIAG_OPTION_PREFIX . 'default_action', 'deny' );
    return array( 'decision' => $default, 'reason' => sprintf( 'No rule matched. Default: %s.', $default ) );
}

/**
 * Find first matching policy rule.
 */
function aiag_find_matching_rule( string $ability_name, $input = null ): ?array {
    $policies = get_option( AIAG_OPTION_PREFIX . 'policies', array() );
    foreach ( $policies as $policy ) {
        if ( empty( $policy['enabled'] ) ) {
            continue;
        }
        if ( ! aiag_pattern_matches( $ability_name, $policy['pattern'] ) ) {
            continue;
        }
        // Check input conditions.
        if ( ! empty( $policy['conditions'] ) && is_array( $input ) ) {
            $met = true;
            foreach ( $policy['conditions'] as $key => $expected ) {
                if ( ! isset( $input[ $key ] ) || (string) $input[ $key ] !== (string) $expected ) {
                    $met = false;
                    break;
                }
            }
            if ( ! $met ) {
                continue;
            }
        }
        return $policy;
    }
    return null;
}

/**
 * Glob-style pattern matching: * = any, ? = single char.
 */
function aiag_pattern_matches( string $name, string $pattern ): bool {
    if ( $name === $pattern ) {
        return true;
    }
    if ( strpos( $pattern, '*' ) === false && strpos( $pattern, '?' ) === false ) {
        return str_starts_with( $name, $pattern . '/' );
    }
    // Split on wildcard chars, quote literal segments, convert wildcards to regex.
    $segments = preg_split( '/(\*|\?)/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE );
    $regex = '/^';
    foreach ( $segments as $seg ) {
        if ( '*' === $seg ) {
            $regex .= '.*?';
        } elseif ( '?' === $seg ) {
            $regex .= '.';
        } else {
            $regex .= preg_quote( $seg, '/' );
        }
    }
    $regex .= '$/';
    return (bool) preg_match( $regex, $name );
}

/* ─── INTERCEPTION ─── */

/**
 * WP 7.1+ short-circuit filter.
 */
function aiag_pre_execute( $ability_or_name, $input = null, $ability_obj = null ) {
    if ( is_object( $ability_or_name ) && method_exists( $ability_or_name, 'get_name' ) ) {
        $name        = $ability_or_name->get_name();
        $annotations = $ability_or_name->get_meta( 'annotations' ) ?? array();
    } elseif ( is_string( $ability_or_name ) ) {
        $name        = $ability_or_name;
        $annotations = array();
        if ( function_exists( 'wp_get_ability' ) ) {
            $obj = wp_get_ability( $name );
            if ( $obj && method_exists( $obj, 'get_meta' ) ) {
                $annotations = $obj->get_meta( 'annotations' ) ?? array();
            }
        }
    } else {
        return;
    }

    $decision = aiag_decide( $name, $input, $annotations );
    $context  = array( 'ability' => $name, 'input' => is_array( $input ) ? $input : array( 'raw' => $input ) );

    if ( 'deny' === $decision['decision'] ) {
        aiag_log_audit( $name, $input, 'denied', $decision['reason'], $context );
        return false;
    }
    if ( 'hold' === $decision['decision'] ) {
        $entry_id = aiag_create_hold_entry( $name, $input, $decision['reason'], $context );
        aiag_notify_admin( $name, $entry_id, $decision['reason'] );
        return false;
    }
    // allow
    aiag_log_audit( $name, $input, 'allowed', $decision['reason'], $context );
}
add_filter( 'wp_pre_execute_ability', 'aiag_pre_execute', 5, 3 );

/**
 * WP 6.9 fallback — wrap permission_callback at registration time.
 *
 * WP 6.9 fires wp_before_execute_ability AFTER check_permissions, so the
 * flag-based approach is too late. Instead, intercept wp_register_ability_args
 * (fires before WP_Ability instantiation) and wrap every permission_callback
 * with a direct aiag_decide() call. This runs during check_permissions — correct timing.
 */
function aiag_wrap_permission_callback( array $args, string $name ): array {
    if ( ! isset( $args['permission_callback'] ) || ! is_callable( $args['permission_callback'] ) ) {
        return $args;
    }
    $original = $args['permission_callback'];
    $args['permission_callback'] = function ( $input = null ) use ( $original, $name, $args ) {
        $annotations = $args["meta"]["annotations"] ?? array();
        if ( empty( $annotations ) && function_exists( 'wp_get_ability' ) ) {
            $obj = wp_get_ability( $name );
            if ( $obj && method_exists( $obj, 'get_meta' ) ) {
                $annotations = $obj->get_meta( 'annotations' ) ?? array();
            }
        }
        $decision = aiag_decide( $name, $input, $annotations );
        if ( 'deny' === $decision['decision'] ) {
            aiag_log_audit( $name, $input, 'denied', $decision['reason'] );
            return new \WP_Error( 'aiag_denied', $decision['reason'], array( 'status' => 403 ) );
        }
        if ( 'hold' === $decision['decision'] ) {
            $context  = array( 'ability' => $name, 'input' => is_array( $input ) ? $input : array( 'raw' => $input ) );
            $entry_id = aiag_create_hold_entry( $name, $input, $decision['reason'], $context );
            aiag_notify_admin( $name, $entry_id, $decision['reason'] );
            return new \WP_Error( 'aiag_hold', sprintf( 'Pending approval (entry #%d).', $entry_id ), array( 'status' => 403 ) );
        }
        // allow
        aiag_log_audit( $name, $input, 'allowed', $decision['reason'] );
        return $original( $input );
    };
    return $args;
}
add_filter( 'wp_register_ability_args', 'aiag_wrap_permission_callback', 999, 2 );

/**
 * Wrap a permission_callback to honor governance decisions.
 *
 * Usage in ability registration:
 *   'permission_callback' => aiag_wrap_permission( 'my_callback', 'my-plugin/my-ability' ),
 */
function aiag_wrap_permission( callable $original, string $ability_name ): callable {
    return function ( $input = null ) use ( $original, $ability_name ) {
        $annotations = array();
        if ( function_exists( 'wp_get_ability' ) ) {
            $obj = wp_get_ability( $ability_name );
            if ( $obj && method_exists( $obj, 'get_meta' ) ) {
                $annotations = $obj->get_meta( 'annotations' ) ?? array();
            }
        }
        $decision = aiag_decide( $ability_name, $input, $annotations );
        if ( 'deny' === $decision['decision'] ) {
            aiag_log_audit( $ability_name, $input, 'denied', $decision['reason'] );
            return new \WP_Error( 'aiag_denied', $decision['reason'], array( 'status' => 403 ) );
        }
        if ( 'hold' === $decision['decision'] ) {
            $context  = array( 'ability' => $ability_name, 'input' => is_array( $input ) ? $input : array( 'raw' => $input ) );
            $entry_id = aiag_create_hold_entry( $ability_name, $input, $decision['reason'], $context );
            aiag_notify_admin( $ability_name, $entry_id, $decision['reason'] );
            return new \WP_Error( 'aiag_hold', sprintf( 'Pending approval (entry #%d).', $entry_id ), array( 'status' => 403 ) );
        }
        aiag_log_audit( $ability_name, $input, 'allowed', $decision['reason'] );
        return $original( $input );
    };
}
