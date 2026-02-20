<?php

if (! defined('ABSPATH')) {
    exit;
}

class RR_Hooks
{
    public static function init()
    {
        add_shortcode('rr_related', array(__CLASS__, 'shortcode_rr_related'));

        add_action('save_post', array(__CLASS__, 'on_save_post'), 10, 3);
        add_action('set_object_terms', array(__CLASS__, 'on_set_object_terms'), 10, 6);
        add_action('transition_post_status', array(__CLASS__, 'on_transition_post_status'), 10, 3);

        RR_Async::init();
    }

    public static function shortcode_rr_related($atts)
    {
        $atts = shortcode_atts(
            array(
                'n' => RR_DEFAULT_N,
            ),
            $atts,
            'rr_related'
        );

        return rr_related_posts(null, absint($atts['n']));
    }

    public static function on_save_post($post_id, $post, $update)
    {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        if (! $post instanceof WP_Post || 'post' !== $post->post_type || 'publish' !== $post->post_status) {
            return;
        }

        RR_Async::enqueue_rebuild((int) $post_id);
    }

    public static function on_set_object_terms($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids)
    {
        if (! in_array($taxonomy, array('post_tag', 'category'), true)) {
            return;
        }

        $post = get_post((int) $object_id);
        if (! $post instanceof WP_Post || 'post' !== $post->post_type || 'publish' !== $post->post_status) {
            return;
        }

        RR_Async::enqueue_rebuild((int) $object_id);
    }

    public static function on_transition_post_status($new_status, $old_status, $post)
    {
        if (! $post instanceof WP_Post || 'post' !== $post->post_type) {
            return;
        }

        if ('publish' !== $new_status) {
            return;
        }

        if ($old_status !== $new_status || 'publish' === $old_status) {
            RR_Async::enqueue_rebuild((int) $post->ID);
        }
    }
}
