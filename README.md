# php-aichat

框架无关的 **AI 对话查询业务数据** 模块：让后台用户用一句话查询自己权限范围内的业务数据。

核心是一个受限 Agent：LLM 只能产出「工具名 + 参数」，所有数据访问都经过
**权限校验 → 工具执行 → 脱敏 → 审计** 的流水线，LLM 永远接触不到数据库与未脱敏原文。

```
用户提问 ──► ChatHandler（Agent 循环，最多 N 轮）
                │
                ├─► LlmClient        OpenAI 兼容协议（新式 tool_calls + 旧式 function_call 双兼容）
                │
                └─► ToolExecutor ──► 你的工具（实现 Tool 契约）
                        │                 │
                        │                 ├─ 行级权限（你的业务口径）
                        │                 └─ Masker 字段脱敏
                        │
                        ├─ 工具级权限二次校验（PermissionProvider 契约）
                        ├─ ConversationStore  会话/历史/槽位（PDO，user_id 强隔离）
                        └─ AuditLogger        全量审计（含被拒请求）
```

## 特性

- **框架无关**：核心只依赖 `PDO` + `PSR-16 缓存` + `PSR-3 日志` + 配置数组；
  附 ThinkPHP 6 / Laravel 适配器，其他框架（Yii、Symfony、CodeIgniter、裸 PHP）直接注入即可
- **核心类实例化 + 构造注入**：无全局状态、无 facade、可单测（仓库自带 46 项断言冒烟测试）
- **三层权限**：工具级（Schema 按权限裁剪 + 执行前二次校验）→ 行级（工具内实现）→ 字段级（脱敏）
- **四态严格区分**：`no_permission_tool / no_permission_row / not_found / empty`，
  绝不把「无权限」说成「没有数据」
- **会话槽位**：工具结果携带 `slots` 由核心合并，支持「他/这个学员」指代消解，带 TTL
- **安全默认值**：未授权工具一律不可用；敏感词前置拦截；限流；异常细节不外泄
- **前端零依赖**：悬浮球 + 独立面板均为原生 JS，任何后台模板可挂

## 环境要求

- PHP >= 7.4，扩展 `curl` / `mbstring` / `pdo`
- MySQL（三张表，见 `sql/install.sql`）
- `psr/simple-cache ^1.0 || ^2.0`、`psr/log ^1.1 || ^2.0 || ^3.0`
  （simple-cache 排除 v3：v3 的强制返回类型与 PHP 7.4 不兼容；Laravel/Symfony 均允许 v1/v2 共存）
- 一个 OpenAI 兼容协议的 LLM 服务（阿里云百炼 / DeepSeek / OpenAI / 本地 vLLM…）

## 安装

```bash
composer require tonyliu/php-aichat
```

1. 建表：执行 `sql/install.sql`（`ai_chat_conversation` / `ai_chat_message` / `ai_chat_log`，用户列为 `user_id`）
2. 配置：把 `config/aichat.php` 复制到宿主配置目录，填 `llm.api_key` 等
3. 接线：按下方任一框架接入

## 接入

### 通用（任何框架 / 裸 PHP）

核心只要求四样东西，全部构造注入：

```php
use aichat\ChatHandler;
use aichat\Config;

$handler = new ChatHandler(
    new Config(require 'config/aichat.php'),  // 配置数组
    $pdo,                                     // PDO（业务库）
    $psr16Cache,                              // PSR-16（限流/缓存）
    $psr3Logger,                              // PSR-3（异常/审计兜底日志）
    null,                                     // PermissionProvider，null=配置驱动默认实现
    ''                                        // 表前缀
);

$result = $handler->ask($userId, '张三最近一次考试成绩', $conversationId);
// ['code'=>0, 'msg'=>'ok', 'data'=>['answer'=>..., 'conversation_id'=>..., 'tools'=>...]]
```

无现成 PSR 实现时可用包内兜底：`aichat\support\FileCache`（PSR-16 文件缓存）、
`aichat\support\ErrorLogLogger`（PSR-3）。完整最小示例见 `examples/generic/index.php`。

### ThinkPHP 6

```php
// app/admin/controller/Aichat.php —— 类名必须是 Aichat（URL aichat 经 Str::studly 映射，Linux 大小写敏感）
class Aichat extends \aichat\adapter\thinkphp\controller\Aichat
{
    protected $middleware = ['AdminCheck'];   // 登录校验由宿主中间件完成
}
```

适配器经 `ThinkPHPBridge::make()` 自动组装：Db 连接取 PDO、Cache/Log 走 PSR 桥接、
表前缀读 database 配置。userId 从 `Session::get(config('aichat.session_user_key'))` 取。
完整业务接线参考 `examples/sms/README.md`。

### Laravel

```php
// routes/web.php
Route::middleware(['web', 'auth'])->prefix('aichat')->group(function () {
    Route::get('panel',         [\aichat\adapter\laravel\AichatController::class, 'panel']);
    Route::post('chat',         [\aichat\adapter\laravel\AichatController::class, 'chat']);
    Route::get('conversations', [\aichat\adapter\laravel\AichatController::class, 'conversations']);
    Route::post('clear',        [\aichat\adapter\laravel\AichatController::class, 'clear']);
    Route::get('tools',         [\aichat\adapter\laravel\AichatController::class, 'tools']);
});
```

Laravel 的 Cache Repository / Log Channel 原生实现 PSR-16/PSR-3，直接注入；
userId 默认 `Auth::id()`（可继承控制器重写 `userId()`）。POST 路由注意 CSRF 豁免或携带 token。

### 前端

