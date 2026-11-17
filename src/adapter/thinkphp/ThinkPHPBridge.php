<?php
/*
 * @Description: ThinkPHP 6 桥接 —— 从 TP 容器组装框架无关的 ChatHandler
 *              用法：$handler = ThinkPHPBridge::make();
 *              前提：宿主已配置 config/aichat.php 并执行 sql/install.sql
 */

declare (strict_types = 1);

namespace aichat\adapter\thinkphp;

use aichat\ChatHandler;
use aichat\Config;
use think\facade\Db;

class ThinkPHPBridge
{
    /** @var ChatHandler|null 进程内复用（同一请求多次调用免重复组装） */
    private static $handler;

    public static function make(): ChatHandler
    {
        if (self::$handler !== null) {
            return self::$handler;
        }

        $config     = new Config((array)config('aichat'));
        $connection = Db::connect((string)config('aichat.db_connection', '') ?: null);

        self::$handler = new ChatHandler(
            $config,
            $connection->getPdo(),
            new TpCache(config('aichat.cache_store') ?: null),
            new TpLogger(),
            null,
            (string)$connection->getConfig('prefix')
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