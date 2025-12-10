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
        
        $prompt = "You are a SQL query generator for WordPress. Generate ONLY SELECT queries. Table prefix: {$prefix}\n\n";
        
        // Key tables with essential columns only
        $key_tables = array(
            $wpdb->users => array(
                'ID' => 'primary key',
                'user_login' => 'username',
                'user_email' => 'email address',
                'user_nicename' => 'display name',
                'display_name' => 'display name',
                'user_registered' => 'registration date'
            ),
            $wpdb->usermeta => array(
                'umeta_id' => 'primary key',
                'user_id' => 'foreign key to users.ID',
                'meta_key' => 'metadata key (e.g., first_name, last_name, license fields)',
                'meta_value' => 'metadata value'
            ),
            $wpdb->posts => array(
                'ID' => 'primary key',
                'post_author' => 'foreign key to users.ID',
                'post_title' => 'title',
                'post_type' => 'post type (e.g., shop_order, product)',
                'post_status' => 'status',
                'post_date' => 'date'
            ),
            $wpdb->postmeta => array(
                'meta_id' => 'primary key',
                'post_id' => 'foreign key to posts.ID',
                'meta_key' => 'metadata key',
                'meta_value' => 'metadata value'
            )
        );
        
        // Add key table info
        $prompt .= "KEY TABLES:\n";
        foreach ($key_tables as $table_name => $columns) {
            if (isset($schema['tables'][$table_name])) {
                $table_info = $schema['tables'][$table_name];
                $prompt .= "{$table_name}: {$table_info['row_count']} rows. ";
                $prompt .= "Key columns: " . implode(', ', array_keys($columns)) . "\n";
                foreach ($columns as $col => $desc) {
                    $prompt .= "  - {$col}: {$desc}\n";
                }
                $prompt .= "\n";
            }
        }
        
        // Add important custom tables (limit to 10 most relevant)
        $custom_count = 0;
        if (!empty($schema['custom_tables'])) {
            $prompt .= "CUSTOM TABLES:\n";
            foreach (array_slice($schema['custom_tables'], 0, 10) as $table_name) {
                if (isset($schema['tables'][$table_name])) {
                    $table_info = $schema['tables'][$table_name];
                    $prompt .= "{$table_name} ({$table_info['row_count']} rows)";
                    if ($table_info['primary_key']) {
                        $prompt .= " PK: {$table_info['primary_key']}";
                    }
                    // Only show first 5 columns
                    $cols = array_slice($table_info['columns'], 0, 5);
                    $col_names = array_map(function($c) { return $c['name']; }, $cols);
                    $prompt .= " Columns: " . implode(', ', $col_names);
                    if (count($table_info['columns']) > 5) {
                        $prompt .= ", ...";
                    }
                    $prompt .= "\n";
                    $custom_count++;
                    if ($custom_count >= 10) break;
                }
            }
            $prompt .= "\n";
        }
        
        // Key relationships only
        $prompt .= "KEY RELATIONSHIPS:\n";
        $prompt .= "- {$wpdb->usermeta}.user_id -> {$wpdb->users}.ID\n";
        $prompt .= "- {$wpdb->posts}.post_author -> {$wpdb->users}.ID\n";
        $prompt .= "- {$wpdb->postmeta}.post_id -> {$wpdb->posts}.ID\n";
        if (!empty($schema['table_relationships'])) {
            // Limit to first 10 relationships
            foreach (array_slice($schema['table_relationships'], 0, 10) as $rel) {
                if (strpos($rel['from_table'], $prefix) === 0) {
                    $prompt .= "- {$rel['from_table']}.{$rel['from_column']} -> {$rel['to_table']}.{$rel['to_column']}\n";
                }
            }
        }
        $prompt .= "\n";
        
        // Important note about usermeta
        $prompt .= "NOTE: User metadata is stored in {$wpdb->usermeta} with meta_key and meta_value. ";
        $prompt .= "To query user metadata, join {$wpdb->users} with {$wpdb->usermeta} WHERE user_id matches.\n";
        $prompt .= "Example: SELECT u.*, um.meta_value FROM {$wpdb->users} u ";
        $prompt .= "JOIN {$wpdb->usermeta} um ON u.ID = um.user_id WHERE um.meta_key = 'first_name';\n\n";
        
        $prompt .= "CRITICAL: Only SELECT queries. Return ONLY SQL, no explanations.";
        
        return $prompt;
    }
}

