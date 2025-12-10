<?php
/**
 * Query Executor
 * 
 * Safely executes SQL queries with read-only protection.
 */

if (!defined('ABSPATH')) {
    exit;
}

class DBQ_Query_Executor {
    
    /**
     * WordPress database instance
     */
    private $wpdb;
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }
    
    /**
     * Execute a SQL query safely (read-only)
     * 
     * @param string $sql SQL query
     * @return array|WP_Error Query results or error
     */
    public function execute_query($sql) {
        // Validate query is read-only
        $validation = $this->validate_query($sql);
        
        if (is_wp_error($validation)) {
            return $validation;
        }
        
        // Clean and prepare query
        $sql = trim($sql);
        
        // Remove trailing semicolon if present
        $sql = rtrim($sql, ';');
        
        // Execute query
        try {
            // Use get_results for SELECT queries
            $results = $this->wpdb->get_results($sql, ARRAY_A);
            
            // Check for database errors
            if ($this->wpdb->last_error) {
                return new WP_Error('db_error', 'Database error: ' . $this->wpdb->last_error);
            }
            
            return $results;
            
        } catch (Exception $e) {
            return new WP_Error('query_error', 'Query execution error: ' . $e->getMessage());
        }
    }
    
    /**
     * Validate that query is read-only
     * 
     * @param string $sql SQL query
     * @return bool|WP_Error True if valid, WP_Error if invalid
     */
    private function validate_query($sql) {
        // Normalize SQL
        $sql_upper = strtoupper(trim($sql));
        
        // Remove comments
        $sql_upper = preg_replace('/--.*$/m', '', $sql_upper);
        $sql_upper = preg_replace('/\/\*.*?\*\//s', '', $sql_upper);
        
        // Check for forbidden keywords
        $forbidden_keywords = array(
            'INSERT',
            'UPDATE',
            'DELETE',
            'DROP',
            'ALTER',
            'CREATE',
            'TRUNCATE',
            'REPLACE',
            'GRANT',
            'REVOKE',
            'EXEC',
            'EXECUTE',
            'CALL',
            'LOCK',
            'UNLOCK'
        );
        
        // Check if query starts with SELECT
        if (strpos($sql_upper, 'SELECT') !== 0) {
            return new WP_Error('invalid_query', 'Only SELECT queries are allowed. Query must start with SELECT.');
        }
        
        // Check for forbidden keywords
        foreach ($forbidden_keywords as $keyword) {
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $sql_upper)) {
                return new WP_Error('forbidden_keyword', "Query contains forbidden keyword: {$keyword}. Only SELECT queries are allowed.");
            }
        }
        
        // Check for semicolons that might indicate multiple statements
        $statements = explode(';', $sql_upper);
        if (count($statements) > 2) { // Allow one trailing semicolon
            return new WP_Error('multiple_statements', 'Multiple statements detected. Only single SELECT queries are allowed.');
        }
        
        // Additional safety: Check for function calls that might be dangerous
        $dangerous_functions = array(
            'LOAD_FILE',
            'INTO OUTFILE',
            'INTO DUMPFILE',
            'BENCHMARK',
            'SLEEP'
        );
        
        foreach ($dangerous_functions as $func) {
            if (stripos($sql_upper, $func) !== false) {
                return new WP_Error('dangerous_function', "Query contains potentially dangerous function: {$func}");
            }
        }
        
        return true;
    }
    
    /**
     * Get query execution time and row count
     * 
     * @param string $sql SQL query
     * @return array Execution stats
     */
    public function get_query_stats($sql) {
        $start_time = microtime(true);
        
        $results = $this->execute_query($sql);
        
        $end_time = microtime(true);
        $execution_time = round(($end_time - $start_time) * 1000, 2); // milliseconds
        
        $row_count = is_array($results) ? count($results) : 0;
        
        return array(
            'execution_time_ms' => $execution_time,
            'row_count' => $row_count,
            'has_error' => is_wp_error($results)
        );
    }
}

