# WordPress Qdrant Indexer

A flexible WordPress content indexer for Qdrant vector database with OpenAI embeddings, intelligent caching, and RAG (Retrieval-Augmented Generation) capabilities supporting multiple LLM providers.

## Features

- **🚀 Easy WordPress Integration** - Index any WordPress post type with minimal configuration
- **💾 Intelligent Caching** - Save 95%+ on re-indexing costs with automatic embedding cache
- **🤖 Multiple LLM Support** - Use OpenAI, Claude, Gemini, DeepSeek, or Grok via [ai-access](https://github.com/aiaccess/ai-access)
- **🔍 Advanced Content Extraction** - Supports ACF fields, Gutenberg blocks, taxonomies, and custom extractors
- **⚡ Optimized Performance** - Batch uploading, rate limiting, and progress tracking
- **📊 RAG Engine** - Complete query pipeline with context retrieval and answer generation
- **🎯 Configurable** - Flexible post type registration and field extraction

## Installation

Install via Composer:

```bash
composer require digitalnode/wp-qdrant-indexer
```

## Requirements

- PHP 8.1 or higher
- WordPress 5.0 or higher
- OpenAI API key (for embeddings)
- Qdrant Cloud account (free tier available)
- LLM API key (OpenAI, Anthropic, Google, etc.)

## Quick Start

### 1. Set Up API Keys

Add to your WordPress `.env` file:

```bash
OPENAI_API_KEY=sk-...
QDRANT_URL=https://....gcp.cloud.qdrant.io:6333
QDRANT_API_KEY=...
```

**Get your API keys:**
- OpenAI: https://platform.openai.com/api-keys
- Qdrant: https://cloud.qdrant.io (create free cluster)
- Anthropic Claude: https://console.anthropic.com/settings/keys
- Google Gemini: https://aistudio.google.com/app/apikey

### 2. Index Your Content

```php
<?php
use DigitalNode\WPQdrantIndexer\Config;
use DigitalNode\WPQdrantIndexer\Indexer;

// Configure
$config = new Config([
    'openai_api_key' => env('OPENAI_API_KEY'),
    'qdrant_url' => env('QDRANT_URL'),
    'qdrant_api_key' => env('QDRANT_API_KEY'),
    'collection_name' => 'my_docs',
]);

// Register post types
$config->registerPostType('post');
$config->registerPostType('page');
$config->registerPostType('product', ['product_code', 'description']);

// Index
$indexer = new Indexer($config);
$result = $indexer->index();

echo "Indexed {$result['chunks']} chunks in {$result['time']}s\n";
```

### 3. Query with RAG

```php
<?php
use DigitalNode\WPQdrantIndexer\RAGEngine;
use AIAccess\Provider\OpenAI\Client as OpenAIClient;

// Set up LLM
$llmClient = new OpenAIClient(env('OPENAI_API_KEY'));
$chat = $llmClient->createChat('gpt-4o-mini');

// Create RAG engine
$rag = new RAGEngine($config, $chat);

// Query
$result = $rag->query("What products do you offer for restaurants?");

if ($result['success']) {
    echo $result['answer'];
    print_r($result['sources']);
}
```

## Configuration

### Basic Configuration

```php
$config = new Config([
    // Required
    'openai_api_key' => 'sk-...',          // OpenAI API key for embeddings
    'qdrant_url' => 'https://...',          // Qdrant instance URL
    'qdrant_api_key' => '...',              // Qdrant API key
    'collection_name' => 'my_collection',   // Qdrant collection name

    // Optional
    'vector_size' => 1536,                  // Embedding dimensions (default: 1536)
    'distance_metric' => 'Cosine',          // Distance metric (default: Cosine)
    'batch_size' => 50,                     // Upload batch size (default: 50)
    'chunk_size' => 3000,                   // Max chunk size in characters (default: 3000)
    'enable_cache' => true,                 // Enable embedding cache (default: true)
    'cache_prefix' => 'qdrant_embedding_',  // Cache key prefix (default: qdrant_embedding_)
]);
```

### Registering Post Types

#### Simple Registration (Default Extraction)

```php
// Index all post content and meta
$config->registerPostType('post');
$config->registerPostType('page');
```

#### With Specific ACF Fields

```php
$config->registerPostType('product', [
    'product_code',
    'listing_content',
    'attributes',
    'product_group',
    'solutions',
    'technologies',
]);
```

#### With Custom Extractor

```php
$config->registerPostType('custom_type', [], function(\WP_Post $post) {
    $parts = [];

    // Title
    $parts[] = $post->post_title;

    // Custom field extraction
    if (function_exists('get_field')) {
        $description = get_field('description', $post->ID);
        if ($description) {
            $parts[] = strip_tags($description);
        }

        // Handle repeater fields
        $specs = get_field('specifications', $post->ID);
        if ($specs && is_array($specs)) {
            foreach ($specs as $spec) {
                $parts[] = "{$spec['key']}: {$spec['value']}";
            }
        }
    }

    return implode("\n\n", array_filter($parts));
});
```

## LLM Provider Support

Thanks to [ai-access](https://github.com/aiaccess/ai-access), you can easily switch between LLM providers:

### OpenAI

```php
use AIAccess\Provider\OpenAI\Client;

$llmClient = new Client(env('OPENAI_API_KEY'));
$chat = $llmClient->createChat('gpt-4o-mini'); // or 'gpt-4o', 'gpt-4-turbo'
$rag = new RAGEngine($config, $chat);
```

### Anthropic Claude

```php
use AIAccess\Provider\Claude\Client;

$llmClient = new Client(env('ANTHROPIC_API_KEY'));
$chat = $llmClient->createChat('claude-3-5-haiku-latest'); // or 'claude-3-5-sonnet-latest'
$rag = new RAGEngine($config, $chat);
```

### Google Gemini

```php
use AIAccess\Provider\Gemini\Client;

$llmClient = new Client(env('GEMINI_API_KEY'));
$chat = $llmClient->createChat('gemini-2.5-flash'); // or 'gemini-2.5-pro'
$rag = new RAGEngine($config, $chat);
```

### DeepSeek

```php
use AIAccess\Provider\DeepSeek\Client;

$llmClient = new Client(env('DEEPSEEK_API_KEY'));
$chat = $llmClient->createChat('deepseek-chat');
$rag = new RAGEngine($config, $chat);
```

### Grok (xAI)

```php
use AIAccess\Provider\Grok\Client;

$llmClient = new Client(env('GROK_API_KEY'));
$chat = $llmClient->createChat('grok-3-fast-latest');
$rag = new RAGEngine($config, $chat);
```

## Advanced Usage

### Conversation History

```php
$conversation_history = [
    ['role' => 'user', 'content' => 'What is Capture Jet technology?'],
    ['role' => 'assistant', 'content' => 'Capture Jet is a ventilation technology that...'],
];

$result = $rag->query("How does it compare to traditional hoods?", $conversation_history);
```

### Custom System Prompt

```php
$rag->setSystemPrompt("You are a helpful assistant for Acme Corp. Answer questions based on our product documentation. Always be professional and concise.");
```

### Search Parameters

```php
$result = $rag->query(
    question: "What products do you offer?",
    conversation_history: [],
    search_limit: 10,              // Return top 10 results (default: 5)
    score_threshold: 0.7           // Only results with >70% similarity (default: 0.5)
);
```

### Re-indexing (Incremental)

```php
// Re-index without recreating collection (uses cache!)
$result = $indexer->index(false);

echo "Cached: {$result['stats']['cached']} (saved $" .
     number_format($result['stats']['cached'] * 0.00001, 5) . ")\n";
echo "New: {$result['stats']['new']} (cost $" .
     number_format($result['stats']['new'] * 0.00001, 5) . ")\n";
```

### Clear Cache

```php
$deleted = $indexer->clearCache();
echo "Cleared {$deleted} cached embeddings\n";
```

### Direct Qdrant Operations

```php
$qdrant = $indexer->getQdrantClient();

// Check if collection exists
if ($qdrant->collectionExists()) {
    $info = $qdrant->getCollectionInfo();
    print_r($info);
}

// Delete collection
$qdrant->deleteCollection();
```

## WordPress Plugin Integration

See [`examples/wordpress-plugin-integration.php`](examples/wordpress-plugin-integration.php) for a complete example showing:

- AJAX handler for chatbot queries
- Shortcode integration
- Conversation history management
- Error handling

## Cost Estimates

### Indexing

- **First index:** ~$0.02 per 1,000 chunks
  - Example: 4,000 content items → ~$0.08
- **Re-indexing (with cache):** ~$0.001 per re-index
  - **95%+ cost savings** on subsequent indexes!

### Querying

| Provider | Model | Cost per 1M Input Tokens | Cost per 1M Output Tokens | Est. Cost per Query |
|----------|-------|---------------------------|---------------------------|---------------------|
| OpenAI | gpt-4o-mini | $0.15 | $0.60 | ~$0.0003 |
| Anthropic | claude-3-5-haiku | $0.80 | $4.00 | ~$0.002 |
| Google | gemini-2.5-flash | Free* | Free* | FREE* |
| DeepSeek | deepseek-chat | $0.14 | $0.28 | ~$0.0002 |

*Gemini has a generous free tier

### Example: 1,000 queries/month

- **Using GPT-4o-mini:** ~$0.30/month
- **Using Claude Haiku:** ~$2.00/month
- **Using Gemini Flash:** FREE*
- **Using DeepSeek:** ~$0.20/month

**Total Year 1 Cost (1,000 queries/month + weekly re-indexing):**
- Indexing: $0.08 + (52 × $0.001) = $0.13
- Queries (GPT-4o-mini): $3.60
- **Total: ~$3.73/year** ☕

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                     WordPress Content                        │
│  (Posts, Pages, Products, Custom Post Types, ACF Fields)    │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
         ┌───────────────────────┐
         │  Content Extractor    │  Extracts text from posts/ACF
         └───────────┬───────────┘
                     │
                     ▼
            ┌────────────────┐
            │    Chunker     │  Splits into manageable pieces
            └────────┬───────┘
                     │
                     ▼
            ┌────────────────┐
            │   Embedder     │  OpenAI text-embedding-3-small
            │  (with cache)  │  95% cost savings on re-index!
            └────────┬───────┘
                     │
                     ▼
            ┌────────────────┐
            │ Qdrant Vector  │  Stores embeddings + metadata
            │   Database     │
            └────────────────┘
```

```
┌──────────────────────────────────────────────────────────────┐
│                      RAG Query Pipeline                       │
└──────────────────────────────────────────────────────────────┘

  User Question
       │
       ▼
  ┌────────────┐
  │  Embedder  │  Convert question to vector
  └─────┬──────┘
        │
        ▼
  ┌────────────┐
  │  Qdrant    │  Search for similar content
  │  Search    │  (semantic similarity)
  └─────┬──────┘
        │
        ▼
  ┌────────────┐
  │  Context   │  Build context from results
  │  Builder   │
  └─────┬──────┘
        │
        ▼
  ┌────────────┐
  │    LLM     │  Generate answer with context
  │ (ai-access)│  (OpenAI/Claude/Gemini/etc.)
  └─────┬──────┘
        │
        ▼
     Answer + Sources
```

## Examples

See the [`examples/`](examples/) directory for complete examples:

- [`index-wordpress-content.php`](examples/index-wordpress-content.php) - Indexing WordPress content
- [`query-with-rag.php`](examples/query-with-rag.php) - Querying with different LLM providers
- [`wordpress-plugin-integration.php`](examples/wordpress-plugin-integration.php) - Complete plugin integration

## Troubleshooting

### Embedding Errors

```php
// Check if API key is set
if (empty($config->openai_api_key)) {
    echo "ERROR: OPENAI_API_KEY not set\n";
}

// Test embedding generation
$embedder = new Embedder($config);
$test = $embedder->getEmbedding("test", "test_key");
if (!$test) {
    echo "Failed to generate test embedding\n";
}
```

### Qdrant Connection Issues

```php
// Test Qdrant connection
$qdrant = new QdrantClient($config);
if (!$qdrant->collectionExists()) {
    echo "Collection does not exist or cannot connect to Qdrant\n";
}
```

### Cache Issues

```php
// Clear cache if embeddings seem stale
$deleted = $indexer->clearCache();
echo "Cleared {$deleted} cached embeddings\n";

// Re-index with fresh embeddings
$indexer->index(true);
```

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

MIT License - see [LICENSE](LICENSE) file for details.

## Credits

- Built by [Digital Node](https://digitalnode.com)
- LLM integration powered by [ai-access](https://github.com/aiaccess/ai-access)
- Vector database by [Qdrant](https://qdrant.tech)
- Embeddings by [OpenAI](https://openai.com)

## Support

For issues or questions:
- Open an issue on [GitHub](https://github.com/digitalnodecom/wp-qdrant-indexer/issues)
- Check the [examples](examples/) directory
- Review the [ai-access documentation](https://github.com/aiaccess/ai-access)
