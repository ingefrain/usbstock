<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class PublicQuoteIdentifiersConcurrencyTest extends TestCase
{
    public function test_independent_sqlite_workers_assign_distinct_ids_and_retry_the_same_quote_idempotently(): void
    {
        if (! function_exists('proc_open') || ! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Independent workers require proc_open and pdo_sqlite.');
        }
        $temporary = realpath(sys_get_temp_dir());
        $this->assertNotFalse($temporary);
        $directory = $temporary.DIRECTORY_SEPARATOR.'usbstock-identifiers-'.bin2hex(random_bytes(16));
        $this->assertTrue(mkdir($directory, 0700));
        file_put_contents($directory.'/test-only', 'isolated-public-identifiers-test');
        try {
            $quotes = $this->runWorker($directory, 'initialize');
            $different = $this->concurrent($directory, 'distinct', [$quotes[0]['id'], $quotes[1]['id']]);
            $differentIds = array_column($different, 'id');
            sort($differentIds);
            $this->assertSame([3000, 3001], $differentIds);
            foreach ($different as $result) {
                $this->assertSame($result['id'], $result['retry']);
            }
            $same = $this->concurrent($directory, 'same', [$quotes[2]['id'], $quotes[2]['id']]);
            $this->assertSame([3002, 3002], array_column($same, 'id'));
            $this->assertSame([3002, 3002], array_column($same, 'retry'));
            $state = $this->runWorker($directory, 'inspect');
            $this->assertSame([3000, 3001, 3002], array_column($state['mappings'], 'public_id'));
            $this->assertCount(3, array_unique(array_column($state['mappings'], 'quote_id')));
            $this->assertCount(3, array_unique(array_column($state['mappings'], 'source_id')));
            $sources = array_column($quotes, 'uuid', 'id');
            foreach ($state['mappings'] as $mapping) {
                $this->assertSame('laravel', $mapping['origin']);
                $this->assertSame($sources[$mapping['quote_id']], $mapping['source_id']);
            }
            $this->assertSame(3002, $state['cursor']);
            $this->assertSame(0, $state['folio_sequence']);
        } finally {
            // Only the exact temporary directory created above is removed; never a configured app database.
            foreach (glob($directory.'/*') ?: [] as $file) {
                if (is_file($file) && dirname(realpath($file)) === realpath($directory)) {
                    unlink($file);
                }
            }
            rmdir($directory);
        }
    }

    private function concurrent(string $directory, string $group, array $quotes): array
    {
        $workers = [];
        try {
            foreach ($quotes as $quote) {
                $worker = $this->worker($directory, 'assign', [(string) $quote, $group]);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 10;
            while (count(glob($directory.'/ready-'.$group.'-*') ?: []) < count($workers)) {
                if (microtime(true) >= $deadline) {
                    $this->fail('Workers did not reach the isolated concurrency gate.');
                }
                usleep(10000);
            }
            touch($directory.'/gate-'.$group);
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $results[] = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(0);
                }
            }
        }
    }

    private function runWorker(string $directory, string $mode): array
    {
        $worker = $this->worker($directory, $mode);
        try {
            $worker->run();
        } catch (ProcessTimedOutException) {
            $stage = is_file($directory.'/stage-'.$mode) ? file_get_contents($directory.'/stage-'.$mode) : 'process-start';
            $this->fail('Isolated worker timed out at '.$stage.'.');
        }
        $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());

        return json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function worker(string $directory, string $mode, array $arguments = []): Process
    {
        $root = dirname(__DIR__, 2);

        return new Process([PHP_BINARY, $root.'/tests/Support/public_quote_identifiers_worker.php', $directory, $mode, ...$arguments], $root, timeout: 30);
    }
}
