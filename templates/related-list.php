<?php
/**
 * Related posts list template.
 *
 * Available variables:
 * @var WP_Post[] $posts
 * @var string    $thumb_size
 * @var int       $tag_limit
 */

if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="rr-related-list">
    <ul class="rr-related-items">
        <?php foreach ($posts as $item_post) : ?>
            <?php
            $permalink = get_permalink($item_post);
            $title     = get_the_title($item_post);
            $tags      = get_the_terms($item_post, 'post_tag');
            if (! is_array($tags)) {
                $tags = array();
            }
            $tags = array_slice($tags, 0, max(0, (int) $tag_limit));
            ?>
            <li class="rr-related-item">
                <a class="rr-related-thumb" href="<?php echo esc_url($permalink); ?>">
                    <?php echo get_the_post_thumbnail($item_post, $thumb_size, array('loading' => 'lazy', 'decoding' => 'async')); ?>
                </a>
                <a class="rr-related-title" href="<?php echo esc_url($permalink); ?>"><?php echo esc_html($title); ?></a>
                <?php if (! empty($tags)) : ?>
                    <div class="rr-related-tags">
                        <?php foreach ($tags as $tag) : ?>
                            <span class="rr-related-tag"><?php echo esc_html($tag->name); ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
