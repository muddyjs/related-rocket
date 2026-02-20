<?php

if (! defined('ABSPATH')) {
    exit;
}

class RR_Install
{
    public static function activate()
    {
        self::create_tables();
        flush_rewrite_rules(false);
    }

    public static function deactivate()
    {
        flush_rewrite_rules(false);
    }

    public static function create_tables()
    {
        global $wpdb;

        $table_name      = $wpdb->prefix . 'rr_related';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            post_id BIGINT UNSIGNED NOT NULL,
            related_json TEXT NOT NULL,
            algo_ver SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL,
            source_hash BINARY(16) NOT NULL,
            PRIMARY KEY  (post_id),
            KEY updated_at (updated_at),
            KEY algo_updated (algo_ver, updated_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
