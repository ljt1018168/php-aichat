<?php
/*
 * @Description: PSR-3 桥接 —— 包装 ThinkPHP 6 日志（think\facade\Log）
 *              仅在 ThinkPHP 宿主内使用；其他框架勿引用本文件
 */

declare (strict_types = 1);

namespace aichat\adapter\thinkphp;

use Psr\Log\AbstractLogger;
use think\facade\Log;

class TpLogger extends AbstractLogger
{
    /** PSR-3 级别 → TP 日志类型 */
    private const LEVEL_MAP = [
        'emergency' => 'error',
        'alert'     => 'error',
        'critical'  => 'error',
        'error'     => 'error',
        'warning'   => 'warning',
        'notice'    => 'notice',
        'info'      => 'info',
        'debug'     => 'debug',
    ];

    /**
     * @param mixed $level
     * @param string|\Stringable $message
     */
    public function log($level, $message, array $context = []): void
    {
        $text = (string)$message;
        if (!empty($context)) {
            $text .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        Log::record($text, self::LEVEL_MAP[strtolower((string)$level)] ?? 'info');
    }
}