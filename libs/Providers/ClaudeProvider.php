<?php

declare(strict_types=1);

require_once __DIR__ . '/LLMProvider.php';
require_once __DIR__ . '/Transport.php';

/**
 * Anthropic Messages API client.
 *
 * Uses native tool use (input_schema). Embeddings are not supported by the
 * Anthropic API; supportsEmbeddings() returns false.
 */
class ClaudeProvider implements LLMProvider
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    private string $apiKey;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    public function getName(): string
    {
        return 'claude';
    }

    public function listModels(): array
    {
        return [
            'claude-opus-4-7',
            'claude-sonnet-4-6',
            'claude-haiku-4-5-20251001',
        ];
    }

    public function chat(array $messages, array $tools, array $opts): LLMResponse
    {
        $body = [
            'model' => $opts['model'] ?? 'claude-sonnet-4-6',
            'max_tokens' => (int) ($opts['max_tokens'] ?? 4096),
            'temperature' => (float) ($opts['temperature'] ?? 0.2),
            'messages' => $this->encodeMessages($messages),
        ];
        if (!empty($opts['system'])) {
            $body['system'] = $opts['system'];
        }
        if (!empty($tools)) {
            $body['tools'] = array_map(function (LLMToolSpec $t) {
                return [
                    'name' => $t->name,
                    'description' => $t->description,
                    'input_schema' => $t->inputSchema,
                ];
            }, $tools);
        }

        $resp = Transport::postJson(
            self::API_URL,
            [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => self::API_VERSION,
            ],
            $body,
            (int) ($opts['timeout'] ?? 60)
        );

        return $this->decodeResponse($resp);
    }

    public function embed(array $texts, array $opts): array
    {
        throw new LLMException(
            LLMException::KIND_BAD_REQUEST,
            'Claude provider does not implement embeddings. Configure a separate embedding source.'
        );
    }

    public function supportsEmbeddings(): bool
    {
        return false;
    }

    /**
     * @param LLMMessage[] $messages
     */
    private function encodeMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            if ($m->role === LLMMessage::ROLE_SYSTEM) {
                continue; // system is handled separately
            }
            if ($m->role === LLMMessage::ROLE_TOOL) {
                $out[] = [
                    'role' => 'user',
                    'content' => [[
                        'type' => 'tool_result',
                        'tool_use_id' => $m->toolCallId ?? '',
                        'content' => $m->content,
                    ]],
                ];
                continue;
            }
            if ($m->role === LLMMessage::ROLE_ASSISTANT) {
                $blocks = [];
                if ($m->content !== '') {
                    $blocks[] = ['type' => 'text', 'text' => $m->content];
                }
                foreach ($m->toolCalls as $tc) {
                    $blocks[] = [
                        'type' => 'tool_use',
                        'id' => $tc->id,
                        'name' => $tc->name,
                        'input' => (object) $tc->arguments,
                    ];
                }
                if (empty($blocks)) {
                    $blocks[] = ['type' => 'text', 'text' => ''];
                }
                $out[] = ['role' => 'assistant', 'content' => $blocks];
                continue;
            }
            // user
            $out[] = ['role' => 'user', 'content' => $m->content];
        }
        return $out;
    }

    private function decodeResponse(array $resp): LLMResponse
    {
        $out = new LLMResponse();
        $out->model = (string) ($resp['model'] ?? '');
        $out->usage = $resp['usage'] ?? [];
        $out->finishReason = (string) ($resp['stop_reason'] ?? 'stop');

        foreach (($resp['content'] ?? []) as $block) {
            $type = $block['type'] ?? '';
            if ($type === 'text') {
                $out->text .= (string) ($block['text'] ?? '');
            } elseif ($type === 'tool_use') {
                $input = $block['input'] ?? [];
                if (is_object($input)) {
                    $input = (array) $input;
                }
                $out->toolCalls[] = new LLMToolCall(
                    (string) ($block['id'] ?? uniqid('tc_', true)),
                    (string) ($block['name'] ?? ''),
                    is_array($input) ? $input : []
                );
            }
        }
        return $out;
    }
}
