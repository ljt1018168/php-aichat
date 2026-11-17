<?php
/*
 * @Description: 工具执行器 —— LLM ⇄ 业务数据之间的唯一通道
 *              实例化，构造注入 ToolRegistry / PermissionProvider / PSR-3 Logger
 */

declare (strict_types = 1);

namespace aichat;

use aichat\contract\PermissionProvider;
use Psr\Log\LoggerInterface;

/**
 * 职责边界（重要）：
 *  - LLM 只能产出"工具名 + 参数"，不能产出 SQL、不能直连数据库
 *  - 所有工具调用都必须经过本类，不得旁路
 *  - 本类负责：工具级权限校验 → 分发 → 结果规范化
 *
 * 行级与字段级权限由各工具实现自行负责（工具最懂自己的数据边界），
 * 但工具必须遵循统一结果结构（见 tool/BaseTool::reply）。
 *
 * 结果 reason 必须区分以下四态，绝不可混淆（"无权限"不等于"没有数据"）：
 *   ok                  查询成功且有数据
 *   empty               查询成功但确实没有数据
 *   not_found           目标对象不存在
 *   no_permission_row   目标对象存在，但当前用户无权查看
 *   no_permission_tool  当前用户无权使用该工具
 *   invalid_argument    参数缺失或不合法
 */
class ToolExecutor
{
    /** @var ToolRegistry */
    private $registry;
    /** @var PermissionProvider */
    private $permission;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(ToolRegistry $registry, PermissionProvider $permission, LoggerInterface $logger)
    {
        $this->registry   = $registry;
        $this->permission = $permission;
        $this->logger     = $logger;
    }

    /**
     * 执行一次工具调用
     *
     * @param string $name      工具名（来自 LLM）
     * @param array  $arguments 参数（来自 LLM，不可信，工具内必须校验）
     * @param int    $userId    当前用户
     * @param array  $slots     会话槽位（用于指代消解）
     * @return array 统一结构
     */
    public function execute(string $name, array $arguments, int $userId, array $slots = []): array
    {
        $tool = $this->registry->get($name);

        // 工具不存在：明确告知，不允许模型猜测其他工具名
        if ($tool === null) {
            return [
                'ok'      => false,
                'reason'  => 'not_found',
                'message' => '工具不存在：' . $name,
                'data'    => [],
            ];
        }

        // 工具级权限（第二道闸：即使 Schema 被绕过，这里仍拦截）
        if (!$this->permission->canUseTool($userId, $name)) {
            return [
                'ok'      => false,
                'reason'  => 'no_permission_tool',
                'message' => '你没有使用「' . $tool->label() . '」的权限，请联系管理员开通。',
                'data'    => [],
            ];
        }

        try {
            $result = $tool->execute($userId, $arguments, $slots);
        } catch (\Throwable $e) {
            // 不把异常细节（可能含表名/SQL）暴露给模型与用户
            $this->logger->error('[aichat] tool execute error', [
                'tool'    => $name,
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return [
                'ok'      => false,
                'reason'  => 'error',
                'message' => '查询过程中出现异常，请稍后重试或改用系统对应页面查询。',
                'data'    => [],
            ];
        }

        return $this->normalize($result);
    }

    /**
     * 结果规范化：补齐字段，保证下游与模型拿到的结构稳定
     */
    private function normalize($result): array
    {
        if (!is_array($result)) {
            $result = ['ok' => true, 'reason' => 'ok', 'message' => '', 'data' => $result];
        }
        return array_merge([
            'ok'      => false,
            'reason'  => 'empty',
            'message' => '',
            'data'    => [],
            'render'  => 'text',
        ], $result);
    }
}