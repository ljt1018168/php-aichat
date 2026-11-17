<?php
/*
 * @Description: 配置对象 —— 替代全局 config() 助手，构造注入到各核心类
 *              支持 'aichat.llm.api_key' 点号取值；兼容传入 ['aichat'=>[...]] 或扁平数组
 */

declare (strict_types = 1);

namespace aichat;

class Config
{
    /** @var array */
    private $items;

    public function __construct(array $items)
    {
        $this->items = (isset($items['aichat']) && is_array($items['aichat']))
            ? $items
            : ['aichat' => $items];
    }

    /**
     * 从配置文件加载（文件返回数组）
     */
    public static function fromFile(string $file): self
    {
        if (!is_file($file)) {
            throw new \InvalidArgumentException('配置文件不存在: ' . $file);
        }
        return new self((array)require $file);
    }

    /**
     * @param string $key     如 'aichat.llm.api_key'
     * @param mixed  $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $value = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    /**
     * aichat 段全部配置
     */
    public function all(): array
    {
        return $this->items['aichat'];
    }
}