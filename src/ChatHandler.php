<?php
/*
 * @Description: AI 对话主入口 —— Agent 循环编排（框架无关，实例化 + 构造注入）
 *              依赖：Config / PDO / PSR-16 缓存 / PSR-3 日志 / PermissionProvider（可选，默认配置驱动）
 *              宿主控制器只需：$handler->ask($userId, $question, $conversationId)
 */

declare (strict_types = 1);

namespace aichat;

use aichat\contract\PermissionProvider;
use aichat\provider\ConfigPermissionProvider;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * 设计约束（安全红线，改动前必读）：
 *  1. LLM 不得直连数据库，只能产出"工具名 + 参数"，全部经 ToolExecutor
 *  2. 工具 Schema 按权限裁剪；执行前二次校验（不信任模型输出）
 *  3. 敏感字段进模型前必须经 Masker 脱敏
 *  4. 会话归属强隔离：所有读写以 user_id 为第一条件
 *  5. "无权限"与"无数据"必须区分，不得混淆
 *  6. 全部请求（含被拒）落审计日志
 */
class ChatHandler
{
    /** 缓存键前缀（避免与宿主应用键冲突） */
    private const CACHE_PREFIX = 'aichat:';

    /** @var Config */
    private $config;
    /** @var CacheInterface */
    private $cache;
    /** @var PermissionProvider */
    private $permission;
    /** @var ConversationStore */
    private $store;
    /** @var AuditLogger */
    private $audit;
    /** @var Masker */
    private $masker;
    /** @var ToolRegistry */
    private $registry;
    /** @var ToolExecutor */
    private $executor;
    /** @var LlmClient */
    private $llm;

    public function __construct(
        Config $config,
        \PDO $pdo,
        CacheInterface $cache,
        LoggerInterface $logger,
        ?PermissionProvider $permission = null,
        string $tablePrefix = ''
    ) {
        $this->config     = $config;
        $this->cache      = $cache;
        $this->permission = $permission ?? self::resolveProvider($config);
        $this->masker     = new Masker($config, $this->permission);
        $this->store      = new ConversationStore($pdo, $config, $tablePrefix);
        $this->audit      = new AuditLogger($pdo, $config, $logger, $tablePrefix);
        $this->registry   = new ToolRegistry($config, $this->permission);
        $this->executor   = new ToolExecutor($this->registry, $this->permission, $logger);
        $this->llm        = new LlmClient($config);

        // 供宿主工具在 execute() 内通过 Masker::current() 取用
        Masker::setCurrent($this->masker);
    }

    /**
     * 解析权限提供者：
     *  - 未配置 → ConfigPermissionProvider（配置驱动默认实现）
     *  - 配置类名 → 构造函数首个参数若需要则注入 Config，否则无参构造
     */
    private static function resolveProvider(Config $config): PermissionProvider
    {
        $class = (string)$config->get('aichat.permission_provider', '');
        if ($class === '') {
            return new ConfigPermissionProvider($config);
        }
        if (!class_exists($class)) {
            throw new \InvalidArgumentException('权限提供者类不存在：' . $class);
        }
        $ctor     = (new \ReflectionClass($class))->getConstructor();
        $provider = ($ctor && $ctor->getNumberOfParameters() > 0) ? new $class($config) : new $class();
        if (!$provider instanceof PermissionProvider) {
            throw new \InvalidArgumentException($class . ' 必须实现 ' . PermissionProvider::class);
        }
        return $provider;
    }

    // ==================== 对外接口 ====================

