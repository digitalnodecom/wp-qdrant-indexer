<?php

namespace DigitalNode\WPQdrantIndexer;

/**
 * Qdrant vector database client for WordPress
 */
class QdrantClient
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Create a new collection (deletes existing if present)
     */
    public function createCollection(bool $delete_existing = true): bool
    {
        if ($delete_existing) {
            $this->deleteCollection();
        }

        $response = $this->request('PUT', "/collections/{$this->config->collection_name}", [
            'vectors' => [
                'size' => $this->config->vector_size,
                'distance' => $this->config->distance_metric,
            ],
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        // Create index on content_hash for fast embedding cache lookups
        $this->createPayloadIndex('content_hash', 'keyword');

        return true;
    }

    /**
     * Create a payload index for fast filtering
     */
    private function createPayloadIndex(string $field_name, string $schema_type): bool
    {
        $response = $this->request(
            'PUT',
            "/collections/{$this->config->collection_name}/index",
            [
                'field_name' => $field_name,
                'field_schema' => $schema_type,
            ]
        );

        return !is_wp_error($response);
    }

    /**
     * Delete a collection
     */
    public function deleteCollection(): bool
    {
        $response = $this->request('DELETE', "/collections/{$this->config->collection_name}");
        return !is_wp_error($response);
    }

    /**
     * Check if collection exists
     */
    public function collectionExists(): bool
    {
        $response = $this->request('GET', "/collections/{$this->config->collection_name}");
        return !is_wp_error($response);
    }

    /**
     * Upload points to collection
     */
    public function uploadPoints(array $points): bool
    {
        $response = $this->request('PUT', "/collections/{$this->config->collection_name}/points", [
            'points' => $points,
        ]);

        return !is_wp_error($response);
    }

    /**
     * Search for similar vectors
     */
    public function search(
        array $vector,
        int $limit = 5,
        float $score_threshold = 0.5,
        bool $with_payload = true
    ): array {
        $response = $this->request('POST', "/collections/{$this->config->collection_name}/points/search", [
            'vector' => $vector,
            'limit' => $limit,
            'score_threshold' => $score_threshold,
            'with_payload' => $with_payload,
        ]);

        if (is_wp_error($response)) {
            error_log('Qdrant search error: ' . $response->get_error_message());
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['result'] ?? [];
    }

    /**
     * Search for existing point by content hash
     * Used for embedding cache across different sites
     */
    public function searchByContentHash(string $content_hash): ?array
    {
        $response = $this->request('POST', "/collections/{$this->config->collection_name}/points/scroll", [
            'filter' => [
                'must' => [
                    [
                        'key' => 'content_hash',
                        'match' => ['value' => $content_hash]
                    ]
                ]
            ],
            'limit' => 1,
            'with_payload' => true,
            'with_vector' => true,
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $points = $body['result']['points'] ?? [];

        return !empty($points) ? $points[0] : null;
    }

    /**
     * Make a request to Qdrant API
     */
    private function request(string $method, string $endpoint, ?array $body = null)
    {
        $url = rtrim($this->config->qdrant_url, '/') . $endpoint;

        $args = [
            'method' => $method,
            'headers' => [
                'api-key' => $this->config->qdrant_api_key,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 60,
        ];

        if ($body !== null) {
            // Sanitize body to ensure valid UTF-8 before encoding
            $body = $this->sanitizeForJson($body);
            $encoded = json_encode($body);

            if ($encoded === false) {
                error_log('Qdrant: json_encode failed - ' . json_last_error_msg());
                return new \WP_Error('json_encode_failed', json_last_error_msg());
            }

            $args['body'] = $encoded;
        }

        return wp_remote_request($url, $args);
    }

    /**
     * Recursively sanitize data for JSON encoding (fix invalid UTF-8)
     */
    private function sanitizeForJson($data)
    {
        if (is_string($data)) {
            // Remove invalid UTF-8 sequences
            $data = mb_convert_encoding($data, 'UTF-8', 'UTF-8');
            // Remove null bytes and other control characters except newlines/tabs
            $data = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $data);
            return $data;
        }

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->sanitizeForJson($value);
            }
        }

        return $data;
    }

    /**
     * Get collection info
     */
    public function getCollectionInfo(): ?array
    {
        $response = $this->request('GET', "/collections/{$this->config->collection_name}");

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['result'] ?? null;
    }
}
