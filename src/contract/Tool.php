<?php
/*
 * @Description: 工具契约 —— 一个工具 = 一份 OpenAI Function Schema + 一个执行入口
 *               宿主应用实现本接口即可把自己的业务查询接入 AI 对话
 */

declare (strict_types = 1);

namespace aichat\contract;

interface Tool
{
    /**
     * 工具名（OpenAI function name，全局唯一，如 get_exam_scores）
     */
    public function name(): string;

    /**
     * 数据域中文名，用于"你没有查看【X】的权限"这类提示语
     */
    public function label(): string;

    /**
     * OpenAI Function Calling 格式的完整 Schema
     * 建议继承 \aichat\tool\BaseTool 由 description()/parameters()/required() 自动构造
     */
    public function schema(): array;

    /**
     * 执行工具
     *
     * @param int   $userId    当前用户（已经过登录校验）
     * @param array $arguments 来自 LLM 的参数，不可信，必须自行校验
     * @param array $slots     会话槽位（用于"他/她/这个"等指代消解）
     * @return array 统一结果结构（见 BaseTool::reply()）；
     *               可额外携带 'slots' => [...]，核心会合并进会话槽位供下一轮使用
     */
    public function execute(int $userId, array $arguments, array $slots): array;
}