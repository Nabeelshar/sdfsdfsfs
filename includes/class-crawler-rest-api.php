<?php
/**
 * REST API endpoints for external crawler
 * 
 * Provides endpoints to create stories and chapters from external scripts
 */

class Fictioneer_Crawler_Rest_API {
    
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }
    
    /**
     * Register REST API routes
     */
    public function register_routes() {
        // Create story endpoint
        register_rest_route('crawler/v1', '/story', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_story'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'url' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_url',
                ),
                'title' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'title_zh' => array(
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'author' => array(
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'description' => array(
                    'required' => false,
                    'type' => 'string',
                ),
                'cover_url' => array(
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_url',
                ),
            ),
        ));
        
        // Create chapter endpoint
        register_rest_route('crawler/v1', '/chapter', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_chapter'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'url' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_url',
                ),
                'story_id' => array(
                    'required' => true,
                    'type' => 'integer',
                    'validate_callback' => function($param, $request, $key) {
                        return is_numeric($param);
                    },
                    'sanitize_callback' => 'absint',
                ),
                'title' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'title_zh' => array(
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'content' => array(
                    'required' => true,
                    'type' => 'string',
                ),
                'chapter_number' => array(
                    'required' => false,
                    'type' => 'integer',
                ),
            ),
        ));
        
        // Health check endpoint
        register_rest_route('crawler/v1', '/health', array(
            'methods' => 'GET',
            'callback' => array($this, 'health_check'),
            'permission_callback' => '__return_true',
        ));
        
        // Debug endpoint to check story-chapter associations
        register_rest_route('crawler/v1', '/story/(?P<id>\d+)/debug', array(
            'methods' => 'GET',
            'callback' => array($this, 'debug_story'),
            'permission_callback' => array($this, 'check_permission'),
        ));
    }
    
    /**
     * Check permission - requires API key
     */
    public function check_permission($request) {
        // Get API key from header or query parameter
        $api_key = $request->get_header('X-API-Key');
        
        if (empty($api_key)) {
            $api_key = $request->get_param('api_key');
        }
        
        if (empty($api_key)) {
            return new WP_Error('rest_forbidden', 'API key required. Provide X-API-Key header or api_key parameter.', array('status' => 401));
        }
        
        // Get stored API key from WordPress options
        $stored_key = get_option('fictioneer_crawler_api_key');
        
        // Generate key if it doesn't exist
        if (empty($stored_key)) {
            $stored_key = wp_generate_password(32, false);
            update_option('fictioneer_crawler_api_key', $stored_key);
        }
        
        // Verify API key
        if (!hash_equals($stored_key, $api_key)) {
            return new WP_Error('rest_forbidden', 'Invalid API key', array('status' => 403));
        }
        
        return true;
    }
    
    /**
     * Create story from crawler data
     */
    public function create_story($request) {
        $url = $request->get_param('url');
        $title = $request->get_param('title');
        $title_zh = $request->get_param('title_zh');
        $author = $request->get_param('author');
        $description = $request->get_param('description');
        $cover_url = $request->get_param('cover_url');
        
        // Check if story already exists
        $existing = get_posts(array(
            'post_type' => 'fcn_story',
            'meta_key' => 'crawler_source_url',
            'meta_value' => $url,
            'posts_per_page' => 1,
        ));
        
        if (!empty($existing)) {
            return array(
                'success' => true,
                'story_id' => $existing[0]->ID,
                'message' => 'Story already exists',
                'existed' => true,
            );
        }
        
        // Create story post
        $story_data = array(
            'post_type' => 'fcn_story',
            'post_title' => $title,
            'post_content' => $description ?: '',
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
        );
        
        $story_id = wp_insert_post($story_data);
        
        if (is_wp_error($story_id)) {
            return new WP_Error('story_creation_failed', $story_id->get_error_message(), array('status' => 500));
        }
        
        // Store metadata
        update_post_meta($story_id, 'crawler_source_url', $url);
        
        if ($title_zh) {
            update_post_meta($story_id, 'fictioneer_story_title_original', $title_zh);
        }
        
        if ($author) {
            update_post_meta($story_id, 'fictioneer_story_author', $author);
        }
        
        // Set default story status
        update_post_meta($story_id, 'fictioneer_story_status', 'Ongoing');
        
        // Initialize crawler progress tracking
        update_post_meta($story_id, 'crawler_chapters_crawled', 0);
        update_post_meta($story_id, 'crawler_chapters_total', 0);
        update_post_meta($story_id, 'crawler_last_chapter', 0);
        
        // Download and set cover image if provided
        if ($cover_url) {
            $this->set_story_cover($story_id, $cover_url);
        }
        
        // Log activity
        $this->log_activity('Story created', array(
            'story_id' => $story_id,
            'title' => $title,
            'url' => $url,
        ));
        
        return array(
            'success' => true,
            'story_id' => $story_id,
            'message' => 'Story created successfully',
            'existed' => false,
        );
    }
    
    /**
     * Create chapter from crawler data
     */
    public function create_chapter($request) {
        $url = $request->get_param('url');
        $story_id = $request->get_param('story_id');
        $title = $request->get_param('title');
        $title_zh = $request->get_param('title_zh');
        $content = $request->get_param('content');
        $chapter_number = $request->get_param('chapter_number');
        
        // Debug logging
        $this->log_activity('Chapter create called', array(
            'story_id_received' => $story_id,
            'story_id_type' => gettype($story_id),
            'story_id_intval' => intval($story_id),
        ));
        
        // Verify story exists
        $story = get_post($story_id);
        if (!$story || $story->post_type !== 'fcn_story') {
            return new WP_Error('invalid_story', 'Story not found', array('status' => 404));
        }
        
        // Check if chapter already exists
        $existing = get_posts(array(
            'post_type' => 'fcn_chapter',
            'meta_key' => 'crawler_source_url',
            'meta_value' => $url,
            'posts_per_page' => 1,
        ));
        
        if (!empty($existing)) {
            $chapter_id = $existing[0]->ID;
            
            // Update associations even for existing chapters
            update_post_meta($chapter_id, 'fictioneer_chapter_story', intval($story_id));
            update_post_meta($chapter_id, '_test_story_id', intval($story_id)); // Add working field too
            
            // Add to story's chapter list if not already there
            $story_chapters = get_post_meta($story_id, 'fictioneer_story_chapters', true);
            if (!is_array($story_chapters)) {
                $story_chapters = array();
            }
            
            if (!in_array($chapter_id, $story_chapters)) {
                $story_chapters[] = $chapter_id;
                update_post_meta($story_id, 'fictioneer_story_chapters', $story_chapters);
            }
            
            return array(
                'success' => true,
                'chapter_id' => $chapter_id,
                'message' => 'Chapter already exists',
                'existed' => true,
            );
        }
        
        // Create chapter post
        $chapter_data = array(
            'post_type' => 'fcn_chapter',
            'post_title' => $title,
            'post_content' => $content,
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
            'meta_input' => array(
                'fictioneer_chapter_story' => intval($story_id),
                'crawler_source_url' => $url,
            ),
        );
        
        $chapter_id = wp_insert_post($chapter_data);
        
        if (is_wp_error($chapter_id)) {
            return new WP_Error('chapter_creation_failed', $chapter_id->get_error_message(), array('status' => 500));
        }
        
        // Force update meta again after post creation (theme might be overwriting it)
        update_post_meta($chapter_id, 'fictioneer_chapter_story', intval($story_id));
        
        // Use a delayed action to set it again after all hooks have run
        add_action('shutdown', function() use ($chapter_id, $story_id) {
            update_post_meta($chapter_id, 'fictioneer_chapter_story', intval($story_id));
        }, 999);
        
        // Store metadata
        $story_id_int = intval($story_id);
        
        // Test: Save to both the correct key and a test key
        $saved = update_post_meta($chapter_id, 'fictioneer_chapter_story', $story_id_int);
        $saved_test = update_post_meta($chapter_id, '_test_story_id', $story_id_int);
        
        // Immediately read back what was saved
        $verify = get_post_meta($chapter_id, 'fictioneer_chapter_story', true);
        $verify_test = get_post_meta($chapter_id, '_test_story_id', true);
        
        update_post_meta($chapter_id, 'crawler_source_url', $url);
        
        // Log what we're saving
        $this->log_activity('Chapter meta saved', array(
            'chapter_id' => $chapter_id,
            'story_id_sent' => $story_id,
            'story_id_int' => $story_id_int,
            'update_result' => $saved,
            'update_test_result' => $saved_test,
            'verify_value' => $verify,
            'verify_test_value' => $verify_test,
            'verify_type' => gettype($verify),
        ));
        
        if ($title_zh) {
            update_post_meta($chapter_id, 'fictioneer_chapter_title_original', $title_zh);
        }
        
        // Append chapter to story's chapter list (avoid duplicates)
        $story_chapters = get_post_meta($story_id, 'fictioneer_story_chapters', true);
        if (!is_array($story_chapters)) {
            $story_chapters = array();
        }
        
        // Only add if not already in the list
        if (!in_array($chapter_id, $story_chapters)) {
            $story_chapters[] = $chapter_id;
            update_post_meta($story_id, 'fictioneer_story_chapters', $story_chapters);
            
            // Update crawler progress tracking
            $chapters_crawled = (int) get_post_meta($story_id, 'crawler_chapters_crawled', true);
            $chapters_crawled++;
            update_post_meta($story_id, 'crawler_chapters_crawled', $chapters_crawled);
            update_post_meta($story_id, 'crawler_last_chapter', $chapter_number);
            
            $this->log_activity('Chapter added to story list', array(
                'chapter_id' => $chapter_id,
                'story_id' => $story_id,
                'total_chapters' => count($story_chapters),
                'chapters_crawled' => $chapters_crawled,
            ));
        }
        
        // Log activity
        $this->log_activity('Chapter created', array(
            'chapter_id' => $chapter_id,
            'story_id' => $story_id,
            'title' => $title,
            'url' => $url,
            'chapter_number' => $chapter_number,
        ));
        
        return array(
            'success' => true,
            'chapter_id' => $chapter_id,
            'message' => 'Chapter created successfully',
            'existed' => false,
        );
    }
    
    /**
     * Health check endpoint
     */
    public function health_check($request) {
        return array(
            'status' => 'ok',
            'timestamp' => current_time('mysql'),
            'wordpress' => get_bloginfo('version'),
            'php' => PHP_VERSION,
        );
    }
    
    /**
     * Debug story endpoint - check chapter associations
     */
    public function debug_story($request) {
        $story_id = $request->get_param('id');
        
        $story = get_post($story_id);
        if (!$story || $story->post_type !== 'fcn_story') {
            return new WP_Error('invalid_story', 'Story not found', array('status' => 404));
        }
        
        $story_chapters = get_post_meta($story_id, 'fictioneer_story_chapters', true);
        
        $chapter_details = array();
        if (is_array($story_chapters)) {
            foreach ($story_chapters as $chapter_id) {
                $chapter = get_post($chapter_id);
                $chapter_story_id = get_post_meta($chapter_id, 'fictioneer_chapter_story', true);
                $chapter_story_id_raw = get_post_meta($chapter_id, 'fictioneer_chapter_story', false);
                $chapter_story_id_test = get_post_meta($chapter_id, '_test_story_id', true);
                
                $chapter_details[] = array(
                    'id' => $chapter_id,
                    'title' => $chapter ? $chapter->post_title : 'Not found',
                    'status' => $chapter ? $chapter->post_status : 'N/A',
                    'story_id' => $chapter_story_id,
                    'story_id_raw' => $chapter_story_id_raw,
                    'story_id_test' => $chapter_story_id_test,
                    'story_id_type' => gettype($chapter_story_id),
                    'association_ok' => ($chapter_story_id == $story_id),
                );
            }
        }
        
        return array(
            'story_id' => $story_id,
            'story_title' => $story->post_title,
            'story_status' => $story->post_status,
            'chapters_meta' => $story_chapters,
            'chapters_count' => is_array($story_chapters) ? count($story_chapters) : 0,
            'chapter_details' => $chapter_details,
        );
    }
    
    /**
     * Set story cover image
     */
    private function set_story_cover($story_id, $cover_url) {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        $attachment_id = media_sideload_image($cover_url, $story_id, null, 'id');
        
        if (!is_wp_error($attachment_id)) {
            set_post_thumbnail($story_id, $attachment_id);
        }
    }
    
    /**
     * Log activity
     */
    private function log_activity($message, $context = array()) {
        if (class_exists('Fictioneer_Crawler_Logger')) {
            $logger = new Fictioneer_Crawler_Logger();
            $logger->info($message, $context);
        }
    }
}

// Initialize
new Fictioneer_Crawler_Rest_API();
