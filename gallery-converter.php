<?php
/*
Plugin Name: Gallery Converter
Plugin URI: https://www.sightseedesign.com/
Description: Converts the custom "gallery" post type to regular posts with Kadence gallery block.
Version: 1.0
Author: Dylan Howell
Author URI: https://www.sightseedesign.com/
*/

function convert_galleries_to_posts() {
    // Check if the "gallery" category exists, create it if needed
    $category_slug = 'gallery';
    $category = get_term_by('slug', $category_slug, 'category');
    if (!$category) {
        $category_id = wp_insert_term($category_slug, 'category', array(
            'slug' => $category_slug,
        ));
        $category = get_term_by('id', $category_id['term_id'], 'category');
    }

    // Query all "gallery" post type entries
    $gallery_posts = get_posts(array(
        'post_type' => 'gallery',
        'posts_per_page' => -1, // Retrieve all posts
    ));

    foreach ($gallery_posts as $post) {
        // Get the gallery image data from the meta field
        $gallery_data = get_post_meta($post->ID, '_post_image_gallery', true);

        // Unserialize the meta value
        $attachment_ids = maybe_unserialize($gallery_data);

        // Check if the unserialized data is an array
        if (!is_array($attachment_ids)) {
            continue; // Skip to the next post if the data is not an array
        }

        // Prepare the gallery data for Kadence Blocks
        $gallery_images = array();
        foreach ($attachment_ids as $attachment_id) {
            $attachment_id = intval($attachment_id); // Convert the attachment ID to an integer
            $image_url = wp_get_attachment_url($attachment_id);
            $thumbnail_url = wp_get_attachment_image_src($attachment_id, 'thumbnail');
            $image_meta = wp_get_attachment_metadata($attachment_id);
            $gallery_images[] = array(
                'id' => $attachment_id,
                'link' => $image_url,
                'alt' => get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
                'url' => $image_url,
                'thumbUrl' => $thumbnail_url[0],
                'lightUrl' => $image_url,
                'width' => $image_meta['width'],
                'height' => $image_meta['height'],
            );
        }

        // Create the new post content with the gallery data
        $existing_content = $post->post_content; // Existing content from the "gallery" post type
        $gallery_block = '<!-- wp:kadence/advancedgallery {"uniqueID":"' . uniqid() . '","ids":' . json_encode(array_column($gallery_images, 'id')) . ',"imagesDynamic":' . json_encode($gallery_images) . ',"kbVersion":2} /-->';
        $new_post_content = $existing_content . "\n\n" . $gallery_block;

        // Check if a regular post with the same title already exists
        $existing_post = get_page_by_title($post->post_title, OBJECT, 'post');
        if ($existing_post) {
            // A post with the same title already exists, update the existing post
            $existing_post->post_content = $new_post_content;
            wp_update_post($existing_post);
            continue;
        }

        // Create a new regular WordPress post with the gallery data
        $new_post = array(
            'post_title' => $post->post_title,
            'post_content' => $new_post_content,
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_category' => array($category->term_id), // Set the category to "gallery"
        );
        $new_post_id = wp_insert_post($new_post);

        // Update any internal links or references to the new post URL
        // ...
    }
}

// Run the conversion when the plugin is activated
register_activation_hook(__FILE__, 'convert_galleries_to_posts');