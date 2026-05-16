<?php

declare(strict_types=1);

require_once __DIR__ . '/../ToolKit.php';

class TreeTools
{
    public static function register(ToolKit $kit): void
    {
        $kit->register(new ToolDef(
            new LLMToolSpec('tree_root', 'List the direct children of the Symcon root.', [
                'type' => 'object',
                'properties' => new \stdClass(),
                'additionalProperties' => false,
            ]),
            Scope::READ_ONLY,
            false,
            [self::class, 'treeRoot']
        ));

        $kit->register(new ToolDef(
            new LLMToolSpec('get_children', 'Return the direct children of an object.', [
                'type' => 'object',
                'properties' => [
                    'parent_id' => ['type' => 'integer', 'description' => 'Object ID of the parent (0 = root).'],
                ],
                'required' => ['parent_id'],
                'additionalProperties' => false,
            ]),
            Scope::READ_ONLY,
            false,
            [self::class, 'getChildren']
        ));

        $kit->register(new ToolDef(
            new LLMToolSpec('get_object', 'Return name, type, parent, and metadata for an object.', [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
                'required' => ['id'],
                'additionalProperties' => false,
            ]),
            Scope::READ_ONLY,
            false,
            [self::class, 'getObject']
        ));

        $kit->register(new ToolDef(
            new LLMToolSpec('get_path', 'Return the human-readable path (Room / Sub / Object) for an object.', [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
                'required' => ['id'],
                'additionalProperties' => false,
            ]),
            Scope::READ_ONLY,
            false,
            [self::class, 'getPath']
        ));

        $kit->register(new ToolDef(
            new LLMToolSpec('search', 'Substring search over object names. For semantic queries, prefer semantic_search.', [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string'],
                    'type' => ['type' => 'integer', 'description' => '0 category, 1 instance, 2 variable, 3 script, 4 event, 5 media, 6 link. Omit for all.'],
                    'limit' => ['type' => 'integer', 'default' => 25],
                ],
                'required' => ['query'],
                'additionalProperties' => false,
            ]),
            Scope::READ_ONLY,
            false,
            [self::class, 'search']
        ));
    }

    public static function treeRoot(array $args, ToolContext $ctx): array
    {
        return self::getChildren(['parent_id' => 0], $ctx);
    }

    public static function getChildren(array $args, ToolContext $ctx): array
    {
        $pid = (int) ($args['parent_id'] ?? 0);
        if ($pid !== 0 && !$ctx->isAllowed($pid)) {
            return $ctx->deny('get_children', "Object $pid is outside the allowed roots.");
        }
        $children = @IPS_GetChildrenIDs($pid);
        if (!is_array($children)) {
            return ['error' => "Object $pid has no children or does not exist", 'code' => 'not_found'];
        }
        $out = [];
        foreach ($children as $cid) {
            $obj = @IPS_GetObject($cid);
            if (!is_array($obj)) {
                continue;
            }
            $out[] = [
                'id' => (int) $cid,
                'name' => $obj['ObjectName'] ?? '',
                'type' => (int) ($obj['ObjectType'] ?? 0),
                'has_children' => (bool) (($obj['HasChildren'] ?? false)),
            ];
        }
        return ['parent_id' => $pid, 'children' => $out, 'count' => count($out)];
    }

    public static function getObject(array $args, ToolContext $ctx): array
    {
        $id = (int) ($args['id'] ?? 0);
        if (!$ctx->isAllowed($id)) {
            return $ctx->deny('get_object', "Object $id is outside the allowed roots.");
        }
        $obj = @IPS_GetObject($id);
        if (!is_array($obj)) {
            return ['error' => "Object $id not found", 'code' => 'not_found'];
        }
        return [
            'id' => $id,
            'name' => $obj['ObjectName'] ?? '',
            'type' => (int) ($obj['ObjectType'] ?? 0),
            'parent' => (int) ($obj['ParentID'] ?? 0),
            'ident' => $obj['ObjectIdent'] ?? '',
            'info' => $obj['ObjectInfo'] ?? '',
            'has_children' => (bool) (($obj['HasChildren'] ?? false)),
            'is_disabled' => (bool) (($obj['ObjectIsDisabled'] ?? false)),
        ];
    }

    public static function getPath(array $args, ToolContext $ctx): array
    {
        $id = (int) ($args['id'] ?? 0);
        if (!$ctx->isAllowed($id)) {
            return $ctx->deny('get_path', "Object $id is outside the allowed roots.");
        }
        $parts = [];
        $cur = $id;
        $guard = 0;
        while ($cur > 0 && $guard++ < 50) {
            $o = @IPS_GetObject($cur);
            if (!is_array($o)) {
                break;
            }
            array_unshift($parts, (string) ($o['ObjectName'] ?? ''));
            $cur = (int) ($o['ParentID'] ?? 0);
        }
        return ['id' => $id, 'path' => implode(' / ', $parts)];
    }

    public static function search(array $args, ToolContext $ctx): array
    {
        $query = strtolower((string) ($args['query'] ?? ''));
        if ($query === '') {
            return ['error' => 'Empty query', 'code' => 'bad_args'];
        }
        $type = isset($args['type']) ? (int) $args['type'] : null;
        $limit = max(1, min(200, (int) ($args['limit'] ?? 25)));

        $allIds = function_exists('IPS_GetObjectIDByName')
            ? null
            : null; // intentionally avoid that variant — walk instead
        $matches = [];
        $stack = [0];
        $guard = 0;
        while (!empty($stack) && $guard++ < 50000) {
            $cur = array_pop($stack);
            $children = @IPS_GetChildrenIDs($cur);
            if (!is_array($children)) {
                continue;
            }
            foreach ($children as $cid) {
                $cid = (int) $cid;
                $obj = @IPS_GetObject($cid);
                if (!is_array($obj)) {
                    continue;
                }
                $stack[] = $cid;
                if ($type !== null && (int) $obj['ObjectType'] !== $type) {
                    continue;
                }
                if (!$ctx->isAllowed($cid)) {
                    continue;
                }
                $name = strtolower((string) ($obj['ObjectName'] ?? ''));
                $ident = strtolower((string) ($obj['ObjectIdent'] ?? ''));
                if ($name !== '' && (strpos($name, $query) !== false || ($ident !== '' && strpos($ident, $query) !== false))) {
                    $matches[] = [
                        'id' => $cid,
                        'name' => $obj['ObjectName'] ?? '',
                        'type' => (int) $obj['ObjectType'],
                        'parent' => (int) ($obj['ParentID'] ?? 0),
                    ];
                    if (count($matches) >= $limit) {
                        return ['matches' => $matches, 'count' => count($matches), 'truncated' => true];
                    }
                }
            }
        }
        return ['matches' => $matches, 'count' => count($matches), 'truncated' => false];
    }
}
