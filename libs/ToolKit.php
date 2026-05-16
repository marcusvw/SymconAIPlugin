<?php

declare(strict_types=1);

require_once __DIR__ . '/Providers/Messages.php';
require_once __DIR__ . '/Audit.php';

/**
 * Symcon object scopes. Higher scope = strictly more permissions.
 */
class Scope
{
    public const READ_ONLY  = 'read_only';
    public const CONTROL    = 'control';
    public const AUTOMATION = 'automation';
    public const FULL       = 'full';

    private const LEVELS = [
        self::READ_ONLY  => 0,
        self::CONTROL    => 1,
        self::AUTOMATION => 2,
        self::FULL       => 3,
    ];

    public static function meets(string $have, string $need): bool
    {
        return (self::LEVELS[$have] ?? -1) >= (self::LEVELS[$need] ?? 99);
    }
}

/**
 * One registered tool.
 */
class ToolDef
{
    /** @var callable(array,ToolContext):mixed */
    public $handler;
    public LLMToolSpec $spec;
    public string $scope;       // minimum scope required to invoke
    public bool $isWrite;       // gated by dry-run

    public function __construct(LLMToolSpec $spec, string $scope, bool $isWrite, callable $handler)
    {
        $this->spec = $spec;
        $this->scope = $scope;
        $this->isWrite = $isWrite;
        $this->handler = $handler;
    }
}

/**
 * Per-invocation runtime context passed to every tool handler.
 */
class ToolContext
{
    public string $scope;
    public bool $dryRun;
    public array $allowedRoots; // object IDs; empty = whole tree
    public string $caller;
    public Audit $audit;
    public ?object $index;      // EmbeddingIndex|null

    public function __construct(string $scope, bool $dryRun, array $allowedRoots, string $caller, Audit $audit, ?object $index = null)
    {
        $this->scope = $scope;
        $this->dryRun = $dryRun;
        $this->allowedRoots = $allowedRoots;
        $this->caller = $caller;
        $this->audit = $audit;
        $this->index = $index;
    }

    /**
     * Walk up the parent chain to verify the object is under one of the
     * allowed root IDs. Empty roots = allow everything.
     */
    public function isAllowed(int $objectID): bool
    {
        if (empty($this->allowedRoots)) {
            return true;
        }
        $cur = $objectID;
        $guard = 0;
        while ($cur > 0 && $guard++ < 200) {
            if (in_array($cur, $this->allowedRoots, true)) {
                return true;
            }
            $obj = @IPS_GetObject($cur);
            if (!is_array($obj)) {
                return false;
            }
            $cur = (int) ($obj['ParentID'] ?? 0);
        }
        return false;
    }

    public function deny(string $tool, string $reason): array
    {
        return ['error' => $reason, 'code' => 'forbidden', 'tool' => $tool];
    }
}

class ToolKit
{
    /** @var array<string,ToolDef> */
    private array $tools = [];

    public function register(ToolDef $tool): void
    {
        $this->tools[$tool->spec->name] = $tool;
    }

    /** @return ToolDef[] tools visible at the given scope */
    public function listForScope(string $scope): array
    {
        $out = [];
        foreach ($this->tools as $t) {
            if (Scope::meets($scope, $t->scope)) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /** @return LLMToolSpec[] */
    public function specsForScope(string $scope): array
    {
        return array_map(fn(ToolDef $t) => $t->spec, $this->listForScope($scope));
    }

    public function get(string $name): ?ToolDef
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * Apply locale overrides to tool descriptions.
     * @param array<string,string> $descriptions  toolName => localized description
     */
    public function applyLocale(array $descriptions): void
    {
        foreach ($descriptions as $name => $desc) {
            if (isset($this->tools[$name])) {
                $this->tools[$name]->spec->description = $desc;
            }
        }
    }

    /**
     * Execute a tool call, enforcing scope + dry-run. Returns the JSON-serializable
     * result (or an error array). Never throws.
     */
    public function invoke(string $name, array $args, ToolContext $ctx): array
    {
        $tool = $this->tools[$name] ?? null;
        if ($tool === null) {
            $err = ['error' => "Unknown tool: $name", 'code' => 'unknown_tool'];
            $ctx->audit->record($name, $args, json_encode($err), $ctx->dryRun, $ctx->scope, $ctx->caller);
            return $err;
        }
        if (!Scope::meets($ctx->scope, $tool->scope)) {
            $err = $ctx->deny($name, "Tool '$name' requires scope '{$tool->scope}', have '{$ctx->scope}'");
            $ctx->audit->record($name, $args, json_encode($err), $ctx->dryRun, $ctx->scope, $ctx->caller);
            return $err;
        }
        try {
            $result = ($tool->handler)($args, $ctx);
            if (!is_array($result)) {
                $result = ['result' => $result];
            }
        } catch (\Throwable $e) {
            $result = ['error' => $e->getMessage(), 'code' => 'tool_exception'];
        }
        $ctx->audit->record($name, $args, json_encode($result), $ctx->dryRun, $ctx->scope, $ctx->caller);
        return $result;
    }
}
