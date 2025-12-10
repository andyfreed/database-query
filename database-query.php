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
        
        // Initialize analyzer
        $analyzer = new DBQ_Database_Analyzer();
        
        // PHASE 1: Discovery - Find relevant fields and their values
        $search_terms = $analyzer->extract_search_terms($message);
        $discovered_keys = $analyzer->discover_meta_keys($search_terms);
        
        // Sample values for discovered keys
        $value_samples = array();
        foreach ($discovered_keys['usermeta'] as $key_info) {
            $meta_key = $key_info['meta_key'];
            $value_format = $analyzer->detect_value_format($meta_key, 'usermeta');
            if ($value_format['non_empty_rows'] > 0) {
                $value_samples['usermeta'][$meta_key] = $value_format;
            }
        }
        foreach ($discovered_keys['postmeta'] as $key_info) {
            $meta_key = $key_info['meta_key'];
            $value_format = $analyzer->detect_value_format($meta_key, 'postmeta');
            if ($value_format['non_empty_rows'] > 0) {
                $value_samples['postmeta'][$meta_key] = $value_format;
            }
        }
        
        // Get database schema
        $schema = $analyzer->get_full_schema();
        
        // Initialize OpenAI client
        $openai = new DBQ_OpenAI_Client();
        
        // Generate SQL query with discovery context
        $sql_query = $openai->generate_sql_query($message, $schema, $conversation_history, array(
            'discovered_keys' => $discovered_keys,
            'value_samples' => $value_samples,
            'search_terms' => $search_terms
        ));
        
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
        
        // PHASE 2: Auto-debugging if no results
        $debug_info = null;
        if (empty($result) || (is_array($result) && count($result) === 0)) {
            $debug_info = $this->troubleshoot_query($sql_query, $discovered_keys, $value_samples, $analyzer);
            
            // If we found issues, try to generate a corrected query
            if ($debug_info && !empty($debug_info['suggestions'])) {
                $correction_prompt = "The query returned 0 results. Investigation shows:\n" . json_encode($debug_info, JSON_PRETTY_PRINT) . "\n\n";
                $correction_prompt .= "Please generate a corrected SQL query based on the actual database structure discovered above.";
                
                // Try to get a corrected query
                $corrected_query = $openai->generate_corrected_query($correction_prompt, $schema, $conversation_history, array(
                    'discovered_keys' => $discovered_keys,
                    'value_samples' => $value_samples,
                    'original_query' => $sql_query,
                    'debug_info' => $debug_info
                ));
                
                if (!is_wp_error($corrected_query)) {
                    $result = $executor->execute_query($corrected_query);
                    if (!is_wp_error($result) && !empty($result)) {
                        $sql_query = $corrected_query; // Use the corrected query
                        $debug_info['corrected'] = true;
                    }
                }
            }
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
            'raw_data' => $result,
            'csv_data' => $this->generate_csv($result),
            'discovery' => array(
                'search_terms' => $search_terms,
                'discovered_keys' => $discovered_keys,
                'value_samples' => $value_samples
            ),
            'debug_info' => $debug_info
        ));
    }
    
    /**
     * Troubleshoot a query that returned no results
     * 
     * @param string $sql_query The SQL query that returned no results
     * @param array $discovered_keys Discovered meta keys
     * @param array $value_samples Value samples
     * @param object $analyzer Database analyzer instance
     * @return array Debug information
     */
    private function troubleshoot_query($sql_query, $discovered_keys, $value_samples, $analyzer) {
        global $wpdb;
        
        $debug_info = array(
            'query' => $sql_query,
            'issues' => array(),
            'suggestions' => array()
        );
        
        // Extract meta_key names from the query (handles both WHERE and JOIN conditions)
        if (preg_match_all("/meta_key\s*=\s*['\"]([^'\"]+)['\"]/i", $sql_query, $matches)) {
            $used_keys = array_unique($matches[1]);
            
            foreach ($used_keys as $used_key) {
                // Check if this key exists in discovered keys (case-insensitive)
                $found = false;
                $found_key = null;
                $found_in = null;
                
                // Build a map of lowercase keys to actual keys
                $usermeta_map = array();
                foreach ($discovered_keys['usermeta'] as $key_info) {
                    $usermeta_map[strtolower($key_info['meta_key'])] = $key_info;
                }
                
                if (isset($usermeta_map[strtolower($used_key)])) {
                    $found = true;
                    $found_key = $usermeta_map[strtolower($used_key)]['meta_key'];
                    $found_in = 'usermeta';
                } else {
                    $postmeta_map = array();
                    foreach ($discovered_keys['postmeta'] as $key_info) {
                        $postmeta_map[strtolower($key_info['meta_key'])] = $key_info;
                    }
                    if (isset($postmeta_map[strtolower($used_key)])) {
                        $found = true;
                        $found_key = $postmeta_map[strtolower($used_key)]['meta_key'];
                        $found_in = 'postmeta';
                    }
                }
                
                if (!$found) {
                    $debug_info['issues'][] = "Meta key '{$used_key}' not found in database";
                    
                    // Suggest similar keys
                    $suggestions = array();
                    foreach ($discovered_keys['usermeta'] as $key_info) {
                        if (stripos($key_info['meta_key'], $used_key) !== false || stripos($used_key, $key_info['meta_key']) !== false) {
                            $suggestions[] = $key_info['meta_key'];
                        }
                    }
                    if (!empty($suggestions)) {
                        $debug_info['suggestions'][] = "Did you mean one of these? " . implode(', ', array_slice($suggestions, 0, 5));
                    } else {
                        $debug_info['suggestions'][] = "Available discovered keys: " . implode(', ', array_slice(array_column($discovered_keys['usermeta'], 'meta_key'), 0, 10));
                    }
                } else {
                    // Use the actual found key name (case-sensitive)
                    $actual_key = $found_key;
                    
                    // Check value samples
                    if (isset($value_samples[$found_in][$actual_key])) {
                        $samples = $value_samples[$found_in][$actual_key];
                        
                        // Extract all meta_value checks from query
                        $sql_upper = strtoupper($sql_query);
                        $value_patterns = array(
                            "/meta_value\s*=\s*['\"]([^'\"]+)['\"]/i",
                            "/meta_value\s*IN\s*\([^)]+\)/i"
                        );
                        
                        $has_value_check = false;
                        foreach ($value_patterns as $pattern) {
                            if (preg_match($pattern, $sql_query)) {
                                $has_value_check = true;
                                break;
                            }
                        }
                        
                        if ($has_value_check && preg_match("/meta_value\s*=\s*['\"]([^'\"]+)['\"]/i", $sql_query, $value_match)) {
                            $used_value = $value_match[1];
                            $value_found = false;
                            
                            // Check if this value exists (case-insensitive)
                            foreach ($samples['value_samples'] as $sample) {
                                if (strcasecmp($sample['value'], $used_value) === 0) {
                                    $value_found = true;
                                    break;
                                }
                            }
                            
                            if (!$value_found && !empty($samples['value_samples'])) {
                                $actual_values = array_map(function($v) { return $v['value']; }, $samples['value_samples']);
                                $top_values = array_slice($actual_values, 0, 10);
                                $debug_info['issues'][] = "Value '{$used_value}' not found for key '{$actual_key}'. Found values include: " . implode(', ', $top_values);
                                
                                // If it's a checkbox pattern, suggest common checked values
                                if (!empty($samples['patterns']['checkbox_checked'])) {
                                    $checked_vals = $samples['patterns']['checkbox_checked'];
                                    $debug_info['suggestions'][] = "For checkbox '{$actual_key}', use: " . implode(' OR ', $checked_vals);
                                } else {
                                    $debug_info['suggestions'][] = "Try using one of these actual values: " . implode(', ', array_slice($top_values, 0, 5));
                                }
                            }
                        } else if ($has_value_check) {
                            // Value check exists but format might be wrong
                            if (!empty($samples['patterns']['checkbox_checked'])) {
                                $checked_vals = $samples['patterns']['checkbox_checked'];
                                $debug_info['suggestions'][] = "For checkbox '{$actual_key}', try: meta_value IN ('" . implode("', '", $checked_vals) . "')";
                            }
                        }
                    }
                }
            }
        }
        
        return $debug_info;
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
    
    /**
     * Generate CSV data from query results
     * 
     * @param array $data Query results
     * @return string CSV data
     */
    private function generate_csv($data) {
        if (empty($data) || !is_array($data)) {
            return '';
        }
        
        // Get headers from first row
        $headers = array_keys($data[0]);
        
        // Start output buffering
        ob_start();
        
        // Output headers
        $output = fopen('php://output', 'w');
        fputcsv($output, $headers);
        
        // Output data rows
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        
        fclose($output);
        $csv = ob_get_clean();
        
        return base64_encode($csv);
    }
}

// Initialize the plugin
Database_Query_AI::get_instance();

