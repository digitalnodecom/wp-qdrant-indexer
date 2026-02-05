<?php

namespace DigitalNode\WPQdrantIndexer;

/**
 * OpenAI embeddings generator with WordPress caching
 */
class Embedder
{
    private Config $config;
    private int $cached_count = 0;
    private int $new_count = 0;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Get embedding for text with caching
     * Returns array with 'vector' and 'cached' flag
     */
    public function getEmbedding(string $text, string $cache_key): ?array
    {
        if (!$this->config->enable_cache) {
            $vector = $this->generateEmbedding($text);
            if ($vector) {
                $this->new_count++;
                return ['vector' => $vector, 'cached' => false];
            }
            return null;
        }

        // Try cache first
        $content_hash = md5($text);
        $cache_key_full = $this->config->cache_prefix . $cache_key;
        $cache_hash_key = $this->config->cache_prefix . $cache_key . '_hash';

        $cached_hash = get_option($cache_hash_key);
        $cached_embedding = get_option($cache_key_full);

        // Cache hit and content unchanged
        if ($cached_hash === $content_hash && $cached_embedding && is_array($cached_embedding)) {
            $this->cached_count++;
            return ['vector' => $cached_embedding, 'cached' => true];
        }

        // Cache miss or content changed - generate new embedding
        $vector = $this->generateEmbedding($text);

        if ($vector) {
            // Store in cache
            update_option($cache_key_full, $vector, false); // false = don't autoload
            update_option($cache_hash_key, $content_hash, false);

            $this->new_count++;
            return ['vector' => $vector, 'cached' => false];
        }

        return null;
    }

    /**
     * Generate embedding via OpenAI API
     */
    private function generateEmbedding(string $text, int $retry_count = 0): ?array
    {
        // Sanitize text to ensure valid UTF-8
        $text = $this->sanitizeUtf8($text);

        $body = json_encode([
            'model' => 'text-embedding-3-small',
            'input' => $text,
        ]);

        if ($body === false) {
            error_log('OpenAI embedding: json_encode failed - ' . json_last_error_msg());
            return null;
        }

        $response = wp_remote_post('https://api.openai.com/v1/embeddings', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->config->openai_api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => $body,
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            error_log('OpenAI embedding error: ' . $response->get_error_message());
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        // Handle API errors
        if (isset($body['error'])) {
            $error_msg = $body['error']['message'];

            // Retry on rate limit
            if (strpos($error_msg, 'Rate limit') !== false && $retry_count < 3) {
                preg_match('/try again in ([\d.]+)s/', $error_msg, $matches);
                $wait_time = isset($matches[1]) ? ceil((float)$matches[1]) : 10;

                error_log("Rate limit hit. Waiting {$wait_time} seconds... (retry " . ($retry_count + 1) . "/3)");
                sleep($wait_time + 1);

                return $this->generateEmbedding($text, $retry_count + 1);
            }

            error_log('OpenAI API error: ' . $error_msg);
            return null;
        }

        return $body['data'][0]['embedding'] ?? null;
    }

    /**
     * Clear all cached embeddings
     */
    public function clearCache(): int
    {
        global $wpdb;

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like($this->config->cache_prefix) . '%'
            )
        );

        return $deleted ?: 0;
    }

    /**
     * Get cache statistics
     */
    public function getCacheStats(): array
    {
        return [
            'cached' => $this->cached_count,
            'new' => $this->new_count,
            'total' => $this->cached_count + $this->new_count,
            'cache_hit_rate' => $this->cached_count + $this->new_count > 0
                ? round(($this->cached_count / ($this->cached_count + $this->new_count)) * 100, 2)
                : 0,
        ];
    }

    /**
     * Reset statistics
     */
    public function resetStats(): void
    {
        $this->cached_count = 0;
        $this->new_count = 0;
    }

    /**
     * Sanitize string to ensure valid UTF-8
     */
    private function sanitizeUtf8(string $text): string
    {
        // Remove invalid UTF-8 sequences
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        // Remove null bytes and other control characters except newlines/tabs
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        return $text;
    }
}
