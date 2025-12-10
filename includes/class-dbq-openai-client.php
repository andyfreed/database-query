<?php
/**
 * OpenAI API Client
 * 
 * Handles communication with OpenAI API to generate SQL queries
 * and format responses.
 */

if (!defined('ABSPATH')) {
    exit;
}

class DBQ_OpenAI_Client {
    
    /**
     * OpenAI API endpoint
     */
    private $api_endpoint = 'https://api.openai.com/v1/chat/completions';
    
    /**
     * Get API key from options
     * 
     * @return string|false API key or false if not set
     */
    private function get_api_key() {
        $api_key = get_option('dbq_openai_api_key');
        return !empty($api_key) ? $api_key : false;
    }
    
    /**
     * Get model from options
     * 
     * @return string Model name
     */
    private function get_model() {
        $model = get_option('dbq_openai_model', 'gpt-4');
        return $model;
    }
    
    /**
     * Make API request to OpenAI
     * 
     * @param array $messages Chat messages
     * @return array|WP_Error Response or error
     */
    private function make_request($messages) {
        $api_key = $this->get_api_key();
        
        if (!$api_key) {
            return new WP_Error('no_api_key', 'OpenAI API key is not configured. Please set it in the plugin settings.');
        }
        
        $body = array(
            'model' => $this->get_model(),
            'messages' => $messages,
            'temperature' => 0.3, // Lower temperature for more consistent SQL generation
            'max_tokens' => 2000
        );
        
        $response = wp_remote_post($this->api_endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($body),
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code !== 200) {
            $error_message = isset($body['error']['message']) ? $body['error']['message'] : 'Unknown error';
            return new WP_Error('api_error', 'OpenAI API error: ' . $error_message);
        }
        
        if (!isset($body['choices'][0]['message']['content'])) {
            return new WP_Error('invalid_response', 'Invalid response from OpenAI API');
        }
        
        return $body['choices'][0]['message']['content'];
    }
    
    /**
     * Generate SQL query from natural language
     * 
     * @param string $user_query Natural language query
     * @param array $schema Database schema
     * @param array $conversation_history Previous conversation messages
     * @param array $discovery_data Discovery data (discovered_keys, value_samples, search_terms)
     * @return string|WP_Error SQL query or error
     */
    public function generate_sql_query($user_query, $schema, $conversation_history = array(), $discovery_data = array()) {
        // Build system prompt with discovery data
        $system_prompt = $this->build_sql_generation_prompt($schema, $discovery_data);
        
        // Build messages
        $messages = array(
            array(
                'role' => 'system',
                'content' => $system_prompt
            )
        );
        
        // Add conversation history
        foreach ($conversation_history as $msg) {
            if (isset($msg['role']) && isset($msg['content'])) {
                $messages[] = array(
                    'role' => $msg['role'],
                    'content' => $msg['content']
                );
            }
        }
        
        // Add current query
        $messages[] = array(
            'role' => 'user',
            'content' => "Generate a SQL SELECT query for: {$user_query}\n\nReturn ONLY the SQL query, no explanations, no markdown formatting, just the raw SQL."
        );
        
        $response = $this->make_request($messages);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Clean up the response - remove markdown code blocks if present
        $sql = trim($response);
        $sql = preg_replace('/^```sql\s*/i', '', $sql);
        $sql = preg_replace('/^```\s*/', '', $sql);
        $sql = preg_replace('/\s*```$/', '', $sql);
        $sql = trim($sql);
        
        return $sql;
    }
    
