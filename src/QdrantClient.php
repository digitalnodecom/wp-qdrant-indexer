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
            $args['body'] = json_encode($body);
        }

        return wp_remote_request($url, $args);
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
