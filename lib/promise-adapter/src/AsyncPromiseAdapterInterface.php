<?php

/*
 * This file is part of the DataLoaderPhp package.
 *
 * (c) Overblog <http://github.com/overblog/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Overblog\PromiseAdapter;

/**
 * Supports promises that require event-loop scheduling and do not expose then().
 *
 * @template TPromise
 *
 * @extends PromiseAdapterInterface<TPromise>
 */
interface AsyncPromiseAdapterInterface extends PromiseAdapterInterface
{
    /**
     * Queue work for the next event-loop turn.
     *
     * A no-argument await() call must drain this work, including work that it
     * enqueues or observes. cancel() must settle adapter-created promises
     * without requiring an event-loop turn.
     */
    public function enqueue(callable $callback): void;

    /**
     * @param TPromise $promise
     *
     * A no-argument await() call must wait for the observer callbacks to finish.
     */
    public function observe($promise, callable $onFulfilled, callable $onRejected): void;
}
