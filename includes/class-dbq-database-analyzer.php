<?php
/**
 * Database Schema Analyzer
 * 
 * Analyzes the WordPress database schema and provides detailed information
 * about tables, columns, relationships, and data types for the AI to use.
 */

if (!defined('ABSPATH')) {
    exit;
}

class DBQ_Database_Analyzer {
    
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
     * Get full database schema
     * 
     * @return array Schema information
     */
    public function get_full_schema() {
        $schema = array(
            'database_name' => DB_NAME,
            'tables' => array(),
            'wordpress_core_tables' => $this->get_wp_core_tables(),
            'custom_tables' => array(),
            'table_relationships' => array(),
            'table_descriptions' => $this->get_table_descriptions()
        );
        
        // Get all tables
        $tables = $this->get_all_tables();
        
        foreach ($tables as $table) {
            $table_info = $this->analyze_table($table);
            $schema['tables'][$table] = $table_info;
            
            // Categorize tables
            if ($this->is_wp_core_table($table)) {
                $schema['wordpress_core_tables'][] = $table;
            } else {
                $schema['custom_tables'][] = $table;
            }
        }
        
        // Analyze relationships
        $schema['table_relationships'] = $this->analyze_relationships($schema['tables']);
        
        return $schema;
    }
    
    /**
     * Get all tables in the database
     * 
     * @return array Table names
     */
    private function get_all_tables() {
        $tables = array();
        $prefix = $this->wpdb->prefix;
        
        $query = "SHOW TABLES LIKE '{$prefix}%'";
        $results = $this->wpdb->get_results($query, ARRAY_N);
        
        foreach ($results as $row) {
            $tables[] = $row[0];
        }
        
        return $tables;
    }
    
    /**
     * Analyze a specific table
     * 
     * @param string $table_name Table name
     * @return array Table information
     */
    private function analyze_table($table_name) {
        $info = array(
            'name' => $table_name,
            'columns' => array(),
            'primary_key' => null,
            'indexes' => array(),
            'row_count' => 0,
            'engine' => null,
            'charset' => null
        );
        
        // Get table structure
        $columns = $this->wpdb->get_results("DESCRIBE `{$table_name}`", ARRAY_A);
        
        foreach ($columns as $column) {
            $col_info = array(
                'name' => $column['Field'],
                'type' => $column['Type'],
                'null' => $column['Null'],
                'key' => $column['Key'],
                'default' => $column['Default'],
                'extra' => $column['Extra']
            );
            
            $info['columns'][] = $col_info;
            
            // Identify primary key
            if ($column['Key'] === 'PRI') {
                $info['primary_key'] = $column['Field'];
            }
        }
        
        // Get indexes
        $indexes = $this->wpdb->get_results("SHOW INDEXES FROM `{$table_name}`", ARRAY_A);
        foreach ($indexes as $index) {
            if (!in_array($index['Key_name'], array_column($info['indexes'], 'name'))) {
                $info['indexes'][] = array(
                    'name' => $index['Key_name'],
                    'column' => $index['Column_name'],
                    'unique' => $index['Non_unique'] == 0
                );
            }
        }
        
        // Get row count
        $row_count = $this->wpdb->get_var("SELECT COUNT(*) FROM `{$table_name}`");
        $info['row_count'] = (int) $row_count;
        
        // Get table engine and charset
        $table_info = $this->wpdb->get_row("SHOW TABLE STATUS LIKE '{$table_name}'", ARRAY_A);
        if ($table_info) {
            $info['engine'] = $table_info['Engine'] ?? null;
            $info['charset'] = $table_info['Collation'] ?? null;
        }
        
        // Get sample data (first row, limited columns)
        $info['sample_data'] = $this->get_sample_data($table_name);
        
        return $info;
    }
    
    /**
     * Get sample data from a table
     * 
     * @param string $table_name Table name
     * @return array Sample data
     */
    private function get_sample_data($table_name) {
        $sample = array();
        
        // Get first row with limited columns to avoid huge data
        $columns = $this->wpdb->get_col("DESCRIBE `{$table_name}`");
        $limit_columns = array_slice($columns, 0, 10); // Limit to first 10 columns
        
        if (!empty($limit_columns)) {
            $columns_str = '`' . implode('`, `', $limit_columns) . '`';
            $row = $this->wpdb->get_row("SELECT {$columns_str} FROM `{$table_name}` LIMIT 1", ARRAY_A);
            
            if ($row) {
                // Truncate long values for display
                foreach ($row as $key => $value) {
                    if (is_string($value) && strlen($value) > 100) {
                        $row[$key] = substr($value, 0, 100) . '...';
                    }
                }
                $sample = $row;
            }
        }
        
        return $sample;
    }
    
