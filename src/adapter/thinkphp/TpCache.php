<?php
/*
 * @Description: PSR-16 桥接 —— 包装 ThinkPHP 6 缓存（think\facade\Cache）
 *              仅在 ThinkPHP 宿主内使用；其他框架勿引用本文件
 */

declare (strict_types = 1);

namespace aichat\adapter\thinkphp;

use Psr\SimpleCache\CacheInterface;
use think\facade\Cache;

class TpCache implements CacheInterface
{
    /** @var string|null 缓存驱动（null=默认驱动） */
    private $store;

    public function __construct(?string $store = null)
    {
        $this->store = $store;
    }

    private function driver()
    {
        return $this->store ? Cache::store($this->store) : Cache::store();
    }

    public function get($key, $default = null)
    {
        return $this->driver()->get($key, $default);
    }

    public function set($key, $value, $ttl = null): bool
    {
        $seconds = null;
        if ($ttl instanceof \DateInterval) {
            $now     = new \DateTimeImmutable();
            $seconds = $now->add($ttl)->getTimestamp() - $now->getTimestamp();
        } elseif ($ttl !== null) {
            $seconds = (int)$ttl;
        }
        return (bool)$this->driver()->set($key, $value, $seconds);
    }

    public function delete($key): bool
    {
        return (bool)$this->driver()->delete($key);
    }

    public function clear(): bool
    {
        return (bool)$this->driver()->clear();
    }

    public function getMultiple($keys, $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    public function setMultiple($values, $ttl = null): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            $ok = $this->set($key, $value, $ttl) && $ok;
        }
        return $ok;
    }

    public function deleteMultiple($keys): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            $ok = $this->delete($key) && $ok;
        }
        return $ok;
    }

    public function has($key): bool
    {
        return (bool)$this->driver()->has($key);
    }
}