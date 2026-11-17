<?php
/*
 * @Description: Laravel 桥接 —— 从 Laravel 容器组装框架无关的 ChatHandler
 *              Laravel 的 Cache Repository 原生实现 PSR-16、Log Channel 原生实现 PSR-3，直接注入
 *              用法：$handler = LaravelBridge::make();（或注册为单例，见 README）
 *              前提：宿主已发布 config/aichat.php 并执行 sql/install.sql
 */

declare (strict_types = 1);

namespace aichat\adapter\laravel;

use aichat\ChatHandler;
use aichat\Config;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LaravelBridge
{
    /** @var ChatHandler|null */
    private static $handler;

    public static function make(): ChatHandler
    {
        if (self::$handler !== null) {
            return self::$handler;
        }

        $config     = new Config((array)config('aichat'));
        $connection = DB::connection(config('aichat.db_connection') ?: null);

        self::$handler = new ChatHandler(
            $config,
            $connection->getPdo(),
            Cache::store(config('aichat.cache_store') ?: null),
            Log::channel(config('aichat.log_channel') ?: null),
            null,
            $connection->getTablePrefix()
        );

        return self::$handler;
    }

    /**
     * 重置（测试用）
     */
    public static function reset(): void
    {
        self::$handler = null;
    }
}