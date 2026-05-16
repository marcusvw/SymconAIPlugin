<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Bootstrap.php';

class AISymconChat extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Provider', 'lmstudio');
        $this->RegisterPropertyString('Endpoint', 'http://localhost:1234/v1');
        $this->RegisterPropertyString('ApiKey', '');
        $this->RegisterPropertyString('Model', '');
        $this->RegisterPropertyFloat('Temperature', 0.2);
        $this->RegisterPropertyInteger('MaxTokens', 4096);

        $this->RegisterPropertyString('EmbedModel', 'text-embedding-nomic-embed-text-v1.5');

        $this->RegisterPropertyString('Language', 'de');
        $this->RegisterPropertyString('SystemPromptOverride', '');
        $this->RegisterPropertyString('Scope', 'control');
        $this->RegisterPropertyString('Actor', '');
        $this->RegisterPropertyString('AllowedRoots', '[]');

        $this->RegisterPropertyInteger('MaxToolCalls', 16);
        $this->RegisterPropertyInteger('MaxIterations', 0);
        $this->RegisterPropertyInteger('TimeoutSec', 60);
        $this->RegisterPropertyInteger('HistoryLimit', 30);

        $this->RegisterPropertyBoolean('DryRun', true);
        $this->RegisterPropertyBoolean('ExposeSecrets', false);
        $this->RegisterPropertyString('AuditVerbosity', 'normal');
        $this->RegisterPropertyString('AuditLogFile', '');

        // Visible state variables.
        $this->RegisterVariableString('InputPrompt', $this->Translate('Prompt'), '', 10);
        $this->EnableAction('InputPrompt');
        $this->RegisterVariableString('LastResponse', $this->Translate('Last response'), '~TextBox', 20);
        $this->RegisterVariableBoolean('Busy', $this->Translate('Busy'), '~Switch', 30);
        $this->RegisterVariableString('History', $this->Translate('History (JSON)'), '~TextBox', 40);
        SetValue($this->GetIDForIdent('History'), '[]');
        SetValue($this->GetIDForIdent('Busy'), false);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'InputPrompt') {
            SetValue($this->GetIDForIdent('InputPrompt'), $Value);
            $this->Send((string) $Value);
            return;
        }
        if ($Ident === 'Send') {
            $this->Send((string) $Value);
            return;
        }
        if ($Ident === 'Reset') {
            $this->ResetHistory();
            return;
        }
        parent::RequestAction($Ident, $Value);
    }

    public function Send(string $prompt): string
    {
        if (trim($prompt) === '') {
            return '';
        }
        SetValue($this->GetIDForIdent('Busy'), true);
        try {
            $reply = $this->runTurn($prompt);
            SetValue($this->GetIDForIdent('LastResponse'), $reply);
            return $reply;
        } finally {
            SetValue($this->GetIDForIdent('Busy'), false);
        }
    }

    public function ResetHistory(): void
    {
        SetValue($this->GetIDForIdent('History'), '[]');
        SetValue($this->GetIDForIdent('LastResponse'), '');
    }

    public function RebuildIndex(): int
    {
        $provider = ProviderFactory::create($this->providerConfig());
        $index = Bootstrap::buildIndex($provider, $this->dataDir(), $this->ReadPropertyString('EmbedModel'));
        if ($index === null) {
            throw new \RuntimeException('Active provider does not support embeddings');
        }
        return $index->rebuild();
    }

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
            return sprintf("OK. model=%s, latency=%dms, reply=%s", $resp->model, $latency, trim($resp->text));
        } catch (\Throwable $e) {
            return 'FAIL: ' . $e->getMessage();
        }
    }

    private function runTurn(string $prompt): string
    {
        $provider = ProviderFactory::create($this->providerConfig());
        $locale = $this->ReadPropertyString('Language');
        $kit = Bootstrap::buildToolKit($locale);
        $system = Bootstrap::loadSystemPrompt($locale, $this->ReadPropertyString('SystemPromptOverride'));
        $index = Bootstrap::buildIndex($provider, $this->dataDir(), $this->ReadPropertyString('EmbedModel'));

        $caller = 'chat:' . $this->InstanceID;
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

        $history = $this->loadHistory();
        $history[] = new LLMMessage(LLMMessage::ROLE_USER, $prompt);

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
        $out = $runtime->run($history, $budget);
        $this->saveHistory($out['messages']);
        return $out['result']->text;
    }

    /** @return LLMMessage[] */
    private function loadHistory(): array
    {
        $raw = json_decode(GetValue($this->GetIDForIdent('History')), true);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $m) {
            $out[] = LLMMessage::fromArray($m);
        }
        return $out;
    }

    /** @param LLMMessage[] $messages */
    private function saveHistory(array $messages): void
    {
        $limit = max(2, $this->ReadPropertyInteger('HistoryLimit'));
        if (count($messages) > $limit) {
            $messages = array_slice($messages, -$limit);
        }
        $arr = array_map(fn(LLMMessage $m) => $m->toArray(), $messages);
        SetValue($this->GetIDForIdent('History'), json_encode($arr, JSON_UNESCAPED_UNICODE));
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
        $dir = IPS_GetKernelDir() . 'media' . DIRECTORY_SEPARATOR . 'AISymcon' . DIRECTORY_SEPARATOR . $this->InstanceID;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }
}
