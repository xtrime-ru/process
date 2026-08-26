<?php declare(strict_types=1);

namespace Amp\Process\Test;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Process\Internal\Windows\WindowsHandle;
use Amp\Process\Process;
use Amp\Process\ProcessException;
use Amp\TimeoutCancellation;

/**
 * @group failing
 */
class KnownIssuesTest extends AsyncTestCase
{
    /**
     * @requires extension posix
     */
    public function testKillTerminatesDescendantProcesses(): void
    {
        $process = Process::start('sleep 2 & wait');
        $process->kill();

        self::assertSame(137, $process->join());

        try {
            self::assertNull($process->getStdout()->read(new TimeoutCancellation(0.5)));
        } catch (CancelledException) {
            self::fail('A descendant kept the output pipe open after the process was killed');
        }
    }

    /**
     * @requires extension posix
     */
    public function testSignalThrowsIfDeliveryFails(): void
    {
        $process = Process::start('sleep 2');

        try {
            $this->expectException(ProcessException::class);
            $process->signal(9999);
        } finally {
            $process->kill();
            $process->join();
        }
    }

    /**
     * @requires OS Windows
     */
    public function testStartupCancellationIsNotReusedForProcessLifetime(): void
    {
        $deferredCancellation = new DeferredCancellation();
        $process = Process::start(
            'cmd /c ping -n 3 127.0.0.1 > nul',
            cancellation: $deferredCancellation->getCancellation(),
        );
        $deferredCancellation->cancel();

        self::assertSame(0, $process->join(new TimeoutCancellation(5)));
    }

    /**
     * @requires OS Windows
     */
    public function testKillThrowsIfTaskkillFails(): void
    {
        $process = Process::start('cmd /c ping -n 3 127.0.0.1 > nul');
        $handle = (new \ReflectionProperty($process, 'handle'))->getValue($process);
        self::assertInstanceOf(WindowsHandle::class, $handle);
        $pid = $handle->pid;
        $handle->pid = 2_147_483_647;

        try {
            $this->expectException(ProcessException::class);
            $process->kill();
        } finally {
            $handle->pid = $pid;
            $process->kill();
            $process->join(new TimeoutCancellation(5));
        }
    }

    /**
     * @requires OS Windows
     */
    public function testWindowsStartupCleansUpAfterThrowable(): void
    {
        $cancellation = new class implements Cancellation {
            public function subscribe(\Closure $callback): string
            {
                return 'test';
            }

            public function unsubscribe(string $id): void
            {
                throw new \Error('Test error while unsubscribing cancellation');
            }

            public function isRequested(): bool
            {
                return false;
            }

            public function throwIfRequested(): void
            {
            }
        };

        $this->expectException(ProcessException::class);
        Process::start(
            'cmd /c ping -n 3 127.0.0.1 > nul',
            cancellation: $cancellation,
        );
    }
}