    /**
     * Analyze relationships between tables
     * 
     * @param array $tables Table information
     * @return array Relationship information
     */
    private function analyze_relationships($tables) {
        $relationships = array();
        
        foreach ($tables as $table_name => $table_info) {
            foreach ($table_info['columns'] as $column) {
                $col_name = $column['name'];
                
                // Look for foreign key patterns (e.g., user_id, post_id, order_id)
                if (preg_match('/^(.+)_id$/', $col_name, $matches)) {
                    $referenced_table = $matches[1];
                    
                    // Check if referenced table exists
                    $full_table_name = $this->wpdb->prefix . $referenced_table;
                    if (isset($tables[$full_table_name])) {
                        $relationships[] = array(
                            'from_table' => $table_name,
                            'from_column' => $col_name,
                            'to_table' => $full_table_name,
                            'to_column' => 'ID', // WordPress convention
                            'type' => 'likely_foreign_key'
                        );
                    }
                }
            }
        }
        
        return $relationships;
    }
    
    /**
     * Get WordPress core tables
     * 
     * @return array Core table names
     */
    private function get_wp_core_tables() {
        return array(
            $this->wpdb->posts,
            $this->wpdb->postmeta,
            $this->wpdb->users,
            $this->wpdb->usermeta,
            $this->wpdb->comments,
            $this->wpdb->commentmeta,
            $this->wpdb->terms,
            $this->wpdb->term_taxonomy,
            $this->wpdb->term_relationships,
            $this->wpdb->options
        );
    }
    
    /**
     * Check if table is a WordPress core table
     * 
     * @param string $table_name Table name
     * @return bool
     */
    private function is_wp_core_table($table_name) {
        $core_tables = $this->get_wp_core_tables();
        return in_array($table_name, $core_tables);
    }
    
    /**
     * Get table descriptions for common WordPress and plugin tables
     * 
     * @return array Table descriptions
     */
    private function get_table_descriptions() {
        $descriptions = array(
            // WordPress Core
            $this->wpdb->posts => 'Stores all posts, pages, and custom post types. Key columns: ID (primary key), post_title, post_content, post_status, post_type, post_date.',
            $this->wpdb->postmeta => 'Stores metadata for posts. Key columns: meta_id (primary key), post_id (foreign key to posts), meta_key, meta_value.',
            $this->wpdb->users => 'Stores user accounts. Key columns: ID (primary key), user_login, user_email, user_registered.',
            $this->wpdb->usermeta => 'Stores user metadata. Key columns: umeta_id (primary key), user_id (foreign key to users), meta_key, meta_value.',
            $this->wpdb->comments => 'Stores comments. Key columns: comment_ID (primary key), comment_post_ID (foreign key to posts), comment_author, comment_content.',
            $this->wpdb->commentmeta => 'Stores comment metadata. Key columns: meta_id (primary key), comment_id (foreign key to comments), meta_key, meta_value.',
            $this->wpdb->terms => 'Stores taxonomy terms. Key columns: term_id (primary key), name, slug.',
            $this->wpdb->term_taxonomy => 'Stores term taxonomy relationships. Key columns: term_taxonomy_id (primary key), term_id (foreign key to terms), taxonomy.',
            $this->wpdb->term_relationships => 'Stores relationships between posts and terms. Key columns: object_id (foreign key to posts), term_taxonomy_id (foreign key to term_taxonomy).',
            $this->wpdb->options => 'Stores WordPress options and settings. Key columns: option_id (primary key), option_name, option_value.',
        );
        
        // WooCommerce tables
        if (class_exists('WooCommerce')) {
            $descriptions[$this->wpdb->prefix . 'woocommerce_sessions'] = 'Stores WooCommerce session data.';
            $descriptions[$this->wpdb->prefix . 'woocommerce_api_keys'] = 'Stores WooCommerce API keys.';
            $descriptions[$this->wpdb->prefix . 'woocommerce_attribute_taxonomies'] = 'Stores WooCommerce product attribute taxonomies.';
            $descriptions[$this->wpdb->prefix . 'woocommerce_downloadable_product_permissions'] = 'Stores downloadable product permissions.';
            $descriptions[$this->wpdb->prefix . 'woocommerce_order_items'] = 'Stores WooCommerce order items. Key columns: order_item_id (primary key), order_id (foreign key to posts where post_type = shop_order).';
            $descriptions[$this->wpdb->prefix . 'woocommerce_order_itemmeta'] = 'Stores metadata for order items. Key columns: meta_id (primary key), order_item_id (foreign key to woocommerce_order_items), meta_key, meta_value.';
            $descriptions[$this->wpdb->prefix . 'woocommerce_tax_rates'] = 'Stores tax rates.';
            $descriptions[$this->wpdb->prefix . 'woocommerce_tax_rate_locations'] = 'Stores tax rate locations.';
            $descriptions[$this->wpdb->prefix . 'woocommerce_shipping_zones'] = 'Stores shipping zones.';
            $descriptions[$this->wpdb->prefix . 'woocommerce_shipping_zone_locations'] = 'Stores shipping zone locations.';
            $descriptions[$this->wpdb->prefix . 'woocommerce_shipping_zone_methods'] = 'Stores shipping zone methods.';
            $descriptions[$this->wpdb->prefix . 'woocommerce_payment_tokens'] = 'Stores payment tokens.';
            $descriptions[$this->wpdb->prefix . 'woocommerce_payment_tokenmeta'] = 'Stores payment token metadata.';
        }
        
        return $descriptions;
    }
    
