<?php

declare(strict_types=1);

require_once __DIR__ . '/LLMProvider.php';
require_once __DIR__ . '/LMStudioProvider.php';
require_once __DIR__ . '/ClaudeProvider.php';

class ProviderFactory
{
    /**
     * @param array{provider:string, endpoint?:string, api_key?:string} $cfg
     */
    public static function create(array $cfg): LLMProvider
    {
        $name = strtolower((string) ($cfg['provider'] ?? 'lmstudio'));
        switch ($name) {
            case 'lmstudio':
            case 'openai-compatible':
                $endpoint = $cfg['endpoint'] ?? 'http://localhost:1234/v1';
                return new LMStudioProvider($endpoint, (string) ($cfg['api_key'] ?? ''));
            case 'claude':
            case 'anthropic':
                $key = (string) ($cfg['api_key'] ?? '');
                if ($key === '') {
                    throw new LLMException(LLMException::KIND_AUTH, 'Claude provider requires an API key');
                }
                return new ClaudeProvider($key);
            default:
                throw new LLMException(LLMException::KIND_BAD_REQUEST, "Unknown provider: $name");
        }
    }
}
