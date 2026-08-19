<?php

/*
 * This file is part of the DataLoaderPhp package.
 *
 * (c) Overblog <http://github.com/overblog/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Overblog\DataLoader\Test;

use Amp\Future;
use Error;
use Overblog\DataLoader\DataLoader;
use Overblog\PromiseAdapter\Adapter\AmpFutureAdapter;
use Overblog\PromiseAdapter\AsyncPromiseAdapterInterface;
use Overblog\PromiseAdapter\PromiseAdapterInterface;

use function Amp\async;

class AmpFutureDataLoaderTest extends TestCase
{
    protected function createPromiseAdapter(): PromiseAdapterInterface
    {
        return new AmpFutureAdapter();
    }

    public function testAwaitUsesTheRegisteredAmpFutureAdapter()
    {
        $loader = new DataLoader(
            static fn (array $keys): Future => Future::complete(['value', 'value']),
            $this->createPromiseAdapter(),
        );

        self::assertSame(['value', 'value'], DataLoader::await($loader->loadMany(['first', 'second'])));
    }

    public function testRejectsEveryQueuedLoadWhenBatchFutureFailsWithAnError()
    {
        $loader = new DataLoader(
            static fn (array $keys): Future => Future::error(new Error('batch load failed')),
            new AmpFutureAdapter(),
        );

        $first = $loader->load('first');
        $second = $loader->load('second');

        foreach ([$first, $second] as $future) {
            try {
                async(static fn () => $future->await())->await();
                self::fail('Expected the queued load to fail.');
            } catch (Error $error) {
                self::assertSame('batch load failed', $error->getMessage());
            }
        }
    }

    public function testAwaitWithoutPromiseCompletesQueuedLoads(): void
    {
        $loader = new DataLoader(
            static fn (array $keys): Future => Future::complete($keys),
            new AmpFutureAdapter(),
        );
        $future = $loader->load('value');

        DataLoader::await();

        self::assertTrue($future->isComplete());
        self::assertSame('value', $future->await());
    }

    public function testDestructionRejectsQueuedLoads(): void
    {
        $loader = new DataLoader(
            static fn (array $keys): Future => Future::complete($keys),
            new AmpFutureAdapter(),
        );
        $future = $loader->load('value');

        $loader->__destruct();
        unset($loader);

        $error = DataLoader::await($future, false);
        self::assertInstanceOf(\RuntimeException::class, $error);
        self::assertSame('DataLoader destroyed before promise complete.', $error->getMessage());
    }

    public function testSupportsDelegatingAsyncPromiseAdapter(): void
    {
        $adapter = new DelegatingAsyncPromiseAdapter();
        $loader = new DataLoader(
            static fn (array $keys): Future => Future::complete($keys),
            $adapter,
        );

        self::assertSame(['first', 'second'], DataLoader::await($loader->loadMany(['first', 'second'])));
    }

    public function testDestructionDoesNotDispatchUnrelatedLoader(): void
    {
        $adapter = new AmpFutureAdapter();
        $loader = new DataLoader(
            static fn (array $keys): Future => Future::complete($keys),
            $adapter,
        );
        $loader->load('cancelled')->ignore();

        $unrelatedLoadCalls = [];
        $unrelatedLoader = new DataLoader(
            static function (array $keys) use (&$unrelatedLoadCalls): Future {
                $unrelatedLoadCalls[] = $keys;

                return Future::complete($keys);
            },
            $adapter,
        );
        $unrelatedFuture = $unrelatedLoader->load('unrelated');

        $loader->__destruct();
        unset($loader);

        self::assertSame([], $unrelatedLoadCalls);
        self::assertFalse($unrelatedFuture->isComplete());
        self::assertSame('unrelated', DataLoader::await($unrelatedFuture));
    }

    public function testBatchLoaderCanAwaitAnotherLoader(): void
    {
        $adapter = new AmpFutureAdapter();
        $innerLoader = new DataLoader(
            static fn (array $keys): Future => Future::complete($keys),
            $adapter,
        );
        $outerLoader = new DataLoader(
            static function (array $keys) use ($innerLoader): Future {
                return Future::complete(array_map(
                    static fn ($key) => DataLoader::await($innerLoader->load($key)),
                    $keys,
                ));
            },
            $adapter,
        );

        self::assertSame('value', DataLoader::await($outerLoader->load('value')));
    }
}

/** @implements AsyncPromiseAdapterInterface<Future<mixed>> */
final class DelegatingAsyncPromiseAdapter implements AsyncPromiseAdapterInterface
{
    private AmpFutureAdapter $adapter;

    public function __construct()
    {
        $this->adapter = new AmpFutureAdapter();
    }

    public function create(&$resolve = null, &$reject = null, ?callable $canceller = null): Future
    {
        return $this->adapter->create($resolve, $reject, $canceller);
    }

    public function createFulfilled($promiseOrValue = null): Future
    {
        return $this->adapter->createFulfilled($promiseOrValue);
    }

    public function createRejected($reason): Future
    {
        return $this->adapter->createRejected($reason);
    }

    public function createAll($promisesOrValues): Future
    {
        return $this->adapter->createAll($promisesOrValues);
    }

    public function isPromise($value, $strict = false): bool
    {
        return $this->adapter->isPromise($value, $strict);
    }

    public function await($promise = null, $unwrap = false): mixed
    {
        return $this->adapter->await($promise, $unwrap);
    }

    public function cancel($promise): void
    {
        $this->adapter->cancel($promise);
    }

    public function enqueue(callable $callback): void
    {
        $this->adapter->enqueue($callback);
    }

    public function observe($promise, callable $onFulfilled, callable $onRejected): void
    {
        $this->adapter->observe($promise, $onFulfilled, $onRejected);
    }
}
