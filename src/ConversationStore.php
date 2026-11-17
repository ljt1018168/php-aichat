<?php
/*
 * @Description: 会话存储 —— 会话 / 消息 / 槽位（原生 PDO，框架无关）
 *              ★ 用户隔离硬实现：所有方法第一行条件都是 user_id
 *                查不到即视为不存在（不返回"无权限"，避免会话ID枚举探测）
 */

declare (strict_types = 1);

namespace aichat;

class ConversationStore
{
    /** @var \PDO */
    private $pdo;
    /** @var Config */
    private $config;
    /** @var string */
    private $conversationTable;
    /** @var string */
    private $messageTable;

    public function __construct(\PDO $pdo, Config $config, string $tablePrefix = '')
    {
        $this->pdo               = $pdo;
        $this->config            = $config;
        $this->conversationTable = $tablePrefix . 'ai_chat_conversation';
        $this->messageTable      = $tablePrefix . 'ai_chat_message';
    }

    /**
     * 取会话（校验归属），不存在则创建
     */
    public function getOrCreate(int $userId, int $conversationId = 0): int
    {
        if ($conversationId > 0) {
            $stmt = $this->pdo->prepare(
                "SELECT id FROM {$this->conversationTable} WHERE id = ? AND user_id = ? AND is_deleted = 0"
            );
            $stmt->execute([$conversationId, $userId]);
            $exists = $stmt->fetchColumn();
            if ($exists) {
                return (int)$exists;
            }
            // 传了不属于自己的 ID：按新会话处理，不报错、不提示"无权限"
        }

        $now = time();
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->conversationTable}
                (user_id, title, slots, slot_update_time, last_active_time, create_time, is_deleted)
             VALUES (?, '', '', 0, ?, ?, 0)"
        );
        $stmt->execute([$userId, $now, $now]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * 取送入 LLM 的历史消息（最近 N 轮）
     *
     * 只影响送给模型的量，消息本身长期保留可回溯。
     */
    public function history(int $userId, int $conversationId, int $rounds = 10): array
    {
        $maxMessages = max(2, $rounds * 2);

        $stmt = $this->pdo->prepare(
            "SELECT role, content, tool_calls, tool_name, tool_call_id
               FROM {$this->messageTable}
              WHERE conversation_id = ? AND user_id = ?
              ORDER BY id DESC LIMIT " . (int)$maxMessages
        );
        $stmt->execute([$conversationId, $userId]);
        $rows = array_reverse($stmt->fetchAll(\PDO::FETCH_ASSOC));

        $messages = [];
        foreach ($rows as $r) {
            $role = (string)$r['role'];

            if ($role === 'tool') {
                // ⚠️ 必须用真实的 tool_call_id，不能用 tool_name 代替：
                // 兼容层要求 tool 消息的 tool_call_id 与对应 assistant tool_call 的 id 严格一致，
                // 否则第二轮起重建历史时会直接报错（多轮对话崩溃）
                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => (string)($r['tool_call_id'] ?: $r['tool_name'] ?: 'call_0'),
                    'content'      => (string)$r['content'],
                ];
                continue;
            }

            if ($role === 'assistant' && !empty($r['tool_calls'])) {
                $calls = json_decode((string)$r['tool_calls'], true);
                if (is_array($calls) && !empty($calls)) {
                    $messages[] = [
                        'role'       => 'assistant',
                        'content'    => (string)$r['content'],
                        'tool_calls' => $calls,
                    ];
                    continue;
                }
            }

            if ($role === 'user' || $role === 'assistant') {
                $messages[] = [
                    'role'    => $role,
                    'content' => (string)$r['content'],
                ];
            }
        }

        // 窗口截断可能切掉 tool 消息对应的 assistant(tool_calls)（孤儿 tool 消息），
        // 或残留没有 tool 回复的 assistant(tool_calls)（上次异常中断）。
        // 这两类孤儿消息会被 OpenAI 兼容端直接拒绝，从两头清理保证重建序列合法。
        while (!empty($messages) && ($messages[0]['role'] ?? '') === 'tool') {
            array_shift($messages);
        }
        while (!empty($messages)) {
            $last = $messages[count($messages) - 1];
            if (($last['role'] ?? '') !== 'assistant' || empty($last['tool_calls'])) {
                break;
            }
            array_pop($messages);
        }

        return $messages;
    }

    /**
     * 追加一条消息
     */
    public function append(
        int $userId,
        int $conversationId,
        string $role,
        string $content,
        string $toolCalls = '',
        string $toolName = '',
        string $toolCallId = ''
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->messageTable}
                (conversation_id, user_id, role, content, tool_calls, tool_name, tool_call_id, token_estimate, create_time)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $conversationId, $userId, $role, $content, $toolCalls, $toolName, $toolCallId,
            (int)ceil(mb_strlen($content) / 2), time(),
        ]);
        $id = (int)$this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            "UPDATE {$this->conversationTable} SET last_active_time = ? WHERE id = ? AND user_id = ?"
        );
        $stmt->execute([time(), $conversationId, $userId]);

        return $id;
    }

    /**
     * 设置会话标题（取首条提问）
     */
    public function touchTitle(int $userId, int $conversationId, string $question): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT title FROM {$this->conversationTable} WHERE id = ? AND user_id = ?"
        );
        $stmt->execute([$conversationId, $userId]);
        $title = $stmt->fetchColumn();

        if (!empty($title)) {
            return;
        }
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->conversationTable} SET title = ? WHERE id = ? AND user_id = ?"
        );
        $stmt->execute([mb_substr($question, 0, 30), $conversationId, $userId]);
    }

    /**
     * 取槽位（含 TTL 判断）
     */
    public function slots(int $userId, int $conversationId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT slots, slot_update_time FROM {$this->conversationTable}
              WHERE id = ? AND user_id = ? AND is_deleted = 0"
        );
        $stmt->execute([$conversationId, $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (empty($row) || empty($row['slots'])) {
            return [];
        }

        $ttl = (int)$this->config->get('aichat.agent.slot_ttl', 86400);
        if ($ttl > 0 && (time() - (int)$row['slot_update_time']) > $ttl) {
            return [];   // 过期清空
        }

        $slots = json_decode((string)$row['slots'], true);
        return is_array($slots) ? $slots : [];
    }

    /**
     * 更新槽位
     */
    public function updateSlots(int $userId, int $conversationId, array $slots): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->conversationTable} SET slots = ?, slot_update_time = ? WHERE id = ? AND user_id = ?"
        );
        $stmt->execute([json_encode($slots, JSON_UNESCAPED_UNICODE), time(), $conversationId, $userId]);
    }

    /**
     * 会话列表
     */
    public function listConversations(int $userId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->conversationTable}
              WHERE user_id = ? AND is_deleted = 0
              ORDER BY last_active_time DESC LIMIT " . (int)$limit
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * 清空会话（软删 + 清槽位）
     */
    public function clear(int $userId, int $conversationId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->conversationTable} SET is_deleted = 1, slots = '', slot_update_time = 0
              WHERE id = ? AND user_id = ?"
        );
        $stmt->execute([$conversationId, $userId]);
        return true;
    }
}