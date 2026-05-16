<?php

declare(strict_types=1);

require_once __DIR__ . '/Messages.php';

interface LLMProvider
{
    /** Human-readable provider name (also used as form dropdown key). */
    public function getName(): string;

    /**
     * Optional model discovery. May return [] if unsupported.
     * @return string[]
     */
    public function listModels(): array;

    /**
     * Run one LLM round-trip.
     *
     * @param LLMMessage[]  $messages
     * @param LLMToolSpec[] $tools
     * @param array{model?:string,temperature?:float,max_tokens?:int,timeout?:int,system?:string} $opts
     */
    public function chat(array $messages, array $tools, array $opts): LLMResponse;

    /**
     * Optional embeddings endpoint. Implementations that do not support
     * embeddings should throw LLMException(KIND_BAD_REQUEST).
     *
     * @param string[] $texts
     * @return float[][]   one vector per input text
     */
    public function embed(array $texts, array $opts): array;

    /** Whether embed() is implemented. */
    public function supportsEmbeddings(): bool;
}
