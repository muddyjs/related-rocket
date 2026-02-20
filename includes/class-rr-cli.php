<?php

if (! defined('ABSPATH')) {
    exit;
}

class RR_CLI
{
    /**
     * Warmup related rebuild queue in batches.
     *
     * ## OPTIONS
     *
     * [--batch=<batch>]
     * : Batch size.
     *
     * [--enqueue=<enqueue>]
     * : 1 to enqueue async rebuild (default), 0 for inline sync rebuild.
     *
     * ## EXAMPLES
     *
     *     wp rr warmup --batch=200 --enqueue=1
     *
     * @when after_wp_load
     */
    public function warmup($args, $assoc_args)
    {
        global $wpdb;

        $batch   = isset($assoc_args['batch']) ? max(1, absint($assoc_args['batch'])) : 200;
        $enqueue = isset($assoc_args['enqueue']) ? (int) $assoc_args['enqueue'] : 1;

        $offset         = 0;
        $total_processed = 0;

        do {
            $sql = $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s ORDER BY ID ASC LIMIT %d OFFSET %d",
                'post',
                'publish',
                (int) $batch,
                (int) $offset
            );

            $ids = $wpdb->get_col($sql);
            if (! is_array($ids) || empty($ids)) {
                break;
            }

            foreach ($ids as $post_id) {
                $post_id = (int) $post_id;

                if (1 === $enqueue) {
                    RR_Async::enqueue_rebuild($post_id);
                } else {
                    $this->sync_rebuild_one($post_id);
                }

                $total_processed++;
            }

            // Per-batch cleanup to avoid OOM.
            unset($ids);
            if (function_exists('stop_the_insanity')) {
                stop_the_insanity();
            }
            gc_collect_cycles();

            $offset += $batch;
        } while (true);

        WP_CLI::success(sprintf('Warmup done. processed=%d batch=%d enqueue=%d', $total_processed, $batch, $enqueue));
    }

    /**
     * Rebuild related results for one post.
     *
     * ## OPTIONS
     *
     * <id>
     * : Post ID.
     *
     * [--sync=<sync>]
     * : 1 to run sync rebuild immediately, otherwise enqueue async rebuild.
     *
     * ## EXAMPLES
     *
     *     wp rr rebuild 123 --sync=1
     *
     * @when after_wp_load
     */
    public function rebuild($args, $assoc_args)
    {
        $post_id = isset($args[0]) ? absint($args[0]) : 0;
        $sync    = isset($assoc_args['sync']) ? (int) $assoc_args['sync'] : 0;

        if ($post_id <= 0) {
            WP_CLI::error('Invalid post id.');
            return;
        }

        if (1 === $sync) {
            $this->sync_rebuild_one($post_id);
            WP_CLI::success(sprintf('Sync rebuild completed for post_id=%d', $post_id));
            return;
        }

        RR_Async::enqueue_rebuild($post_id);
        WP_CLI::success(sprintf('Enqueued rebuild for post_id=%d', $post_id));
    }

    protected function sync_rebuild_one($post_id)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
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
    }
}
