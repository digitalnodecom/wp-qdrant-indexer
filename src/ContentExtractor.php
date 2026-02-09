<?php

namespace DigitalNode\WPQdrantIndexer;

/**
 * Extract content from WordPress posts for vector indexing.
 *
 * Automatically extracts:
 * - All core WordPress fields (title, content, excerpt, etc.)
 * - ALL post meta (custom fields) with smart conversion
 * - ALL taxonomies (categories, tags, custom taxonomies)
 * - ACF fields (automatically included via post meta)
 * - Featured images converted to URLs
 * - Attachment IDs automatically converted to URLs
 */
class ContentExtractor
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Build a document for a post with all extractable content.
     *
     * @param int $post_id The post ID to build document for.
     * @return array The document array with all fields.
     */
    public static function getDocument(int $post_id): array
    {
        clean_post_cache($post_id);
        $post = get_post($post_id);

        if (!$post) {
            return ['id' => $post_id];
        }

        $document = [
            'id' => (int) $post->ID,
            'title' => $post->post_title,
            'post_content' => wp_strip_all_tags($post->post_content),
            'post_excerpt' => $post->post_excerpt,
            'permalink' => get_permalink($post),
            'type' => $post->post_type,
            'status' => $post->post_status,
            'created_at' => $post->post_date,
            'modified_at' => $post->post_modified,
        ];

        // Add featured image if exists
        if (has_post_thumbnail($post->ID)) {
            $document['featured_image'] = get_the_post_thumbnail_url($post->ID, 'full');
        }

        // Add ALL post meta
        $document = self::addPostMetaToDocument($document, $post);

        // Add ALL taxonomies
        $document = self::addTaxonomiesToDocument($document, $post);

        return $document;
    }

    /**
     * Add ALL post meta to the document.
     * Handles serialized arrays, converts attachment IDs to URLs.
     */
    private static function addPostMetaToDocument(array $document, \WP_Post $post): array
    {
        $all_meta = get_post_meta($post->ID);

        // Blacklist of meta keys to exclude (WordPress internal fields)
        $blacklist = apply_filters('qdrant_indexer_meta_blacklist', [
            '_edit_lock',
            '_edit_last',
            '_wp_old_slug',
            '_wp_old_date',
            '_wp_page_template',
            '_thumbnail_id',
            '_wp_attached_file',
            '_wp_attachment_metadata',
        ]);

        foreach ($all_meta as $meta_key => $meta_values) {
            // Skip private/internal fields (prefixed with _)
            if (strpos($meta_key, '_') === 0) {
                continue;
            }

            // Skip ACF field references (field_ prefixed)
            if (strpos($meta_key, 'field_') === 0) {
                continue;
            }

            // Skip blacklisted keys
            if (in_array($meta_key, $blacklist, true)) {
                continue;
            }

            // Get the first value (WordPress meta is always an array)
            $value = isset($meta_values[0]) ? $meta_values[0] : null;

            // Handle serialized data
            if (is_serialized($value)) {
                $value = maybe_unserialize($value);
            }

            // Convert attachment IDs to URLs
            $value = self::processMetaValue($value);

            // Only add if not empty
            if (!empty($value) || $value === 0 || $value === '0') {
                $document[$meta_key] = $value;
            }
        }

        return $document;
    }

    /**
     * Process meta value - convert attachment IDs to URLs, handle arrays.
     */
    private static function processMetaValue($value)
    {
        // Handle arrays (repeater fields, multi-select, etc.)
        if (is_array($value)) {
            $processed = [];
            foreach ($value as $key => $item) {
                $processed[$key] = self::processMetaValue($item);
            }
            return $processed;
        }

        // Convert attachment IDs to URLs (if it's a valid attachment ID)
        if (is_numeric($value) && absint($value) > 0) {
            $attachment_url = wp_get_attachment_url(absint($value));
            if ($attachment_url) {
                return $attachment_url;
            }
        }

        return $value;
    }

    /**
     * Add all taxonomies for a post to the document.
     */
    private static function addTaxonomiesToDocument(array $document, \WP_Post $post): array
    {
        $taxonomies = get_object_taxonomies($post->post_type);

        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_post_terms($post->ID, $taxonomy);
            if (!empty($terms) && !is_wp_error($terms)) {
                $document[$taxonomy] = wp_list_pluck($terms, 'name');
            }
        }

        return $document;
    }

    /**
     * Convert a document to searchable text.
     *
     * @param array $document The document array.
     * @return string Text representation for embedding.
     */
    public static function documentToText(array $document): string
    {
        $parts = [];

        // Title
        if (!empty($document['title'])) {
            $parts[] = "Title: " . $document['title'];
        }

        // Main content
        if (!empty($document['post_content'])) {
            $parts[] = $document['post_content'];
        }

        // Excerpt
        if (!empty($document['post_excerpt'])) {
            $parts[] = $document['post_excerpt'];
        }

        // Skip these fields when converting to text
        $skip_fields = [
            'id', 'title', 'post_content', 'post_excerpt', 'permalink',
            'type', 'status', 'created_at', 'modified_at', 'featured_image'
        ];

        // Add all other fields (meta, taxonomies)
        foreach ($document as $key => $value) {
            if (in_array($key, $skip_fields)) {
                continue;
            }

            $text_value = self::valueToText($value);
            if (!empty($text_value)) {
                $label = ucwords(str_replace('_', ' ', $key));
                $parts[] = "{$label}: {$text_value}";
            }
        }

        return implode("\n\n", array_filter($parts));
    }

    /**
     * Convert a value to text representation.
     */
    private static function valueToText($value): string
    {
        if (is_string($value)) {
            return trim(wp_strip_all_tags($value));
        }

        if (is_array($value)) {
            $texts = [];
            foreach ($value as $item) {
                $text = self::valueToText($item);
                if (!empty($text)) {
                    $texts[] = $text;
                }
            }
            return implode(', ', $texts);
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Extract searchable text from a post (legacy method for compatibility).
     */
    public function extractContent(\WP_Post $post): string
    {
        $document = self::getDocument($post->ID);
        return self::documentToText($document);
    }

    /**
     * Create metadata payload for Qdrant (legacy method for compatibility).
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
