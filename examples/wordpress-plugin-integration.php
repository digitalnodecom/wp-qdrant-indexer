<?php
/**
 * Example: WordPress Plugin Integration
 *
 * This shows how to integrate the package into a WordPress plugin
 */

// In your WordPress plugin file:

require_once __DIR__ . '/vendor/autoload.php';

use DigitalNode\WPQdrantIndexer\Config;
use DigitalNode\WPQdrantIndexer\RAGEngine;
use AIAccess\Provider\OpenAI\Client as OpenAIClient;

class My_Chatbot_Plugin
{
    private Config $config;
    private RAGEngine $rag;

    public function __construct()
    {
        // Initialize configuration
        $this->config = new Config([
            'openai_api_key' => env('OPENAI_API_KEY'),
            'qdrant_url' => env('QDRANT_URL'),
            'qdrant_api_key' => env('QDRANT_API_KEY'),
            'collection_name' => 'my_chatbot_docs',
        ]);

        // Register AJAX handlers
        add_action('wp_ajax_my_chatbot_query', [$this, 'handle_query']);
        add_action('wp_ajax_nopriv_my_chatbot_query', [$this, 'handle_query']);

        // Register shortcode
        add_shortcode('my_chatbot', [$this, 'render_chatbot']);
    }

    /**
     * Handle chatbot query via AJAX
     */
    public function handle_query()
    {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'my_chatbot_nonce')) {
            wp_send_json_error(['message' => 'Security check failed']);
            return;
        }

        $question = sanitize_text_field($_POST['question']);

        if (empty($question)) {
            wp_send_json_error(['message' => 'Please enter a question']);
            return;
        }

        // Get conversation history
        $conversation_history = isset($_POST['conversation_history'])
            ? json_decode(stripslashes($_POST['conversation_history']), true)
            : [];

        // Limit history
        if (is_array($conversation_history) && count($conversation_history) > 10) {
            $conversation_history = array_slice($conversation_history, -10);
        }

        // Initialize RAG engine (lazy loading)
        if (!isset($this->rag)) {
            $llmClient = new OpenAIClient($this->config->openai_api_key);
            $chat = $llmClient->createChat('gpt-4o-mini');
            $this->rag = new RAGEngine($this->config, $chat);
        }

        // Query RAG engine
        $result = $this->rag->query($question, $conversation_history);

        if ($result['success']) {
            // Update conversation history
            $conversation_history[] = ['role' => 'user', 'content' => $question];
            $conversation_history[] = ['role' => 'assistant', 'content' => $result['answer']];

            wp_send_json_success([
                'answer' => $result['answer'],
                'sources' => $result['sources'] ?? [],
                'conversation_history' => $conversation_history,
            ]);
        } else {
            wp_send_json_error(['message' => $result['error'] ?? 'Failed to generate response']);
        }
    }

    /**
     * Render chatbot shortcode
     */
    public function render_chatbot($atts)
    {
        ob_start();
        ?>
        <div id="my-chatbot-widget">
            <!-- Your chatbot UI here -->
            <div id="chatbot-messages"></div>
            <input type="text" id="chatbot-input" placeholder="Ask a question...">
            <button id="chatbot-send">Send</button>
        </div>

        <script>
        jQuery(document).ready(function($) {
            let conversationHistory = [];

            $('#chatbot-send').on('click', function() {
                const question = $('#chatbot-input').val();

                $.post(ajaxurl, {
                    action: 'my_chatbot_query',
                    nonce: '<?php echo wp_create_nonce('my_chatbot_nonce'); ?>',
                    question: question,
                    conversation_history: JSON.stringify(conversationHistory)
                }, function(response) {
                    if (response.success) {
                        // Display answer
                        $('#chatbot-messages').append(
                            '<div class="message user">' + question + '</div>' +
                            '<div class="message bot">' + response.data.answer + '</div>'
                        );

                        // Update history
                        conversationHistory = response.data.conversation_history;

                        // Clear input
                        $('#chatbot-input').val('');
                    }
                });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }
}

// Initialize plugin
new My_Chatbot_Plugin();
