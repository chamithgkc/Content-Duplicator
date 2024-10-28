<?php
/**
 * Plugin Name: Content Duplicator
 * Plugin URI: 
 * Description: Duplicates posts, pages, custom post types, and taxonomies with a single click
 * Version: 1.0
 * Author: Chamith Koralage
 * Author URI: https://profiles.wordpress.org/chamithgkc/
 * License: GPLv2
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class ContentDuplicator {
    
    public function __construct() {
        // Add admin menu items
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Add duplicate links to post/page/cpt lists
        add_filter('post_row_actions', array($this, 'add_duplicate_link'), 10, 2);
        add_filter('page_row_actions', array($this, 'add_duplicate_link'), 10, 2);
        
        // Add duplicate links to taxonomy term pages
        add_filter('tag_row_actions', array($this, 'add_taxonomy_duplicate_link'), 10, 2);
        add_filter('category_row_actions', array($this, 'add_taxonomy_duplicate_link'), 10, 2);
        
        // Handle duplicate actions
        add_action('admin_action_duplicate_content', array($this, 'duplicate_content'));
        add_action('admin_action_duplicate_taxonomy', array($this, 'duplicate_taxonomy_term'));
        
        // Add duplicate button to post editor
        add_action('post_submitbox_misc_actions', array($this, 'add_duplicate_button'));
        
        // Add duplicate button to taxonomy term pages
        add_action('admin_footer-edit-tags.php', array($this, 'add_taxonomy_duplicate_button'));
    }
    
    public function add_admin_menu() {
        add_management_page(
            'Content Duplicator',
            'Content Duplicator',
            'manage_options',
            'content-duplicator',
            array($this, 'render_settings_page')
        );
    }
    
    public function render_settings_page() {
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Content Duplicator Settings', 'content-duplicator'); ?></h1>
        <p><?php esc_html_e('Use the "Duplicate" link in your content lists or the "Duplicate" button in the editor to create copies of your content.', 'content-duplicator'); ?></p>
        
        <h2><?php esc_html_e('Supported Content Types:', 'content-duplicator'); ?></h2>
        <ul>
            <li><?php esc_html_e('Posts', 'content-duplicator'); ?></li>
            <li><?php esc_html_e('Pages', 'content-duplicator'); ?></li>
            <li><?php esc_html_e('Custom Post Types', 'content-duplicator'); ?></li>
            <li><?php esc_html_e('Categories and Tags', 'content-duplicator'); ?></li>
            <li><?php esc_html_e('Custom Taxonomies', 'content-duplicator'); ?></li>
        </ul>
    </div>
    <?php
}

public function add_duplicate_link($actions, $post) {
    if (current_user_can('edit_posts')) {
        $url = wp_nonce_url(
            admin_url('admin.php?action=duplicate_content&post=' . absint($post->ID)),
            'duplicate_content_' . $post->ID
        );
        $actions['duplicate'] = sprintf(
            '<a href="%s" title="%s">%s</a>',
            esc_url($url),
            esc_attr__('Duplicate this item', 'content-duplicator'),
            esc_html__('Duplicate', 'content-duplicator')
        );
    }
    return $actions;
}

public function add_taxonomy_duplicate_link($actions, $term) {
    if (current_user_can('manage_categories')) {
        $url = wp_nonce_url(
            admin_url(sprintf(
                'admin.php?action=duplicate_taxonomy&term_id=%d&taxonomy=%s',
                absint($term->term_id),
                sanitize_key($term->taxonomy)
            )),
            'duplicate_taxonomy_' . $term->term_id
        );
        $actions['duplicate'] = sprintf(
            '<a href="%s" title="%s">%s</a>',
            esc_url($url),
            esc_attr__('Duplicate this term', 'content-duplicator'),
            esc_html__('Duplicate', 'content-duplicator')
        );
    }
    return $actions;
}

public function add_duplicate_button() {
    global $post;
    if (isset($post) && current_user_can('edit_posts')) {
        $url = wp_nonce_url(
            admin_url('admin.php?action=duplicate_content&post=' . absint($post->ID)),
            'duplicate_content_' . $post->ID
        );
        printf(
            '<div id="duplicate-action"><a class="button" href="%s">%s</a></div>',
            esc_url($url),
            esc_html__('Duplicate', 'content-duplicator')
        );
    }
}

public function add_taxonomy_duplicate_button() {
    if (isset($_GET['taxonomy']) && isset($_GET['tag_ID'])) {
        $term_id = absint($_GET['tag_ID']);
        $taxonomy = sanitize_key($_GET['taxonomy']);
        if (current_user_can('manage_categories')) {
            $url = wp_nonce_url(
                admin_url(sprintf(
                    'admin.php?action=duplicate_taxonomy&term_id=%d&taxonomy=%s',
                    $term_id,
                    $taxonomy
                )),
                'duplicate_taxonomy_' . $term_id
            );
            ?>
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('.edit-tag-actions').append(
                    $('<a>', {
                        'href': '<?php echo esc_js($url); ?>',
                        'class': 'button',
                        'text': '<?php echo esc_js(__('Duplicate Term', 'content-duplicator')); ?>'
                    })
                );
            });
            </script>
            <?php
        }
    }
}

public function duplicate_taxonomy_term() {
    // Check if term ID and taxonomy are provided
    $term_id = isset($_REQUEST['term_id']) ? absint($_REQUEST['term_id']) : 0;
    $taxonomy = isset($_REQUEST['taxonomy']) ? sanitize_key($_REQUEST['taxonomy']) : '';
    
    if (!$term_id || !$taxonomy) {
        wp_die(esc_html__('Invalid term or taxonomy.', 'content-duplicator'));
    }
    
    // Verify nonce
    if (!check_admin_referer('duplicate_taxonomy_' . $term_id)) {
        wp_die(esc_html__('Security check failed.', 'content-duplicator'));
    }
    
    // Check permissions
    if (!current_user_can('manage_categories')) {
        wp_die(esc_html__('You do not have permission to duplicate terms.', 'content-duplicator'));
    }
    
    // Get original term
    $term = get_term($term_id, $taxonomy);
    if (!$term || is_wp_error($term)) {
        wp_die(esc_html__('Term not found.', 'content-duplicator'));
    }
    
    // Prepare new term name
    $new_term_name = sprintf(
        /* translators: %s: Original term name */
        __('%s (Copy)', 'content-duplicator'),
        $term->name
    );
    $counter = 1;
    
    // Make sure the new term name is unique
    while (term_exists($new_term_name, $taxonomy)) {
        $new_term_name = sprintf(
            /* translators: 1: Original term name, 2: Copy number */
            __('%1$s (Copy %2$d)', 'content-duplicator'),
            $term->name,
            $counter
        );
        $counter++;
    }
    
    // Create the duplicate term
    $new_term = wp_insert_term(
        $new_term_name,
        $taxonomy,
        array(
            'description' => $term->description,
            'slug'        => sanitize_title($new_term_name),
            'parent'      => $term->parent
        )
    );
    
    if (is_wp_error($new_term)) {
        wp_die(sprintf(
            /* translators: %s: Error message */
            esc_html__('Error creating duplicate term: %s', 'content-duplicator'),
            esc_html($new_term->get_error_message())
        ));
    }
    
    // Copy term meta
    $term_meta = get_term_meta($term_id);
    if ($term_meta) {
        foreach ($term_meta as $meta_key => $meta_values) {
            foreach ($meta_values as $meta_value) {
                add_term_meta($new_term['term_id'], $meta_key, maybe_unserialize($meta_value));
            }
        }
    }
    
    // Redirect to terms list
    wp_safe_redirect(admin_url('edit-tags.php?taxonomy=' . $taxonomy));
    exit;
}
    
    public function duplicate_content() {
        // Check if post ID is provided
        $post_id = isset($_REQUEST['post']) ? intval($_REQUEST['post']) : 0;
        if (!$post_id) {
            wp_die('No post ID provided for duplication.');
        }
        
        // Verify nonce
        if (!check_admin_referer('duplicate_content_' . $post_id)) {
            wp_die('Security check failed.');
        }
        
        // Check permissions
        if (!current_user_can('edit_posts')) {
            wp_die('You do not have permission to duplicate content.');
        }
        
        // Get original post
        $post = get_post($post_id);
        if (!$post) {
            wp_die('Post not found.');
        }
        
        // Create duplicate post
        $new_post = array(
            'post_author'    => get_current_user_id(),
            'post_content'   => $post->post_content,
            'post_excerpt'   => $post->post_excerpt,
            'post_name'      => $post->post_name . '-copy',
            'post_status'    => 'draft',
            'post_title'     => $post->post_title . ' (Copy)',
            'post_type'      => $post->post_type,
            'comment_status' => $post->comment_status,
            'ping_status'    => $post->ping_status,
            'post_password'  => $post->post_password,
            'to_ping'        => $post->to_ping,
            'menu_order'     => $post->menu_order
        );
        
        // Insert new post
        $new_post_id = wp_insert_post($new_post);
        
        if ($new_post_id) {
            // Copy post meta
            $post_meta = get_post_meta($post_id);
            if ($post_meta) {
                foreach ($post_meta as $meta_key => $meta_values) {
                    foreach ($meta_values as $meta_value) {
                        add_post_meta($new_post_id, $meta_key, maybe_unserialize($meta_value));
                    }
                }
            }
            
            // Copy taxonomies and terms
            $taxonomies = get_object_taxonomies($post->post_type);
            foreach ($taxonomies as $taxonomy) {
                $post_terms = wp_get_object_terms($post_id, $taxonomy, array('fields' => 'all'));
                $term_ids = array();
                
                foreach ($post_terms as $term) {
                    $term_ids[] = $term->term_id;
                }
                
                wp_set_object_terms($new_post_id, $term_ids, $taxonomy);
            }
            
            // Handle attachments
            $attachments = get_posts(array(
                'post_type'      => 'attachment',
                'posts_per_page' => -1,
                'post_parent'    => $post_id
            ));
            
            if ($attachments) {
                foreach ($attachments as $attachment) {
                    $attachment_url = wp_get_attachment_url($attachment->ID);
                    $attachment_data = array(
                        'guid'           => $attachment_url,
                        'post_mime_type' => $attachment->post_mime_type,
                        'post_title'     => $attachment->post_title,
                        'post_content'   => $attachment->post_content,
                        'post_status'    => 'inherit'
                    );
                    
                    // Insert attachment
                    $new_attachment_id = wp_insert_attachment($attachment_data, '', $new_post_id);
                    
                    // Copy attachment metadata
                    $attachment_meta = get_post_meta($attachment->ID);
                    foreach ($attachment_meta as $meta_key => $meta_values) {
                        foreach ($meta_values as $meta_value) {
                            add_post_meta($new_attachment_id, $meta_key, maybe_unserialize($meta_value));
                        }
                    }
                }
            }
            
            // Redirect to edit screen
            wp_redirect(admin_url('post.php?action=edit&post=' . $new_post_id));
            exit;
        } else {
            wp_die('Error creating duplicate content.');
        }
    }
}

// Initialize plugin
new ContentDuplicator();
