<?php

declare(strict_types=1);

require_once __DIR__ . '/Providers/LLMProvider.php';
require_once __DIR__ . '/ToolKit.php';

/**
 * Budget for one agent invocation.
 */
class Budget
{
    public int $maxToolCalls;
    public int $maxIterations;
    public int $maxTokens;
    public int $timeoutSec;

    public function __construct(int $maxToolCalls = 16, int $maxTokens = 8192, int $timeoutSec = 60, int $maxIterations = 0)
    {
        $this->maxToolCalls = $maxToolCalls;
        $this->maxIterations = $maxIterations > 0 ? $maxIterations : $maxToolCalls + 2;
        $this->maxTokens = $maxTokens;
        $this->timeoutSec = $timeoutSec;
    }
}

class AgentResult
{
    public string $text = '';
    public int $toolCalls = 0;
    public int $iterations = 0;
    public bool $budgetExceeded = false;
    public ?string $error = null;
    /** @var array<int,array> */
    public array $trace = [];
    public array $usage = [];

    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'tool_calls' => $this->toolCalls,
            'iterations' => $this->iterations,
            'budget_exceeded' => $this->budgetExceeded,
            'error' => $this->error,
            'usage' => $this->usage,
            'trace' => $this->trace,
        ];
    }
}

class AgentRuntime
{
    private LLMProvider $provider;
    private ToolKit $tools;
    private ToolContext $ctx;
    private array $chatOpts;
    private string $systemPrompt;

    /**
     * @param array{model?:string,temperature?:float,max_tokens?:int,timeout?:int} $chatOpts
     */
    public function __construct(LLMProvider $provider, ToolKit $tools, ToolContext $ctx, string $systemPrompt, array $chatOpts)
    {
        $this->provider = $provider;
        $this->tools = $tools;
        $this->ctx = $ctx;
        $this->systemPrompt = $systemPrompt;
        $this->chatOpts = $chatOpts;
    }

    /**
     * Run the tool loop until the model produces a final text response
     * or the budget is exhausted.
     *
     * @param LLMMessage[] $history starting conversation (without system)
     * @return array{messages: LLMMessage[], result: AgentResult}
     */
    public function run(array $history, Budget $budget): array
    {
        $result = new AgentResult();
        $messages = $history;
        $specs = $this->tools->specsForScope($this->ctx->scope);
        $opts = array_merge($this->chatOpts, ['system' => $this->systemPrompt, 'timeout' => $budget->timeoutSec]);
        $deadline = microtime(true) + $budget->timeoutSec * max(1, ($budget->maxIterations));

        $audit = $this->ctx->audit;
        $audit->debug('agent.start', sprintf(
            'provider=%s model=%s scope=%s tools=%d budget=%d/%d/%ds',
            $this->provider->getName(),
            (string) ($opts['model'] ?? ''),
            $this->ctx->scope,
            count($specs),
            $budget->maxToolCalls,
            $budget->maxIterations,
            $budget->timeoutSec
        ));

        for ($i = 0; $i < $budget->maxIterations; $i++) {
            $result->iterations++;

            $audit->debug('llm.request', sprintf('iter=%d messages=%d tools=%d', $i + 1, count($messages), count($specs)));
            try {
                $resp = $this->provider->chat($messages, $specs, $opts);
            } catch (LLMException $e) {
                $result->error = '[' . $e->kind . '] ' . $e->getMessage();
                $audit->debug('llm.error', $result->error);
                return ['messages' => $messages, 'result' => $result];
            }
            if (!empty($resp->usage)) {
                $result->usage = $resp->usage;
            }
            $audit->debug('llm.response', sprintf(
                'finish=%s text=%d chars tool_calls=%d usage=%s',
                $resp->finishReason,
                strlen($resp->text),
                count($resp->toolCalls),
                json_encode($resp->usage, JSON_UNESCAPED_SLASHES)
            ));

            // Append assistant turn.
            $assistantMsg = new LLMMessage(LLMMessage::ROLE_ASSISTANT, $resp->text, $resp->toolCalls);
            $messages[] = $assistantMsg;

            if (!$resp->hasToolCalls()) {
                $result->text = $resp->text;
                $audit->debug('agent.done', sprintf('iters=%d tool_calls=%d chars=%d', $result->iterations, $result->toolCalls, strlen($result->text)));
                return ['messages' => $messages, 'result' => $result];
            }

            foreach ($resp->toolCalls as $call) {
                if ($result->toolCalls >= $budget->maxToolCalls) {
                    $result->budgetExceeded = true;
                    $result->text = $resp->text !== '' ? $resp->text : '[budget exceeded: max tool calls]';
                    return ['messages' => $messages, 'result' => $result];
                }
                $result->toolCalls++;

                $toolDef = $this->tools->get($call->name);
                $isDryRun = $this->ctx->dryRun && $toolDef !== null && $toolDef->isWrite;
                $argsForCall = $call->arguments;
                if ($isDryRun) {
                    $argsForCall['__dry_run'] = true;
                }
                $toolResult = $this->tools->invoke($call->name, $argsForCall, $this->ctx);
                if ($isDryRun) {
                    $toolResult['dry_run'] = true;
                    $toolResult['note'] = 'Dry-run: no state was modified.';
                }
                $result->trace[] = [
                    'tool' => $call->name,
                    'args' => $call->arguments,
                    'result' => $toolResult,
                ];
                $messages[] = new LLMMessage(
                    LLMMessage::ROLE_TOOL,
                    json_encode($toolResult, JSON_UNESCAPED_UNICODE),
                    [],
                    $call->id,
                    $call->name
                );
            }

            if (microtime(true) > $deadline) {
                $result->budgetExceeded = true;
                $result->text = '[budget exceeded: wall-clock timeout]';
                return ['messages' => $messages, 'result' => $result];
            }
        }

        $result->budgetExceeded = true;
        $result->text = '[budget exceeded: max iterations]';
        return ['messages' => $messages, 'result' => $result];
    }
}
