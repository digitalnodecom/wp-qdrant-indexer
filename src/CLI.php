<?php

namespace DigitalNode\WPQdrantIndexer;

/**
 * WP-CLI Commands for Qdrant Indexer
 *
 * Indexes WordPress content to Qdrant vector database.
 * Supports multilingual sites with separate collections per language.
 */
class CLI
{
    private string $base_collection;
    private Config $config;
    private ?QdrantClient $qdrant = null;
    private ?Embedder $embedder = null;
    private ?string $log_file = null;
    private array $log_data = [];

    public function __construct()
    {
        $this->base_collection = $this->getEnv('QDRANT_COLLECTION', 'wp_content');
    }

    /**
     * Get environment variable (supports both env() function and constants)
     */
    private function getEnv(string $key, string $default = ''): string
    {
        if (defined($key)) {
            return constant($key);
        }

        if (function_exists('env')) {
            return env($key, $default);
        }

        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }

        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        return $default;
    }

    /**
     * Get Config instance for a specific language collection
     */
    private function getConfig(string $language): Config
    {
        return new Config([
            'openai_api_key' => $this->getEnv('OPENAI_API_KEY'),
            'qdrant_url' => $this->getEnv('QDRANT_URL'),
            'qdrant_api_key' => $this->getEnv('QDRANT_API_KEY'),
            'collection_name' => $this->base_collection . '_' . $language,
            'chunk_size' => 25000,
            'batch_size' => 50,
            'enable_cache' => true,
        ]);
    }

    /**
     * Get available languages from Polylang/WPML or default
     */
    private function getAvailableLanguages(): array
    {
        // Polylang
        if (function_exists('pll_languages_list')) {
            $languages = pll_languages_list(['fields' => 'slug']);
            if (!empty($languages)) {
                return $languages;
            }
        }

        // WPML
        if (function_exists('icl_get_languages')) {
            $languages = icl_get_languages('skip_missing=0');
            if (!empty($languages)) {
                return array_keys($languages);
            }
        }

        // Default to site language
        return [substr(get_locale(), 0, 2)];
    }

    /**
     * Sync content to Qdrant vector database.
     *
     * ## OPTIONS
     *
     * [<language>]
     * : Language to sync (e.g., 'en', 'de'). Use 'all' or omit to sync all languages.
     *
     * [--post_type=<type>]
     * : Specific post type to sync (default: all public post types)
     *
     * [--recreate]
     * : Delete and recreate Qdrant collection (default: true)
     *
     * [--limit=<number>]
     * : Limit number of posts to sync per language (for testing)
     *
     * ## EXAMPLES
     *
     *     wp qdrant sync
     *     wp qdrant sync en
     *     wp qdrant sync de --recreate=false
     *     wp qdrant sync all --post_type=product
     *     wp qdrant sync en --limit=50
     *
     * @when after_wp_load
     */
    public function sync($args, $assoc_args)
    {
        set_time_limit(0);
        ini_set('max_execution_time', '0');

        $language_arg = $args[0] ?? 'all';
        $recreate = !isset($assoc_args['recreate']) || $assoc_args['recreate'] !== 'false';
        $limit = isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : 0;
        $specific_type = $assoc_args['post_type'] ?? null;

        // Determine which languages to sync
        $available_languages = $this->getAvailableLanguages();

        if ($language_arg === 'all') {
            $languages_to_sync = $available_languages;
        } else {
            if (!in_array($language_arg, $available_languages)) {
                \WP_CLI::error("Language '{$language_arg}' not found. Available: " . implode(', ', $available_languages));
            }
            $languages_to_sync = [$language_arg];
        }

        // Create log file
        $date = date('Y-m-d_H-i-s');
        $this->log_file = getcwd() . "/qdrant-index-{$language_arg}-{$date}.txt";
        $this->log_data = [
            'started_at' => date('Y-m-d H:i:s'),
            'languages' => $languages_to_sync,
            'base_collection' => $this->base_collection,
            'posts' => [],
        ];

        \WP_CLI::log("Starting Qdrant sync...");
        \WP_CLI::log("Base collection: {$this->base_collection}");
        \WP_CLI::log("Languages to sync: " . implode(', ', $languages_to_sync) . "\n");

        $total_start_time = microtime(true);
        $grand_total_chunks = 0;

        foreach ($languages_to_sync as $language) {
            $chunks = $this->syncLanguage($language, $specific_type, $limit, $recreate);
            $grand_total_chunks += $chunks;
        }

        $total_elapsed = round(microtime(true) - $total_start_time, 2);

        // Write log file
        $this->writeLogFile($grand_total_chunks, $total_elapsed);

        \WP_CLI::log("\n" . str_repeat("=", 50));
        \WP_CLI::success("All sync complete!");
        \WP_CLI::log("   Total chunks across all languages: {$grand_total_chunks}");
        \WP_CLI::log("   Total time elapsed: {$total_elapsed}s");
        \WP_CLI::log("   Log file: {$this->log_file}");
    }

    /**
     * Write the index log file
     */
    private function writeLogFile(int $total_chunks, float $elapsed): void
    {
        $content = "QDRANT INDEX LOG\n";
        $content .= str_repeat("=", 60) . "\n\n";
        $content .= "Started: {$this->log_data['started_at']}\n";
        $content .= "Finished: " . date('Y-m-d H:i:s') . "\n";
        $content .= "Duration: {$elapsed}s\n";
        $content .= "Base Collection: {$this->log_data['base_collection']}\n";
        $content .= "Languages: " . implode(', ', $this->log_data['languages']) . "\n";
        $content .= "Total Chunks: {$total_chunks}\n";
        $content .= "\n" . str_repeat("=", 60) . "\n";
        $content .= "INDEXED POSTS\n";
        $content .= str_repeat("=", 60) . "\n\n";

        $indexed_count = 0;
        $skipped_count = 0;

        foreach ($this->log_data['posts'] as $post) {
            if ($post['status'] === 'indexed') {
                $indexed_count++;
                $content .= "[INDEXED] #{$post['id']} | {$post['language']} | {$post['type']}\n";
                $content .= "  Title: {$post['title']}\n";
                $content .= "  URL: {$post['url']}\n";
                $content .= "  Content: {$post['content_length']} chars → {$post['chunks']} chunk(s)\n";
                $content .= "  Embeddings: {$post['embeddings']}\n";
                $content .= "\n";
            } else {
                $skipped_count++;
                $content .= "[SKIPPED] #{$post['id']} | {$post['language']} | {$post['type']}\n";
                $content .= "  Title: {$post['title']}\n";
                $content .= "  Reason: {$post['reason']}\n";
                $content .= "\n";
            }
        }

        $content .= str_repeat("=", 60) . "\n";
        $content .= "SUMMARY\n";
        $content .= str_repeat("=", 60) . "\n";
        $content .= "Total Posts Processed: " . count($this->log_data['posts']) . "\n";
        $content .= "Indexed: {$indexed_count}\n";
        $content .= "Skipped: {$skipped_count}\n";
        $content .= "Total Chunks: {$total_chunks}\n";

        file_put_contents($this->log_file, $content);
    }

    /**
     * Log a post to the index log
     */
    private function logPost(array $data): void
    {
        $this->log_data['posts'][] = $data;
    }

    /**
     * Sync a single language to its collection
     */
    private function syncLanguage(string $language, ?string $specific_type, int $limit, bool $recreate): int
    {
        $config = $this->getConfig($language);
        $qdrant = new QdrantClient($config);
        $embedder = new Embedder($config);

        \WP_CLI::log(str_repeat("-", 50));
        \WP_CLI::log("Syncing language: {$language}");
        \WP_CLI::log("Collection: {$config->collection_name}\n");

        // Setup collection
        if ($recreate) {
            \WP_CLI::log("Recreating collection...");
            $qdrant->createCollection(true);
        } else {
            \WP_CLI::log("Using existing collection...");
            if (!$qdrant->collectionExists()) {
                $qdrant->createCollection(false);
            }
        }

        // Get post types
        $post_types = $this->getPostTypesToIndex($specific_type);

        if (empty($post_types)) {
            \WP_CLI::warning("No post types to index for {$language}.");
            return 0;
        }

        \WP_CLI::log("Post types: " . implode(', ', $post_types) . "\n");

        // Process each post type
        $total_chunks = 0;
        $global_chunk_id = 0;
        $start_time = microtime(true);

        foreach ($post_types as $post_type) {
            $result = $this->processPostType(
                $post_type,
                $language,
                $limit,
                $recreate,
                $global_chunk_id,
                $qdrant,
                $embedder,
                $config
            );
            $total_chunks += $result['uploaded'];
            $global_chunk_id = $result['next_id'];
        }

        $elapsed = round(microtime(true) - $start_time, 2);
        $stats = $embedder->getCacheStats();

        \WP_CLI::log("Language '{$language}' complete:");
        \WP_CLI::log("   Chunks uploaded: {$total_chunks}");
        \WP_CLI::log("   Time: {$elapsed}s");
        \WP_CLI::log("   Cache hits: {$stats['cached']}, New: {$stats['new']}\n");

        return $total_chunks;
    }

    /**
     * Show Qdrant collection statistics.
     *
     * ## OPTIONS
     *
     * [<language>]
     * : Language collection to check (e.g., 'en'). Omit to show all.
     *
     * ## EXAMPLES
     *
     *     wp qdrant stats
     *     wp qdrant stats en
     *
     * @when after_wp_load
     */
    public function stats($args, $assoc_args)
    {
        $language_arg = $args[0] ?? null;
        $languages = $language_arg ? [$language_arg] : $this->getAvailableLanguages();

        \WP_CLI::log("Qdrant Collection Stats");
        \WP_CLI::log("Base: {$this->base_collection}\n");

        foreach ($languages as $language) {
            $config = $this->getConfig($language);
            $qdrant = new QdrantClient($config);
            $info = $qdrant->getCollectionInfo();

            \WP_CLI::log("Collection: {$config->collection_name}");
            if ($info) {
                \WP_CLI::log("   Points: " . ($info['points_count'] ?? 'N/A'));
                \WP_CLI::log("   Vectors: " . ($info['vectors_count'] ?? 'N/A'));
                \WP_CLI::log("   Status: " . ($info['status'] ?? 'N/A'));
            } else {
                \WP_CLI::log("   Status: Not found or not accessible");
            }
            \WP_CLI::log("");
        }
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
        // Use any language config just to get an embedder instance
        $languages = $this->getAvailableLanguages();
        $config = $this->getConfig($languages[0]);
        $embedder = new Embedder($config);

        $deleted = $embedder->clearCache();
        \WP_CLI::success("Cleared {$deleted} cached embeddings.");
    }

    /**
     * List available languages.
     *
     * ## EXAMPLES
     *
     *     wp qdrant languages
     *
     * @when after_wp_load
     */
    public function languages($args, $assoc_args)
    {
        $languages = $this->getAvailableLanguages();

        \WP_CLI::log("Available languages: " . implode(', ', $languages));
        \WP_CLI::log("\nCollection names:");
        foreach ($languages as $lang) {
            \WP_CLI::log("   {$lang} → {$this->base_collection}_{$lang}");
        }
    }

    /**
     * Get post types to index
     */
    private function getPostTypesToIndex(?string $specific_type): array
    {
        if ($specific_type) {
            return [$specific_type];
        }

        $post_types = get_post_types(['public' => true], 'names');
        unset($post_types['attachment']);

        return apply_filters('qdrant_indexer_post_types', array_values($post_types));
    }

    /**
     * Get posts for a specific language
     */
    private function getPostsForLanguage(string $post_type, string $language, int $limit): array
    {
        $args = [
            'post_type' => $post_type,
            'post_status' => 'publish',
            'posts_per_page' => $limit > 0 ? $limit : -1,
            'suppress_filters' => false,
        ];

        // Polylang
        if (function_exists('pll_get_post_language')) {
            $args['lang'] = $language;
        }

        // WPML - set language context
        if (function_exists('icl_get_languages')) {
            global $sitepress;
            if ($sitepress) {
                $sitepress->switch_lang($language);
            }
        }

        return get_posts($args);
    }

    /**
     * Process a single post type for a language
     */
    private function processPostType(
        string $post_type,
        string $language,
        int $limit,
        bool $skip_qdrant_cache,
        int $start_chunk_id,
        QdrantClient $qdrant,
        Embedder $embedder,
        Config $config
    ): array {
        \WP_CLI::log("Processing {$post_type}...");

        $posts = $this->getPostsForLanguage($post_type, $language, $limit);
        $total = count($posts);

        if ($total === 0) {
            \WP_CLI::log("   No {$post_type} posts found for '{$language}'.\n");
            return ['uploaded' => 0, 'next_id' => $start_chunk_id];
        }

        \WP_CLI::log("   Found {$total} posts...");

        $chunk_id = $start_chunk_id;
        $points = [];
        $uploaded_count = 0;
        $failed_count = 0;

        foreach ($posts as $index => $post) {
            $document = ContentExtractor::getDocument($post->ID);
            $text = ContentExtractor::documentToText($document);

            $title = $document['title'] ?? $post->post_title;
            $url = $document['permalink'] ?? get_permalink($post->ID);
            $text_length = strlen($text);

            // Show what's being processed
            $progress = $index + 1;
            \WP_CLI::log("   [{$progress}/{$total}] #{$post->ID}: {$title}");

            if ($text_length < 100) {
                \WP_CLI::log("      ⏭ Skipped (content too short: {$text_length} chars)");
                $this->logPost([
                    'id' => $post->ID,
                    'title' => $title,
                    'url' => $url,
                    'type' => $post_type,
                    'language' => $language,
                    'status' => 'skipped',
                    'reason' => "Content too short ({$text_length} chars)",
                ]);
                continue;
            }

            $post_chunks = $this->chunkText($text, $post->ID, $config);
            $chunk_count = count($post_chunks);
            \WP_CLI::log("      📄 {$text_length} chars → {$chunk_count} chunk(s)");

            $post_cached = 0;
            $post_new = 0;
            $post_failed = 0;

            foreach ($post_chunks as $chunk) {
                $chunk['id'] = $chunk_id++;

                $content_hash = md5($chunk['text']);
                $existing_point = !$skip_qdrant_cache ? $qdrant->searchByContentHash($content_hash) : null;

                if ($existing_point) {
                    $vector = $existing_point['vector'];
                    $post_cached++;
                } else {
                    $cache_key = $chunk['post_id'] . '_' . $chunk['id'];
                    $embedding = $embedder->getEmbedding($chunk['text'], $cache_key);

                    if (!$embedding) {
                        $post_failed++;
                        continue;
                    }

                    $vector = $embedding['vector'];

                    if ($embedding['cached']) {
                        $post_cached++;
                    } else {
                        $post_new++;
                        usleep(100000);
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

                // Batch upload when enough points accumulated
                if (count($points) >= $config->batch_size) {
                    $batch_count = count($points);
                    $success = $qdrant->uploadPoints($points);
                    if (!$success) {
                        \WP_CLI::warning("      ❌ Failed to upload batch of {$batch_count} points!");
                        $failed_count += $batch_count;
                    } else {
                        $uploaded_count += $batch_count;
                        \WP_CLI::log("      ✅ Uploaded batch of {$batch_count} points");
                    }
                    $points = [];
                }
            }

            // Show embedding stats for this post
            $embed_info = [];
            if ($post_cached > 0) $embed_info[] = "cached: {$post_cached}";
            if ($post_new > 0) $embed_info[] = "new: {$post_new}";
            if ($post_failed > 0) $embed_info[] = "failed: {$post_failed}";
            $embed_str = implode(', ', $embed_info);
            if (!empty($embed_info)) {
                \WP_CLI::log("      🔢 Embeddings: {$embed_str}");
            }

            // Log this post
            $this->logPost([
                'id' => $post->ID,
                'title' => $title,
                'url' => $url,
                'type' => $post_type,
                'language' => $language,
                'status' => 'indexed',
                'content_length' => $text_length,
                'chunks' => $chunk_count,
                'embeddings' => $embed_str ?: 'none',
            ]);
        }

        if (!empty($points)) {
            $batch_count = count($points);
            $success = $qdrant->uploadPoints($points);
            if (!$success) {
                $failed_count += $batch_count;
            } else {
                $uploaded_count += $batch_count;
            }
        }

        \WP_CLI::log("   {$post_type}: {$uploaded_count} uploaded, {$failed_count} failed\n");

        return ['uploaded' => $uploaded_count, 'next_id' => $chunk_id];
    }

    /**
     * Chunk text into smaller pieces
     */
    private function chunkText(string $text, int $post_id, Config $config): array
    {
        $chunks = [];

        if (strlen($text) <= $config->chunk_size) {
            $chunks[] = [
                'post_id' => $post_id,
                'text' => $text,
            ];
            return $chunks;
        }

        $start = 0;
        while ($start < strlen($text)) {
            $chunk_text = substr($text, $start, $config->chunk_size);

            if ($start + $config->chunk_size < strlen($text)) {
                $last_period = strrpos($chunk_text, '.');
                if ($last_period !== false && $last_period > $config->chunk_size / 2) {
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
