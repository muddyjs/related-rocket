<?php

if (! defined('ABSPATH')) {
    exit;
}

class RR_DB
{
    public static function table_name()
    {
        global $wpdb;

        // Multisite-safe: uses current blog prefix.
        return $wpdb->prefix . 'rr_related';
    }

    public static function get_row($post_id)
    {
        global $wpdb;

        $table = self::table_name();
        $sql   = $wpdb->prepare(
            "SELECT post_id, related_json, algo_ver, updated_at, HEX(source_hash) AS source_hash_hex FROM {$table} WHERE post_id = %d LIMIT 1",
            (int) $post_id
        );

        $row = $wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public static function get_related_pairs($post_id)
    {
        $row = self::get_row($post_id);
        if (! is_array($row) || empty($row['related_json'])) {
            return array();
        }

        $pairs = json_decode($row['related_json'], true);
        if (! is_array($pairs)) {
            return array();
        }

        $normalized = array();
        foreach ($pairs as $pair) {
            if (! is_array($pair) || ! isset($pair[0])) {
                continue;
            }

            $normalized[] = array(
                (int) $pair[0],
                isset($pair[1]) ? (int) $pair[1] : 0,
            );
        }

        return $normalized;
    }

    public static function upsert_related_pairs($post_id, $pairs, $source_hash_hex)
    {
        global $wpdb;

        $table = self::table_name();
        $json  = wp_json_encode(is_array($pairs) ? $pairs : array());
        if (! is_string($json)) {
            $json = '[]';
        }

        $source_hash_hex = strtolower((string) $source_hash_hex);
        if (! preg_match('/^[a-f0-9]{32}$/', $source_hash_hex)) {
            $source_hash_hex = md5('');
        }

        $sql = $wpdb->prepare(
            "INSERT INTO {$table} (post_id, related_json, algo_ver, updated_at, source_hash)
            VALUES (%d, %s, %d, %s, UNHEX(%s))
            ON DUPLICATE KEY UPDATE
                related_json = VALUES(related_json),
                algo_ver = VALUES(algo_ver),
                updated_at = VALUES(updated_at),
                source_hash = VALUES(source_hash)",
            (int) $post_id,
            $json,
            (int) RR_ALGO_VER,
            current_time('mysql'),
            $source_hash_hex
        );

        return $wpdb->query($sql);
    }

    /**
     * Backward-compatible helper for current scaffold callsites.
     */
    public static function get_related_ids($post_id)
    {
        $pairs = self::get_related_pairs($post_id);
        $ids   = array();

        foreach ($pairs as $pair) {
            $ids[] = (int) $pair[0];
        }

        return $ids;
    }

    /**
     * Backward-compatible helper for current scaffold callsites.
     */
    public static function replace_related($post_id, array $pairs, $algo_ver = RR_ALGO_VER)
    {
        unset($algo_ver);

        return self::upsert_related_pairs((int) $post_id, $pairs, self::build_source_hash_hex((int) $post_id));
    }

    public static function build_source_hash_hex($post_id)
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

        $payload = 't:' . implode(',', array_map('intval', $tag_ids)) . '|c:' . implode(',', array_map('intval', $cat_ids));

        return md5($payload);
    }
}
