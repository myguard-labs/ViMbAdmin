<?php

declare(strict_types=1);

/** Test-only operation-local DBAL observer shared by maintenance baselines. */
final class MaintenanceOperationObserver extends \Psr\Log\AbstractLogger
{
    /** @var list<string> */
    public array $sql = [];

    #[\Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (isset($context['sql']) && is_string($context['sql'])) {
            $this->sql[] = $context['sql'];
        }
    }

    /**
     * @param callable():mixed $operation
     * @param null|callable():int $backendCalls
     * @param null|callable():void $afterOperation
     * @return array{result:mixed,failed:bool,queries:int,backend:int,elapsed:int,memory:int,peak_rss_kib:int,sql:list<string>}
     */
    public function measure(callable $operation, ?callable $backendCalls = null, ?callable $afterOperation = null): array
    {
        $this->sql = [];
        $calls = $backendCalls === null ? 0 : $backendCalls();
        $memory = memory_get_usage(true);
        $started = hrtime(true);
        try {
            $result = $operation();
            $failed = false;
        } catch (Throwable $error) {
            $result = $error;
            $failed = true;
        }
        if ($afterOperation !== null) {
            $afterOperation();
        }
        $elapsed = hrtime(true) - $started;
        $usage = getrusage();
        return ['result' => $result, 'failed' => $failed, 'queries' => count($this->sql),
            'backend' => ($backendCalls === null ? 0 : $backendCalls()) - $calls,
            'elapsed' => $elapsed, 'memory' => max(0, memory_get_usage(true) - $memory),
            // Process lifetime high-water RSS, including bootstrap/fixture load.
            'peak_rss_kib' => isset($usage['ru_maxrss']) && is_int($usage['ru_maxrss']) ? $usage['ru_maxrss'] : 0, 'sql' => $this->sql];
    }
}
