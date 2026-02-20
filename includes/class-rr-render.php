<?php

if (! defined('ABSPATH')) {
    exit;
}

class RR_Render
{
    public static function rr_related_posts($post_id = null, $n = null)
    {
        $post_id = $post_id ? (int) $post_id : (int) get_the_ID();
        $n       = $n ? absint($n) : RR_DEFAULT_N;

        if ($post_id <= 0 || $n <= 0) {
            return '';
        }

        RR_Cache::register_n_variant($n);

        $theme_hash  = self::theme_hash();
        $runtime_key = $post_id . ':' . $n . ':' . $theme_hash;

        // 1) runtime cache
        $runtime = RR_Cache::get_runtime($runtime_key);
        if (null !== $runtime) {
            return (string) $runtime;
        }

        // 2) HTML cache
        $html_key = RR_Cache::key_html($post_id, RR_ALGO_VER, $n, RR_TPL_VER, $theme_hash);
        $html     = RR_Cache::get($html_key);
        if (false !== $html) {
            $html = (string) $html;
            RR_Cache::set_runtime($runtime_key, $html);
            return $html;
        }

        // 3) negative cache
        if (RR_Cache::is_negative($post_id, $n)) {
            RR_Cache::set_runtime($runtime_key, '');
            return '';
        }

        // 4) IDs cache
        $ids_key   = RR_Cache::key_ids($post_id, RR_ALGO_VER, $n);
        $cached_ids = RR_Cache::get($ids_key);
        $ids       = array();

        if (is_array($cached_ids) && ! empty($cached_ids)) {
            $ids = array_map('intval', $cached_ids);
        } else {
            // 5) DB fallback
            $pairs = RR_DB::get_related_pairs($post_id);
            foreach ($pairs as $pair) {
                if (is_array($pair) && isset($pair[0])) {
                    $ids[] = (int) $pair[0];
                }
            }

            $ids = array_values(array_filter($ids));

            if (empty($ids)) {
                // 6) NEG + enqueue
                RR_Cache::set_negative($post_id, $n);
                RR_Async::enqueue_rebuild($post_id);
                RR_Cache::set_runtime($runtime_key, '');
                return '';
            }

            // IDs came from DB; write IDs cache.
            RR_Cache::set($ids_key, $ids, RR_Cache::ttl_with_jitter((int) RR_TTL_IDS));
        }

        $html = self::render_related_list(array_slice($ids, 0, $n));

        // render done, write HTML cache.
        RR_Cache::set($html_key, $html, RR_Cache::ttl_with_jitter((int) RR_TTL_HTML));
        RR_Cache::set_runtime($runtime_key, $html);

        return $html;
    }

    public static function render_related_list(array $ids)
    {
        if (empty($ids)) {
            return '';
        }

        $query = new WP_Query(
            array(
                'post_type'              => 'post',
                'post_status'            => 'publish',
                'post__in'               => array_map('intval', $ids),
                'orderby'                => 'post__in',
                'posts_per_page'         => count($ids),
                'ignore_sticky_posts'    => true,
                'update_post_meta_cache' => true,
                'update_post_term_cache' => true,
            )
        );

        if (! $query->have_posts()) {
            return '';
        }

        $posts      = $query->posts;
        $template   = self::locate_template();
        $thumb_size = apply_filters('rr_thumb_size', 'medium');
        $tag_limit  = 3;

        ob_start();
        include $template;
        wp_reset_postdata();

        return (string) ob_get_clean();
    }

    public static function locate_template()
    {
        static $template_path = null;

        if (null !== $template_path) {
            return $template_path;
        }

        $theme_template = trailingslashit(get_stylesheet_directory()) . 'rr/related-list.php';
        if (file_exists($theme_template)) {
            $template_path = $theme_template;
            return $template_path;
        }

        $template_path = RR_PLUGIN_DIR . 'templates/related-list.php';

        return $template_path;
    }

    public static function theme_hash()
    {
        $theme = wp_get_theme();
        $name  = (string) $theme->get('Name');
        $ver   = (string) $theme->get('Version');

        return substr(md5($name . '|' . $ver), 0, 8);
    }
}
