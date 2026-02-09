<?php

namespace DigitalNode\WPQdrantIndexer;

/**
 * WP-CLI Commands for Qdrant Indexer
 *
 * Indexes WordPress content to Qdrant vector database.
 * Extracts all post meta, taxonomies, and content automatically.
 */
class CLI
{
    private Config $config;
    private QdrantClient $qdrant;
    private Embedder $embedder;

    public function __construct()
    {
        $this->config = new Config([
            'openai_api_key' => $this->getEnv('OPENAI_API_KEY'),
            'qdrant_url' => $this->getEnv('QDRANT_URL'),
            'qdrant_api_key' => $this->getEnv('QDRANT_API_KEY'),
            'collection_name' => $this->getEnv('QDRANT_COLLECTION', 'wp_content'),
            'chunk_size' => 25000,
            'batch_size' => 50,
            'enable_cache' => true,
        ]);

        $this->qdrant = new QdrantClient($this->config);
        $this->embedder = new Embedder($this->config);
    }

    /**
     * Get environment variable (supports both env() function and constants)
     */
    private function getEnv(string $key, string $default = ''): string
    {
        // Check for constant first
        if (defined($key)) {
            return constant($key);
        }

        // Check for env() function (Bedrock)
        if (function_exists('env')) {
            return env($key, $default);
        }

        // Check $_ENV
        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }

