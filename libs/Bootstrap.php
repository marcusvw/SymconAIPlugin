<?php

declare(strict_types=1);

require_once __DIR__ . '/AgentRuntime.php';
require_once __DIR__ . '/Audit.php';
require_once __DIR__ . '/ToolKit.php';
require_once __DIR__ . '/Index/EmbeddingIndex.php';
require_once __DIR__ . '/Providers/ProviderFactory.php';
require_once __DIR__ . '/Tools/TreeTools.php';
require_once __DIR__ . '/Tools/VariableTools.php';
require_once __DIR__ . '/Tools/InstanceTools.php';
require_once __DIR__ . '/Tools/ScriptTools.php';
require_once __DIR__ . '/Tools/ProfileTools.php';
require_once __DIR__ . '/Tools/SemanticSearchTool.php';

/**
 * Shared assembly used by both AISymconChat and AISymconAgent.
 */
class Bootstrap
{
    public static function buildToolKit(string $locale = 'de'): ToolKit
    {
        $kit = new ToolKit();
        TreeTools::register($kit);
        VariableTools::register($kit);
        InstanceTools::register($kit);
        ScriptTools::register($kit);
        ProfileTools::register($kit);
        SemanticSearchTool::register($kit);

        $locFile = __DIR__ . '/Tools/locales/' . ($locale === 'en' ? 'en' : 'de') . '.json';
        if (is_file($locFile)) {
            $data = json_decode((string) file_get_contents($locFile), true) ?: [];
            $kit->applyLocale($data['tools'] ?? []);
        }
        return $kit;
    }

    public static function loadSystemPrompt(string $locale, ?string $override = null): string
    {
        if ($override !== null && trim($override) !== '') {
            return $override;
        }
        $locFile = __DIR__ . '/Tools/locales/' . ($locale === 'en' ? 'en' : 'de') . '.json';
        $data = is_file($locFile) ? (json_decode((string) file_get_contents($locFile), true) ?: []) : [];
        return (string) ($data['system_prompt'] ?? '');
    }

    /**
     * Build an embedding index if the active provider supports embeddings.
     * Returns null otherwise (semantic_search will then degrade gracefully).
     */
    public static function buildIndex(LLMProvider $provider, string $dataDir, string $embedModel): ?EmbeddingIndex
    {
        if (!$provider->supportsEmbeddings()) {
            return null;
        }
        $path = rtrim($dataDir, '/\\') . DIRECTORY_SEPARATOR . 'index.sqlite';
        return new EmbeddingIndex($path, $provider, $embedModel);
    }
}
