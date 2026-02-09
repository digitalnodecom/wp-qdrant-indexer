<?php
/**
 * Plugin Name: WP Qdrant Indexer
 * Plugin URI: https://github.com/digitalnodecom/wp-qdrant-indexer
 * Description: A flexible WordPress content indexer for Qdrant vector database with OpenAI embeddings, intelligent caching, and RAG capabilities using multiple LLM providers.
 * Version: 1.0.0
 * Author: Nikola Anastasovski
 * Author URI: https://digitalnode.com
 * License: MIT
 * Requires PHP: 8.1
 * Requires at least: 6.0
 *
 * @package DigitalNode\WPQdrantIndexer
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WP_QDRANT_INDEXER_VERSION', '1.0.0');
define('WP_QDRANT_INDEXER_PATH', plugin_dir_path(__FILE__));
define('WP_QDRANT_INDEXER_URL', plugin_dir_url(__FILE__));

// Autoload classes - check multiple locations
// 1. Plugin's own vendor (standalone installation with composer install in plugin dir)
// 2. Bedrock project vendor (when installed via project's composer.json)
// 3. Standard WordPress with Composer
$autoload_paths = [
    __DIR__ . '/vendor/autoload.php',           // Plugin vendor
    dirname(ABSPATH, 2) . '/vendor/autoload.php', // Bedrock project vendor
    ABSPATH . '../vendor/autoload.php',          // Standard WP with Composer
];

foreach ($autoload_paths as $autoload_path) {
    if (file_exists($autoload_path)) {
        require_once $autoload_path;
        break;
    }
}

// Make classes available for use by other plugins/themes
// Usage:
//   use DigitalNode\WPQdrantIndexer\Config;
//   use DigitalNode\WPQdrantIndexer\Indexer;
//   use DigitalNode\WPQdrantIndexer\RAGEngine;
//
// Example:
//   $config = new Config([
//       'openai_api_key' => 'sk-...',
//       'qdrant_url' => 'https://...',
//       'qdrant_api_key' => '...',
//       'collection_name' => 'my_docs',
//   ]);
//
//   $indexer = new Indexer($config);
//   $indexer->index();

/**
 * Register WP-CLI commands if WP-CLI is available
 */
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('qdrant', DigitalNode\WPQdrantIndexer\CLI::class);
}

/**
 * Plugin activation hook
 */
register_activation_hook(__FILE__, function () {
    // Nothing to do on activation for now
    // The plugin is designed to be used as a library by other plugins
});

/**
 * Plugin deactivation hook
 */
register_deactivation_hook(__FILE__, function () {
    // Nothing to do on deactivation
});
