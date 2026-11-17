<?php
/*
 * @Description: 权限提供者契约 —— 宿主应用实现本接口，把自身权限体系接入 AI 对话模块
 *               ★ 本接口是安全的唯一收口：核心代码不内置任何业务权限假设
 */

declare (strict_types = 1);

namespace aichat\contract;

interface PermissionProvider
{
    /**
     * 是否超级管理员（超管可见全部数据、可用全部工具）
     */
    public function isSuper(int $userId): bool;

    /**
     * 工具级权限：用户是否有权调用某工具
     *
     * ⚠️ 工具名来自 LLM，一律视为不可信输入；安全默认值应为"未配置即拒绝"
     */
    public function canUseTool(int $userId, string $toolName): bool;

    /**
     * 字段级权限：用户是否可查看完整手机号（否则一律脱敏）
     */
    public function canSeeFullPhone(int $userId): bool;

    /**
     * 用户角色的中文描述，注入 System Prompt
     * 例如 "（超级管理员，可见全部分校）"；无特殊说明返回空串
     */
    public function roleLabel(int $userId): string;
}