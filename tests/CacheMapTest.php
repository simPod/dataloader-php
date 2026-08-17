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

use Overblog\DataLoader\CacheMap;

class CacheMapTest extends \PHPUnit\Framework\TestCase
{
    public function testDistinguishesScalarTypes()
    {
        $cacheMap = new CacheMap();
        $cacheMap
            ->set(1, 'integer')
            ->set('1', 'string')
            ->set(true, 'boolean')
            ->set(1.0, 'float');

        $this->assertSame('integer', $cacheMap->get(1));
        $this->assertSame('string', $cacheMap->get('1'));
        $this->assertSame('boolean', $cacheMap->get(true));
        $this->assertSame('float', $cacheMap->get(1.0));
    }

    public function testDistinguishesArrayKeysFromStringsWithTheSameSerializedForm()
    {
        $cacheMap = new CacheMap();
        $arrayKey = ['id' => 1];
        $stringKey = json_encode($arrayKey);

        $cacheMap->set($arrayKey, 'array')->set($stringKey, 'string');

        $this->assertSame('array', $cacheMap->get($arrayKey));
        $this->assertSame('string', $cacheMap->get($stringKey));
    }

    public function testDistinguishesArrayKeysThatJsonCannotEncode()
    {
        $cacheMap = new CacheMap();
        $firstKey = ["\xB1\x31"];
        $secondKey = ["\xB1\x32"];

        $this->assertFalse(json_encode($firstKey));
        $this->assertFalse(json_encode($secondKey));

        $cacheMap->set($firstKey, 'first')->set($secondKey, 'second');

        $this->assertSame('first', $cacheMap->get($firstKey));
        $this->assertSame('second', $cacheMap->get($secondKey));
    }

    public function testRejectsResourceValuesInArrayKeys()
    {
        $resource = fopen('php://memory', 'r');

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Resources cannot be used in CacheMap keys.');

            (new CacheMap())->set([$resource], 'value');
        } finally {
            fclose($resource);
        }
    }

    public function testUsesObjectIdentity()
    {
        $cacheMap = new CacheMap();
        $firstKey = (object) ['id' => 1];
        $secondKey = (object) ['id' => 1];

        $cacheMap->set($firstKey, 'first')->set($secondKey, 'second');

        $this->assertSame('first', $cacheMap->get($firstKey));
        $this->assertSame('second', $cacheMap->get($secondKey));

        $cacheMap->clear($firstKey);

        $this->assertFalse($cacheMap->has($firstKey));
        $this->assertTrue($cacheMap->has($secondKey));
    }

    public function testUsesNestedObjectIdentity()
    {
        $cacheMap = new CacheMap();
        $firstObject = (object) ['id' => 1];
        $secondObject = (object) ['id' => 1];

        $cacheMap->set([$firstObject], 'first')->set([$secondObject], 'second');

        $this->assertSame('first', $cacheMap->get([$firstObject]));
        $this->assertSame('second', $cacheMap->get([$secondObject]));
    }

    public function testReusesIdentityForTheSameNestedObject()
    {
        $cacheMap = new CacheMap();
        $object = new \stdClass();

        $cacheMap->set([$object, $object], 'value');

        $this->assertSame('value', $cacheMap->get([$object, $object]));
    }

    public function testSupportsClosuresInArrayKeysByIdentity()
    {
        $cacheMap = new CacheMap();
        $firstClosure = function () {
        };
        $secondClosure = function () {
        };

        $cacheMap->set([$firstClosure], 'first')->set([$secondClosure], 'second');

        $this->assertSame('first', $cacheMap->get([$firstClosure]));
        $this->assertSame('second', $cacheMap->get([$secondClosure]));
    }

    public function testRejectsRecursiveArrayKeys()
    {
        $key = [];
        $key['self'] = &$key;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Recursive arrays cannot be used in CacheMap keys.');

        (new CacheMap())->set($key, 'value');
    }

    public function testClearAllClearsScalarAndObjectIdentityKeys()
    {
        $cacheMap = new CacheMap();
        $object = new \stdClass();
        $cacheMap
            ->set('scalar', 'scalar value')
            ->set($object, 'object value')
            ->set([$object], 'nested object value')
            ->clearAll();

        $this->assertFalse($cacheMap->has('scalar'));
        $this->assertFalse($cacheMap->has($object));
        $this->assertFalse($cacheMap->has([$object]));

        $cacheMap->set([$object], 'new value');

        $this->assertSame('new value', $cacheMap->get([$object]));
    }
}
