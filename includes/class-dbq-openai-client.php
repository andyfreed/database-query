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
     * @return string|WP_Error SQL query or error
     */
    public function generate_sql_query($user_query, $schema, $conversation_history = array()) {
        // Build system prompt
        $system_prompt = $this->build_sql_generation_prompt($schema);
        
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
        $user_message .= "Format this into a clear, natural language response for the user.";
        
        $messages[] = array(
            'role' => 'user',
            'content' => $user_message
        );
        
        return $this->make_request($messages);
    }
    
    /**
     * Build SQL generation system prompt
     * 
     * @param array $schema Database schema
     * @return string System prompt
     */
    private function build_sql_generation_prompt($schema) {
        global $wpdb;
        $prefix = $wpdb->prefix;
        
        // Get meta keys from usermeta and postmeta
        $analyzer = new DBQ_Database_Analyzer();
        $usermeta_keys = $analyzer->get_usermeta_keys();
        $postmeta_keys = $analyzer->get_postmeta_keys();
        
        $prompt = "You are a SQL query generator for WordPress. Generate ONLY SELECT queries. Table prefix: {$prefix}\n\n";
        
        // Add ALL tables with ALL columns - this is crucial for the AI to understand the full structure
        $prompt .= "COMPLETE DATABASE STRUCTURE:\n\n";
        
        foreach ($schema['tables'] as $table_name => $table_info) {
            $prompt .= "TABLE: {$table_name} ({$table_info['row_count']} rows)\n";
            if ($table_info['primary_key']) {
                $prompt .= "Primary Key: {$table_info['primary_key']}\n";
            }
            $prompt .= "Columns: ";
            $column_list = array();
            foreach ($table_info['columns'] as $column) {
                $col_desc = "{$column['name']} ({$column['type']}";
                if ($column['null'] === 'NO') {
                    $col_desc .= ", NOT NULL";
                }
                if ($column['key'] === 'PRI') {
                    $col_desc .= ", PRIMARY KEY";
                } elseif ($column['key'] === 'MUL') {
                    $col_desc .= ", INDEXED";
                }
                $col_desc .= ")";
                $column_list[] = $col_desc;
            }
            $prompt .= implode(", ", $column_list) . "\n\n";
        }
        
        // Add all meta keys from usermeta - this is critical for finding license fields, etc.
        if (!empty($usermeta_keys)) {
            $prompt .= "USERMETA META_KEYS (all available user metadata fields - use these exact names in queries):\n";
            // Show all meta_keys, but in a compact format
            // If too many, show first 200 most common ones
            $keys_to_show = count($usermeta_keys) > 200 ? array_slice($usermeta_keys, 0, 200) : $usermeta_keys;
            $prompt .= implode(', ', $keys_to_show);
            if (count($usermeta_keys) > 200) {
                $prompt .= "\n... and " . (count($usermeta_keys) - 200) . " more (total: " . count($usermeta_keys) . " unique keys)";
            }
            $prompt .= "\n\n";
        }
        
        // Add all meta keys from postmeta
        if (!empty($postmeta_keys)) {
            $prompt .= "POSTMETA META_KEYS (available post metadata fields):\n";
            $keys_to_show = count($postmeta_keys) > 200 ? array_slice($postmeta_keys, 0, 200) : $postmeta_keys;
            $prompt .= implode(', ', $keys_to_show);
            if (count($postmeta_keys) > 200) {
                $prompt .= "\n... and " . (count($postmeta_keys) - 200) . " more (total: " . count($postmeta_keys) . " unique keys)";
            }
            $prompt .= "\n\n";
        }
        
        // Add relationships
        $prompt .= "TABLE RELATIONSHIPS:\n";
        $prompt .= "- {$wpdb->usermeta}.user_id -> {$wpdb->users}.ID\n";
        $prompt .= "- {$wpdb->posts}.post_author -> {$wpdb->users}.ID\n";
        $prompt .= "- {$wpdb->postmeta}.post_id -> {$wpdb->posts}.ID\n";
        if (!empty($schema['table_relationships'])) {
            foreach ($schema['table_relationships'] as $rel) {
                if (strpos($rel['from_table'], $prefix) === 0) {
                    $prompt .= "- {$rel['from_table']}.{$rel['from_column']} -> {$rel['to_table']}.{$rel['to_column']}\n";
                }
            }
        }
        $prompt .= "\n";
        
        // Important examples
        $prompt .= "EXAMPLES:\n";
        $prompt .= "To get user email and first_name/last_name from usermeta:\n";
        $prompt .= "SELECT u.user_email, um1.meta_value AS first_name, um2.meta_value AS last_name ";
        $prompt .= "FROM {$wpdb->users} u ";
        $prompt .= "JOIN {$wpdb->usermeta} um1 ON u.ID = um1.user_id AND um1.meta_key = 'first_name' ";
        $prompt .= "JOIN {$wpdb->usermeta} um2 ON u.ID = um2.user_id AND um2.meta_key = 'last_name';\n\n";
        
        $prompt .= "To filter by a meta_key value (e.g., IAR_license = 'checked' or '1' or 'yes'):\n";
        $prompt .= "JOIN {$wpdb->usermeta} um3 ON u.ID = um3.user_id ";
        $prompt .= "WHERE um3.meta_key = 'IAR_license' AND (um3.meta_value = 'checked' OR um3.meta_value = '1' OR um3.meta_value = 'yes');\n\n";
        
        $prompt .= "IMPORTANT: When searching for metadata fields:\n";
        $prompt .= "- ALWAYS check the USERMETA META_KEYS list above to find the EXACT field name\n";
        $prompt .= "- Field names are case-sensitive and may vary (e.g., 'IAR_license', 'iar_license', 'IAR license', 'license_IAR')\n";
        $prompt .= "- Values may be stored as '1', 'yes', 'true', 'checked', 'on', or other formats\n";
        $prompt .= "- If the field name isn't obvious, search in the meta_keys list for variations or use LIKE queries\n";
        $prompt .= "- When filtering by checkbox values, try: meta_value IN ('1', 'yes', 'true', 'checked', 'on')\n\n";
        
        $prompt .= "CRITICAL: Only SELECT queries. Return ONLY SQL, no explanations.";
        
        return $prompt;
    }
}

