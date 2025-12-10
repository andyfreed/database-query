# Database Query AI Assistant

A WordPress plugin that provides an AI-powered chatbot interface in the admin area for querying your database using natural language. The chatbot uses OpenAI API to understand your database structure and generate safe, read-only SQL queries.

## Features

- 🤖 **AI-Powered Queries**: Ask questions in natural language and get SQL queries generated automatically
- 🔒 **Read-Only Safety**: Only SELECT queries are allowed - no data modification possible
- 📊 **Database Schema Awareness**: The AI understands your complete database structure
- 💬 **Conversational Interface**: Chat-like interface for easy interaction
- ⚙️ **Configurable**: Set your OpenAI API key and choose your preferred model

## Installation

### Via WP Pusher

1. Install the [WP Pusher plugin](https://wppusher.com/) on your WordPress site
2. Connect your GitHub account in WP Pusher settings
3. Add this repository: `andyfreed/database-query`
4. The plugin will be automatically deployed

### Manual Installation

1. Download or clone this repository
2. Upload the `database-query` folder to `/wp-content/plugins/`
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Go to Settings → DB Query AI Settings and enter your OpenAI API key

## Configuration

1. Navigate to **DB Query AI → Settings** in your WordPress admin
2. Enter your OpenAI API key (get one from [OpenAI Platform](https://platform.openai.com/api-keys))
3. Select your preferred model (GPT-4 recommended for best accuracy)
4. Save settings

## Usage

1. Navigate to **DB Query AI** in your WordPress admin menu
2. Type your question in natural language, for example:
   - "How many users are registered?"
   - "Show me all orders from last month"
   - "What are the most popular products?"
   - "List all posts published this year"
3. The AI will generate and execute a SQL query, then provide you with a formatted response

## Security

- **Read-Only Queries**: Only SELECT queries are executed
- **Query Validation**: All queries are validated before execution
- **Forbidden Operations**: INSERT, UPDATE, DELETE, DROP, ALTER, and other write operations are blocked
- **Admin Only**: Only users with `manage_options` capability can use this plugin

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- OpenAI API key
- Admin access to WordPress

## Support

For issues, feature requests, or contributions, please visit the [GitHub repository](https://github.com/andyfreed/database-query).

## License

GPL v2 or later
