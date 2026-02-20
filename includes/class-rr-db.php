<?php

if (! defined('ABSPATH')) {
    exit;
}

class RR_DB
{
    public static function table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'rr_related';
    }

    public static function get_related_ids($post_id)
    {
        global $wpdb;

        $table = self::table_name();
        $sql   = $wpdb->prepare("SELECT related_json FROM {$table} WHERE post_id = %d LIMIT 1", (int) $post_id);
        $json  = $wpdb->get_var($sql);

        if (empty($json)) {
            return array();
        }

        $rows = json_decode($json, true);
        if (! is_array($rows)) {
            return array();
        }

        $ids = array();
        foreach ($rows as $row) {
            if (is_array($row) && isset($row[0])) {
                $ids[] = (int) $row[0];
            }
        }

        return $ids;
    }

    public static function replace_related($post_id, array $pairs, $algo_ver = RR_ALGO_VER)
    {
        global $wpdb;

        $table = self::table_name();
        $json  = wp_json_encode($pairs);

        if (! is_string($json)) {
            $json = '[]';
        }

        $source_hash_payload = self::source_hash_payload($post_id);
        $source_hash_hex     = md5($source_hash_payload);

        return $wpdb->replace(
            $table,
            array(
                'post_id'      => (int) $post_id,
                'related_json' => $json,
                'algo_ver'     => (int) $algo_ver,
                'updated_at'   => current_time('mysql'),
                'source_hash'  => hex2bin($source_hash_hex),
            ),
            array('%d', '%s', '%d', '%s', '%s')
        );
    }

    public static function source_hash_payload($post_id)
    {
        $tag_ids = wp_get_post_terms((int) $post_id, 'post_tag', array('fields' => 'ids'));
        $cat_ids = wp_get_post_terms((int) $post_id, 'category', array('fields' => 'ids'));

        if (! is_array($tag_ids)) {
            $tag_ids = array();
        }

        if (! is_array($cat_ids)) {
            $cat_ids = array();
        }

        sort($tag_ids);
        sort($cat_ids);

        return 't:' . implode(',', array_map('intval', $tag_ids)) . '|c:' . implode(',', array_map('intval', $cat_ids));
    }
}
