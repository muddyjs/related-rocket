<?php

if (! defined('ABSPATH')) {
    exit;
}

class RR_Async
{
    protected static $seen = array();

    public static function init()
    {
        add_action('rr_rebuild_one', array(__CLASS__, 'handle_rebuild_one'));
    }

    public static function enqueue_rebuild($post_id)
    {
        $post_id = (int) $post_id;

        if ($post_id <= 0 || isset(self::$seen[$post_id])) {
            return false;
        }

        self::$seen[$post_id] = true;

        $queued_key = RR_Cache::key_queued($post_id, RR_ALGO_VER);
        if (! RR_Cache::add($queued_key, 1, 300)) {
            return false;
        }

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('rr_rebuild_one', array('post_id' => $post_id), 'rr');
            return true;
        }

        return wp_schedule_single_event(time() + 5, 'rr_rebuild_one', array($post_id));
    }

    public static function handle_rebuild_one($payload)
    {
        $post_id = self::normalize_post_id($payload);
        if ($post_id <= 0) {
            return;
        }

        $post = get_post($post_id);
        if (! $post instanceof WP_Post || 'post' !== $post->post_type || 'publish' !== $post->post_status) {
            return;
        }

        $pairs = RR_Builder::build_for_post($post_id, RR_DEFAULT_N);
        RR_DB::replace_related($post_id, $pairs, RR_ALGO_VER);

        RR_Cache::delete(RR_Cache::key_ids($post_id, RR_ALGO_VER, RR_DEFAULT_N));
        RR_Cache::delete(RR_Cache::key_negative($post_id, RR_ALGO_VER, RR_DEFAULT_N));
    }

    protected static function normalize_post_id($payload)
    {
        if (is_array($payload) && isset($payload['post_id'])) {
            return (int) $payload['post_id'];
        }

        if (is_numeric($payload)) {
            return (int) $payload;
        }

        return 0;
    }
}
