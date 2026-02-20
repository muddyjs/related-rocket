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
        return wp_cache_get((string) $key, self::GROUP);
    }

    public static function set($key, $value, $ttl)
    {
        return wp_cache_set((string) $key, $value, self::GROUP, (int) $ttl);
    }

    public static function add($key, $value, $ttl)
    {
        return wp_cache_add((string) $key, $value, self::GROUP, (int) $ttl);
    }

    public static function delete($key)
    {
        return wp_cache_delete((string) $key, self::GROUP);
    }

    public static function ttl_with_jitter($base_ttl)
    {
        $base_ttl = max(0, (int) $base_ttl);
        if ($base_ttl <= 0) {
            return 0;
        }

        $max_jitter = (int) floor($base_ttl * (float) RR_JITTER_RATIO);
        if ($max_jitter <= 0) {
            return $base_ttl;
        }

        return $base_ttl + wp_rand(0, $max_jitter);
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

    public static function acquire_lock($post_id, $n)
    {
        $lock_key = self::key_lock((int) $post_id, (int) RR_ALGO_VER, (int) $n);

        return self::add($lock_key, 1, (int) RR_TTL_LOCK);
    }

    public static function set_negative($post_id, $n)
    {
        $neg_key = self::key_negative((int) $post_id, (int) RR_ALGO_VER, (int) $n);

        return self::set($neg_key, 1, (int) RR_TTL_NEG);
    }

    public static function is_negative($post_id, $n)
    {
        $neg_key = self::key_negative((int) $post_id, (int) RR_ALGO_VER, (int) $n);

        return (bool) self::get($neg_key);
    }
}
