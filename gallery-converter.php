<?php
/*
Plugin Name: Gallery Converter
Plugin URI: https://www.sightseedesign.com/
Description: Converts the custom "gallery" post type to regular posts with Kadence gallery block.
Version: 1.6
Author: Dylan Howell
Author URI: https://www.sightseedesign.com/
*/

function convert_galleries_to_posts_batch($batch_size, $offset) {
  // Check if the "gallery" category exists, create it if needed
  $category_slug = 'gallery';
  $category = get_term_by('slug', $category_slug, 'category');
  if (!$category) {
    $category_id = wp_insert_term($category_slug, 'category', [
      'slug' => $category_slug,
    ]);
    $category = get_term_by('id', $category_id['term_id'], 'category');
  }

  // Query a batch of "gallery" post type entries
  $gallery_posts = get_posts([
    'post_type' => 'gallery',
    'posts_per_page' => $batch_size,
    'offset' => $offset,
  ]);

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
    $gallery_images = [];
    foreach ($attachment_ids as $attachment_id) {
      $attachment_id = intval($attachment_id); // Convert the attachment ID to an integer

      // Get full-size image URL
      $image_url = wp_get_attachment_url($attachment_id);

      // Ensure we have a full-size URL
      if (!$image_url) {
        continue; // Skip if no full-size image is found
      }

      $image_meta = wp_get_attachment_metadata($attachment_id);

      // Get alt text
      $alt_text = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);

      $gallery_images[] = [
        'id' => $attachment_id,
        'link' => $image_url,
        'alt' => $alt_text,
        'url' => $image_url,
        'thumbUrl' => $image_url, // Use full-size for thumb
        'lightUrl' => $image_url,
        'width' => $image_meta['width'] ?? 0, // Use null coalescing operator
        'height' => $image_meta['height'] ?? 0, // Use null coalescing operator
      ];
    }

    // Get the featured image from the original gallery post
    $featured_image_id = get_post_meta($post->ID, '_thumbnail_id', true);

    // Get the categories and tags from the original gallery post
    $categories = wp_get_post_categories($post->ID, [
      'exclude' => get_option('default_category'),
    ]);
    $categories[] = $category->term_id; // Add the "gallery" category

    $tags = wp_get_post_tags($post->ID, ['fields' => 'ids']);

    // Get the original publish date
    $original_publish_date = $post->post_date;

    // Create the new post content with the gallery data
    $existing_content = $post->post_content; // Existing content from the "gallery" post type
    $gallery_block =
      '<!-- wp:kadence/advancedgallery {"uniqueID":"' .
      uniqid() .
      '","ids":' .
      json_encode(array_column($gallery_images, 'id')) .
      ',"imagesDynamic":' .
      json_encode($gallery_images) .
      ',"kbVersion":2} /-->';
    $new_post_content = $existing_content . "\n\n" . $gallery_block;

    // Check if a regular post with the same title already exists
    $existing_post = get_posts([
      'post_type' => 'post',
      'title' => $post->post_title,
      'numberposts' => 1, // Limit to 1 result
      'fields' => 'ids', // Only get the post IDs
    ]);

    if ($existing_post) {
      $existing_post_id = $existing_post[0]; // Get the post ID
      $post_data = [
        'ID' => $existing_post_id,
        'post_title' => $post->post_title,
        'post_content' => $new_post_content,
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date' => $original_publish_date, // Set the original publish date
        'post_date_gmt' => get_gmt_from_date($original_publish_date), // Set the GMT date
      ];
      wp_update_post($post_data);
    } else {
      // Create a new regular WordPress post with the gallery data
      $post_data = [
        'post_title' => $post->post_title,
        'post_content' => $new_post_content,
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_date' => $original_publish_date, // Set the original publish date
        'post_date_gmt' => get_gmt_from_date($original_publish_date), // Set the GMT date
      ];
      wp_insert_post($post_data);
    }

    if ($new_post_id) {
      // Set the featured image for the new post
      if ($featured_image_id) {
        update_post_meta($new_post_id, '_thumbnail_id', $featured_image_id);
      }

      // Set categories and tags
      wp_set_post_categories($new_post_id, $categories);
      wp_set_post_tags($new_post_id, $tags);
    }

    // Update any internal links or references to the new post URL
    // ...
  }

  // Schedule the next batch conversion
  schedule_next_batch_conversion('convert_galleries_to_posts_batch', $batch_size);
}

function schedule_next_batch_conversion($hook, $batch_size) {
  // Get the number of remaining galleries to convert
  $remaining_galleries = wp_count_posts('gallery')->publish;

  if ($remaining_galleries > 0) {
    $offset = max(0, $remaining_galleries - $batch_size);
    wp_schedule_single_event(time() + 60, $hook, [$batch_size, $offset]);
  }
}

function convert_galleries_to_posts() {
  $batch_size = 30; // Set your desired batch size
  $hook = 'convert_galleries_to_posts_batch';

  add_action($hook, 'convert_galleries_to_posts_batch', 10, 2);
  schedule_next_batch_conversion($hook, $batch_size);
}

function clear_scheduled_conversions($hook) {
  $scheduled_events = _get_cron_array();
  foreach ($scheduled_events as $timestamp => $cron) {
    if (isset($cron[$hook])) {
      unset($scheduled_events[$timestamp][$hook]);
    }
  }
  _set_cron_array($scheduled_events);
}

// Run the conversion when the plugin is activated
register_activation_hook(__FILE__, 'convert_galleries_to_posts');

// Clear scheduled conversions when the plugin is deactivated (optional)
register_deactivation_hook(__FILE__, 'clear_scheduled_conversions');
