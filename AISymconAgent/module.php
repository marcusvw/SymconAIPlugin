<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Bootstrap.php';

class AISymconAgent extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Provider
        $this->RegisterPropertyString('Provider', 'lmstudio');
        $this->RegisterPropertyString('Endpoint', 'http://localhost:1234/v1');
        $this->RegisterPropertyString('ApiKey', '');
        $this->RegisterPropertyString('Model', '');
        $this->RegisterPropertyFloat('Temperature', 0.2);
        $this->RegisterPropertyInteger('MaxTokens', 4096);

        // Embeddings
        $this->RegisterPropertyString('EmbedModel', 'text-embedding-nomic-embed-text-v1.5');

        // Agent
        $this->RegisterPropertyString('Language', 'de');
        $this->RegisterPropertyString('SystemPromptOverride', '');
        $this->RegisterPropertyString('Scope', 'control');
        $this->RegisterPropertyString('Actor', '');
        $this->RegisterPropertyString('AllowedRoots', '[]'); // JSON int[]

        // Budgets
        $this->RegisterPropertyInteger('MaxToolCalls', 16);
        $this->RegisterPropertyInteger('MaxIterations', 0);
        $this->RegisterPropertyInteger('TimeoutSec', 60);

        // Safety
        $this->RegisterPropertyBoolean('DryRun', true);
        $this->RegisterPropertyBoolean('ExposeSecrets', false);
        $this->RegisterPropertyString('AuditVerbosity', 'normal'); // minimal|normal|verbose
        $this->RegisterPropertyString('AuditLogFile', '');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    /**
     * Public scripting entry point. Symcon exposes it as AISYMCON_Ask($id, $prompt).
     */
    public function Ask(string $prompt): string
    {
        $result = $this->runAgent($prompt, /*structured*/ null);
        return $result['text'] ?? '';
    }

    /**
     * Structured-output variant. Returns a JSON string matching the supplied schema.
     */
    public function AskStructured(string $prompt, string $jsonSchema): string
    {
        $augmented = $prompt . "\n\nRespond with a single JSON document matching this schema:\n" . $jsonSchema;
        $result = $this->runAgent($augmented, $jsonSchema);
        return $result['text'] ?? '';
    }

    /**
     * Trigger an index rebuild. Returns the count of indexed objects.
     */
    public function RebuildIndex(): int
    {
        $provider = ProviderFactory::create($this->providerConfig());
        $index = Bootstrap::buildIndex($provider, $this->dataDir(), $this->ReadPropertyString('EmbedModel'));
        if ($index === null) {
            throw new \RuntimeException('Active provider does not support embeddings');
        }
        return $index->rebuild();
    }

    /**
     * Form button: smoke-test the LLM connection.
     */
    public function TestConnection(): string
    {
        try {
            $provider = ProviderFactory::create($this->providerConfig());
            $t0 = microtime(true);
            $resp = $provider->chat(
                [new LLMMessage(LLMMessage::ROLE_USER, 'Reply with exactly the word "ok".')],
                [],
                ['model' => $this->ReadPropertyString('Model'), 'temperature' => 0.0, 'max_tokens' => 16, 'timeout' => 20]
            );
            $latency = round((microtime(true) - $t0) * 1000);
            $msg = sprintf("OK. model=%s, latency=%dms, reply=%s", $resp->model, $latency, trim($resp->text));
            $this->LogMessage($msg, KL_NOTIFY);
            return $msg;
        } catch (\Throwable $e) {
            $msg = 'FAIL: ' . $e->getMessage();
            $this->LogMessage($msg, KL_ERROR);
            return $msg;
        }
    }

    /**
     * Core: run one stateless agent invocation.
     * @return array{text:string,trace:array,error:?string,tool_calls:int}
     */
    private function runAgent(string $prompt, ?string $schemaHint): array
    {
        $provider = ProviderFactory::create($this->providerConfig());
        $locale = $this->ReadPropertyString('Language');
        $kit = Bootstrap::buildToolKit($locale);
        $system = Bootstrap::loadSystemPrompt($locale, $this->ReadPropertyString('SystemPromptOverride'));
        $index = Bootstrap::buildIndex($provider, $this->dataDir(), $this->ReadPropertyString('EmbedModel'));

        $caller = isset($_IPS['SELF']) ? 'script:' . (int) $_IPS['SELF'] : 'internal';
        $audit = new Audit(
            $this->InstanceID,
            $this->ReadPropertyString('Actor') ?: IPS_GetName($this->InstanceID),
            $this->ReadPropertyString('AuditLogFile') ?: null,
            $this->ReadPropertyString('AuditVerbosity')
        );
        $allowedRoots = json_decode($this->ReadPropertyString('AllowedRoots'), true) ?: [];
        $ctx = new ToolContext(
            $this->ReadPropertyString('Scope'),
            $this->ReadPropertyBoolean('DryRun'),
            array_map('intval', $allowedRoots),
            $caller,
            $audit,
            $index
        );

        $budget = new Budget(
            $this->ReadPropertyInteger('MaxToolCalls'),
            $this->ReadPropertyInteger('MaxTokens'),
            $this->ReadPropertyInteger('TimeoutSec'),
            $this->ReadPropertyInteger('MaxIterations')
        );

        $opts = [
            'model' => $this->ReadPropertyString('Model'),
            'temperature' => $this->ReadPropertyFloat('Temperature'),
            'max_tokens' => $this->ReadPropertyInteger('MaxTokens'),
        ];

        $runtime = new AgentRuntime($provider, $kit, $ctx, $system, $opts);
        $history = [new LLMMessage(LLMMessage::ROLE_USER, $prompt)];
        $out = $runtime->run($history, $budget);
        return $out['result']->toArray();
    }

    private function providerConfig(): array
    {
        return [
            'provider' => $this->ReadPropertyString('Provider'),
            'endpoint' => $this->ReadPropertyString('Endpoint'),
            'api_key' => $this->ReadPropertyString('ApiKey'),
        ];
    }

    private function dataDir(): string
    {
        // IPS_GetKernelDir() returns the writable Symcon data root (e.g. C:\ProgramData\Symcon\).
        $dir = IPS_GetKernelDir() . 'media' . DIRECTORY_SEPARATOR . 'AISymcon' . DIRECTORY_SEPARATOR . $this->InstanceID;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }
}
