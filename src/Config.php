<?php

namespace DigitalNode\WPQdrantIndexer;

/**
 * Configuration class for WordPress Qdrant Indexer
 */
class Config
{
    public string $openai_api_key;
    public string $qdrant_url;
    public string $qdrant_api_key;
    public string $collection_name;
    public int $vector_size = 1536; // text-embedding-3-small dimension
    public string $distance_metric = 'Cosine';
    public int $batch_size = 50;
    public int $chunk_size = 3000; // characters per chunk
    public bool $enable_cache = true;
    public string $cache_prefix = 'qdrant_embedding_';

    /**
     * Post types and their content extraction configurations
     * @var array<string, array{
     *     enabled: bool,
     *     fields: array<string>,
     *     extractor?: callable
     * }>
     */
    public array $post_types = [];

    public function __construct(array $config = [])
    {
        foreach ($config as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }

        // Validate required fields
        if (empty($this->openai_api_key)) {
            throw new \InvalidArgumentException('OpenAI API key is required');
        }
        if (empty($this->qdrant_url)) {
            throw new \InvalidArgumentException('Qdrant URL is required');
        }
        if (empty($this->qdrant_api_key)) {
            throw new \InvalidArgumentException('Qdrant API key is required');
        }
        if (empty($this->collection_name)) {
            throw new \InvalidArgumentException('Collection name is required');
        }
    }

    /**
     * Register a post type for indexing
     */
    public function registerPostType(
        string $post_type,
        array $fields = [],
        ?callable $extractor = null
    ): void {
        $this->post_types[$post_type] = [
            'enabled' => true,
            'fields' => $fields,
            'extractor' => $extractor,
        ];
    }

    /**
     * Get registered post types
     */
    public function getPostTypes(): array
    {
        return array_keys(array_filter(
            $this->post_types,
            fn($config) => $config['enabled']
        ));
    }

    /**
     * Get field configuration for a post type
     */
    public function getFieldsForPostType(string $post_type): array
    {
        return $this->post_types[$post_type]['fields'] ?? [];
    }

    /**
     * Get custom extractor for a post type
     */
    public function getExtractorForPostType(string $post_type): ?callable
    {
        return $this->post_types[$post_type]['extractor'] ?? null;
    }
}