    /**
     * Format response with natural language explanation
     * 
     * @param string $original_query Original user query
     * @param string $sql_query SQL query that was executed
     * @param array $query_results Query results
     * @param array $conversation_history Previous conversation messages
     * @return string|WP_Error Formatted response or error
     */
    public function format_response($original_query, $sql_query, $query_results, $conversation_history = array()) {
        $system_prompt = "You are a helpful database assistant. Format query results into a clear, natural language response. Be concise but informative. If there are many results, summarize the key findings.";
        
        $messages = array(
            array(
                'role' => 'system',
                'content' => $system_prompt
            )
        );
        
        // Add conversation history
        foreach ($conversation_history as $msg) {
            if (isset($msg['role']) && isset($msg['content'])) {
                $messages[] = array(
                    'role' => $msg['role'],
                    'content' => $msg['content']
                );
            }
        }
        
        // Prepare results summary
        $results_summary = array(
            'query' => $original_query,
            'sql' => $sql_query,
            'row_count' => is_array($query_results) ? count($query_results) : 0,
            'sample_data' => is_array($query_results) && !empty($query_results) ? array_slice($query_results, 0, 5) : array()
        );
        
        $user_message = "The user asked: '{$original_query}'\n\n";
        $user_message .= "SQL query executed: {$sql_query}\n\n";
        $user_message .= "Results: " . json_encode($results_summary, JSON_PRETTY_PRINT) . "\n\n";
        
        // If no results, be more helpful
        if (empty($query_results) || (is_array($query_results) && count($query_results) === 0)) {
            $user_message .= "NOTE: The query returned 0 results. Suggest checking:\n";
            $user_message .= "1. If the field names match exactly (case-sensitive)\n";
            $user_message .= "2. If the values are stored in the expected format\n";
            $user_message .= "3. If there are actually any records matching the criteria\n\n";
        }
        
        $user_message .= "Format this into a clear, natural language response for the user.";
        
        $messages[] = array(
            'role' => 'user',
            'content' => $user_message
        );
        
        return $this->make_request($messages);
    }
    
    /**
     * Generate corrected SQL query based on debug information
     * 
     * @param string $debug_prompt Debug information and correction request
     * @param array $schema Database schema
     * @param array $conversation_history Previous conversation messages
     * @param array $discovery_data Discovery data
     * @return string|WP_Error Corrected SQL query or error
     */
    public function generate_corrected_query($debug_prompt, $schema, $conversation_history = array(), $discovery_data = array()) {
        // Build system prompt with discovery data
        $system_prompt = $this->build_sql_generation_prompt($schema, $discovery_data);
        
        // Build messages
        $messages = array(
            array(
                'role' => 'system',
                'content' => $system_prompt . "\n\nIMPORTANT: A previous query returned 0 results. Use the debug information to generate a corrected query."
            )
        );
        
        // Add conversation history
        foreach ($conversation_history as $msg) {
            if (isset($msg['role']) && isset($msg['content'])) {
                $messages[] = array(
                    'role' => $msg['role'],
                    'content' => $msg['content']
                );
            }
        }
        
        // Add debug prompt
        $messages[] = array(
            'role' => 'user',
            'content' => $debug_prompt . "\n\nGenerate the corrected SQL query. Return ONLY the SQL query, no explanations."
        );
        
        $response = $this->make_request($messages);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Clean up the response
        $sql = trim($response);
        $sql = preg_replace('/^```sql\s*/i', '', $sql);
        $sql = preg_replace('/^```\s*/', '', $sql);
        $sql = preg_replace('/\s*```$/', '', $sql);
        $sql = trim($sql);
        
        return $sql;
    }
    
