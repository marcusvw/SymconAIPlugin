<?php

declare(strict_types=1);

/**
 * Audit logger. Writes one JSON line per tool call to the Symcon log
 * (via IPS_LogMessage) and, when enabled, to a rotating file.
 */
class Audit
{
    private int $instanceID;
    private string $actor;
    private ?string $logFile;
    private string $verbosity;

    public function __construct(int $instanceID, string $actor, ?string $logFile = null, string $verbosity = 'normal')
    {
        $this->instanceID = $instanceID;
        $this->actor = $actor !== '' ? $actor : ('instance:' . $instanceID);
        $this->logFile = $logFile;
        $this->verbosity = $verbosity;
    }

    public function record(string $tool, array $args, string $result, bool $dryRun, string $scope, string $caller): void
    {
        $entry = [
            'ts' => date('c'),
            'instance' => $this->instanceID,
            'actor' => $this->actor,
            'caller' => $caller,
            'tool' => $tool,
            'args' => $this->sanitizeArgs($args),
            'result' => $this->verbosity === 'verbose' ? $result : $this->summarize($result),
            'dry_run' => $dryRun,
            'scope' => $scope,
        ];
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return;
        }
        if (function_exists('IPS_LogMessage')) {
            IPS_LogMessage('AISymcon', $line);
        }
        if ($this->logFile !== null && $this->logFile !== '') {
            $this->appendToFile($line);
        }
    }

    private function sanitizeArgs(array $args): array
    {
        if ($this->verbosity === 'minimal') {
            return array_keys($args);
        }
        return $args;
    }

    private function summarize(string $result): string
    {
        if (strlen($result) <= 200) {
            return $result;
        }
        return substr($result, 0, 200) . '… [+' . (strlen($result) - 200) . ' chars]';
    }

    private function appendToFile(string $line): void
    {
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        // Rotate at 5 MB.
        if (is_file($this->logFile) && filesize($this->logFile) > 5 * 1024 * 1024) {
            @rename($this->logFile, $this->logFile . '.' . date('YmdHis'));
        }
        @file_put_contents($this->logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
