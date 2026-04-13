<?php
/*
Plugin Name: Subscription System
Description: A simple subscription system for WordPress. Custom Made.
Version: 1.0
Author: Apollo & Charley
*/

/* ======================================================
   1. ROLE + CAPABILITY SETUP (RUN ON ACTIVATION ONLY)
========================================================= */
register_activation_hook(__FILE__, function () {

  $role = get_role('premium_user');

  if (!$role) {
    $role = add_role('premium_user', 'Premium User');
  }

  $role->add_cap('view_premium_content');
});

/* ======================================================
   2. CONTENT RESTRICTION
========================================================= */


// Handle content restriction for premium posts
add_filter('the_content', function ($content) {

  if (!is_singular('premium_post')) {
    return $content;
  }

  $badge = '<div class="premium-badge">🔒 Premium Content</div>';
  $freeBadge = '<div class="premium-badge">🔓 Unlocked Content</div>';
  $adminBadge = '<div class="premium-badge">🔓 Admin Override</div>';
  $paidBadge = '<div class="premium-badge">⭐ Premium Access</div>';

  if (!is_user_logged_in()) {
    return $badge . '<p>Please log in to view this post.</p>';
  }

  // Get post requirement
  $requires_premium = get_post_meta(get_the_ID(), '_requires_premium', true);
  var_dump($requires_premium);

  // Premium check
  if (current_user_can('view_premium_content')) {
    return $paidBadge . $content;
  }

  // If not premium content → allow everyone logged in
  if (!$requires_premium) {
    return $freeBadge . $content;
  }

  // Admin bypass
  // if (current_user_can('manage_options')) {
  //   return $adminBadge . $content;
  // }


  return $badge . '<p>This content is for Premium users only.</p>';
});

/* ======================================================
   3. AJAX: UPGRADE USER TO PREMIUM
========================================================= */

add_action('wp_ajax_make_premium', 'make_premium_user');

function make_premium_user()
{
  // wp_send_json_success("HI!");

  $user_id = get_current_user_id();

  if (!$user_id) {
    wp_send_json_error("Not logged in");
  }

  $user = wp_get_current_user();

  // Handle case where user is already a premium subscriber
  if (in_array('premium_user', (array) $user->roles)) {
    wp_send_json_error("Already a premium user");
  }

  // Add role (DO NOT replace roles)
  $user->add_role('premium_user');

  wp_send_json_success("Upgraded to Premium User");
}


/* ======================================================
   3b. AJAX: DOWNGRADE USER TO FREE
========================================================= */

add_action('wp_ajax_make_free_user', 'make_free_user');

function make_free_user()
{
  // wp_send_json_success("BYE!");

  $user_id = get_current_user_id();

  if (!$user_id) {
    wp_send_json_error("Not logged in");
  }

  $user = wp_get_current_user();

  // Handle case where user is already a free user
  if (!in_array('premium_user', (array) $user->roles)) {
    wp_send_json_error("Already a free user");
  }

  // Remove role (DO NOT replace roles)
  $user->remove_role('premium_user');

  wp_send_json_success("Downgraded to Free User");
}


/* ======================================================
   3c. AJAX: GET CURRENT USER STATUS
========================================================= */

add_action('wp_ajax_get_user_status', 'get_user_status');

function get_user_status()
{
  $user_id = get_current_user_id();

  if (!$user_id) {
    wp_send_json_error("Not logged in");
  }

  $user = wp_get_current_user();

  $is_premium = in_array('premium_user', (array) $user->roles);

  wp_send_json_success([
    'is_premium' => $is_premium ? 'active' : 'inactive'
  ]);
}

/* ======================================================
   4. CUSTOM POST TYPE FOR PREMIUM CONTENT
========================================================= */
add_action('init', function () {
  register_post_type('premium_post', [
    'label' => 'Premium Posts',
    'public' => true,
    'publicly_queryable' => true,
    'show_ui' => true,
    'show_in_rest' => true,
    'supports' => ['title', 'editor', 'thumbnail', 'excerpt'],
  ]);
  register_post_meta('premium_post', '_requires_premium', [
    'type' => 'boolean',
    'single' => true,
    'show_in_rest' => true,
    'default' => true, // Default to true (requires premium access) for new posts
  ]);
});


// Show premium posts in main query
add_filter('query_loop_block_query_vars', function ($query) {

  if (!empty($query['post_type'])) {
    $query['post_type'] = array('post', 'premium_post');
  }

  return $query;
});

// Create meta box for premium settings
add_action('add_meta_boxes', function () {
  add_meta_box(
    'premium_settings',
    'Premium Settings',
    'render_premium_meta_box',
    'premium_post', // post type
    'side',
    'default'
  );
});

function render_premium_meta_box($post)
{
  $value = get_post_meta($post->ID, '_requires_premium', true);

  if ($value === '') {
    $value = true; // default to checked
  }

  wp_nonce_field('save_premium_meta', 'premium_meta_nonce');

?>
  <label>
    <input type="checkbox" name="requires_premium" value="1" <?php checked($value, '1'); ?> />
    Requires Premium Access
  </label>
<?php
}
add_action('save_post_premium_post', function ($post_id) {

  // Nonce check
  if (
    !isset($_POST['premium_meta_nonce']) ||
    !wp_verify_nonce($_POST['premium_meta_nonce'], 'save_premium_meta')
  ) {
    return;
  }

  // Autosave check
  if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
    return;
  }

  // Permission check
  if (!current_user_can('edit_post', $post_id)) {
    return;
  }

  $value = isset($_POST['requires_premium']);
  update_post_meta($post_id, '_requires_premium', $value);
});
