<?php

namespace DigitalNode\WPQdrantIndexer;

/**
 * WordPress content indexer for Qdrant vector database
 */
class Indexer
{
    private Config $config;
    private QdrantClient $qdrant;
    private Embedder $embedder;
    private ContentExtractor $extractor;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->qdrant = new QdrantClient($config);
        $this->embedder = new Embedder($config);
        $this->extractor = new ContentExtractor($config);
    }

    /**
     * Run the full indexing process
     */
    public function index(bool $recreate_collection = true): array
    {
        $start_time = microtime(true);

        echo "Starting content indexing...\n";

        // Step 1: Setup collection
        if ($recreate_collection) {
            echo "Creating Qdrant collection...\n";
            $this->qdrant->createCollection(true);
        }

        // Step 2: Gather content
        echo "Gathering content from WordPress...\n";
        $content = $this->gatherContent();
        echo "Found " . count($content) . " content items\n";

        if (empty($content)) {
            return [
                'success' => false,
                'message' => 'No content found to index',
            ];
        }

        // Step 3: Chunk content
        echo "Creating chunks...\n";
        $chunks = $this->chunkContent($content);
        echo "Created " . count($chunks) . " chunks\n";

        // Step 4: Embed and upload
        echo "Generating embeddings and uploading to Qdrant...\n";
        $this->embedAndUpload($chunks, $recreate_collection);

        $elapsed = round(microtime(true) - $start_time, 2);
        $stats = $this->embedder->getCacheStats();

        echo "\nIndexing complete!\n";
        echo "Time elapsed: {$elapsed}s\n";
        echo "Cached embeddings reused: {$stats['cached']}\n";
        echo "New embeddings generated: {$stats['new']}\n";
        echo "Cache hit rate: {$stats['cache_hit_rate']}%\n";

        return [
            'success' => true,
            'chunks' => count($chunks),
            'time' => $elapsed,
            'stats' => $stats,
        ];
    }

    /**
     * Gather content from WordPress
     */
    private function gatherContent(): array
    {
        $content = [];
        $post_types = $this->config->getPostTypes();

        foreach ($post_types as $post_type) {
            echo "  - Gathering {$post_type}...\n";

            $posts = get_posts([
                'post_type' => $post_type,
                'posts_per_page' => -1,
                'post_status' => 'publish',
            ]);

            foreach ($posts as $post) {
                $text = $this->extractor->extractContent($post);

                // Skip if too short
                if (strlen($text) < 100) {
                    continue;
                }

                $metadata = $this->extractor->extractMetadata($post);

                $content[] = [
                    'id' => $post->ID,
                    'text' => $text,
                    'metadata' => $metadata,
                ];
            }
        }

        return $content;
    }

    /**
     * Chunk content into smaller pieces
     */
    private function chunkContent(array $content): array
    {
        $chunks = [];
        $chunk_id = 0;

        foreach ($content as $item) {
            $text = $item['text'];
            $metadata = $item['metadata'];

            // If content fits in one chunk
            if (strlen($text) <= $this->config->chunk_size) {
                $chunks[] = [
                    'id' => $chunk_id++,
                    'post_id' => $item['id'],
                    'text' => $text,
                    'metadata' => $metadata,
                ];
                continue;
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
                    'id' => $chunk_id++,
                    'post_id' => $item['id'],
                    'text' => trim($chunk_text),
                    'metadata' => $metadata,
                ];

                $start += strlen($chunk_text);
            }
        }

        return $chunks;
    }

    /**
     * Generate embeddings and upload to Qdrant
     */
    private function embedAndUpload(array $chunks, bool $skip_qdrant_cache = true): void
    {
        $total = count($chunks);
        $points = [];
        $start_time = time();
        $qdrant_cache_hits = 0;

        foreach ($chunks as $index => $chunk) {
            // Progress indicator
            $elapsed = time() - $start_time;
            $rate = $index > 0 ? $elapsed / $index : 0;
            $remaining = $total - $index;
            $eta_seconds = $rate > 0 ? $remaining * $rate : 0;
            $eta = gmdate("H:i:s", $eta_seconds);
            $stats = $this->embedder->getCacheStats();

            echo sprintf(
                "\rProcessing %d/%d (local: %d, qdrant: %d, new: %d) ETA: %s    ",
                $index + 1,
                $total,
                $stats['cached'],
                $qdrant_cache_hits,
                $stats['new'],
                $eta
            );

            // Step 1: Check Qdrant for existing embedding with same content hash
            // (Skip if recreating collection since it's empty anyway)
            $content_hash = md5($chunk['text']);
            $existing_point = !$skip_qdrant_cache ? $this->qdrant->searchByContentHash($content_hash) : null;

            if ($existing_point) {
                // Reuse existing vector from Qdrant
                $vector = $existing_point['vector'];
                $qdrant_cache_hits++;
            } else {
                // Step 2: Check local WordPress cache or generate new embedding
                $cache_key = $chunk['post_id'] . '_' . $chunk['id'];
                $embedding = $this->embedder->getEmbedding($chunk['text'], $cache_key);

                if (!$embedding) {
                    echo "\nFailed to embed chunk {$chunk['id']}, skipping...\n";
                    continue;
                }

                $vector = $embedding['vector'];

                // Rate limiting for new embeddings
                if (!$embedding['cached']) {
                    usleep(500000); // 0.5 second delay
                }
            }

            // Prepare point with content hash for future cache lookups
            $points[] = [
                'id' => $chunk['id'],
                'vector' => $vector,
                'payload' => array_merge(
                    [
                        'text' => $chunk['text'],
                        'content_hash' => $content_hash, // Add hash for cache lookups
                    ],
                    $chunk['metadata']
                ),
            ];

            // Upload in batches
            if (count($points) >= $this->config->batch_size) {
                $this->qdrant->uploadPoints($points);
                $points = [];
            }
        }

        // Upload remaining points
        if (!empty($points)) {
            $this->qdrant->uploadPoints($points);
        }

        echo "\n";
    }

    /**
     * Clear the embedding cache
     */
    public function clearCache(): int
    {
        return $this->embedder->clearCache();
    }

    /**
     * Get Qdrant client
     */
    public function getQdrantClient(): QdrantClient
    {
        return $this->qdrant;
    }

    /**
     * Get Embedder
     */
    public function getEmbedder(): Embedder
    {
        return $this->embedder;
    }
}
