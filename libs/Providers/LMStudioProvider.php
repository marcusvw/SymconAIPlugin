<?php

declare(strict_types=1);

require_once __DIR__ . '/LLMProvider.php';
require_once __DIR__ . '/Transport.php';

/**
 * OpenAI-compatible chat completions client targeting LM Studio.
 *
 * Tested against the /v1 surface exposed by LM Studio Server. Compatible
 * with any other OpenAI-style endpoint that supports `tools` and
 * `tool_choice="auto"`.
 */
class LMStudioProvider implements LLMProvider
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct(string $baseUrl, string $apiKey = '')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
    }

    public function getName(): string
    {
        return 'lmstudio';
    }

    public function listModels(): array
    {
        try {
            $resp = Transport::getJson($this->baseUrl . '/models', $this->headers());
            $ids = [];
            foreach (($resp['data'] ?? []) as $m) {
                if (isset($m['id'])) {
                    $ids[] = (string) $m['id'];
                }
            }
            return $ids;
        } catch (LLMException $e) {
            return [];
        }
    }

    public function chat(array $messages, array $tools, array $opts): LLMResponse
    {
        $body = [
            'model' => $opts['model'] ?? 'local-model',
            'messages' => $this->encodeMessages($messages, $opts['system'] ?? null),
            'temperature' => (float) ($opts['temperature'] ?? 0.2),
        ];
        if (isset($opts['max_tokens'])) {
            $body['max_tokens'] = (int) $opts['max_tokens'];
        }
        if (!empty($tools)) {
            $body['tools'] = array_map([$this, 'encodeTool'], $tools);
            $body['tool_choice'] = 'auto';
        }

        $resp = Transport::postJson(
            $this->baseUrl . '/chat/completions',
            $this->headers(),
            $body,
            (int) ($opts['timeout'] ?? 60)
        );

        return $this->decodeResponse($resp);
    }

    public function embed(array $texts, array $opts): array
    {
        $body = [
            'model' => $opts['model'] ?? 'text-embedding-nomic-embed-text-v1.5',
            'input' => $texts,
        ];
        $resp = Transport::postJson(
            $this->baseUrl . '/embeddings',
            $this->headers(),
            $body,
            (int) ($opts['timeout'] ?? 60)
        );
        $vectors = [];
        foreach (($resp['data'] ?? []) as $item) {
            $vectors[] = array_map('floatval', $item['embedding'] ?? []);
        }
        if (count($vectors) !== count($texts)) {
            throw new LLMException(LLMException::KIND_SERVER, 'Embedding count mismatch');
        }
        return $vectors;
    }

    public function supportsEmbeddings(): bool
    {
        return true;
    }

    private function headers(): array
    {
        $h = [];
        if ($this->apiKey !== '') {
            $h['Authorization'] = 'Bearer ' . $this->apiKey;
        }
        return $h;
    }

    /**
     * @param LLMMessage[] $messages
     */
    private function encodeMessages(array $messages, ?string $system): array
    {
        $out = [];
        if ($system !== null && $system !== '') {
            $out[] = ['role' => 'system', 'content' => $system];
        }
        foreach ($messages as $m) {
            if ($m->role === LLMMessage::ROLE_TOOL) {
                $out[] = [
                    'role' => 'tool',
                    'tool_call_id' => $m->toolCallId ?? '',
                    'name' => $m->toolName ?? '',
                    'content' => $m->content,
                ];
                continue;
            }
            $entry = ['role' => $m->role, 'content' => $m->content];
            if ($m->role === LLMMessage::ROLE_ASSISTANT && !empty($m->toolCalls)) {
                $entry['tool_calls'] = [];
                foreach ($m->toolCalls as $tc) {
                    $entry['tool_calls'][] = [
                        'id' => $tc->id,
                        'type' => 'function',
                        'function' => [
                            'name' => $tc->name,
                            'arguments' => json_encode($tc->arguments, JSON_UNESCAPED_UNICODE),
                        ],
                    ];
                }
                if ($entry['content'] === '') {
                    $entry['content'] = null;
                }
            }
            $out[] = $entry;
        }
        return $out;
    }

    private function encodeTool(LLMToolSpec $t): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $t->name,
                'description' => $t->description,
                'parameters' => $t->inputSchema,
            ],
        ];
    }

    private function decodeResponse(array $resp): LLMResponse
    {
        $out = new LLMResponse();
        $out->model = (string) ($resp['model'] ?? '');
        $out->usage = $resp['usage'] ?? [];
        $choice = $resp['choices'][0] ?? null;
        if ($choice === null) {
            throw new LLMException(LLMException::KIND_SERVER, 'No choices in response');
        }
        $out->finishReason = (string) ($choice['finish_reason'] ?? 'stop');
        $msg = $choice['message'] ?? [];
        $out->text = is_string($msg['content'] ?? null) ? $msg['content'] : '';
        foreach (($msg['tool_calls'] ?? []) as $tc) {
            $fn = $tc['function'] ?? [];
            $args = [];
            if (isset($fn['arguments'])) {
                $decoded = json_decode((string) $fn['arguments'], true);
                $args = is_array($decoded) ? $decoded : [];
            }
            $out->toolCalls[] = new LLMToolCall(
                (string) ($tc['id'] ?? uniqid('tc_', true)),
                (string) ($fn['name'] ?? ''),
                $args
            );
        }
        return $out;
    }
}
