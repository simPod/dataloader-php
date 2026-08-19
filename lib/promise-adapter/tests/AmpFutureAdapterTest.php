<?php

/*
 * This file is part of the DataLoaderPhp package.
 *
 * (c) Overblog <http://github.com/overblog/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Overblog\PromiseAdapter\Tests;

use Amp\CancelledException;
use Amp\Future;
use Overblog\PromiseAdapter\Adapter\AmpFutureAdapter;

use function Amp\async;
use function Amp\delay;

class AmpFutureAdapterTest extends \PHPUnit\Framework\TestCase
{
    public function testAwaitReturnsRejectionWithoutUnwrapByDefault(): void
    {
        $adapter = new AmpFutureAdapter();
        $error = new \RuntimeException('failed');

        self::assertSame($error, $adapter->await(Future::error($error)));
    }

    public function testCreateRejectedPreservesFuture(): void
    {
        $adapter = new AmpFutureAdapter();
        $future = Future::complete('value');

        self::assertSame($future, $adapter->createRejected($future));
    }

    public function testCancelInvokesCancellerAndRejectsFuture(): void
    {
        $adapter = new AmpFutureAdapter();
        $error = new \RuntimeException('cancelled');
        $future = $adapter->create(
            $resolve,
            $reject,
            static function () use ($error): void {
                throw $error;
            },
        );

        $adapter->cancel($future);

        self::assertSame($error, $adapter->await($future));
    }

    public function testCancelRejectsInvalidFuture(): void
    {
        $adapter = new AmpFutureAdapter();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('::cancel" method must be called with a compatible Future.');

        $adapter->cancel(Future::complete(null));
    }

    public function testCancelWithoutCancellerRejectsFuture(): void
    {
        $adapter = new AmpFutureAdapter();
        $future = $adapter->create($resolve, $reject);

        $adapter->cancel($future);

        self::assertInstanceOf(CancelledException::class, $adapter->await($future));
    }

    public function testAwaitDrainsWorkEnqueuedByPendingWork(): void
    {
        $adapter = new AmpFutureAdapter();
        $calls = [];
        $adapter->enqueue(function () use ($adapter, &$calls): void {
            $calls[] = 'first';
            $adapter->enqueue(function () use (&$calls): void {
                $calls[] = 'second';
            });
        });

        $adapter->await();

        self::assertSame(['first', 'second'], $calls);
    }

    public function testFailedResolutionLeavesFutureCancellable(): void
    {
        $adapter = new AmpFutureAdapter();
        $future = $adapter->create($resolve, $reject);

        try {
            $resolve(Future::complete('invalid nested future'));
            self::fail('Expected resolving with a Future to fail.');
        } catch (\Error $error) {
            self::assertSame('Cannot complete with an instance of Amp\Future', $error->getMessage());
        }

        $adapter->cancel($future);

        self::assertInstanceOf(CancelledException::class, $adapter->await($future));
    }

    public function testAwaitWaitsForWorkRunningInAnotherFiber(): void
    {
        $adapter = new AmpFutureAdapter();
        $completed = false;
        $adapter->enqueue(function () use (&$completed): void {
            delay(0.01);
            $completed = true;
        });

        async(function () use ($adapter, &$completed): void {
            delay(0);
            $adapter->await();

            self::assertTrue($completed);
        })->await();
    }
}
