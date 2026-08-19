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

use Amp\DeferredFuture;
use Amp\Future;
use Overblog\PromiseAdapter\PromiseAdapterInterface;
use function Amp\async;
use function Amp\Future\await;

/**
 * Promise adapter backed by amphp/amp v3 fiber-based futures.
 *
 * Unlike the Guzzle/React adapters, amp v3 futures do not expose a `then()`
 * method and only settle once the event loop advances (inside a fiber).
 * The DataLoader core is patched to cooperate with this model via `Amp\async`
 * and `Future::await()` instead of `->then()`.
 *
 * @implements PromiseAdapterInterface<Future<mixed>>
 */
class AmpFutureAdapter implements PromiseAdapterInterface
{
    /**
     * @return Future<mixed>
     */
    public function create(&$resolve = null, &$reject = null, ?callable $canceller = null): Future
    {
        $deferred = new DeferredFuture();

        $resolve = static function ($value) use ($deferred): void {
            if ($deferred->isComplete()) {
                return;
            }

            $deferred->complete($value);
        };
        $reject = static function (\Throwable $reason) use ($deferred): void {
            if ($deferred->isComplete()) {
                return;
            }

            $deferred->error($reason);
        };

        return $deferred->getFuture();
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

    public function await($promise = null, $unwrap = true): mixed
    {
        if (null === $promise) {
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
        // amp v3 futures are cancelled through a Cancellation token passed at
        // creation time; there is no post-hoc cancel handle here.
    }
}
