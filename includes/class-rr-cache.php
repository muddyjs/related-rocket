<?php

if (! defined('ABSPATH')) {
    exit;
}

class RR_Cache
{
    const GROUP = 'rr';

    protected static $runtime_cache = array();

    public static function get_runtime($key)
    {
        return isset(self::$runtime_cache[$key]) ? self::$runtime_cache[$key] : null;
    }

    public static function set_runtime($key, $value)
    {
        self::$runtime_cache[$key] = $value;
    }

    public static function get($key)
    {
        return wp_cache_get($key, self::GROUP);
    }

    public static function set($key, $value, $ttl)
    {
        return wp_cache_set($key, $value, self::GROUP, (int) $ttl);
    }

    public static function add($key, $value, $ttl)
    {
        return wp_cache_add($key, $value, self::GROUP, (int) $ttl);
    }

    public static function delete($key)
    {
        return wp_cache_delete($key, self::GROUP);
    }

    public static function key_ids($post_id, $algo_ver, $n)
    {
        return sprintf('rel_ids:%d:v%d:n%d', (int) $post_id, (int) $algo_ver, (int) $n);
    }

    public static function key_html($post_id, $algo_ver, $n, $tpl_ver, $theme_hash)
    {
        return sprintf('rel_html:%d:v%d:n%d:tpl%d:th%s', (int) $post_id, (int) $algo_ver, (int) $n, (int) $tpl_ver, (string) $theme_hash);
    }

    public static function key_negative($post_id, $algo_ver, $n)
    {
        return sprintf('rel_neg:%d:v%d:n%d', (int) $post_id, (int) $algo_ver, (int) $n);
    }

    public static function key_lock($post_id, $algo_ver, $n)
    {
        return sprintf('rel_lock:%d:v%d:n%d', (int) $post_id, (int) $algo_ver, (int) $n);
    }

    public static function key_queued($post_id, $algo_ver)
    {
        return sprintf('queued:%d:v%d', (int) $post_id, (int) $algo_ver);
    }
}
