<?php

declare(strict_types=1);

require_once __DIR__ . '/../ToolKit.php';
require_once __DIR__ . '/../Index/EmbeddingIndex.php';

class SemanticSearchTool
{
    public static function register(ToolKit $kit): void
    {
        $kit->register(new ToolDef(
            new LLMToolSpec('semantic_search', 'Find Symcon objects by meaning, not just by substring. Preferred entry point for natural-language references like "kitchen lights" or "outdoor temperature".', [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string'],
                    'k' => ['type' => 'integer', 'default' => 8, 'description' => 'Top-k results.'],
                    'type' => ['type' => 'integer', 'description' => 'Optional object type filter (0=cat,1=inst,2=var,3=script,4=event).'],
                ],
                'required' => ['query'],
                'additionalProperties' => false,
            ]),
            Scope::READ_ONLY,
            false,
            [self::class, 'run']
        ));
    }

    public static function run(array $args, ToolContext $ctx): array
    {
        if (!($ctx->index instanceof EmbeddingIndex)) {
            return ['error' => 'Embedding index is not configured on this instance', 'code' => 'index_unavailable'];
        }
        $query = (string) ($args['query'] ?? '');
        if ($query === '') {
            return ['error' => 'Empty query', 'code' => 'bad_args'];
        }
        $k = max(1, min(50, (int) ($args['k'] ?? 8)));
        $type = isset($args['type']) ? (int) $args['type'] : null;

        $hits = $ctx->index->search($query, $k, $type);
        $hits = array_values(array_filter($hits, fn($h) => $ctx->isAllowed((int) $h['id'])));
        return ['matches' => $hits, 'count' => count($hits)];
    }
}
