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
        $prompt = "You are a SQL query generator for a WordPress database. Your task is to convert natural language questions into safe, read-only SELECT queries.\n\n";
        
        $prompt .= "CRITICAL RULES:\n";
        $prompt .= "1. ONLY generate SELECT queries. NEVER generate INSERT, UPDATE, DELETE, DROP, ALTER, or any write operations.\n";
        $prompt .= "2. Use proper table prefixes. The WordPress table prefix is: " . $GLOBALS['wpdb']->prefix . "\n";
        $prompt .= "3. Always use prepared statements or proper escaping for any user input (though in this case, the query is generated from trusted input).\n";
        $prompt .= "4. Use proper JOINs when querying related tables.\n";
        $prompt .= "5. Return ONLY the SQL query, no explanations.\n\n";
        
        $prompt .= "DATABASE SCHEMA:\n\n";
        
        // Add table information summary
        $prompt .= "Database: {$schema['database_name']}\n";
        $prompt .= "WordPress Core Tables: " . count($schema['wordpress_core_tables']) . "\n";
        $prompt .= "Custom Tables: " . count($schema['custom_tables']) . "\n\n";
        
        // Add detailed table information
        $prompt .= "DETAILED TABLE INFORMATION:\n\n";
        foreach ($schema['tables'] as $table_name => $table_info) {
            $prompt .= "Table: {$table_name}\n";
            $prompt .= "- Row count: {$table_info['row_count']}\n";
            if ($table_info['primary_key']) {
                $prompt .= "- Primary key: {$table_info['primary_key']}\n";
            }
            $prompt .= "- Columns:\n";
            foreach ($table_info['columns'] as $column) {
                $prompt .= "  * {$column['name']} ({$column['type']})";
                if ($column['key'] === 'PRI') {
                    $prompt .= " [PRIMARY KEY]";
                } elseif ($column['key'] === 'MUL') {
                    $prompt .= " [INDEXED]";
                }
                if ($column['null'] === 'NO') {
                    $prompt .= " [NOT NULL]";
                }
                $prompt .= "\n";
            }
            
            if (isset($schema['table_descriptions'][$table_name])) {
                $prompt .= "- Description: {$schema['table_descriptions'][$table_name]}\n";
            }
            
            if (!empty($table_info['sample_data'])) {
                $prompt .= "- Sample data: " . json_encode($table_info['sample_data'], JSON_PRETTY_PRINT) . "\n";
            }
            
            $prompt .= "\n";
        }
        
        // Add relationships
        if (!empty($schema['table_relationships'])) {
            $prompt .= "TABLE RELATIONSHIPS:\n";
            foreach ($schema['table_relationships'] as $rel) {
                $prompt .= "- {$rel['from_table']}.{$rel['from_column']} references {$rel['to_table']}.{$rel['to_column']}\n";
            }
            $prompt .= "\n";
        }
        
        $prompt .= "Remember: Only generate SELECT queries. Never modify data.";
        
        return $prompt;
    }
}

