<?php

declare(strict_types=1);

require_once __DIR__ . '/../ToolKit.php';

class VariableTools
{
    public static function register(ToolKit $kit): void
    {
        $kit->register(new ToolDef(
            new LLMToolSpec('get_variable', 'Read a Symcon variable: value, type, profile, last update.', [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
                'required' => ['id'],
                'additionalProperties' => false,
            ]),
            Scope::READ_ONLY,
            false,
            [self::class, 'getVariable']
        ));

        $kit->register(new ToolDef(
            new LLMToolSpec('set_variable', 'Preferred write op for any variable with has_action=true. Uses RequestAction when an action is associated, else SetValue. Performs profile-based type coercion and range checks.', [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'value' => ['description' => 'Value compatible with the variable type AND profile range. For % profiles whose max is not 100, the value must be the raw scaled value (e.g. 40% of max=254 → 102), not the percentage literal.'],
                ],
                'required' => ['id', 'value'],
                'additionalProperties' => true,
            ]),
            Scope::CONTROL,
            true,
            [self::class, 'setVariable']
        ));
    }

    public static function getVariable(array $args, ToolContext $ctx): array
    {
        $id = (int) ($args['id'] ?? 0);
        if (!$ctx->isAllowed($id)) {
            return $ctx->deny('get_variable', "Object $id is outside the allowed roots.");
        }
        if (!@IPS_VariableExists($id)) {
            return ['error' => "Variable $id does not exist", 'code' => 'not_found'];
        }
        $var = IPS_GetVariable($id);
        $obj = IPS_GetObject($id);
        return [
            'id' => $id,
            'name' => $obj['ObjectName'] ?? '',
            'value' => @GetValue($id),
            'type' => self::typeName((int) $var['VariableType']),
            'profile' => $var['VariableCustomProfile'] ?: $var['VariableProfile'] ?: '',
            'has_action' => !empty($var['VariableCustomAction']) || !empty($var['VariableAction']),
            'updated' => (int) ($var['VariableUpdated'] ?? 0),
            'changed' => (int) ($var['VariableChanged'] ?? 0),
        ];
    }

    public static function setVariable(array $args, ToolContext $ctx): array
    {
        $id = (int) ($args['id'] ?? 0);
        $value = $args['value'] ?? null;
        $dryRun = !empty($args['__dry_run']);

        if (!$ctx->isAllowed($id)) {
            return $ctx->deny('set_variable', "Object $id is outside the allowed roots.");
        }
        if (!@IPS_VariableExists($id)) {
            return ['error' => "Variable $id does not exist", 'code' => 'not_found'];
        }
        $var = IPS_GetVariable($id);
        $coerced = self::coerce($value, (int) $var['VariableType']);
        if ($coerced['error'] !== null) {
            return ['error' => $coerced['error'], 'code' => 'bad_args'];
        }
        $newVal = $coerced['value'];

        // Optional profile range check
        $profileName = $var['VariableCustomProfile'] ?: $var['VariableProfile'] ?: '';
        if ($profileName !== '' && is_numeric($newVal) && @IPS_VariableProfileExists($profileName)) {
            $prof = IPS_GetVariableProfile($profileName);
            $min = $prof['MinValue'] ?? null;
            $max = $prof['MaxValue'] ?? null;
            if (is_numeric($min) && is_numeric($max) && $min !== $max) {
                if ($newVal < $min || $newVal > $max) {
                    return ['error' => "Value $newVal out of profile range [$min, $max]", 'code' => 'bad_args'];
                }
            }
        }

        $hasAction = !empty($var['VariableCustomAction']) || !empty($var['VariableAction']);
        $method = $hasAction ? 'RequestAction' : 'SetValue';

        if ($dryRun) {
            return [
                'id' => $id,
                'would_call' => $method,
                'value' => $newVal,
                'previous' => @GetValue($id),
            ];
        }

        if ($hasAction) {
            $ok = @RequestAction($id, $newVal);
            if ($ok === false) {
                return ['error' => 'RequestAction returned false', 'code' => 'action_failed'];
            }
        } else {
            @SetValue($id, $newVal);
        }
        return ['id' => $id, 'method' => $method, 'value' => $newVal, 'ok' => true];
    }

    private static function typeName(int $t): string
    {
        return ['boolean', 'integer', 'float', 'string'][$t] ?? 'unknown';
    }

    private static function coerce($value, int $type): array
    {
        switch ($type) {
            case 0: // bool
                if (is_bool($value)) return ['value' => $value, 'error' => null];
                if (is_string($value)) {
                    $lc = strtolower($value);
                    if (in_array($lc, ['true', '1', 'on', 'an', 'ja', 'yes'], true)) return ['value' => true, 'error' => null];
                    if (in_array($lc, ['false', '0', 'off', 'aus', 'nein', 'no'], true)) return ['value' => false, 'error' => null];
                }
                if (is_numeric($value)) return ['value' => (bool) $value, 'error' => null];
                return ['value' => null, 'error' => 'Cannot coerce value to boolean'];
            case 1:
                if (!is_numeric($value)) return ['value' => null, 'error' => 'Integer expected'];
                return ['value' => (int) $value, 'error' => null];
            case 2:
                if (!is_numeric($value)) return ['value' => null, 'error' => 'Float expected'];
                return ['value' => (float) $value, 'error' => null];
            case 3:
                return ['value' => (string) $value, 'error' => null];
        }
        return ['value' => null, 'error' => "Unknown variable type $type"];
    }
}
