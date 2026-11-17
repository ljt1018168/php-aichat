<?php
/*
 * @Description: PSR-16 文件缓存 —— 无框架/无 Redis 环境的兜底实现（限流、通用缓存）
 *              生产环境建议换宿主框架的缓存实现（Laravel Cache::store() / TP 桥接 TpCache）
 */

declare (strict_types = 1);

namespace aichat\support;

use Psr\SimpleCache\CacheInterface;

class FileCache implements CacheInterface
{
    /** @var string */
    private $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/');
        if (!is_dir($this->dir) && !mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('缓存目录创建失败：' . $this->dir);
        }
    }

    public function get($key, $default = null)
    {
        $file = $this->file($key);
        if (!is_file($file)) {
            return $default;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return $default;
        }
        $data = @unserialize($raw);
        if (!is_array($data) || !array_key_exists('value', $data)) {
            return $default;
        }
        if ($data['expire'] > 0 && time() >= $data['expire']) {
            @unlink($file);
            return $default;
        }
        return $data['value'];
    }

    public function set($key, $value, $ttl = null): bool
    {
        $expire = 0;
        if ($ttl !== null) {
            $seconds = $ttl instanceof \DateInterval ? $this->intervalSeconds($ttl) : (int)$ttl;
            if ($seconds <= 0) {
                return $this->delete($key);
            }
            $expire = time() + $seconds;
        }
        $file = $this->file($key);
        $tmp  = $file . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, serialize(['expire' => $expire, 'value' => $value]), LOCK_EX) === false) {
            return false;
        }
        return rename($tmp, $file);
    }

    public function delete($key): bool
    {
        $file = $this->file($key);
        return !is_file($file) || @unlink($file);
    }

    public function clear(): bool
    {
        $ok = true;
        foreach (glob($this->dir . '/*.cache') ?: [] as $file) {
            $ok = @unlink($file) && $ok;
        }
        return $ok;
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
        return $this->get($key, $this) !== $this;
    }

    private function file(string $key): string
    {
        return $this->dir . '/' . md5($key) . '.cache';
    }

    private function intervalSeconds(\DateInterval $interval): int
    {
        $now = new \DateTimeImmutable();
        return $now->add($interval)->getTimestamp() - $now->getTimestamp();
    }
}