    /**
     * 提问（核心入口）
     *
     * @param int    $userId         当前用户（由宿主从登录态取出，核心不碰 Session）
     * @param string $question       用户提问
     * @param int    $conversationId 会话ID，0=新建
     */
    public function ask(int $userId, string $question, int $conversationId = 0): array
    {
        $start = microtime(true);

        // ---------- 前置校验 ----------
        if (!$this->config->get('aichat.enable', false)) {
            return $this->denied($userId, $conversationId, $question, 'disabled', 'AI 助手当前未开启。', $start);
        }

        $question = trim($question);
        if ($question === '') {
            return ['code' => 1, 'msg' => '请输入问题', 'data' => []];
        }
        $maxLen = (int)$this->config->get('aichat.security.max_question_length', 500);
        if (mb_strlen($question) > $maxLen) {
            return ['code' => 1, 'msg' => "问题过长，请控制在 {$maxLen} 字以内", 'data' => []];
        }
        if ($this->isBlocked($question)) {
            return $this->denied($userId, $conversationId, $question, 'blocked', '该问题涉及敏感信息，暂不支持查询。', $start);
        }
        if (!$this->checkRateLimit($userId)) {
            return $this->denied($userId, $conversationId, $question, 'rate_limited', '提问过于频繁，请稍后再试。', $start);
        }

        // ---------- 会话与槽位 ----------
        $conversationId = $this->store->getOrCreate($userId, $conversationId);
        $slots          = $this->store->slots($userId, $conversationId);
        $history        = $this->store->history(
            $userId,
            $conversationId,
            (int)$this->config->get('aichat.agent.history_rounds', 10)
        );

        // ---------- 工具 Schema 按权限裁剪 ----------
        $toolSchemas = $this->registry->schemasFor($userId);
        if (empty($toolSchemas)) {
            return $this->denied(
                $userId, $conversationId, $question, 'no_permission_tool',
                '你当前没有任何 AI 查询工具的使用权限，请联系管理员开通。', $start
            );
        }

        $this->store->append($userId, $conversationId, 'user', $question);
        $this->store->touchTitle($userId, $conversationId, $question);

        // ---------- 组装 messages ----------
        $messages = array_merge(
            [['role' => 'system', 'content' => $this->systemPrompt($userId, $slots)]],
            $history,
            [['role' => 'user', 'content' => $question]]
        );

        // ---------- Agent 循环 ----------
        $maxRounds = (int)$this->config->get('aichat.agent.max_tool_rounds', 4);
        $toolTrace = [];
        $usedTools = [];
        $totalUsage = ['prompt_tokens' => 0, 'completion_tokens' => 0];
        $answer     = '';
        $render     = 'text';
        $lastData   = [];

        for ($round = 0; $round <= $maxRounds; $round++) {
            // 达到上限后不再提供工具，强制模型基于已有信息作答
            $toolsForCall = ($round < $maxRounds) ? $toolSchemas : [];
            $resp = $this->llm->chat($messages, $toolsForCall);

            if (!$resp['ok']) {
                return $this->denied($userId, $conversationId, $question, 'llm_error', $resp['error'], $start, $usedTools);
            }

            $totalUsage['prompt_tokens']     += (int)($resp['usage']['prompt_tokens'] ?? 0);
            $totalUsage['completion_tokens'] += (int)($resp['usage']['completion_tokens'] ?? 0);

            $calls = $resp['tool_calls'];
            if (empty($calls)) {
                $answer = $resp['content'];
                break;
            }

            // 记录 assistant 的工具调用意图（保留原始结构，保证历史可重放）
            $assistantMsg = ['role' => 'assistant', 'content' => $resp['content']];
            $rawCalls = [];
            foreach ($calls as $c) {
                $rawCalls[] = $c['raw'] ?: [
                    'id'       => $c['id'],
                    'type'     => 'function',
                    'function' => [
                        'name'      => $c['name'],
                        'arguments' => json_encode($c['arguments'], JSON_UNESCAPED_UNICODE),
                    ],
                ];
            }
            $assistantMsg['tool_calls'] = $rawCalls;
            $messages[] = $assistantMsg;
            $this->store->append(
                $userId, $conversationId, 'assistant', $resp['content'],
                json_encode($rawCalls, JSON_UNESCAPED_UNICODE)
            );

            // 逐个执行工具
            foreach ($calls as $call) {
                $result = $this->executor->execute($call['name'], $call['arguments'], $userId, $slots);

                $usedTools[] = $call['name'];
                $toolTrace[] = [
                    'tool'      => $call['name'],
                    'arguments' => $call['arguments'],
                    'reason'    => $result['reason'],
                ];

                // 槽位更新：工具结果可携带 'slots'，由核心统一合并（指代消解）
                if (!empty($result['slots']) && is_array($result['slots'])) {
                    $slots = array_merge($slots, $result['slots']);
                    $this->store->updateSlots($userId, $conversationId, $slots);
                }

                if (($result['render'] ?? 'text') !== 'text') {
                    $render   = $result['render'];
                    $lastData = $result['data'];
                }

                // 结果回灌给模型（已是脱敏数据）
                $payload = json_encode([
                    'ok'      => $result['ok'],
                    'reason'  => $result['reason'],
                    'message' => $result['message'],
                    'data'    => $result['data'],
                ], JSON_UNESCAPED_UNICODE);

                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $call['id'],
                    'content'      => $payload,
                ];
                $this->store->append($userId, $conversationId, 'tool', $payload, '', $call['name'], $call['id']);
            }
        }

        if ($answer === '') {
            $answer = '抱歉，本次查询未能得出明确结论，请尝试更具体地描述（例如提供学员姓名或手机号后四位）。';
        }

        $this->store->append($userId, $conversationId, 'assistant', $answer);

        $reason = empty($usedTools) ? 'ok' : 'ok';
        $this->audit->record(
            $userId, $conversationId, $question, $usedTools,
            ['slots' => array_keys($slots)],
            $reason, $answer,
            (int)((microtime(true) - $start) * 1000),
            $totalUsage
        );

