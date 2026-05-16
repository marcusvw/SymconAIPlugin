<?php

declare(strict_types=1);

require_once __DIR__ . '/../ToolKit.php';

class ScriptTools
{
    public static function register(ToolKit $kit): void
    {
        $kit->register(new ToolDef(
            new LLMToolSpec('get_script_source', 'Return the PHP source of a Symcon script.', [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
                'required' => ['id'],
                'additionalProperties' => false,
            ]),
            Scope::READ_ONLY,
            false,
            [self::class, 'getSource']
        ));

        $kit->register(new ToolDef(
            new LLMToolSpec('run_script', 'Execute a Symcon script and return its result.', [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'params' => ['type' => 'object', 'description' => 'Parameter map passed to the script.'],
                ],
                'required' => ['id'],
                'additionalProperties' => false,
            ]),
            Scope::AUTOMATION,
            true,
            [self::class, 'runScript']
        ));
    }

    public static function getSource(array $args, ToolContext $ctx): array
    {
        $id = (int) ($args['id'] ?? 0);
        if (!$ctx->isAllowed($id)) {
            return $ctx->deny('get_script_source', "Object $id is outside the allowed roots.");
        }
        if (!@IPS_ScriptExists($id)) {
            return ['error' => "Script $id does not exist", 'code' => 'not_found'];
        }
        $content = @IPS_GetScriptContent($id);
        return ['id' => $id, 'source' => is_string($content) ? $content : ''];
    }

    public static function runScript(array $args, ToolContext $ctx): array
    {
        $id = (int) ($args['id'] ?? 0);
        $params = is_array($args['params'] ?? null) ? $args['params'] : [];
        $dryRun = !empty($args['__dry_run']);
        if (!$ctx->isAllowed($id)) {
            return $ctx->deny('run_script', "Object $id is outside the allowed roots.");
        }
        if (!@IPS_ScriptExists($id)) {
            return ['error' => "Script $id does not exist", 'code' => 'not_found'];
        }
        if ($dryRun) {
            return ['id' => $id, 'would_call' => 'IPS_RunScriptEx', 'params' => $params];
        }
        $result = @IPS_RunScriptWaitEx($id, $params);
        return ['id' => $id, 'result' => $result];
    }
}
