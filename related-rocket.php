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

require_once RR_PLUGIN_DIR . 'includes/class-rr-install.php';
require_once RR_PLUGIN_DIR . 'includes/class-rr-cache.php';
require_once RR_PLUGIN_DIR . 'includes/class-rr-db.php';
require_once RR_PLUGIN_DIR . 'includes/class-rr-hooks.php';
require_once RR_PLUGIN_DIR . 'includes/class-rr-builder.php';
require_once RR_PLUGIN_DIR . 'includes/class-rr-render.php';
require_once RR_PLUGIN_DIR . 'includes/class-rr-async.php';

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
    return RR_Render::rr_related_posts($post_id, $n);
}

RR_Hooks::init();
