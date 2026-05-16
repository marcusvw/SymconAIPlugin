<?php

declare(strict_types=1);

require_once __DIR__ . '/../ToolKit.php';

class InstanceTools
{
    /** @var array<string,string[]>|null */
    private static ?array $secretsList = null;
    private const HEURISTIC = '/(pass(word)?|secret|token|api[_-]?key|client[_-]?secret|credential|pin)/i';

    public static function register(ToolKit $kit): void
    {
        $kit->register(new ToolDef(
            new LLMToolSpec('get_instance', 'Read instance info: module GUID, name, status, configuration (secrets redacted).', [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
                'required' => ['id'],
                'additionalProperties' => false,
            ]),
            Scope::READ_ONLY,
            false,
            [self::class, 'getInstance']
        ));

        $kit->register(new ToolDef(
            new LLMToolSpec('list_instances', 'List instances, optionally filtered by module GUID.', [
                'type' => 'object',
                'properties' => [
                    'module_guid' => ['type' => 'string', 'description' => 'Module GUID, e.g. {ABC...}. Omit to list all.'],
                    'limit' => ['type' => 'integer', 'default' => 100],
                ],
                'additionalProperties' => false,
            ]),
            Scope::READ_ONLY,
            false,
            [self::class, 'listInstances']
        ));

        $kit->register(new ToolDef(
            new LLMToolSpec('request_action', 'Trigger an action on an instance by ident.', [
                'type' => 'object',
                'properties' => [
                    'instance_id' => ['type' => 'integer'],
                    'ident' => ['type' => 'string'],
                    'value' => ['description' => 'Value to pass to the action.'],
                ],
                'required' => ['instance_id', 'ident', 'value'],
                'additionalProperties' => true,
            ]),
            Scope::CONTROL,
            true,
            [self::class, 'requestAction']
        ));
    }

    public static function getInstance(array $args, ToolContext $ctx): array
    {
        $id = (int) ($args['id'] ?? 0);
        if (!$ctx->isAllowed($id)) {
            return $ctx->deny('get_instance', "Object $id is outside the allowed roots.");
        }
        if (!@IPS_InstanceExists($id)) {
            return ['error' => "Instance $id does not exist", 'code' => 'not_found'];
        }
        $inst = IPS_GetInstance($id);
        $obj = IPS_GetObject($id);
        $moduleGuid = (string) ($inst['ModuleInfo']['ModuleID'] ?? '');
        $rawCfg = json_decode(@IPS_GetConfiguration($id) ?: '{}', true) ?: [];
        [$cfg, $redacted] = self::redact($moduleGuid, $rawCfg);
        return [
            'id' => $id,
            'name' => $obj['ObjectName'] ?? '',
            'module_guid' => $moduleGuid,
            'module_name' => $inst['ModuleInfo']['ModuleName'] ?? '',
            'status' => (int) ($inst['InstanceStatus'] ?? 0),
            'configuration' => $cfg,
            '_redacted' => $redacted,
        ];
    }

    public static function listInstances(array $args, ToolContext $ctx): array
    {
        $guid = (string) ($args['module_guid'] ?? '');
        $limit = max(1, min(500, (int) ($args['limit'] ?? 100)));
        $ids = $guid !== '' ? @IPS_GetInstanceListByModuleID($guid) : @IPS_GetInstanceList();
        if (!is_array($ids)) {
            return ['error' => 'IPS_GetInstanceList failed', 'code' => 'symcon_error'];
        }
        $out = [];
        foreach ($ids as $id) {
            if (!$ctx->isAllowed((int) $id)) {
                continue;
            }
            $obj = @IPS_GetObject((int) $id);
            $inst = @IPS_GetInstance((int) $id);
            if (!is_array($obj) || !is_array($inst)) {
                continue;
            }
            $out[] = [
                'id' => (int) $id,
                'name' => $obj['ObjectName'] ?? '',
                'module_guid' => $inst['ModuleInfo']['ModuleID'] ?? '',
                'module_name' => $inst['ModuleInfo']['ModuleName'] ?? '',
                'status' => (int) ($inst['InstanceStatus'] ?? 0),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        return ['instances' => $out, 'count' => count($out)];
    }

    public static function requestAction(array $args, ToolContext $ctx): array
    {
        $id = (int) ($args['instance_id'] ?? 0);
        $ident = (string) ($args['ident'] ?? '');
        $value = $args['value'] ?? null;
        $dryRun = !empty($args['__dry_run']);

        if (!$ctx->isAllowed($id)) {
            return $ctx->deny('request_action', "Object $id is outside the allowed roots.");
        }
        if (!@IPS_InstanceExists($id)) {
            return ['error' => "Instance $id does not exist", 'code' => 'not_found'];
        }
        if ($dryRun) {
            return ['instance_id' => $id, 'ident' => $ident, 'would_call' => 'IPS_RequestAction', 'value' => $value];
        }
        $ok = @IPS_RequestAction($id, $ident, $value);
        if ($ok === false) {
            return ['error' => 'IPS_RequestAction returned false', 'code' => 'action_failed'];
        }
        return ['instance_id' => $id, 'ident' => $ident, 'value' => $value, 'ok' => true];
    }

    /**
     * @return array{0:array,1:string[]}
     */
    private static function redact(string $moduleGuid, array $cfg): array
    {
        if (self::$secretsList === null) {
            $path = __DIR__ . '/instance_secrets.json';
            self::$secretsList = is_file($path)
                ? (json_decode((string) file_get_contents($path), true) ?: [])
                : [];
        }
        $denied = self::$secretsList[$moduleGuid] ?? [];
        $redactedFields = [];
        foreach ($cfg as $k => $v) {
            $isDenied = in_array($k, $denied, true);
            $isHeuristic = preg_match(self::HEURISTIC, (string) $k) === 1;
            if ($isDenied || $isHeuristic) {
                $cfg[$k] = '<redacted>';
                $redactedFields[] = $k;
            }
        }
        return [$cfg, $redactedFields];
    }
}