    /**
     * Get schema summary as text for AI context
     * 
     * @return string Schema summary
     */
    public function get_schema_summary() {
        $schema = $this->get_full_schema();
        $summary = "Database Schema for: {$schema['database_name']}\n\n";
        
        $summary .= "WordPress Core Tables:\n";
        foreach ($schema['wordpress_core_tables'] as $table) {
            if (isset($schema['tables'][$table])) {
                $table_info = $schema['tables'][$table];
                $summary .= "- {$table}: {$table_info['row_count']} rows. ";
                if (isset($schema['table_descriptions'][$table])) {
                    $summary .= $schema['table_descriptions'][$table];
                }
                $summary .= "\n";
            }
        }
        
        $summary .= "\nCustom Tables:\n";
        foreach ($schema['custom_tables'] as $table) {
            if (isset($schema['tables'][$table])) {
                $table_info = $schema['tables'][$table];
                $summary .= "- {$table}: {$table_info['row_count']} rows\n";
            }
        }
        
        $summary .= "\nTable Relationships:\n";
        foreach ($schema['table_relationships'] as $rel) {
            $summary .= "- {$rel['from_table']}.{$rel['from_column']} -> {$rel['to_table']}.{$rel['to_column']}\n";
        }
        
        return $summary;
    }
    
    /**
     * Get all unique meta keys from usermeta table
     * 
     * @return array Array of unique meta keys
     */
    public function get_usermeta_keys() {
        $query = "SELECT DISTINCT meta_key FROM {$this->wpdb->usermeta} WHERE meta_key != '' ORDER BY meta_key";
        $results = $this->wpdb->get_col($query);
        return $results ? $results : array();
    }
    
    /**
     * Get all unique meta keys from postmeta table
     * 
     * @return array Array of unique meta keys
     */
    public function get_postmeta_keys() {
        $query = "SELECT DISTINCT meta_key FROM {$this->wpdb->postmeta} WHERE meta_key != '' ORDER BY meta_key";
        $results = $this->wpdb->get_col($query);
        return $results ? $results : array();
    }
    
    /**
     * Get all column names for a table
     * 
     * @param string $table_name Table name
     * @return array Array of column names
     */
    public function get_table_columns($table_name) {
        $columns = $this->wpdb->get_col("DESCRIBE `{$table_name}`");
        return $columns ? $columns : array();
    }
    
    /**
     * Discover meta keys related to search terms
     * 
     * @param array $search_terms Array of search terms
     * @return array Discovered meta keys with counts
     */
    public function discover_meta_keys($search_terms) {
        $discovered = array(
            'usermeta' => array(),
            'postmeta' => array()
        );
        
        foreach ($search_terms as $term) {
            // Search usermeta
            $user_query = $this->wpdb->prepare(
                "SELECT DISTINCT meta_key, COUNT(*) as count 
                 FROM {$this->wpdb->usermeta} 
                 WHERE meta_key != '' AND meta_key LIKE %s 
                 GROUP BY meta_key 
                 ORDER BY count DESC 
                 LIMIT 20",
                '%' . $this->wpdb->esc_like($term) . '%'
            );
            $user_results = $this->wpdb->get_results($user_query, ARRAY_A);
            
            if ($user_results) {
                foreach ($user_results as $row) {
                    $key = $row['meta_key'];
                    if (!isset($discovered['usermeta'][$key])) {
                        $discovered['usermeta'][$key] = array(
                            'meta_key' => $key,
                            'count' => (int) $row['count'],
                            'search_term' => $term
                        );
                    }
                }
            }
            
            // Search postmeta
            $post_query = $this->wpdb->prepare(
                "SELECT DISTINCT meta_key, COUNT(*) as count 
                 FROM {$this->wpdb->postmeta} 
                 WHERE meta_key != '' AND meta_key LIKE %s 
                 GROUP BY meta_key 
                 ORDER BY count DESC 
                 LIMIT 20",
                '%' . $this->wpdb->esc_like($term) . '%'
            );
            $post_results = $this->wpdb->get_results($post_query, ARRAY_A);
            
            if ($post_results) {
                foreach ($post_results as $row) {
                    $key = $row['meta_key'];
                    if (!isset($discovered['postmeta'][$key])) {
                        $discovered['postmeta'][$key] = array(
                            'meta_key' => $key,
                            'count' => (int) $row['count'],
                            'search_term' => $term
                        );
                    }
                }
            }
        }
        
        return $discovered;
    }
    
