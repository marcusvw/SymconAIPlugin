<?php

declare(strict_types=1);

/**
 * Canonical message / tool DTOs used by every LLMProvider.
 *
 * Providers translate to and from these. The AgentRuntime never sees
 * provider-specific payloads.
 */

class LLMMessage
{
    public const ROLE_SYSTEM = 'system';
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';
    public const ROLE_TOOL = 'tool';

    public string $role;
    public string $content;

    /** @var LLMToolCall[] */
    public array $toolCalls = [];

    /** Set when role === 'tool'. */
    public ?string $toolCallId = null;
    public ?string $toolName = null;

    public function __construct(string $role, string $content = '', array $toolCalls = [], ?string $toolCallId = null, ?string $toolName = null)
    {
        $this->role = $role;
        $this->content = $content;
        $this->toolCalls = $toolCalls;
        $this->toolCallId = $toolCallId;
        $this->toolName = $toolName;
    }

    public function toArray(): array
    {
        return [
            'role' => $this->role,
            'content' => $this->content,
            'toolCalls' => array_map(fn(LLMToolCall $c) => $c->toArray(), $this->toolCalls),
            'toolCallId' => $this->toolCallId,
            'toolName' => $this->toolName,
        ];
    }

    public static function fromArray(array $a): self
    {
        $msg = new self(
            $a['role'],
            $a['content'] ?? '',
            array_map(fn($c) => LLMToolCall::fromArray($c), $a['toolCalls'] ?? []),
            $a['toolCallId'] ?? null,
            $a['toolName'] ?? null
        );
        return $msg;
    }
}

class LLMToolCall
{
    public string $id;
    public string $name;
    /** @var array<string,mixed> */
    public array $arguments;

    public function __construct(string $id, string $name, array $arguments)
    {
        $this->id = $id;
        $this->name = $name;
        $this->arguments = $arguments;
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'arguments' => $this->arguments];
    }

    public static function fromArray(array $a): self
    {
        return new self($a['id'], $a['name'], $a['arguments'] ?? []);
    }
}

class LLMToolSpec
{
    public string $name;
    public string $description;
    /** JSON-schema object describing input parameters. */
    public array $inputSchema;

    public function __construct(string $name, string $description, array $inputSchema)
    {
        $this->name = $name;
        $this->description = $description;
        $this->inputSchema = $inputSchema;
    }
}

class LLMResponse
{
    public string $text = '';
    /** @var LLMToolCall[] */
    public array $toolCalls = [];
    public string $finishReason = 'stop';
    public array $usage = [];
    public string $model = '';

    public function hasToolCalls(): bool
    {
        return count($this->toolCalls) > 0;
    }
}

class LLMException extends \RuntimeException
{
    public const KIND_AUTH = 'auth';
    public const KIND_RATE = 'rate_limit';
    public const KIND_TIMEOUT = 'timeout';
    public const KIND_BAD_REQUEST = 'bad_request';
    public const KIND_SERVER = 'server';
    public const KIND_TRANSPORT = 'transport';
    public const KIND_BAD_TOOL_ARGS = 'bad_tool_args';

    public string $kind;

    public function __construct(string $kind, string $message, ?\Throwable $prev = null)
    {
        parent::__construct($message, 0, $prev);
        $this->kind = $kind;
    }
}