    /**
     * Build SQL generation system prompt
     * 
     * @param array $schema Database schema
     * @param array $discovery_data Discovery data (discovered_keys, value_samples, search_terms)
     * @return string System prompt
     */
    private function build_sql_generation_prompt($schema, $discovery_data = array()) {
        global $wpdb;
        $prefix = $wpdb->prefix;
        
        $prompt = "You are a WordPress database expert. Generate ONLY SELECT queries. Table prefix: {$prefix}\n\n";
        
        // Add core table structures (essential tables only to save tokens)
        $prompt .= "CORE TABLE STRUCTURES:\n\n";
        $core_tables = array($wpdb->users, $wpdb->usermeta, $wpdb->posts, $wpdb->postmeta);
        foreach ($core_tables as $table_name) {
            if (isset($schema['tables'][$table_name])) {
                $table_info = $schema['tables'][$table_name];
                $prompt .= "TABLE: {$table_name} ({$table_info['row_count']} rows)\n";
                if ($table_info['primary_key']) {
                    $prompt .= "PK: {$table_info['primary_key']}\n";
                }
                $col_names = array_map(function($c) { return $c['name']; }, $table_info['columns']);
                $prompt .= "Columns: " . implode(', ', $col_names) . "\n\n";
            }
        }
        
        // Add discovered meta keys with value samples (THIS IS THE KEY PART!)
        if (!empty($discovery_data['discovered_keys'])) {
            $discovered = $discovery_data['discovered_keys'];
            
            if (!empty($discovered['usermeta'])) {
                $prompt .= "DISCOVERED USERMETA FIELDS (found by searching for: " . implode(', ', $discovery_data['search_terms'] ?? array()) . "):\n";
                foreach ($discovered['usermeta'] as $key_info) {
                    $meta_key = $key_info['meta_key'];
                    $prompt .= "- {$meta_key} ({$key_info['count']} rows)";
                    
                    // Add actual value samples if available
                    if (isset($discovery_data['value_samples']['usermeta'][$meta_key])) {
                        $value_info = $discovery_data['value_samples']['usermeta'][$meta_key];
                        $prompt .= "\n  Values: ";
                        $value_list = array();
                        foreach (array_slice($value_info['value_samples'], 0, 10) as $sample) {
                            $value_list[] = "'{$sample['value']}' (x{$sample['count']})";
                        }
                        $prompt .= implode(', ', $value_list);
                        
                        // Highlight checkbox patterns
                        if (!empty($value_info['patterns']['checkbox_checked'])) {
                            $prompt .= " [CHECKBOX: checked = " . implode(' OR ', $value_info['patterns']['checkbox_checked']) . "]";
                        }
                    }
                    $prompt .= "\n";
                }
                $prompt .= "\n";
            }
            
            if (!empty($discovered['postmeta'])) {
                $prompt .= "DISCOVERED POSTMETA FIELDS:\n";
                foreach (array_slice($discovered['postmeta'], 0, 10) as $key_info) {
                    $meta_key = $key_info['meta_key'];
                    $prompt .= "- {$meta_key} ({$key_info['count']} rows)";
                    if (isset($discovery_data['value_samples']['postmeta'][$meta_key])) {
                        $value_info = $discovery_data['value_samples']['postmeta'][$meta_key];
                        $sample_values = array_slice($value_info['value_samples'], 0, 5);
                        $prompt .= " Values: " . implode(', ', array_map(function($v) { return "'{$v['value']}'"; }, $sample_values));
                    }
                    $prompt .= "\n";
                }
                $prompt .= "\n";
            }
        }
        
        // If no discovery data, fall back to showing all meta keys (but limited)
        if (empty($discovery_data['discovered_keys'])) {
            $analyzer = new DBQ_Database_Analyzer();
            $usermeta_keys = $analyzer->get_usermeta_keys();
            if (!empty($usermeta_keys)) {
                $prompt .= "SAMPLE USERMETA META_KEYS (showing first 100):\n";
                $prompt .= implode(', ', array_slice($usermeta_keys, 0, 100));
                if (count($usermeta_keys) > 100) {
                    $prompt .= " ... (total: " . count($usermeta_keys) . " keys)";
                }
                $prompt .= "\n\n";
            }
        }
        
        // Add relationships
        $prompt .= "TABLE RELATIONSHIPS:\n";
        $prompt .= "- {$wpdb->usermeta}.user_id -> {$wpdb->users}.ID\n";
        $prompt .= "- {$wpdb->posts}.post_author -> {$wpdb->users}.ID\n";
        $prompt .= "- {$wpdb->postmeta}.post_id -> {$wpdb->posts}.ID\n\n";
        
        // Critical instructions
        $prompt .= "CRITICAL INSTRUCTIONS:\n";
        $prompt .= "1. Use the EXACT meta_key names from the DISCOVERED fields above\n";
        $prompt .= "2. Use the EXACT values shown in the value samples (e.g., if checkbox shows 'on', use 'on' not '1')\n";
        $prompt .= "3. For checkboxes, look for the [CHECKBOX: checked = ...] pattern to see what value means 'checked'\n";
        $prompt .= "4. To join multiple meta_keys, create separate JOINs for each (um1, um2, um3, etc.)\n";
        $prompt .= "5. Example: JOIN {$wpdb->usermeta} um1 ON u.ID = um1.user_id AND um1.meta_key = 'first_name'\n\n";
        
        $prompt .= "Return ONLY the SQL query, no explanations, no markdown formatting.";
        
        return $prompt;
    }
}

