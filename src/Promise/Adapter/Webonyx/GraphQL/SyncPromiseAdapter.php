<?php

/*
 * This file is part of the DataLoaderPhp package.
 *
 * (c) Overblog <http://github.com/overblog/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Overblog\DataLoader\Promise\Adapter\Webonyx\GraphQL;

use Countable;
use Ds\Map;
use Exception;
use GraphQL\Error\InvariantViolation;
use GraphQL\Executor\Promise\Adapter\SyncPromise;
use GraphQL\Executor\Promise\Adapter\SyncPromiseAdapter as BaseSyncPromiseAdapter;
use GraphQL\Executor\Promise\Promise;
use Overblog\DataLoader\DataLoader;
use Throwable;
use function assert;
use function is_array;

class SyncPromiseAdapter extends BaseSyncPromiseAdapter
{
    protected function beforeWait(Promise $promise): void
    {
        DataLoader::await();
    }

    protected function onWait(Promise $promise): void
    {
        DataLoader::await();
    }

    /** @throws InvariantViolation */
    public function all(iterable $promisesOrValues): Promise
    {
        assert(is_countable($promisesOrValues));

        $all = new SyncPromise();

        $total = count($promisesOrValues);
        $count = 0;
        /** @var Map<K, V|null> $result */
        $result = new Map();

        $resolveAllWhenFinished = function () use (&$count, &$total, $all, &$result, $promisesOrValues): void {
            if ($count === $total) {
                $all->resolve(is_array($promisesOrValues) ? $result->toArray() : $result);
            }
        };

        foreach ($promisesOrValues as $index => $promiseOrValue) {
            if ($promiseOrValue instanceof Promise) {
                $result->put($index, null);
                $promiseOrValue->then(
                    static function ($value) use ($result, $index, &$count, &$resolveAllWhenFinished): void {
                        $result->put($index, $value);
                        ++$count;
                        $resolveAllWhenFinished();
                    },
                    static fn (Throwable $reason): SyncPromise => $all->reject($reason)
                );
            } else {
                $result->put($index, $promiseOrValue);
                ++$count;
            }
        }

        $resolveAllWhenFinished();

        return new Promise($all, $this);
    }
}
