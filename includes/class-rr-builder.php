<?php

if (! defined('ABSPATH')) {
    exit;
}

class RR_Builder
{
    public static function build($post_id, $n)
    {
        $post_id = (int) $post_id;
        $n       = max(1, (int) $n);

        $post = get_post($post_id);
        if (! $post instanceof WP_Post || 'post' !== $post->post_type || 'publish' !== $post->post_status) {
            return array();
        }

        $tag_ids = wp_get_post_terms($post_id, 'post_tag', array('fields' => 'ids'));
        $cat_ids = wp_get_post_terms($post_id, 'category', array('fields' => 'ids'));

        if (! is_array($tag_ids)) {
            $tag_ids = array();
        }
        if (! is_array($cat_ids)) {
            $cat_ids = array();
        }

        $tag_ids = array_values(array_unique(array_map('intval', $tag_ids)));
        $cat_ids = array_values(array_unique(array_map('intval', $cat_ids)));

        $candidate_ids = array();

        foreach ($tag_ids as $tag_id) {
            $ids = get_posts(
                array(
                    'post_type'              => 'post',
                    'post_status'            => 'publish',
                    'fields'                 => 'ids',
                    'posts_per_page'         => (int) RR_PER_TAG_FETCH,
                    'orderby'                => 'date',
                    'order'                  => 'DESC',
                    'tag__in'                => array((int) $tag_id),
                    'post__not_in'           => array($post_id),
                    'no_found_rows'          => true,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                    'ignore_sticky_posts'    => true,
                )
            );

            if (is_array($ids)) {
                foreach ($ids as $id) {
                    $candidate_ids[] = (int) $id;
                }
            }
        }

        if (count($candidate_ids) < (int) RR_MAX_CANDIDATES) {
            foreach ($cat_ids as $cat_id) {
                $ids = get_posts(
                    array(
                        'post_type'              => 'post',
                        'post_status'            => 'publish',
                        'fields'                 => 'ids',
                        'posts_per_page'         => (int) RR_PER_CAT_FETCH,
                        'orderby'                => 'date',
                        'order'                  => 'DESC',
                        'category__in'           => array((int) $cat_id),
                        'post__not_in'           => array($post_id),
                        'no_found_rows'          => true,
                        'update_post_meta_cache' => false,
                        'update_post_term_cache' => false,
                        'ignore_sticky_posts'    => true,
                    )
                );

                if (is_array($ids)) {
                    foreach ($ids as $id) {
                        $candidate_ids[] = (int) $id;
                    }
                }

                if (count($candidate_ids) >= (int) RR_MAX_CANDIDATES) {
                    break;
                }
            }
        }

        $candidate_ids = array_values(array_unique(array_filter(array_map('intval', $candidate_ids))));
        $candidate_ids = array_values(array_diff($candidate_ids, array($post_id)));

        if (empty($candidate_ids)) {
            return array();
        }

        $candidate_ids = get_posts(
            array(
                'post_type'              => 'post',
                'post_status'            => 'publish',
                'fields'                 => 'ids',
                'post__in'               => $candidate_ids,
                'orderby'                => 'date',
                'order'                  => 'DESC',
                'posts_per_page'         => (int) RR_MAX_CANDIDATES,
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'ignore_sticky_posts'    => true,
            )
        );

        if (! is_array($candidate_ids) || empty($candidate_ids)) {
            return array();
        }

        update_object_term_cache($candidate_ids, 'post');

        $date_rank = array();
        foreach ($candidate_ids as $idx => $cid) {
            $date_rank[(int) $cid] = (int) $idx;
        }

        // P1: batch term fetch to avoid per-candidate term API overhead.
        $term_rows = wp_get_object_terms(
            $candidate_ids,
            array('post_tag', 'category'),
            array(
                'fields' => 'all_with_object_id',
            )
        );

        $map_tags = array();
        $map_cats = array();

        if (is_array($term_rows)) {
            foreach ($term_rows as $term_row) {
                if (! isset($term_row->object_id, $term_row->term_id, $term_row->taxonomy)) {
                    continue;
                }

                $oid = (int) $term_row->object_id;
                $tid = (int) $term_row->term_id;

                if ('post_tag' === $term_row->taxonomy) {
                    if (! isset($map_tags[$oid])) {
                        $map_tags[$oid] = array();
                    }
                    $map_tags[$oid][] = $tid;
                } elseif ('category' === $term_row->taxonomy) {
                    if (! isset($map_cats[$oid])) {
                        $map_cats[$oid] = array();
                    }
                    $map_cats[$oid][] = $tid;
                }
            }
        }

        $tag_lookup = array_fill_keys($tag_ids, 1);
        $cat_lookup = array_fill_keys($cat_ids, 1);

        $scored = array();

        foreach ($candidate_ids as $candidate_id) {
            $candidate_id = (int) $candidate_id;

            $cand_tags = isset($map_tags[$candidate_id]) ? array_values(array_unique(array_map('intval', $map_tags[$candidate_id]))) : array();
            $cand_cats = isset($map_cats[$candidate_id]) ? array_values(array_unique(array_map('intval', $map_cats[$candidate_id]))) : array();

            $common_tag_count = 0;
            foreach ($cand_tags as $tag_id) {
                if (isset($tag_lookup[$tag_id])) {
                    $common_tag_count++;
                }
            }

            $common_cat_count = 0;
            foreach ($cand_cats as $cat_id) {
                if (isset($cat_lookup[$cat_id])) {
                    $common_cat_count++;
                }
            }

            $score = ($common_tag_count * (int) RR_W_TAG) + ($common_cat_count * (int) RR_W_CAT);
            if ($score <= 0) {
                continue;
            }

            $scored[] = array(
                'id'    => $candidate_id,
                'score' => (int) $score,
                'rank'  => isset($date_rank[$candidate_id]) ? $date_rank[$candidate_id] : PHP_INT_MAX,
            );
        }

        if (empty($scored)) {
            return array();
        }

        usort(
            $scored,
            static function ($a, $b) {
                if ($a['score'] === $b['score']) {
                    return $a['rank'] <=> $b['rank'];
                }

                return $b['score'] <=> $a['score'];
            }
        );

        $scored = array_slice($scored, 0, $n);

        $pairs = array();
        foreach ($scored as $row) {
            $pairs[] = array((int) $row['id'], (int) $row['score']);
        }

        return $pairs;
    }

    public static function build_for_post($post_id, $n = RR_DEFAULT_N)
    {
        return self::build($post_id, $n);
    }
}