    /**
     * Detect value format for a specific meta_key
     * 
     * @param string $meta_key Meta key to analyze
     * @param string $table_type 'usermeta' or 'postmeta'
     * @return array Value samples and format info
     */
    public function detect_value_format($meta_key, $table_type = 'usermeta') {
        $table = ($table_type === 'postmeta') ? $this->wpdb->postmeta : $this->wpdb->usermeta;
        $id_column = ($table_type === 'postmeta') ? 'post_id' : 'user_id';
        
        // Get value samples
        $query = $this->wpdb->prepare(
            "SELECT meta_value, COUNT(*) as count 
             FROM {$table} 
             WHERE meta_key = %s 
             GROUP BY meta_value 
             ORDER BY count DESC 
             LIMIT 20",
            $meta_key
        );
        $samples = $this->wpdb->get_results($query, ARRAY_A);
        
        $total_count = 0;
        $non_empty_count = 0;
        $value_formats = array();
        
        if ($samples) {
            foreach ($samples as $sample) {
                $value = $sample['meta_value'];
                $count = (int) $sample['count'];
                $total_count += $count;
                
                if (!empty($value)) {
                    $non_empty_count += $count;
                    $value_formats[] = array(
                        'value' => $value,
                        'count' => $count,
                        'length' => strlen($value)
                    );
                }
            }
        }
        
        // Detect common patterns
        $patterns = array();
        if ($value_formats) {
            $checked_values = array();
            foreach ($value_formats as $vf) {
                $val = strtolower(trim($vf['value']));
                if (in_array($val, array('1', 'yes', 'true', 'checked', 'on', 'active'))) {
                    $checked_values[] = $vf['value'];
                }
            }
            if (!empty($checked_values)) {
                $patterns['checkbox_checked'] = array_unique($checked_values);
            }
        }
        
        return array(
            'meta_key' => $meta_key,
            'table_type' => $table_type,
            'value_samples' => $value_formats,
            'total_rows' => $total_count,
            'non_empty_rows' => $non_empty_count,
            'patterns' => $patterns,
            'unique_value_count' => count($value_formats)
        );
    }
    
    /**
     * Extract search terms from user query
     * 
     * @param string $query User query text
     * @return array Extracted search terms
     */
    public function extract_search_terms($query) {
        // Convert to lowercase for matching
        $lower_query = strtolower($query);
        
        $terms = array();
        
        // Common license-related terms
        if (preg_match('/\b(iar|license|licence)\b/i', $query)) {
            $terms[] = 'iar';
            $terms[] = 'license';
        }
        
        // Extract quoted terms
        if (preg_match_all('/["\']([^"\']+)["\']/', $query, $matches)) {
            foreach ($matches[1] as $match) {
                $terms[] = strtolower($match);
            }
        }
        
        // Extract capitalized words (likely field names)
        if (preg_match_all('/\b([A-Z][A-Z_]+)\b/', $query, $matches)) {
            foreach ($matches[1] as $match) {
                $terms[] = strtolower($match);
            }
        }
        
        // Extract common field name patterns
        if (preg_match_all('/\b(\w+_license|\w+_iar|license_\w+|iar_\w+)\b/i', $query, $matches)) {
            foreach ($matches[1] as $match) {
                $terms[] = strtolower($match);
            }
        }
        
        // Remove duplicates and empty values
        $terms = array_unique(array_filter($terms));
        
        // If no specific terms found, use keywords from query
        if (empty($terms)) {
            $keywords = explode(' ', $lower_query);
            $keywords = array_filter($keywords, function($word) {
                return strlen($word) > 3 && !in_array($word, array('the', 'and', 'for', 'with', 'that', 'this', 'have', 'users', 'user', 'show', 'get', 'list', 'find', 'where', 'email', 'name'));
            });
            $terms = array_slice($keywords, 0, 5);
        }
        
        return array_values($terms);
    }
}