        // Check getenv()
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        return $default;
    }

    /**
     * Sync content to Qdrant vector database.
     *
     * ## OPTIONS
     *
     * [--post_type=<type>]
     * : Specific post type to sync (default: all public post types)
     *
     * [--recreate]
     * : Delete and recreate Qdrant collection (default: true)
     *
     * [--limit=<number>]
     * : Limit number of posts to sync (for testing)
     *
     * ## EXAMPLES
     *
     *     wp qdrant sync
     *     wp qdrant sync --post_type=product
     *     wp qdrant sync --limit=50
     *     wp qdrant sync --recreate=false
     *
     * @when after_wp_load
     */
    public function sync($args, $assoc_args)
    {
        // Remove time limits for long-running indexing
        set_time_limit(0);
        ini_set('max_execution_time', '0');

        $recreate = !isset($assoc_args['recreate']) || $assoc_args['recreate'] !== 'false';
        $limit = isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : 0;
        $specific_type = $assoc_args['post_type'] ?? null;

        \WP_CLI::log("Starting Qdrant sync...");
        \WP_CLI::log("Collection: {$this->config->collection_name}\n");

        // Step 1: Setup collection
        if ($recreate) {
            \WP_CLI::log("Recreating Qdrant collection...");
            $this->qdrant->createCollection(true);
        } else {
            \WP_CLI::log("Using existing Qdrant collection...");
            if (!$this->qdrant->collectionExists()) {
                $this->qdrant->createCollection(false);
            }
        }

        // Step 2: Get post types to index
        $post_types = $this->getPostTypesToIndex($specific_type);

        if (empty($post_types)) {
            \WP_CLI::error('No post types to index.');
        }

        \WP_CLI::log("Post types to index: " . implode(', ', $post_types) . "\n");

        // Step 3: Gather and process content
        $total_chunks = 0;
        $global_chunk_id = 0;
        $start_time = microtime(true);

        foreach ($post_types as $post_type) {
            $result = $this->processPostType($post_type, $limit, $recreate, $global_chunk_id);
            $total_chunks += $result['uploaded'];
            $global_chunk_id = $result['next_id'];
        }

        $elapsed = round(microtime(true) - $start_time, 2);
        $stats = $this->embedder->getCacheStats();

        \WP_CLI::log("\n" . str_repeat("=", 50));
        \WP_CLI::success("Qdrant sync complete!");
        \WP_CLI::log("   Total chunks uploaded: {$total_chunks}");
        \WP_CLI::log("   Time elapsed: {$elapsed}s");
        \WP_CLI::log("   Cache stats:");
        \WP_CLI::log("     - Local cache hits: {$stats['cached']}");
        \WP_CLI::log("     - New embeddings: {$stats['new']}");
        \WP_CLI::log("     - Cache hit rate: {$stats['cache_hit_rate']}%");
    }

    /**
     * Show Qdrant collection statistics.
     *
     * ## EXAMPLES
     *
     *     wp qdrant stats
     *
     * @when after_wp_load
     */
    public function stats($args, $assoc_args)
    {
        $info = $this->qdrant->getCollectionInfo();

        if (!$info) {
            \WP_CLI::error('Could not retrieve collection info. Is Qdrant running?');
        }

        \WP_CLI::log("Qdrant Collection: {$this->config->collection_name}");
        \WP_CLI::log("   Points: " . ($info['points_count'] ?? 'N/A'));
        \WP_CLI::log("   Vectors: " . ($info['vectors_count'] ?? 'N/A'));
        \WP_CLI::log("   Status: " . ($info['status'] ?? 'N/A'));
    }

    /**
     * Clear embedding cache.
     *
     * ## EXAMPLES
     *
     *     wp qdrant clear_cache
     *
     * @when after_wp_load
     */
    public function clear_cache($args, $assoc_args)
    {
        $deleted = $this->embedder->clearCache();
        \WP_CLI::success("Cleared {$deleted} cached embeddings.");
    }

    /**
     * Get post types to index
     */
    private function getPostTypesToIndex(?string $specific_type): array
    {
        if ($specific_type) {
            return [$specific_type];
        }

        // Get all public post types
        $post_types = get_post_types(['public' => true], 'names');
        unset($post_types['attachment']);

        // Allow filtering
        return apply_filters('qdrant_indexer_post_types', array_values($post_types));
    }

    /**
     * Process a single post type
     */
    private function processPostType(
        string $post_type,
        int $limit,
        bool $skip_qdrant_cache,
        int $start_chunk_id
    ): array {
        \WP_CLI::log("Processing {$post_type}...");

        $args = [
            'post_type' => $post_type,
            'post_status' => 'publish',
            'posts_per_page' => $limit > 0 ? $limit : -1,
        ];

        $posts = get_posts($args);
        $total = count($posts);

        if ($total === 0) {
            \WP_CLI::log("   No {$post_type} posts found.\n");
            return ['uploaded' => 0, 'next_id' => $start_chunk_id];
        }

        \WP_CLI::log("   Found {$total} posts to process...");

        $chunk_id = $start_chunk_id;
        $points = [];
        $uploaded_count = 0;
        $failed_count = 0;

        foreach ($posts as $index => $post) {
            // Extract content using ContentExtractor
            $document = ContentExtractor::getDocument($post->ID);
            $text = ContentExtractor::documentToText($document);

            $title = $document['title'] ?? $post->post_title;
            $url = $document['permalink'] ?? get_permalink($post->ID);

            // Get language (Polylang/WPML support)
            $language = $this->getPostLanguage($post->ID);

            // Skip if too short
            if (strlen($text) < 100) {
                continue;
            }

            // Chunk the content
            $post_chunks = $this->chunkText($text, $post->ID);

            foreach ($post_chunks as $chunk) {
                $chunk['id'] = $chunk_id++;

                // Generate embedding
                $content_hash = md5($chunk['text']);
                $existing_point = !$skip_qdrant_cache ? $this->qdrant->searchByContentHash($content_hash) : null;

                if ($existing_point) {
                    $vector = $existing_point['vector'];
                } else {
                    $cache_key = $chunk['post_id'] . '_' . $chunk['id'];
                    $embedding = $this->embedder->getEmbedding($chunk['text'], $cache_key);

                    if (!$embedding) {
                        \WP_CLI::warning("Failed to embed chunk for post {$chunk['post_id']}, skipping...");
                        continue;
                    }

                    $vector = $embedding['vector'];

                    // Rate limiting for new embeddings
                    if (!$embedding['cached']) {
                        usleep(100000); // 0.1 second delay
                    }
                }

                $points[] = [
                    'id' => $chunk['id'],
                    'vector' => $vector,
                    'payload' => [
                        'text' => $chunk['text'],
                        'content_hash' => $content_hash,
                        'post_id' => $chunk['post_id'],
                        'title' => $title,
                        'url' => $url,
                        'type' => $post_type,
                        'language' => $language,
                    ],
                ];

                // Upload in batches
                if (count($points) >= $this->config->batch_size) {
                    $batch_count = count($points);
                    $success = $this->qdrant->uploadPoints($points);
                    if (!$success) {
                        \WP_CLI::warning("Failed to upload batch of {$batch_count} points to Qdrant!");
                        $failed_count += $batch_count;
                    } else {
                        $uploaded_count += $batch_count;
                    }
                    $points = [];
                }
            }

            // Progress
            $progress = $index + 1;
            $stats = $this->embedder->getCacheStats();
            \WP_CLI::log("   Processed {$progress}/{$total} (cached: {$stats['cached']}, new: {$stats['new']})");
        }

        // Upload remaining points
        if (!empty($points)) {
            $batch_count = count($points);
            $success = $this->qdrant->uploadPoints($points);
            if (!$success) {
                \WP_CLI::warning("Failed to upload final batch of {$batch_count} points to Qdrant!");
                $failed_count += $batch_count;
            } else {
                $uploaded_count += $batch_count;
            }
        }

        \WP_CLI::log("   {$post_type} complete: {$uploaded_count} uploaded, {$failed_count} failed\n");

        return ['uploaded' => $uploaded_count, 'next_id' => $chunk_id];
    }

    /**
     * Get post language using Polylang, WPML, or default
     */
    private function getPostLanguage(int $post_id): string
    {
        // Polylang
        if (function_exists('pll_get_post_language')) {
            $lang = pll_get_post_language($post_id, 'slug');
            if ($lang) {
                return $lang;
            }
        }

        // WPML
        if (function_exists('wpml_get_language_information')) {
            $lang_info = wpml_get_language_information(null, $post_id);
            if (!empty($lang_info['language_code'])) {
                return $lang_info['language_code'];
            }
        }

        // Default to site language
        return substr(get_locale(), 0, 2);
    }

    /**
     * Chunk text into smaller pieces
     */
    private function chunkText(string $text, int $post_id): array
    {
        $chunks = [];

        // If content fits in one chunk
        if (strlen($text) <= $this->config->chunk_size) {
            $chunks[] = [
                'post_id' => $post_id,
                'text' => $text,
            ];
            return $chunks;
        }

        // Split into chunks
        $start = 0;
        while ($start < strlen($text)) {
            $chunk_text = substr($text, $start, $this->config->chunk_size);

            // Try to end at sentence boundary
            if ($start + $this->config->chunk_size < strlen($text)) {
                $last_period = strrpos($chunk_text, '.');
                if ($last_period !== false && $last_period > $this->config->chunk_size / 2) {
                    $chunk_text = substr($chunk_text, 0, $last_period + 1);
                }
            }

            $chunks[] = [
                'post_id' => $post_id,
                'text' => trim($chunk_text),
            ];

            $start += strlen($chunk_text);
        }

        return $chunks;
    }
}
