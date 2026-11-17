<?php
/*
 * @Description: 原生 PHP 模板渲染 —— 面板页等视图，不依赖任何模板引擎
 */

declare (strict_types = 1);

namespace aichat\support;

class Template
{
    /**
     * 渲染 .phtml 模板（变量经 extract 注入，模板内自行 htmlspecialchars）
     */
    public static function render(string $file, array $vars = []): string
    {
        if (!is_file($file)) {
            throw new \RuntimeException('模板不存在：' . $file);
        }
        extract($vars, EXTR_SKIP);
        ob_start();
        include $file;
        return (string)ob_get_clean();
    }
}