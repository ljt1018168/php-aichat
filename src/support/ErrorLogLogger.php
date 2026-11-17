<?php
/*
 * @Description: PSR-3 error_log 日志 —— 无框架环境的兜底实现
 *              生产环境建议换宿主框架的日志实现（Laravel Log::channel() / TP 桥接 TpLogger）
 */

declare (strict_types = 1);

namespace aichat\support;

use Psr\Log\AbstractLogger;

class ErrorLogLogger extends AbstractLogger
{
    /**
     * @param mixed  $level
     * @param string|\Stringable $message
     */
    public function log($level, $message, array $context = []): void
    {
        $text = '[' . date('Y-m-d H:i:s') . '] aichat.' . $level . ': ' . $this->interpolate((string)$message, $context);
        if (!empty($context)) {
            $text .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        error_log($text);
    }

    private function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $val) {
            if (is_scalar($val) || $val === null) {
                $replace['{' . $key . '}'] = (string)$val;
            }
        }
        return strtr($message, $replace);
    }
}