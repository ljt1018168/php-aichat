<?php
/*
 * @Description: 工具注册表 —— 配置驱动，按权限裁剪 Schema
 *              ★ LLM 只能看到自己有权调用的工具：Schema 裁剪是权限的第一道闸
 *              工具类由宿主注册（aichat.tools），实现 aichat\contract\Tool（无参构造或带构造参数）
 */

declare (strict_types = 1);

namespace aichat;

use aichat\contract\PermissionProvider;
use aichat\contract\Tool;

class ToolRegistry
{
    /** @var Config */
    private $config;
    /** @var PermissionProvider */
    private $permission;
    /** @var Tool[]|null 工具实例缓存（name => instance） */
    private $instances;

    public function __construct(Config $config, PermissionProvider $permission)
    {
        $this->config     = $config;
        $this->permission = $permission;
    }

    /**
     * 全部已注册工具
     *
     * @return Tool[] name => instance
     */
    public function all(): array
    {
        if ($this->instances !== null) {
            return $this->instances;
        }

        $this->instances = [];
        foreach ((array)$this->config->get('aichat.tools', []) as $entry) {
            if ($entry instanceof Tool) {
                $tool = $entry;
            } elseif (is_array($entry) && !empty($entry['class'])) {
                // ['class' => 类名, 'args' => [构造参数...]]：支持同一工具类多实例注册
                $class = $entry['class'];
                $args  = (array)($entry['args'] ?? []);
                $tool  = new $class(...$args);
            } elseif (is_string($entry) && $entry !== '') {
                $tool = new $entry();
            } else {
                continue;
            }

            if (!$tool instanceof Tool) {
                throw new \InvalidArgumentException('工具必须实现 ' . Tool::class . '：' . get_class($tool));
            }
            $this->instances[$tool->name()] = $tool;
        }

        return $this->instances;
    }

    /**
     * 取当前用户可用的工具 Schema（送给 LLM）
     *
     * 关键：不可用的工具不出现在 Schema 里，模型连它的存在都不知道。
     */
    public function schemasFor(int $userId): array
    {
        $schemas = [];
        foreach ($this->all() as $name => $tool) {
            if ($this->permission->canUseTool($userId, $name)) {
                $schemas[] = $tool->schema();
            }
        }
        return $schemas;
    }

    /**
     * 当前用户可用的工具名（用于审计与调试）
     */
    public function namesFor(int $userId): array
    {
        $names = [];
        foreach (array_keys($this->all()) as $name) {
            if ($this->permission->canUseTool($userId, $name)) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * 按名称取工具（执行阶段用）
     */
    public function get(string $name): ?Tool
    {
        $all = $this->all();
        return $all[$name] ?? null;
    }
}