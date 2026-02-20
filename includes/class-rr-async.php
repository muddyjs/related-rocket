<?php

if (! defined('ABSPATH')) {
    exit;
}

class RR_Async
{
    const CRON_HOOK    = 'rr_process_queue';
    const QUEUE_OPTION = 'rr_async_queue';

    protected static $seen = array();

    public static function init()
    {
        add_action('rr_rebuild_one', array(__CLASS__, 'handle_rebuild_one'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'process_cron_queue'));
    }

    public static function enqueue_rebuild($post_id)
    {
        $post_id = (int) $post_id;

        // 单请求去重。
        if ($post_id <= 0 || isset(self::$seen[$post_id])) {
            return false;
        }

        self::$seen[$post_id] = true;

        // 跨请求去重：queued:{post_id}:v{algo_ver}
        $queued_key = RR_Cache::key_queued($post_id, RR_ALGO_VER);
        if (! wp_cache_add($queued_key, 1, RR_Cache::GROUP, 300)) {
            return false;
        }

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('rr_rebuild_one', array('post_id' => $post_id), 'rr');
            return true;
        }

        return self::enqueue_to_cron_queue($post_id);
    }

    public static function process_cron_queue()
    {
        $queue = get_option(self::QUEUE_OPTION, array());
        if (! is_array($queue) || empty($queue)) {
            return;
        }

        $batch_size = 20;
        $batch      = array_slice($queue, 0, $batch_size);
        $remaining  = array_slice($queue, $batch_size);

        update_option(self::QUEUE_OPTION, array_values($remaining), false);

        foreach ($batch as $post_id) {
            self::handle_rebuild_one(array('post_id' => (int) $post_id));
        }

        if (! empty($remaining)) {
            self::ensure_cron_scheduled();
        }
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

        $pairs = RR_Builder::build($post_id, RR_DEFAULT_N);
        RR_DB::upsert_related_pairs($post_id, $pairs, RR_DB::build_source_hash_hex($post_id));

        RR_Cache::delete(RR_Cache::key_ids($post_id, RR_ALGO_VER, RR_DEFAULT_N));
        RR_Cache::delete(RR_Cache::key_negative($post_id, RR_ALGO_VER, RR_DEFAULT_N));
        RR_Cache::delete(
            RR_Cache::key_html(
                $post_id,
                RR_ALGO_VER,
                RR_DEFAULT_N,
                RR_TPL_VER,
                RR_Render::theme_hash()
            )
        );

        // 可选预热：降低冷启动抖动。
        RR_Render::rr_related_posts($post_id, RR_DEFAULT_N);
    }

    protected static function enqueue_to_cron_queue($post_id)
    {
        $queue = get_option(self::QUEUE_OPTION, array());
        if (! is_array($queue)) {
            $queue = array();
        }

        $post_id = (int) $post_id;
        if (! in_array($post_id, array_map('intval', $queue), true)) {
            $queue[] = $post_id;
        }

        update_option(self::QUEUE_OPTION, array_values($queue), false);

        self::ensure_cron_scheduled();

        return true;
    }

    protected static function ensure_cron_scheduled()
    {
        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + 10, self::CRON_HOOK);
        }
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
