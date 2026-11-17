<?php
/*
 * @Description: php-aichat 主配置 —— 复制到宿主项目 config/aichat.php
 *              所有可调整项集中在此，业务代码不硬编码。
 *
 * 本模板为纯数组（框架无关）。ThinkPHP/Laravel 用户可自行把敏感项改回
 * env('aichat.api_key') / env('AICHAT_API_KEY') 写法，由宿主配置加载器解析。
 *
 * ⚠️ API_KEY 属敏感凭证，禁止提交进 Git（.env 应在 .gitignore 中）。
 */

return [

    // ==================== 总开关 ====================
    // 关闭时接口直接返回"功能未开启"，不调用 LLM
    'enable' => false,

    // ==================== 宿主接入 ====================
    // 登录用户ID的 Session 键（ThinkPHP 适配器使用；Laravel 适配器默认 Auth::id()）
    'session_user_key' => 'admin.id',

    // 前端接口地址前缀（需与宿主路由一致，见 README「接入」一节）
    'api_base' => '/aichat',

    // 数据库连接名（ThinkPHP database.php connections 键 / Laravel database.php connections 键）；
    // 留空用默认连接。通用 PDO 接入时本项无效（PDO 由宿主直接注入）
    'db_connection' => '',

    // 权限提供者：实现 \aichat\contract\PermissionProvider 的类名。
    // 留空使用内置 ConfigPermissionProvider（纯配置驱动，见下方 super_ids/tool_users）。
    // 有自身 RBAC 的项目务必替换（参考 examples/sms/SmsPermissionProvider.php）。
    // 构造函数可选：首个参数需要时核心会注入 Config 对象，否则无参构造。
    'permission_provider' => '',

    // 内置提供者使用：超管用户ID列表
    'super_ids' => [1],
    // 内置提供者使用：true = 所有登录用户可用全部工具（仅建议内部演示环境）
    'allow_all_tools' => false,
    // 内置提供者使用：工具名 => 允许使用的用户ID列表；未配置的工具非超管一律不可用
    'tool_users' => [
        // 'get_exam_scores' => [2, 3],
    ],
    // 内置提供者使用：可查看完整手机号的用户ID列表；超管默认可见
    'phone_full_users' => [],

    // ==================== 工具注册 ====================
    // 实现 \aichat\contract\Tool 的类名，或 ['class'=>类名, 'args'=>[构造参数]]
    'tools' => [
        // \app\tool\MyTool::class,
        // ['class' => \app\tool\TableTool::class, 'args' => ['get_orders', '订单', '查询订单', [...]]],
    ],

    // ==================== LLM ====================
    // OpenAI 兼容协议，换厂商只需改 base_url 与 model。
    // 例：DeepSeek https://api.deepseek.com + deepseek-chat
    //     阿里云百炼 https://dashscope.aliyuncs.com/compatible-mode/v1 + qwen-plus
    'llm' => [
        'base_url'   => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
        'api_key'    => '',
        'model'      => 'qwen-plus',

        // ⚠️ 调 LLM 必须显式放大超时，常规 HTTP 助手的 5 秒超时会稳定失败
        'timeout'    => 60,
        // 连接阶段超时（秒），独立于整体超时
        'connect_timeout' => 10,

        'temperature' => 0.1,   // 查询场景要稳定，不要发散
        'max_tokens'  => 1500,

        // OpenAI 兼容模式统一为 /chat/completions
        'chat_path'   => '/chat/completions',
    ],

    // ==================== Agent 循环 ====================
    'agent' => [
        // 一次提问最多允许的 LLM ⇄ 工具往返轮数，防止死循环烧钱
        'max_tool_rounds' => 4,
        // 送入 LLM 的历史轮数（1 轮 = 1 条用户 + 1 条助手）
        'history_rounds'  => 10,
        // 槽位有效期（秒），过期清空指代缓存
        'slot_ttl'        => 86400,
    ],

    // ==================== 安全 ====================
    'security' => [
        // 单条提问最大长度
        'max_question_length' => 500,
        // 命中关键词直接拒绝，不调用 LLM，省一次往返
        'blocked_keywords' => [
            // '薪资', '工资', '薪酬', '提成', '财务报表',
        ],
    ],

    // ==================== 限流 ====================
    'rate_limit' => [
        'limit'    => 30,   // 次数
        'window'   => 60,   // 秒
    ],

    // 缓存驱动名（TP cache.php stores 键 / Laravel cache.php stores 键，如 redis）；留空用默认驱动
    'cache_store' => '',
    // 日志通道名（仅 Laravel 适配器使用，log.php channels 键）；留空用默认通道
    'log_channel' => '',

    // ==================== 脱敏规则 ====================
    'mask' => [
        // 身份证：默认一律不返回
        'idcard_return'  => false,
        // 家庭住址：一律不返回
        'address_return' => false,
    ],

    // ==================== 业务术语表 ====================
    // 注入 System Prompt，把行话告诉模型；支持键值数组或整段字符串
    'glossary' => [
        // 'status' => '1=启用，2=停用',
    ],

    // ==================== System Prompt 覆盖 ====================
    // 留空使用内置通用模板；自定义时支持占位符 {glossary} {role} {slots} {user_id}
    'system_prompt' => '',

    // ==================== 审计 ====================
    'audit' => [
        'enable'       => true,
        // 是否记录完整提问原文（关闭则只记录意图与工具名）
        'log_question' => true,
    ],
];