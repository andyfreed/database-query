<?php
/**
 * Plugin Name: Database Query AI Assistant
 * Plugin URI: https://github.com/andyfreed/database-query
 * Description: An AI-powered chatbot for querying your WordPress database safely using OpenAI API. Read-only queries only.
 * Version: 1.0.0
 * Author: Andy Freed
 * Author URI: https://github.com/andyfreed
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: database-query
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('DBQ_VERSION', '1.0.0');
define('DBQ_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DBQ_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DBQ_PLUGIN_FILE', __FILE__);

// Include required files
require_once DBQ_PLUGIN_DIR . 'includes/class-dbq-database-analyzer.php';
require_once DBQ_PLUGIN_DIR . 'includes/class-dbq-openai-client.php';
require_once DBQ_PLUGIN_DIR . 'includes/class-dbq-query-executor.php';
require_once DBQ_PLUGIN_DIR . 'includes/class-dbq-admin.php';

/**
 * Main plugin class
 */
class Database_Query_AI {
    
    /**
     * Instance of this class
     */
    private static $instance = null;
    
    /**
     * Get instance of this class
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Activation hook
        register_activation_hook(__FILE__, array($this, 'activate'));
        
        // Deactivation hook
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Initialize admin
        if (is_admin()) {
            DBQ_Admin::get_instance();
        }
        
        // AJAX handlers
        add_action('wp_ajax_dbq_chat', array($this, 'handle_chat_request'));
        add_action('wp_ajax_dbq_get_schema', array($this, 'handle_get_schema'));
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Set default options
        if (!get_option('dbq_openai_api_key')) {
            add_option('dbq_openai_api_key', '');
        }
        
        if (!get_option('dbq_openai_model')) {
            add_option('dbq_openai_model', 'gpt-4');
        }
        
        // Flush rewrite rules if needed
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clean up if needed
        flush_rewrite_rules();
    }
    
    /**
     * Handle chat request via AJAX
     */
    public function handle_chat_request() {
        // Check nonce
        check_ajax_referer('dbq_chat_nonce', 'nonce');
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        $message = isset($_POST['message']) ? sanitize_text_field($_POST['message']) : '';
        $conversation_history = isset($_POST['history']) ? json_decode(stripslashes($_POST['history']), true) : array();
        
        if (empty($message)) {
            wp_send_json_error(array('message' => 'Message is required'));
            return;
        }
        
        // Get database schema
        $analyzer = new DBQ_Database_Analyzer();
        $schema = $analyzer->get_full_schema();
        
        // Initialize OpenAI client
        $openai = new DBQ_OpenAI_Client();
        
        // Generate SQL query
        $sql_query = $openai->generate_sql_query($message, $schema, $conversation_history);
        
        if (is_wp_error($sql_query)) {
            wp_send_json_error(array('message' => $sql_query->get_error_message()));
            return;
        }
        
        // Execute query safely
        $executor = new DBQ_Query_Executor();
        $result = $executor->execute_query($sql_query);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
            return;
        }
        
        // Format response using OpenAI
        $formatted_response = $openai->format_response($message, $sql_query, $result, $conversation_history);
        
        if (is_wp_error($formatted_response)) {
            wp_send_json_error(array('message' => $formatted_response->get_error_message()));
            return;
        }
        
        wp_send_json_success(array(
            'response' => $formatted_response,
            'sql_query' => $sql_query,
            'raw_data' => $result
        ));
    }
    
    /**
     * Handle get schema request via AJAX
     */
    public function handle_get_schema() {
        // Check nonce
        check_ajax_referer('dbq_chat_nonce', 'nonce');
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        $analyzer = new DBQ_Database_Analyzer();
        $schema = $analyzer->get_full_schema();
        
        wp_send_json_success(array('schema' => $schema));
    }
}

// Initialize the plugin
Database_Query_AI::get_instance();
