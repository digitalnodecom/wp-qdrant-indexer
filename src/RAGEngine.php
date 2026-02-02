<?php

namespace DigitalNode\WPQdrantIndexer;

use AIAccess\Chat\Chat;

/**
 * RAG (Retrieval-Augmented Generation) Engine
 * Handles the complete query pipeline: question → embedding → search → context → LLM → answer
 */
class RAGEngine
{
    private Config $config;
    private QdrantClient $qdrant;
    private Embedder $embedder;
    private Chat $llm;
    private string $system_prompt;

    public function __construct(
        Config $config,
        Chat $llm,
        ?string $system_prompt = null
    ) {
        $this->config = $config;
        $this->qdrant = new QdrantClient($config);
        $this->embedder = new Embedder($config);
        $this->llm = $llm;
        $this->system_prompt = $system_prompt ?? $this->getDefaultSystemPrompt();
    }

    /**
     * Query the RAG system
     */
    public function query(
        string $question,
        array $conversation_history = [],
        int $search_limit = 5,
        float $score_threshold = 0.5
    ): array {
        // Step 1: Generate embedding for the question
        $embedding = $this->embedder->getEmbedding($question, 'query_' . md5($question));

        if (!$embedding) {
            return [
                'success' => false,
                'error' => 'Failed to generate embedding for question',
            ];
        }

        // Step 2: Search Qdrant for relevant content
        $search_results = $this->qdrant->search(
            $embedding['vector'],
            $search_limit,
            $score_threshold,
            true
        );

        // Step 3: Check if we have relevant results
        if (empty($search_results)) {
            return [
                'success' => true,
                'answer' => "I couldn't find relevant information in the knowledge base to answer your question.",
                'sources' => [],
                'no_results' => true,
            ];
        }

        // Step 4: Build context from search results
        $context = $this->buildContext($search_results);

        // Step 5: Generate answer using LLM
        try {
            // Start chat with system prompt
            $this->llm->addMessage('system', $this->system_prompt);

            // Add conversation history
            foreach ($conversation_history as $msg) {
                if (isset($msg['role']) && isset($msg['content'])) {
                    $this->llm->addMessage($msg['role'], $msg['content']);
                }
            }

            // Add current question with context
            $prompt = "Context from knowledge base:\n\n{$context}\n\n---\n\nUser question: {$question}";
            $response = $this->llm->sendMessage($prompt);

            $answer = $response->getText() ?? 'No response generated';

            return [
                'success' => true,
                'answer' => $answer,
                'sources' => $this->extractSources($search_results),
                'search_results_count' => count($search_results),
            ];
        } catch (\Exception $e) {
            error_log('RAG Engine LLM error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to generate response: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Build context string from search results
     */
    private function buildContext(array $results): string
    {
        $context_parts = [];

        foreach ($results as $result) {
            $payload = $result['payload'] ?? [];
            $text = $payload['text'] ?? '';
            $title = $payload['title'] ?? '';

            if ($title) {
                $context_parts[] = "## {$title}\n\n{$text}";
            } else {
                $context_parts[] = $text;
            }
        }

        return implode("\n\n---\n\n", $context_parts);
    }

    /**
     * Extract source URLs from search results
     */
    private function extractSources(array $results): array
    {
        $sources = [];

        foreach ($results as $result) {
            $payload = $result['payload'] ?? [];

            if (!empty($payload['url'])) {
                $sources[] = [
                    'title' => $payload['title'] ?? 'Source',
                    'url' => $payload['url'],
                    'type' => $payload['type'] ?? '',
                ];
            }
        }

        return array_unique($sources, SORT_REGULAR);
    }

    /**
     * Default system prompt
     */
    private function getDefaultSystemPrompt(): string
    {
        return "You are a helpful AI assistant that answers questions based ONLY on the provided context from the knowledge base.

Your role:
- Answer questions accurately using only the provided context
- Be helpful, professional, and concise
- If the context doesn't contain enough information, say so honestly
- Do not make up information not present in the context
- Cite specific details from the context when relevant

Format:
- Use clear, simple language
- Keep responses concise but complete
- Use bullet points only when listing multiple items";
    }

    /**
     * Set custom system prompt
     */
    public function setSystemPrompt(string $prompt): void
    {
        $this->system_prompt = $prompt;
    }

    /**
     * Get the Qdrant client
     */
    public function getQdrantClient(): QdrantClient
    {
        return $this->qdrant;
    }

    /**
     * Get the Embedder
     */
    public function getEmbedder(): Embedder
    {
        return $this->embedder;
    }
}
