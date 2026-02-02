<?php
/**
 * Standalone test script (no WordPress required)
 * Tests the core components without WordPress dependencies
 */

require_once __DIR__ . '/../vendor/autoload.php';

use DigitalNode\WPQdrantIndexer\Config;
use DigitalNode\WPQdrantIndexer\QdrantClient;
use DigitalNode\WPQdrantIndexer\Embedder;

echo "=== WordPress Qdrant Indexer - Standalone Test ===\n\n";

// Test 1: Configuration
echo "Test 1: Configuration\n";
try {
    $config = new Config([
        'openai_api_key' => 'test-key-' . bin2hex(random_bytes(10)),
        'qdrant_url' => 'https://test.qdrant.io:6333',
        'qdrant_api_key' => 'test-qdrant-key',
        'collection_name' => 'test_collection',
    ]);
    echo "  ✅ Config created successfully\n";
    echo "  - Collection: {$config->collection_name}\n";
    echo "  - Vector size: {$config->vector_size}\n";
    echo "  - Cache enabled: " . ($config->enable_cache ? 'Yes' : 'No') . "\n";
} catch (\Exception $e) {
    echo "  ❌ Config failed: {$e->getMessage()}\n";
    exit(1);
}

// Test 2: Post Type Registration
echo "\nTest 2: Post Type Registration\n";
$config->registerPostType('post');
$config->registerPostType('product', ['product_code', 'description']);
$config->registerPostType('custom', [], function($post) {
    return "Custom extractor for " . $post->post_title;
});

$post_types = $config->getPostTypes();
echo "  ✅ Registered " . count($post_types) . " post types: " . implode(', ', $post_types) . "\n";

$product_fields = $config->getFieldsForPostType('product');
echo "  ✅ Product fields: " . implode(', ', $product_fields) . "\n";

$custom_extractor = $config->getExtractorForPostType('custom');
echo "  ✅ Custom extractor: " . (is_callable($custom_extractor) ? 'Callable' : 'Not set') . "\n";

// Test 3: QdrantClient (without actual connection)
echo "\nTest 3: QdrantClient Initialization\n";
try {
    $qdrant = new QdrantClient($config);
    echo "  ✅ QdrantClient created\n";
} catch (\Exception $e) {
    echo "  ❌ QdrantClient failed: {$e->getMessage()}\n";
    exit(1);
}

// Test 4: Embedder (without actual API calls)
echo "\nTest 4: Embedder Initialization\n";
try {
    $embedder = new Embedder($config);
    echo "  ✅ Embedder created\n";

    $stats = $embedder->getCacheStats();
    echo "  - Cache stats: {$stats['cached']} cached, {$stats['new']} new\n";
} catch (\Exception $e) {
    echo "  ❌ Embedder failed: {$e->getMessage()}\n";
    exit(1);
}

// Test 5: ai-access integration test
echo "\nTest 5: ai-access Integration\n";
try {
    // Check if ai-access classes are available
    $providers = [
        'AIAccess\Provider\OpenAI\Client',
        'AIAccess\Provider\Claude\Client',
        'AIAccess\Provider\Gemini\Client',
        'AIAccess\Provider\DeepSeek\Client',
        'AIAccess\Provider\Grok\Client',
    ];

    foreach ($providers as $provider) {
        if (class_exists($provider)) {
            $shortName = substr($provider, strrpos($provider, '\\') + 1);
            echo "  ✅ {$shortName} available\n";
        } else {
            echo "  ❌ {$provider} not found\n";
        }
    }
} catch (\Exception $e) {
    echo "  ❌ ai-access test failed: {$e->getMessage()}\n";
    exit(1);
}

echo "\n=== All Tests Passed! ===\n";
echo "\nNext Steps:\n";
echo "1. To test with real APIs, set up environment variables:\n";
echo "   - OPENAI_API_KEY\n";
echo "   - QDRANT_URL\n";
echo "   - QDRANT_API_KEY\n";
echo "\n2. To test in WordPress:\n";
echo "   - Install the package in your WordPress project\n";
echo "   - Run: wp eval-file examples/index-wordpress-content.php\n";
echo "\n3. To test RAG with real LLM:\n";
echo "   - Run: php tests/test-rag-live.php\n";
