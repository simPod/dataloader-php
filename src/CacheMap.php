<?php

/*
 * This file is part of the DataLoaderPhp package.
 *
 * (c) Overblog <http://github.com/overblog/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Overblog\DataLoader;

class CacheMap
{
    private $promiseCache = [];
    private $objectPromiseCache;
    private $objectIds;
    private $nextObjectId = 0;

    public function get($key)
    {
        if (is_object($key)) {
            return null !== $this->objectPromiseCache && isset($this->objectPromiseCache[$key])
                ? $this->objectPromiseCache[$key]
                : null;
        }

        $key = $this->serializedKey($key);

        return isset($this->promiseCache[$key]) ? $this->promiseCache[$key] : null;
    }

    public function has($key)
    {
        if (is_object($key)) {
            return null !== $this->objectPromiseCache && isset($this->objectPromiseCache[$key]);
        }

        return isset($this->promiseCache[$this->serializedKey($key)]);
    }

    public function set($key, $promise)
    {
        if (is_object($key)) {
            if (null === $this->objectPromiseCache) {
                $this->objectPromiseCache = new \WeakMap();
            }
            $this->objectPromiseCache[$key] = $promise;

            return $this;
        }

        $this->promiseCache[$this->serializedKey($key)] = $promise;

        return $this;
    }

    public function clear($key)
    {
        if (is_object($key)) {
            if (null !== $this->objectPromiseCache) {
                unset($this->objectPromiseCache[$key]);
            }

            return $this;
        }

        unset($this->promiseCache[$this->serializedKey($key)]);

        return $this;
    }

    public function clearAll()
    {
        $this->promiseCache = [];
        $this->objectPromiseCache = null;

        return $this;
    }

    private function serializedKey($key)
    {
        $arrayReferences = [];

        return serialize($this->encodeValue($key, $arrayReferences));
    }

    private function encodeValue(&$value, array &$arrayReferences)
    {
        $type = gettype($value);
        if ('resource' === $type || 'resource (closed)' === $type) {
            throw new \InvalidArgumentException('Resources cannot be used in CacheMap keys.');
        }
        if (is_object($value)) {
            if (null === $this->objectIds) {
                $this->objectIds = new \WeakMap();
            }
            if (!isset($this->objectIds[$value])) {
                $this->objectIds[$value] = ++$this->nextObjectId;
            }

            return ['object', $this->objectIds[$value]];
        }
        if (!is_array($value)) {
            return [$type, $value];
        }

        $referenceHolder = [&$value];
        $referenceId = \ReflectionReference::fromArrayElement($referenceHolder, 0)->getId();
        if (isset($arrayReferences[$referenceId])) {
            throw new \InvalidArgumentException('Recursive arrays cannot be used in CacheMap keys.');
        }

        $arrayReferences[$referenceId] = true;
        $items = [];
        try {
            foreach ($value as $key => &$item) {
                $items[] = [[gettype($key), $key], $this->encodeValue($item, $arrayReferences)];
            }
        } finally {
            unset($item, $arrayReferences[$referenceId]);
        }

        return ['array', $items];
    }
}
