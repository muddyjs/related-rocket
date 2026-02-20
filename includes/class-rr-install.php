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

        $related_table    = $wpdb->prefix . 'rr_related';
        $queue_table      = $wpdb->prefix . 'rr_queue';
        $charset_collate  = $wpdb->get_charset_collate();

        $sql_related = "CREATE TABLE {$related_table} (
            post_id BIGINT UNSIGNED NOT NULL,
            related_json TEXT NOT NULL,
            algo_ver SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL,
            source_hash BINARY(16) NOT NULL,
            PRIMARY KEY  (post_id),
            KEY updated_at (updated_at),
            KEY algo_updated (algo_ver, updated_at)
        ) ENGINE=InnoDB {$charset_collate};";

        $sql_queue = "CREATE TABLE {$queue_table} (
            post_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (post_id),
            KEY created_at (created_at)
        ) ENGINE=InnoDB {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_related);
        dbDelta($sql_queue);
    }
}
