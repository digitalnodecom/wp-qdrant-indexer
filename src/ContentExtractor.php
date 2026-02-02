<?php

namespace DigitalNode\WPQdrantIndexer;

/**
 * Extract content from WordPress posts including Gutenberg blocks and ACF fields
 */
class ContentExtractor
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Extract searchable text from a post
     */
    public function extractContent(\WP_Post $post): string
    {
        $post_type = $post->post_type;

        // Check if there's a custom extractor for this post type
        $custom_extractor = $this->config->getExtractorForPostType($post_type);
        if ($custom_extractor !== null) {
            return call_user_func($custom_extractor, $post);
        }

        // Default extraction
        return $this->defaultExtraction($post);
    }

    /**
     * Default content extraction
     */
    private function defaultExtraction(\WP_Post $post): string
    {
        $parts = [];

        // Title
        $parts[] = $post->post_title;

        // Post content
        if ($post->post_content) {
            $parts[] = $this->cleanContent($post->post_content);
        }

        // Excerpt
        if ($post->post_excerpt) {
            $parts[] = $post->post_excerpt;
        }

        // Extract from Gutenberg blocks
        $blocks_content = $this->extractGutenbergContent($post->post_content);
        if ($blocks_content) {
            $parts[] = $blocks_content;
        }

        // Extract ACF fields
        $fields = $this->config->getFieldsForPostType($post->post_type);
        if (!empty($fields)) {
            $acf_content = $this->extractACFFields($post->ID, $fields);
            if ($acf_content) {
                $parts[] = $acf_content;
            }
        }

        // Extract from all post meta (fallback if no specific fields configured)
        if (empty($fields)) {
            $meta_content = $this->extractPostMeta($post->ID);
            if ($meta_content) {
                $parts[] = $meta_content;
            }
        }

        // Taxonomies
        $taxonomies = get_object_taxonomies($post->post_type);
        foreach ($taxonomies as $taxonomy) {
            $terms = get_the_terms($post->ID, $taxonomy);
            if ($terms && !is_wp_error($terms)) {
                $term_names = array_map(fn($term) => $term->name, $terms);
                $tax_object = get_taxonomy($taxonomy);
                $tax_label = $tax_object ? $tax_object->label : ucfirst($taxonomy);
                $parts[] = $tax_label . ': ' . implode(', ', $term_names);
            }
        }

        return implode("\n\n", array_filter($parts));
    }

    /**
     * Extract specific ACF fields
     */
    private function extractACFFields(int $post_id, array $fields): string
    {
        if (!function_exists('get_field')) {
            return '';
        }

        $parts = [];

        foreach ($fields as $field) {
            $value = get_field($field, $post_id);

            if (empty($value)) {
                continue;
            }

            // Handle different field types
            if (is_string($value)) {
                $parts[] = strip_tags($value);
            } elseif (is_array($value)) {
                $this->extractTextFromArray($value, $parts);
            } elseif ($value instanceof \WP_Post) {
                $parts[] = $value->post_title;
                if ($value->post_content) {
                    $parts[] = strip_tags(wp_trim_words($value->post_content, 50));
                }
            }
        }

        return implode("\n\n", array_filter($parts));
    }

    /**
     * Extract from post meta
     */
    private function extractPostMeta(int $post_id): string
    {
        $parts = [];
        $all_meta = get_post_meta($post_id);

        if (!$all_meta) {
            return '';
        }

        foreach ($all_meta as $key => $values) {
            // Skip private fields and old data
            if (strpos($key, '_') === 0 || strpos($key, 'everblox_') === 0) {
                continue;
            }

            foreach ($values as $value) {
                // Try to unserialize
                if (is_string($value) && (strpos($value, 'a:') === 0 || strpos($value, 's:') === 0)) {
                    $unserialized = @unserialize($value);
                    if ($unserialized !== false) {
                        $value = $unserialized;
                    }
                }

                // Extract text
                if (is_string($value) && strlen(strip_tags($value)) > 20) {
                    $parts[] = strip_tags($value);
                } elseif (is_array($value)) {
                    $this->extractTextFromArray($value, $parts);
                }
            }
        }

        return implode("\n\n", array_filter($parts));
    }

    /**
     * Recursively extract text from arrays
     */
    private function extractTextFromArray($arr, array &$output): void
    {
        if (!is_array($arr)) {
            if (is_string($arr)) {
                $cleaned = strip_tags($arr);
                if (strlen($cleaned) > 20) {
                    $output[] = $cleaned;
                }
            }
            return;
        }

        foreach ($arr as $key => $value) {
            // Skip private keys
            if (is_string($key) && (strpos($key, '_') === 0 || strpos($key, 'everblox_') === 0)) {
                continue;
            }

            if (is_array($value)) {
                $this->extractTextFromArray($value, $output);
            } elseif (is_string($value)) {
                $cleaned = strip_tags($value);
                if (strlen($cleaned) > 20) {
                    $output[] = $cleaned;
                }
            }
        }
    }

    /**
     * Extract content from Gutenberg blocks
     */
    private function extractGutenbergContent(string $content): string
    {
        $extracted = [];
        $offset = 0;

        while (preg_match('/<!-- wp:(\S+) (\{)/s', $content, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $match[2][1]; // Position of opening {
            $json = $this->extractBalancedJson($content, $start);

            if ($json) {
                $data = json_decode($json, true);
                if ($data) {
                    $this->extractTextFromArray($data, $extracted);
                }
            }

            $offset = $start + strlen($json ?: '{}');
        }

        return implode("\n\n", $extracted);
    }

    /**
     * Extract balanced JSON from content
     */
    private function extractBalancedJson(string $content, int $start): ?string
    {
        $depth = 0;
        $len = strlen($content);
        $in_string = false;
        $escape_next = false;

        for ($i = $start; $i < $len; $i++) {
            $char = $content[$i];

            if ($escape_next) {
                $escape_next = false;
                continue;
            }

            if ($char === '\\') {
                $escape_next = true;
                continue;
            }

            if ($char === '"') {
                $in_string = !$in_string;
                continue;
            }

            if (!$in_string) {
                if ($char === '{') {
                    $depth++;
                } elseif ($char === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return substr($content, $start, $i - $start + 1);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Clean HTML content
     */
    private function cleanContent(string $content): string
    {
        // Remove shortcodes
        $content = strip_shortcodes($content);
        // Remove HTML
        $content = wp_strip_all_tags($content);
        // Normalize whitespace
        $content = preg_replace('/\s+/', ' ', $content);
        // Trim
        return trim($content);
    }

    /**
     * Create metadata payload for Qdrant
     */
    public function extractMetadata(\WP_Post $post): array
    {
        return [
            'title' => $post->post_title,
            'url' => get_permalink($post->ID),
            'type' => $post->post_type,
            'excerpt' => $post->post_excerpt ?: wp_trim_words(strip_tags($post->post_content), 30, '...'),
        ];
    }
}
