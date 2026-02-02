<?php
/**
 * Example: Index WordPress content to Qdrant
 *
 * Run via WP-CLI: wp eval-file examples/index-wordpress-content.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use DigitalNode\WPQdrantIndexer\Config;
use DigitalNode\WPQdrantIndexer\Indexer;

// Create configuration
$config = new Config([
    'openai_api_key' => env('OPENAI_API_KEY'),
    'qdrant_url' => env('QDRANT_URL'),
    'qdrant_api_key' => env('QDRANT_API_KEY'),
    'collection_name' => 'my_wordpress_docs',
    'batch_size' => 50,
    'chunk_size' => 3000,
    'enable_cache' => true,
]);

// Register post types to index
// Option 1: Simple post types (uses default extraction)
$config->registerPostType('post');
$config->registerPostType('page');

// Option 2: Post type with specific ACF fields
$config->registerPostType('product', [
    'product_code',
    'listing_content',
    'attributes',
    'product_group',
    'solutions',
]);

// Option 3: Post type with custom extractor function
$config->registerPostType('custom_type', [], function(\WP_Post $post) {
    $parts = [];

    $parts[] = "Title: " . $post->post_title;
    $parts[] = strip_tags($post->post_content);

    // Custom ACF extraction
    if (function_exists('get_field')) {
        $custom_field = get_field('my_custom_field', $post->ID);
        if ($custom_field) {
            $parts[] = "Custom: " . $custom_field;
        }
    }

    return implode("\n\n", array_filter($parts));
});

// Create indexer
$indexer = new Indexer($config);

// Run indexing
$result = $indexer->index(true); // true = recreate collection

if ($result['success']) {
    echo "✅ Indexing completed successfully!\n";
    echo "   Chunks indexed: {$result['chunks']}\n";
    echo "   Time elapsed: {$result['time']}s\n";
    echo "   Cache hit rate: {$result['stats']['cache_hit_rate']}%\n";
} else {
    echo "❌ Indexing failed: {$result['message']}\n";
}
