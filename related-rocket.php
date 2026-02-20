<?php
/**
 * Plugin Name: Related Rocket
 * Plugin URI:  https://example.com/related-rocket
 * Description: High-performance related posts plugin skeleton using DB fallback, Redis object cache, and async rebuild architecture.
 * Version:     0.1.0
 * Author:      Related Rocket
 * Text Domain: related-rocket
 * Requires PHP: 7.4
 */

if (! defined('ABSPATH')) {
    exit;
}

define('RR_PLUGIN_VERSION', '0.1.0');
define('RR_PLUGIN_FILE', __FILE__);
define('RR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('RR_ALGO_VER', 1);
define('RR_TPL_VER', 1);
define('RR_DEFAULT_N', 30);
define('RR_TTL_IDS', 86400);
define('RR_TTL_HTML', 86400);
define('RR_TTL_NEG', 300);
define('RR_TTL_LOCK', 60);
define('RR_JITTER_RATIO', 0.1);
define('RR_W_TAG', 5);
define('RR_W_CAT', 2);
define('RR_PER_TAG_FETCH', 200);
define('RR_PER_CAT_FETCH', 200);
define('RR_MAX_CANDIDATES', 1200);

require_once RR_PLUGIN_DIR . 'includes/class-rr-install.php';
require_once RR_PLUGIN_DIR . 'includes/class-rr-cache.php';
require_once RR_PLUGIN_DIR . 'includes/class-rr-db.php';
require_once RR_PLUGIN_DIR . 'includes/class-rr-hooks.php';
require_once RR_PLUGIN_DIR . 'includes/class-rr-builder.php';
require_once RR_PLUGIN_DIR . 'includes/class-rr-render.php';
require_once RR_PLUGIN_DIR . 'includes/class-rr-async.php';
require_once RR_PLUGIN_DIR . 'includes/class-rr-cli.php';

register_activation_hook(RR_PLUGIN_FILE, array('RR_Install', 'activate'));
register_deactivation_hook(RR_PLUGIN_FILE, array('RR_Install', 'deactivate'));

/**
 * Template tag for rendering related posts.
 *
 * @param int|null $post_id Post ID.
 * @param int|null $n       Number of items.
 *
 * @return string
 */
function rr_related_posts($post_id = null, $n = null)
{
    static $memo = array();

    $pid = null === $post_id ? (int) get_the_ID() : (int) $post_id;
    $num = null === $n ? (int) RR_DEFAULT_N : absint($n);
    $key = $pid . ':' . $num;

    if (isset($memo[$key])) {
        return $memo[$key];
    }

    $memo[$key] = RR_Render::rr_related_posts($pid, $num);

    return $memo[$key];
}


/**
 * Admin-only DB self-check tool.
 *
 * @return array{ok:bool,message:string,data?:array}
 */
function rr_db_selfcheck()
{
    if (! is_admin() || ! current_user_can('manage_options')) {
        return array(
            'ok'      => false,
            'message' => 'admin_only',
        );
    }

    global $wpdb;

    $table = RR_DB::table_name();
    $like  = $wpdb->esc_like($table);
    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like));

    if ($found !== $table) {
        return array(
            'ok'      => false,
            'message' => 'table_not_found',
        );
    }

    $test_post_id = 0;
    $test_pairs   = array(array(123, 9));
    $test_hash    = md5('rr_db_selfcheck');

    $write_ok = RR_DB::upsert_related_pairs($test_post_id, $test_pairs, $test_hash);
    if (false === $write_ok) {
        return array(
            'ok'      => false,
            'message' => 'write_failed',
        );
    }

    $row = RR_DB::get_row($test_post_id);

    // Cleanup test row to avoid polluting normal data.
    $wpdb->delete($table, array('post_id' => $test_post_id), array('%d'));

    if (! is_array($row)) {
        return array(
            'ok'      => false,
            'message' => 'read_failed',
        );
    }

    return array(
        'ok'      => true,
        'message' => 'ok',
        'data'    => $row,
    );
}


if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('rr', 'RR_CLI');
}

RR_Hooks::init();
