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
<section class="w-full px-4 md:px-10 mt-16 lg:mt-24 mb-20 rr-related-list">
    <h2 class="text-2xl font-bold text-center text-gray-900 mb-12"><?php echo esc_html__('More like this', 'related-rocket'); ?></h2>
    <div class="columns-2 sm:columns-3 md:columns-4 xl:columns-5 gap-4 lg:gap-6 rr-related-items">
        <?php foreach ($posts as $item_post) : ?>
            <?php
            $permalink = get_permalink($item_post);
            $title     = get_the_title($item_post);
            ?>
            <article class="masonry-item group break-inside-avoid mb-4 lg:mb-6 rr-related-item">
                <div class="relative overflow-hidden rounded-md bg-gray-100 shadow-sm transition-all duration-300">
                    <a href="<?php echo esc_url($permalink); ?>" class="block rr-related-thumb">
                        <?php echo get_the_post_thumbnail($item_post, 'large', array('class' => 'w-full h-auto block', 'loading' => 'lazy', 'decoding' => 'async')); ?>
                    </a>
                    <a href="<?php echo esc_url($permalink); ?>" class="absolute inset-0 z-10" aria-label="<?php echo esc_attr($title); ?>"></a>
                </div>
                <div class="mt-2 px-1">
                    <h3 class="text-xs font-bold text-gray-800 line-clamp-1 rr-related-title"><?php echo esc_html($title); ?></h3>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
