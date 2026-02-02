<?php
/**
 * Example: Query indexed content using RAG
 *
 * This shows how to use the RAG engine with different LLM providers
 */

require_once __DIR__ . '/../vendor/autoload.php';

use DigitalNode\WPQdrantIndexer\Config;
use DigitalNode\WPQdrantIndexer\RAGEngine;

// Import ai-access providers
use AIAccess\Provider\OpenAI\Client as OpenAIClient;
use AIAccess\Provider\Claude\Client as ClaudeClient;
use AIAccess\Provider\Gemini\Client as GeminiClient;

// Create configuration (same as indexing)
$config = new Config([
    'openai_api_key' => env('OPENAI_API_KEY'),
    'qdrant_url' => env('QDRANT_URL'),
    'qdrant_api_key' => env('QDRANT_API_KEY'),
    'collection_name' => 'my_wordpress_docs',
]);

// Choose your LLM provider
// Option 1: OpenAI (gpt-4o-mini - fast and cheap)
$llmClient = new OpenAIClient(env('OPENAI_API_KEY'));
$chat = $llmClient->createChat('gpt-4o-mini');

// Option 2: Anthropic Claude (claude-3-5-haiku - fast and affordable)
// $llmClient = new ClaudeClient(env('ANTHROPIC_API_KEY'));
// $chat = $llmClient->createChat('claude-3-5-haiku-latest');

// Option 3: Google Gemini (gemini-2.5-flash - free tier available)
// $llmClient = new GeminiClient(env('GEMINI_API_KEY'));
// $chat = $llmClient->createChat('gemini-2.5-flash');

// Create RAG engine
$rag = new RAGEngine($config, $chat);

// Optional: Set custom system prompt
$rag->setSystemPrompt("You are a helpful assistant for [Your Company]. Answer questions based on our knowledge base...");

// Query without conversation history
$question = "What products do you offer for restaurants?";
$result = $rag->query($question);

if ($result['success']) {
    echo "Answer: {$result['answer']}\n\n";

    if (!empty($result['sources'])) {
        echo "Sources:\n";
        foreach ($result['sources'] as $source) {
            echo "  - {$source['title']}: {$source['url']}\n";
        }
    }
} else {
    echo "Error: {$result['error']}\n";
}

// Example with conversation history (multi-turn conversation)
$conversation_history = [
    ['role' => 'user', 'content' => 'What is Capture Jet technology?'],
    ['role' => 'assistant', 'content' => 'Capture Jet is a ventilation technology that...'],
];

$follow_up = "How does it compare to traditional hoods?";
$result = $rag->query($follow_up, $conversation_history);

if ($result['success']) {
    echo "\nFollow-up Answer: {$result['answer']}\n";
}