- 悬浮球：复制 `resources/assets/ai-float.{js,css}` 到静态目录，引入前设置 `window.AI_FLOAT_CONFIG`
  （chatUrl / clearUrl / toolsUrl / panelUrl / quickQuestions / subtitle）
- 独立面板：控制器 `panel` 动作渲染 `resources/views/panel.phtml`（原生 PHP 模板，无模板引擎依赖）

## 扩展契约（签名稳定，不随核心重构变化）

### Tool —— 一个查询能力

```php
use aichat\tool\BaseTool;

class GetOrders extends BaseTool
{
    public function name(): string  { return 'get_orders'; }
    public function label(): string { return '订单查询'; }
    public function description(): string { return '查询订单。当用户问订单/发货状态时使用。'; }
    public function parameters(): array
    {
        return ['order_no' => ['type' => 'string', 'description' => '订单号']];
    }

    public function execute(int $userId, array $arguments, array $slots): array
    {
        // 1. 参数校验（LLM 输出不可信）
        // 2. 行级权限：按你的业务口径过滤（分校/部门/归属人…）
        // 3. 脱敏：Masker::current()->maskRow($row, $userId)
        // 4. 四态区分返回；可携带 'slots' => ['order_id' => $id] 供指代消解
        return self::reply(true, 'ok', '查询成功', $data);
    }
}
```

注册到配置 `aichat.tools`：类名字符串，或 `['class' => 类名, 'args' => [构造参数]]`
（同一工具类多实例，如通用单表查询工具）。

### PermissionProvider —— 你的权限体系

```php
interface PermissionProvider
{
    public function isSuper(int $userId): bool;
    public function canUseTool(int $userId, string $toolName): bool;
    public function canSeeFullPhone(int $userId): bool;
    public function roleLabel(int $userId): string;   // 注入 System Prompt 的角色说明
}
```

配置 `aichat.permission_provider` 指向实现类（构造函数首参需要时核心自动注入 `Config`，否则无参构造）。
不配置则使用内置 `ConfigPermissionProvider`：`super_ids` / `tool_users` 白名单 / `phone_full_users`，
**未配置的工具非超管一律不可用**。

生产级参考实现（RBAC href 映射 + 分校隔离 + 班主任收窄）见 `examples/sms/SmsPermissionProvider.php`。

## 安全设计（改动前必读）

1. LLM 不得直连数据库，只能产出工具名 + 参数，全部经 `ToolExecutor`
2. 工具 Schema 按权限裁剪（模型看不到无权工具）+ 执行前二次校验（不信任模型输出）
3. 敏感字段进模型前必经 `Masker`：手机号 `138****1234`、身份证/住址/银行卡默认剔除；
   与用户是否要求「完整显示」无关
4. 会话/消息/槽位/审计所有读写以 `user_id` 为第一条件；他人会话 ID 按新会话处理，不泄露存在性
5. 「无权限」≠「没有数据」：四态 + `invalid_argument` 严格区分
6. 全部请求（含被拒）落审计；审计参数本身也脱敏
7. 敏感词前置拦截、提问长度限制、固定窗口限流、Agent 轮数上限（防死循环烧钱）
8. 历史重建合法性：窗口截断产生的孤儿 tool 消息两头清理；`tool_call_id` 严格一致

## 目录结构

```
├── src/
│   ├── ChatHandler.php            核心编排（Agent 循环）
│   ├── Config.php                 配置对象（点号取值）
│   ├── LlmClient.php              OpenAI 兼容协议客户端
│   ├── ConversationStore.php      会话/历史/槽位（PDO）
│   ├── AuditLogger.php            审计（PDO + PSR-3）
│   ├── Masker.php                 字段脱敏
│   ├── ToolRegistry.php           工具注册 + Schema 裁剪
│   ├── ToolExecutor.php           执行流水线（二次校验/异常兜底）
│   ├── contract/                  Tool / PermissionProvider 契约
│   ├── tool/BaseTool.php          工具基类（schema 构造 + reply 统一结构）
│   ├── provider/                  ConfigPermissionProvider 默认实现
│   ├── support/                   Http / FileCache / ErrorLogLogger / Template
│   └── adapter/
│       ├── thinkphp/              TpCache / TpLogger / ThinkPHPBridge / 控制器基类
│       └── laravel/               LaravelBridge / AichatController
├── config/aichat.php              配置模板（纯数组，框架无关）
├── sql/install.sql                三张表
├── resources/
│   ├── assets/ai-float.{js,css}   悬浮球前端（零依赖）
│   └── views/panel.phtml          独立面板（原生 PHP 模板）
├── examples/
│   ├── generic/index.php          裸 PHP 最小接线
│   └── sms/                       sms3.0 完整业务参考实现（ThinkPHP 宿主）
├── scripts/
│   ├── core-smoke-test.php        46 项断言冒烟测试（SQLite，无需框架/网络）
│   └── llm-selftest.php           LLM 连通性独立自检
└── docs/reference/                原系统架构与 PRD 参考
```

## 测试

```bash
composer install
php scripts/core-smoke-test.php        # 核心逻辑 46 项断言（含 SQLite 存储验证）
php scripts/llm-selftest.php --key=sk-xxx --model=qwen-plus   # LLM 连通性
```

## 已知边界

- 存储 SQL 面向 MySQL 编写（冒烟测试用 SQLite 验证通用子集）；其他数据库需自行适配建表脚本
- `examples/sms` 依赖 sms3.0 宿主的类与表结构，不能脱离宿主运行，仅作移植参考
- 文件缓存兜底实现仅限单机；多机部署请注入 Redis 等集中式 PSR-16 实现

## License

MIT