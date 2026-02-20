<?php

if (! defined('ABSPATH')) {
    exit;
}

class RR_Builder
{
    public static function build_for_post($post_id, $n = RR_DEFAULT_N)
    {
        $post = get_post((int) $post_id);
        if (! $post instanceof WP_Post || 'publish' !== $post->post_status || 'post' !== $post->post_type) {
            return array();
        }

        // Skeleton only: return empty list now.
        return array();
    }
}