        return [
            'code' => 0,
            'msg'  => 'ok',
            'data' => [
                'conversation_id' => $conversationId,
                'answer'          => $answer,
                'render'          => $render,
                'render_data'     => $lastData,
                'tools'           => $toolTrace,
                'usage'           => $totalUsage,
            ],
        ];
    }

    /**
     * 会话列表
     */
    public function conversations(int $userId): array
    {
        $list = $this->store->listConversations($userId);
        $data = [];
        foreach ($list as $row) {
            $data[] = [
                'id'         => (int)$row['id'],
                'title'      => (string)($row['title'] ?: '新对话'),
                'last_active'=> (int)$row['last_active_time'],
            ];
        }
        return ['code' => 0, 'msg' => 'ok', 'data' => $data];
    }

    /**
     * 清空会话
     */
    public function clear(int $userId, int $conversationId): array
    {
        $this->store->clear($userId, $conversationId);
        return ['code' => 0, 'msg' => 'ok', 'data' => []];
    }

    /**
     * 当前用户可用的工具（前端展示"我能问什么"）
     */
    public function availableTools(int $userId): array
    {
        $data = [];
        foreach ($this->registry->all() as $name => $tool) {
            if ($this->permission->canUseTool($userId, $name)) {
                $data[] = ['name' => $name, 'label' => $tool->label()];
            }
        }
        return ['code' => 0, 'msg' => 'ok', 'data' => $data];
    }

    // ==================== 内部 ====================

    /**
     * System Prompt：模板占位符 {glossary} {role} {slots} {user_id}，可用 aichat.system_prompt 整体覆盖
     */
    private function systemPrompt(int $userId, array $slots): string
    {
        $template = (string)$this->config->get('aichat.system_prompt', '');
        if ($template === '') {
            $template = $this->defaultPrompt();
        }

        $slotText = '（无）';
        if (!empty($slots)) {
            $parts = [];
            foreach ($slots as $k => $v) {
                $parts[] = $k . '=' . (is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE));
            }
            $slotText = implode('，', $parts);
        }

        return strtr($template, [
            '{glossary}' => $this->glossaryText(),
            '{role}'     => $this->permission->roleLabel($userId),
            '{slots}'    => $slotText,
            '{user_id}'  => (string)$userId,
        ]);
    }

    /**
     * 术语表：支持字符串或键值数组（数组逐行展开）
     */
    private function glossaryText(): string
    {
        $glossary = $this->config->get('aichat.glossary', '');
        if (is_array($glossary)) {
            $lines = [];
            foreach ($glossary as $k => $v) {
                $lines[] = is_int($k) ? (string)$v : ($k . '：' . (is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE)));
            }
            return implode("\n", $lines);
        }
        return (string)$glossary;
    }

    private function defaultPrompt(): string
    {
        return <<<TXT
你是业务系统的 AI 数据查询助手，帮助当前登录用户查询其权限范围内的业务数据。

【业务术语】
{glossary}

【当前用户】
ID={user_id}{role}
【会话上下文槽位】
{slots}
用户说"这个学员/他/她/上面那个"等指代时，优先使用槽位中的对象；槽位为空则要求用户明确对象。

【铁律】
1. 你只能通过提供的工具获取数据，禁止编造任何数据、姓名、金额、日期。
2. 工具返回 reason=no_permission_row 或 no_permission_tool 时，必须明确告知"无权限查看"，
   严禁表述为"没有数据/不存在"——无权限与无数据是完全不同的两件事。
3. 工具返回 reason=not_found 时告知对象不存在；reason=empty 时告知确实没有数据。
4. 返回数据中的手机号等已脱敏，按原样呈现，不要尝试还原或推测完整值。
5. 涉及金额、日期时逐字引用工具返回值，不要自行计算或换算。
6. 一次回答尽量聚焦一个问题；需要多个工具时按顺序调用。
7. 你只有查询能力，任何修改/删除数据的请求都要明确拒绝并引导用户去系统对应页面操作。
8. 用简体中文回答，简洁、结构化；列表数据用 Markdown 表格呈现。
TXT;
    }

    /**
     * 敏感词拦截
     */
    private function isBlocked(string $question): bool
    {
        foreach ((array)$this->config->get('aichat.security.blocked_keywords', []) as $kw) {
            if ($kw !== '' && mb_stripos($question, (string)$kw) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 限流：固定窗口（PSR-16）
     */
    private function checkRateLimit(int $userId): bool
    {
        $max    = (int)$this->config->get('aichat.rate_limit.limit', 30);
        $window = (int)$this->config->get('aichat.rate_limit.window', 60);
        if ($max <= 0) {
            return true;
        }

        $key   = self::CACHE_PREFIX . 'rl:' . $userId;
        $count = (int)$this->cache->get($key, 0);
        if ($count >= $max) {
            return false;
        }
        $this->cache->set($key, $count + 1, $window);
        return true;
    }

    /**
     * 拒绝应答（同样落审计）
     */
    private function denied(
        int $userId,
        int $conversationId,
        string $question,
        string $reason,
        string $message,
        float $start,
        array $usedTools = []
    ): array {
        $this->audit->record(
            $userId, $conversationId, $question, $usedTools, [],
            $reason, $message,
            (int)((microtime(true) - $start) * 1000)
        );
        return [
            'code' => 1,
            'msg'  => $message,
            'data' => ['reason' => $reason, 'conversation_id' => $conversationId],
        ];
    }
}