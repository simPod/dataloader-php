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

use function Amp\async;

class AmpFutureDataLoaderTest extends TestCase
{
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
}
