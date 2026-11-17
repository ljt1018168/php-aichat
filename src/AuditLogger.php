<?php
/*
 * @Description: 审计日志 —— 记录全部请求（含被拒），原生 PDO + PSR-3
 *              日志本身含业务敏感信息，需纳入与业务数据同级的访问控制
 */

declare (strict_types = 1);

namespace aichat;

use Psr\Log\LoggerInterface;

class AuditLogger
{
    /** @var \PDO */
    private $pdo;
    /** @var Config */
    private $config;
    /** @var LoggerInterface */
    private $logger;
    /** @var string */
    private $table;

    public function __construct(\PDO $pdo, Config $config, LoggerInterface $logger, string $tablePrefix = '')
    {
        $this->pdo    = $pdo;
        $this->config = $config;
        $this->logger = $logger;
        $this->table  = $tablePrefix . 'ai_chat_log';
    }

    /**
     * 记录一次对话
     *
     * @param int    $userId
     * @param int    $conversationId
     * @param string $question   用户原始提问
     * @param array  $tools      本次调用的工具名列表
     * @param array  $params     查询参数（不含敏感结果）
     * @param string $reason     结果类型
     * @param string $summary    响应摘要（已脱敏）
     * @param int    $latencyMs
     * @param array  $usage      token 用量
     */
    public function record(
        int $userId,
        int $conversationId,
        string $question,
        array $tools,
        array $params,
        string $reason,
        string $summary,
        int $latencyMs = 0,
        array $usage = []
    ): void {
        if (!$this->config->get('aichat.audit.enable', true)) {
            return;
        }

        $logQuestion = $this->config->get('aichat.audit.log_question', true);

        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO {$this->table}
                    (user_id, conversation_id, question, tool_called, params, reason, response_summary,
                     latency_ms, prompt_tokens, completion_tokens, create_time)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $userId,
                $conversationId,
                $logQuestion ? mb_substr($question, 0, 500) : '',
                mb_substr(implode(',', $tools), 0, 100),
                mb_substr(self::safeParams($params), 0, 1000),
                $reason,
                mb_substr($summary, 0, 1000),
                $latencyMs,
                (int)($usage['prompt_tokens'] ?? 0),
                (int)($usage['completion_tokens'] ?? 0),
                time(),
            ]);
        } catch (\Throwable $e) {
            // 审计失败不得影响主流程，但必须留痕到日志
            $this->logger->error('[aichat] audit write failed: ' . $e->getMessage(), [
                'user_id' => $userId,
                'reason'  => $reason,
            ]);
        }
    }

    /**
     * 参数脱敏后再落库（防止把敏感输入原样写进日志）
     */
    private static function safeParams(array $params): string
    {
        $safe = [];
        foreach ($params as $k => $v) {
            $lower = strtolower((string)$k);
            if ($lower === 'phone' || $lower === 'mobile') {
                $safe[$k] = Masker::maskPhone((string)$v);
            } elseif (strpos($lower, 'idcard') !== false || strpos($lower, 'id_card') !== false) {
                $safe[$k] = '***';
            } else {
                $safe[$k] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v;
            }
        }
        return json_encode($safe, JSON_UNESCAPED_UNICODE);
    }
}