<?php

declare(strict_types=1);

require_once __DIR__ . '/../ToolKit.php';

class ProfileTools
{
    public static function register(ToolKit $kit): void
    {
        $kit->register(new ToolDef(
            new LLMToolSpec('get_profile', 'Return associations, range and suffix for a variable profile by name.', [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string']],
                'required' => ['name'],
                'additionalProperties' => false,
            ]),
            Scope::READ_ONLY,
            false,
            [self::class, 'getProfile']
        ));
    }

    public static function getProfile(array $args, ToolContext $ctx): array
    {
        $name = (string) ($args['name'] ?? '');
        if ($name === '' || !@IPS_VariableProfileExists($name)) {
            return ['error' => "Profile '$name' does not exist", 'code' => 'not_found'];
        }
        $p = IPS_GetVariableProfile($name);
        $assoc = [];
        foreach (($p['Associations'] ?? []) as $a) {
            $assoc[] = [
                'value' => $a['Value'] ?? null,
                'name' => $a['Name'] ?? '',
                'color' => $a['Color'] ?? 0,
            ];
        }
        return [
            'name' => $name,
            'type' => $p['ProfileType'] ?? null,
            'min' => $p['MinValue'] ?? null,
            'max' => $p['MaxValue'] ?? null,
            'step' => $p['StepSize'] ?? null,
            'suffix' => $p['Suffix'] ?? '',
            'digits' => $p['Digits'] ?? 0,
            'associations' => $assoc,
        ];
    }
}
