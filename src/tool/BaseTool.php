<?php
/*
 * @Description: 工具基类 —— Schema 构造 + 统一结果结构
 *              工具描述质量直接决定意图识别准确率：
 *              每个描述都写清「用途 + 触发场景 + 参数如何获得」
 */

declare (strict_types = 1);

namespace aichat\tool;

use aichat\contract\Tool;

abstract class BaseTool implements Tool
{
    /**
     * 工具描述：用途 + 触发场景 + 参数如何获得
     */
    abstract public function description(): string;

    /**
     * 参数 JSON Schema properties，如 ['name' => ['type'=>'string','description'=>'..']]
     */
    public function parameters(): array
    {
        return [];
    }

    /**
     * 必填参数名列表
     */
    public function required(): array
    {
        return [];
    }

    public function label(): string
    {
        return $this->name();
    }

    public function schema(): array
    {
        return [
            'type'     => 'function',
            'function' => [
                'name'        => $this->name(),
                'description' => $this->description(),
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => $this->parameters() ?: new \stdClass(),
                    'required'   => $this->required(),
                ],
            ],
        ];
    }

    /**
     * 统一结果结构
     *
     * @param bool   $ok
     * @param string $reason  ok / no_permission_tool / no_permission_row / not_found / empty / ambiguous / disabled / error / missing_param
     * @param string $message 面向用户的中文提示
     * @param array  $data
     * @param string $render  text / table
     */
    public static function reply(bool $ok, string $reason, string $message, array $data = [], string $render = 'text'): array
    {
        return [
            'ok'      => $ok,
            'reason'  => $reason,
            'message' => $message,
            'data'    => $data,
            'render'  => $render,
        ];
    }
}