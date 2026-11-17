<?php
/*
 * @Description: 默认权限提供者 —— 纯配置驱动，适合无复杂 RBAC 的小型应用开箱即用
 *              有自身权限体系的项目请实现 PermissionProvider 并配置 aichat.permission_provider
 *              （参考 examples/sms/SmsPermissionProvider.php）
 */

declare (strict_types = 1);

namespace aichat\provider;

use aichat\Config;
use aichat\contract\PermissionProvider;

class ConfigPermissionProvider implements PermissionProvider
{
    /** @var Config */
    private $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function isSuper(int $userId): bool
    {
        return in_array($userId, array_map('intval', (array)$this->config->get('aichat.super_ids', [1])), true);
    }

    public function canUseTool(int $userId, string $toolName): bool
    {
        if ($this->isSuper($userId)) {
            return true;
        }
        if ($this->config->get('aichat.allow_all_tools', false)) {
            return true;
        }
        // 工具 → 允许使用的用户ID列表；未配置 = 不可用（安全默认值）
        $map = (array)$this->config->get('aichat.tool_users', []);
        $ids = isset($map[$toolName]) ? (array)$map[$toolName] : [];
        return in_array($userId, array_map('intval', $ids), true);
    }

    public function canSeeFullPhone(int $userId): bool
    {
        if ($this->isSuper($userId)) {
            return true;
        }
        $ids = array_map('intval', (array)$this->config->get('aichat.phone_full_users', []));
        return in_array($userId, $ids, true);
    }

    public function roleLabel(int $userId): string
    {
        return $this->isSuper($userId) ? '（超级管理员，可见全部数据）' : '';
    }
}