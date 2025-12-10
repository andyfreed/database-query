<?php
/**
 * Admin Interface
 * 
 * Handles admin pages, settings, and UI.
 */

if (!defined('ABSPATH')) {
    exit;
}

class DBQ_Admin {
    
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
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Database Query AI', 'database-query'),
            __('DB Query AI', 'database-query'),
            'manage_options',
            'database-query-ai',
            array($this, 'render_chat_page'),
            'dashicons-database-view',
            30
        );
        
        add_submenu_page(
            'database-query-ai',
            __('Settings', 'database-query'),
            __('Settings', 'database-query'),
            'manage_options',
            'database-query-settings',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('dbq_settings', 'dbq_openai_api_key', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));
        
        register_setting('dbq_settings', 'dbq_openai_model', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'gpt-4'
        ));
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on our admin pages
        if (strpos($hook, 'database-query') === false) {
            return;
        }
        
        wp_enqueue_style(
            'dbq-admin-style',
            DBQ_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            DBQ_VERSION
        );
        
        wp_enqueue_script(
            'dbq-admin-script',
            DBQ_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            DBQ_VERSION,
            true
        );
        
        wp_localize_script('dbq-admin-script', 'dbqData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dbq_chat_nonce'),
            'strings' => array(
                'sending' => __('Sending...', 'database-query'),
                'error' => __('An error occurred. Please try again.', 'database-query'),
                'noApiKey' => __('OpenAI API key is not configured. Please set it in Settings.', 'database-query')
            )
        ));
    }
    
    /**
     * Render chat page
     */
    public function render_chat_page() {
        // Check API key
        $api_key = get_option('dbq_openai_api_key');
        $has_api_key = !empty($api_key);
        
        ?>
        <div class="wrap dbq-wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php if (!$has_api_key): ?>
                <div class="notice notice-error">
                    <p><?php _e('OpenAI API key is not configured. Please <a href="' . admin_url('admin.php?page=database-query-settings') . '">configure it in Settings</a> to use this feature.', 'database-query'); ?></p>
                </div>
            <?php endif; ?>
            
            <div class="dbq-chat-container">
                <div class="dbq-chat-messages" id="dbq-chat-messages">
                    <div class="dbq-welcome-message">
                        <h2><?php _e('Database Query AI Assistant', 'database-query'); ?></h2>
                        <p><?php _e('Ask me anything about your database! I can help you query data, find information, and analyze your WordPress database.', 'database-query'); ?></p>
                        <p><strong><?php _e('Examples:', 'database-query'); ?></strong></p>
                        <ul>
                            <li>"How many users are registered?"</li>
                            <li>"Show me all orders from last month"</li>
                            <li>"What are the most popular products?"</li>
                            <li>"List all posts published this year"</li>
                        </ul>
                        <p class="dbq-note"><em><?php _e('Note: This tool only performs read-only queries. No data will be modified.', 'database-query'); ?></em></p>
                    </div>
                </div>
                
                <div class="dbq-chat-input-container">
                    <form id="dbq-chat-form" class="dbq-chat-form">
                        <textarea 
                            id="dbq-chat-input" 
                            class="dbq-chat-input" 
                            placeholder="<?php esc_attr_e('Ask a question about your database...', 'database-query'); ?>"
                            rows="3"
                            <?php echo !$has_api_key ? 'disabled' : ''; ?>
                        ></textarea>
                        <button 
                            type="submit" 
                            class="button button-primary dbq-send-button"
                            <?php echo !$has_api_key ? 'disabled' : ''; ?>
                        >
                            <span class="dbq-button-text"><?php _e('Send', 'database-query'); ?></span>
                            <span class="dbq-spinner spinner" style="display: none;"></span>
                        </button>
                    </form>
                </div>
            </div>
            
            <div class="dbq-query-details" id="dbq-query-details" style="display: none;">
                <h3><?php _e('Query Details', 'database-query'); ?></h3>
                <div class="dbq-sql-query">
                    <strong><?php _e('SQL Query:', 'database-query'); ?></strong>
                    <pre id="dbq-sql-display"></pre>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (isset($_POST['submit']) && check_admin_referer('dbq_settings_nonce')) {
            update_option('dbq_openai_api_key', sanitize_text_field($_POST['dbq_openai_api_key']));
            update_option('dbq_openai_model', sanitize_text_field($_POST['dbq_openai_model']));
            
            echo '<div class="notice notice-success"><p>' . __('Settings saved!', 'database-query') . '</p></div>';
        }
        
        $api_key = get_option('dbq_openai_api_key', '');
        $model = get_option('dbq_openai_model', 'gpt-4');
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('dbq_settings_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="dbq_openai_api_key"><?php _e('OpenAI API Key', 'database-query'); ?></label>
                        </th>
                        <td>
                            <input 
                                type="password" 
                                id="dbq_openai_api_key" 
                                name="dbq_openai_api_key" 
                                value="<?php echo esc_attr($api_key); ?>" 
                                class="regular-text"
                                placeholder="sk-..."
                            />
                            <p class="description">
                                <?php _e('Enter your OpenAI API key. You can get one from <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI Platform</a>.', 'database-query'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="dbq_openai_model"><?php _e('OpenAI Model', 'database-query'); ?></label>
                        </th>
                        <td>
                            <select id="dbq_openai_model" name="dbq_openai_model">
                                <option value="gpt-4" <?php selected($model, 'gpt-4'); ?>>GPT-4</option>
                                <option value="gpt-4-turbo-preview" <?php selected($model, 'gpt-4-turbo-preview'); ?>>GPT-4 Turbo</option>
                                <option value="gpt-3.5-turbo" <?php selected($model, 'gpt-3.5-turbo'); ?>>GPT-3.5 Turbo</option>
                            </select>
                            <p class="description">
                                <?php _e('Select the OpenAI model to use. GPT-4 provides better accuracy but is slower and more expensive.', 'database-query'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <hr>
            
            <h2><?php _e('About This Plugin', 'database-query'); ?></h2>
            <p>
                <?php _e('This plugin allows you to query your WordPress database using natural language. The AI assistant understands your database structure and can help you find information quickly.', 'database-query'); ?>
            </p>
            <p>
                <strong><?php _e('Security:', 'database-query'); ?></strong>
                <?php _e('This plugin only executes SELECT queries. All write operations (INSERT, UPDATE, DELETE, etc.) are blocked for your safety.', 'database-query'); ?>
            </p>
        </div>
        <?php
    }
}

