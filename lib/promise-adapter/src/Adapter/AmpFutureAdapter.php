<?php

/*
 * This file is part of the DataLoaderPhp package.
 *
 * (c) Overblog <http://github.com/overblog/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Overblog\PromiseAdapter\Adapter;

use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\Future;
use Overblog\PromiseAdapter\AsyncPromiseAdapterInterface;
use function Amp\async;
use function Amp\Future\await;
use function Amp\Future\awaitAll;

/**
 * Promise adapter backed by amphp/amp v3 fiber-based futures.
 *
 * Unlike the Guzzle/React adapters, amp v3 futures do not expose a `then()`
 * method and only settle once the event loop advances (inside a fiber).
 * The DataLoader core is patched to cooperate with this model via `Amp\async`
 * and `Future::await()` instead of `->then()`.
 *
 * @implements AsyncPromiseAdapterInterface<Future<mixed>>
 */
class AmpFutureAdapter implements AsyncPromiseAdapterInterface
{
    /** @var \WeakMap<Future<mixed>, array{deferred: DeferredFuture|null, canceller: callable|null}>|null */
    private ?\WeakMap $cancellations = null;

    /** @var array<int, Future<mixed>> */
    private array $pending = [];

    /** @var array<int, \Fiber> */
    private array $running = [];

    private int $nextPendingId = 0;

    /**
     * @return Future<mixed>
     */
    public function create(&$resolve = null, &$reject = null, ?callable $canceller = null): Future
    {
        $deferred = new DeferredFuture();
        $future = $deferred->getFuture();
        $this->cancellations ??= new \WeakMap();
        $this->cancellations[$future] = [
            'deferred' => $deferred,
            'canceller' => $canceller,
        ];

        $resolve = function ($value) use ($deferred, $future): void {
            if ($deferred->isComplete()) {
                return;
            }

            $deferred->complete($value);
            $this->markSettled($future);
        };
        $reject = function (\Throwable $reason) use ($deferred, $future): void {
            if ($deferred->isComplete()) {
                return;
            }

            $deferred->error($reason);
            $this->markSettled($future);
        };

        return $future;
    }

    /**
     * @return Future<mixed>
     */
    public function createFulfilled($promiseOrValue = null): Future
    {
        if ($promiseOrValue instanceof Future) {
            return $promiseOrValue;
        }

        return Future::complete($promiseOrValue);
    }

    /**
     * @return Future<mixed>
     */
    public function createRejected($reason): Future
    {
        if ($reason instanceof Future) {
            return $reason;
        }

        return Future::error($reason);
    }

    /**
     * @return Future<mixed>
     */
    public function createAll($promisesOrValues): Future
    {
        $futures = [];
        foreach ($promisesOrValues as $key => $value) {
            $futures[$key] = $value instanceof Future ? $value : Future::complete($value);
        }

        return async(static fn () => await($futures));
    }

    public function isPromise($value, $strict = false): bool
    {
        return $value instanceof Future;
    }

    public function await($promise = null, $unwrap = false): mixed
    {
        if (null === $promise) {
            $firstError = null;

            while ([] !== $this->pending) {
                $pending = $this->pending;
                $currentFiber = \Fiber::getCurrent();
                if (null !== $currentFiber) {
                    foreach ($this->running as $id => $fiber) {
                        if ($fiber === $currentFiber) {
                            unset($pending[$id]);
                        }
                    }
                }
                if ([] === $pending) {
                    break;
                }
                [$errors] = awaitAll($pending);

                if (null === $firstError && [] !== $errors) {
                    $firstError = reset($errors);
                }
            }

            if (null !== $firstError) {
                throw $firstError;
            }

            return null;
        }

        if (!$promise instanceof Future) {
            throw new \InvalidArgumentException(sprintf('The "%s" method must be called with an amp Future.', __METHOD__));
        }

        try {
            return $promise->await();
        } catch (\Throwable $reason) {
            if (!$unwrap) {
                return $reason;
            }

            throw $reason;
        }
    }

    public function cancel($promise): void
    {
        if (!$promise instanceof Future || null === $this->cancellations || !$this->cancellations->offsetExists($promise)) {
            throw new \InvalidArgumentException(sprintf('The "%s" method must be called with a compatible Future.', __METHOD__));
        }

        $cancellation = $this->cancellations[$promise];
        $deferred = $cancellation['deferred'];
        if (null === $deferred) {
            return;
        }

        $this->markSettled($promise);
        $promise->ignore();

        try {
            if (null !== $cancellation['canceller']) {
                ($cancellation['canceller'])();
            }
        } catch (\Throwable $reason) {
            if (!$deferred->isComplete()) {
                $deferred->error($reason);
            }

            return;
        }

        if (!$deferred->isComplete()) {
            $deferred->error(new CancelledException());
        }
    }

    public function enqueue(callable $callback): void
    {
        $id = ++$this->nextPendingId;
        $this->track(async(function () use ($callback, $id): void {
            $this->running[$id] = \Fiber::getCurrent();

            try {
                $callback();
            } finally {
                unset($this->running[$id]);
            }
        }), $id);
    }

    public function observe($promise, callable $onFulfilled, callable $onRejected): void
    {
        if (!$promise instanceof Future) {
            throw new \InvalidArgumentException(sprintf('The "%s" method must be called with a compatible Future.', __METHOD__));
        }

        $id = ++$this->nextPendingId;
        $observer = async(function () use ($promise, $onFulfilled, $onRejected, $id): void {
            $this->running[$id] = \Fiber::getCurrent();

            try {
                try {
                    $onFulfilled($promise->await());
                } catch (\Throwable $error) {
                    $onRejected($error);
                }
            } finally {
                unset($this->running[$id]);
            }
        });

        $this->track($observer, $id);
    }

    private function markSettled(Future $future): void
    {
        if (null === $this->cancellations || !$this->cancellations->offsetExists($future)) {
            return;
        }

        $this->cancellations[$future] = [
            'deferred' => null,
            'canceller' => null,
        ];
    }

    private function track(Future $future, int $id): void
    {
        $tracked = $future->finally(function () use ($id): void {
            unset($this->pending[$id]);
        });
        $tracked->ignore();
        $this->pending[$id] = $tracked;
    }
}
