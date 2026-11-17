<?php
/*
 * @Description: php-aichat 核心冒烟测试 —— 不依赖任何框架/网络/MySQL
 *              用法：composer install 后执行 php scripts/core-smoke-test.php
 *              覆盖：Config / Masker / 权限提供者 / 工具注册与执行流水线 /
 *                    PDO 会话存储（SQLite 内存库）/ 审计 / ChatHandler 守卫 /
 *                    LLM 协议解析 / FileCache / 面板模板
 */

declare (strict_types = 1);

require __DIR__ . '/../vendor/autoload.php';

use aichat\ChatHandler;
use aichat\Config;
use aichat\ConversationStore;
use aichat\AuditLogger;
use aichat\LlmClient;
use aichat\Masker;
use aichat\ToolExecutor;
use aichat\ToolRegistry;
use aichat\provider\ConfigPermissionProvider;
use aichat\support\FileCache;
use aichat\support\Template;
use aichat\tool\BaseTool;
use Psr\Log\NullLogger;

$pass = 0;
$fail = 0;
function check(bool $cond, string $name): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  [PASS] {$name}\n"; }
    else       { $fail++; echo "  [FAIL] {$name}\n"; }
}

// ---------- 演示工具 ----------
class DemoTool extends BaseTool
{
    public function name(): string { return 'demo_query'; }
    public function label(): string { return '演示查询'; }
    public function description(): string { return '演示工具'; }
    public function parameters(): array
    {
        return ['keyword' => ['type' => 'string', 'description' => '关键词']];
    }
    public function execute(int $userId, array $arguments, array $slots): array
    {
        if (($arguments['keyword'] ?? '') === 'boom') {
            throw new \RuntimeException('内部细节不应外泄');
        }
        return self::reply(true, 'ok', '查询成功', ['keyword' => $arguments['keyword'] ?? ''], 'text');
    }
}
class OtherTool extends DemoTool
{
    public function name(): string { return 'other_query'; }
    public function label(): string { return '另一演示'; }
}

echo "== 1. Config ==\n";
$config = new Config(['enable' => true, 'llm' => ['api_key' => 'sk-test']]);
check($config->get('aichat.llm.api_key') === 'sk-test', '点号取值 + 扁平数组自动包 aichat 段');
check($config->get('aichat.not_exist', 'dft') === 'dft', '缺失键返回默认值');
check((new Config(['aichat' => ['enable' => false]]))->get('aichat.enable') === false, '兼容已带 aichat 段的数组');

echo "== 2. 权限提供者（配置驱动）==\n";
$permConfig = new Config([
    'super_ids'        => [1],
    'tool_users'       => ['demo_query' => [2]],
    'phone_full_users' => [3],
]);
$provider = new ConfigPermissionProvider($permConfig);
check($provider->isSuper(1) && !$provider->isSuper(2), '超管判定');
check($provider->canUseTool(2, 'demo_query') && !$provider->canUseTool(2, 'other_query'), '工具白名单（未配置=拒绝）');
check($provider->canUseTool(1, 'other_query'), '超管可用全部工具');
check($provider->canSeeFullPhone(3) && !$provider->canSeeFullPhone(2), '完整手机号白名单');

echo "== 3. Masker ==\n";
$masker = new Masker(new Config([]), $provider);
$row = $masker->maskRow(['phone' => '13812341234', 'idcard' => '110101199001011234', 'home_address' => 'xx路', 'bank_card' => '6222', 'name' => '张三'], 2);
check($row['phone'] === '138****1234', '普通用户手机号脱敏');
check(!isset($row['idcard'], $row['home_address'], $row['bank_card']) && $row['name'] === '张三', '身份证/住址/银行卡剔除，普通字段保留');
check($masker->maskRow(['phone' => '13812341234'], 1)['phone'] === '13812341234', '超管可见完整手机号');
check(Masker::maskPhone('13812341234') === '138****1234' && Masker::maskIdCard('110101199001011234') === '110101********1234', '纯函数静态可用');

echo "== 4. 工具注册与 Schema 裁剪 ==\n";
$regConfig = new Config([
    'super_ids'  => [1],
    'tool_users' => ['demo_query' => [2]],
    'tools'      => [DemoTool::class, ['class' => OtherTool::class, 'args' => []]],
]);
$registry = new ToolRegistry($regConfig, new ConfigPermissionProvider($regConfig));
check(count($registry->all()) === 2, '类名 + class/args 两种注册方式');
$schemas = $registry->schemasFor(2);
check(count($schemas) === 1 && $schemas[0]['function']['name'] === 'demo_query', 'Schema 按权限裁剪（用户2只见1个）');
check(count($registry->schemasFor(1)) === 2, '超管见全部 Schema');
check(($schemas[0]['type'] ?? '') === 'function' && isset($schemas[0]['function']['parameters']['properties']['keyword']), 'OpenAI function schema 结构');

echo "== 5. 工具执行流水线 ==\n";
$executor = new ToolExecutor($registry, new ConfigPermissionProvider($regConfig), new NullLogger());
$r = $executor->execute('demo_query', ['keyword' => 'x'], 2);
check($r['ok'] && $r['reason'] === 'ok' && $r['render'] === 'text', '正常执行 + 结果规范化');
$r = $executor->execute('other_query', [], 2);
check(!$r['ok'] && $r['reason'] === 'no_permission_tool', '执行前二次权限校验');
$r = $executor->execute('ghost_tool', [], 1);
check(!$r['ok'] && $r['reason'] === 'not_found', '未知工具明确拒绝');
$r = $executor->execute('demo_query', ['keyword' => 'boom'], 1);
check(!$r['ok'] && $r['reason'] === 'error' && strpos($r['message'], '内部细节') === false, '异常兜底且不泄露细节');

echo "== 6. PDO 会话存储（SQLite）==\n";
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE ai_chat_conversation (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, title TEXT, slots TEXT, slot_update_time INT, last_active_time INT, create_time INT, is_deleted INT)');
$pdo->exec('CREATE TABLE ai_chat_message (id INTEGER PRIMARY KEY AUTOINCREMENT, conversation_id INT, user_id INT, role TEXT, content TEXT, tool_calls TEXT, tool_name TEXT, tool_call_id TEXT, token_estimate INT, create_time INT)');
$pdo->exec('CREATE TABLE ai_chat_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, conversation_id INT, question TEXT, tool_called TEXT, params TEXT, reason TEXT, response_summary TEXT, latency_ms INT, prompt_tokens INT, completion_tokens INT, create_time INT)');

$store = new ConversationStore($pdo, new Config(['agent' => ['slot_ttl' => 86400]]));
$cid = $store->getOrCreate(7);
check($cid > 0, '新建会话');
check($store->getOrCreate(7, $cid) === $cid, '归属校验通过复用会话');
$other = $store->getOrCreate(8, $cid);
check($other !== $cid, '他人会话ID按新会话处理（不泄露存在性）');

$store->append(7, $cid, 'user', '问题一');
$store->append(7, $cid, 'assistant', '', json_encode([['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'demo_query', 'arguments' => '{}']]]));
$store->append(7, $cid, 'tool', '{"ok":true}', '', 'demo_query', 'call_1');
$store->append(7, $cid, 'assistant', '回答一');
$full = $store->history(7, $cid, 10);
check(count($full) === 4 && $full[2]['tool_call_id'] === 'call_1', '历史完整重建 + tool_call_id 严格一致');
$win = $store->history(7, $cid, 1);   // 窗口=2条 → 截到 tool+assistant，头部孤儿 tool 应被清理
check(count($win) === 1 && $win[0]['role'] === 'assistant' && $win[0]['content'] === '回答一', '窗口截断后孤儿 tool 消息清理');
check($store->history(8, $cid, 10) === [], '历史读取同样强隔离 user_id');

$store->updateSlots(7, $cid, ['student_id' => 9]);
check($store->slots(7, $cid)['student_id'] === 9, '槽位写入/读取');
check($store->slots(8, $cid) === [], '槽位强隔离');
$store->touchTitle(7, $cid, '这是一个很长很长的问题标题');
$list = $store->listConversations(7);
check(count($list) === 1 && mb_strlen($list[0]['title']) <= 30, '会话列表 + 标题截断');
$store->clear(7, $cid);
check($store->listConversations(7) === [] && $store->slots(7, $cid) === [], '清空会话（软删+清槽位）');

echo "== 7. 审计 ==\n";
$audit = new AuditLogger($pdo, new Config([]), new NullLogger());
$audit->record(7, $cid, '查手机号', ['demo_query'], ['phone' => '13812341234', 'idcard' => '110101199001011234'], 'ok', '摘要', 12, ['prompt_tokens' => 5]);
$logRow = $pdo->query('SELECT * FROM ai_chat_log')->fetch(PDO::FETCH_ASSOC);
check($logRow && strpos($logRow['params'], '138****1234') !== false && strpos($logRow['params'], '13812341234') === false, '审计参数手机号脱敏落库');
check(strpos($logRow['params'], '110101199001011234') === false, '审计参数身份证剔除');

echo "== 8. ChatHandler 守卫（不调 LLM）==\n";
$cacheDir = sys_get_temp_dir() . '/aichat-smoke-' . getmypid();
$cache    = new FileCache($cacheDir);
$baseItems = [
    'enable'           => true,
    'allow_all_tools'  => true,
    'tools'            => [DemoTool::class],
    'llm'              => ['api_key' => ''],   // 故意留空：走到 LLM 即返回 llm_error，不出网
    'rate_limit'       => ['limit' => 2, 'window' => 60],
    'security'         => ['max_question_length' => 10, 'blocked_keywords' => ['薪资']],
];
$handler = new ChatHandler(new Config($baseItems), $pdo, $cache, new NullLogger());
check(Masker::current() instanceof Masker, 'ChatHandler 构造即注册 Masker::current()');

$r = $handler->ask(7, '');
check($r['code'] === 1, '空提问拒绝');
$r = $handler->ask(7, '一二三四五六七八九十多');
check($r['code'] === 1 && strpos($r['msg'], '过长') !== false, '超长提问拒绝');
$r = $handler->ask(7, '查薪资');
check($r['code'] === 1 && $r['data']['reason'] === 'blocked', '敏感词拦截');
$r1 = $handler->ask(7, '问题A');
$r2 = $handler->ask(7, '问题B');
check(($r1['data']['reason'] ?? '') === 'llm_error' && ($r2['data']['reason'] ?? '') === 'llm_error', '无 api_key 时 LLM 失败兜底（不出网）');
$r3 = $handler->ask(7, '问题C');
check(($r3['data']['reason'] ?? '') === 'rate_limited', '限流：第3次触发（limit=2）');

$disabled = new ChatHandler(new Config(['enable' => false]), $pdo, $cache, new NullLogger());
$r = $disabled->ask(7, '你好');
check($r['code'] === 1 && $r['data']['reason'] === 'disabled', '总开关关闭直接拒绝');

$noTools = new ChatHandler(new Config(['enable' => true, 'super_ids' => [1]]), $pdo, new FileCache($cacheDir . '-2'), new NullLogger());
$r = $noTools->ask(99, '你好');   // 用户99非超管且无工具授权
check($r['code'] === 1 && $r['data']['reason'] === 'no_permission_tool', '无可用工具明确拒绝');

echo "== 9. LLM 协议解析 ==\n";
$new = LlmClient::extractToolCalls(['tool_calls' => [['id' => 'c1', 'function' => ['name' => 'demo_query', 'arguments' => '{"keyword":"x"}']]]]);
check(count($new) === 1 && $new[0]['id'] === 'c1' && $new[0]['arguments']['keyword'] === 'x', '新式 tool_calls 解析');
$legacy = LlmClient::extractToolCalls(['function_call' => ['name' => 'demo_query', 'arguments' => ['keyword' => 'y']]]);
check(count($legacy) === 1 && $legacy[0]['name'] === 'demo_query' && $legacy[0]['arguments']['keyword'] === 'y', '旧式 function_call 兜底（百炼实测协议）');
check(LlmClient::extractToolCalls([]) === [], '无工具调用返回空');

echo "== 10. FileCache / 模板 ==\n";
$cache->set('k', ['a' => 1], 60);
check($cache->get('k')['a'] === 1 && $cache->has('k'), 'FileCache 读写');
$cache->set('expired', 'v', -1);
check(!$cache->has('expired'), '非正 TTL 即删除');
$cache->delete('k');
check(!$cache->has('k'), '删除');

$html = Template::render(__DIR__ . '/../resources/views/panel.phtml', ['enable' => true, 'api_base' => '/admin.php/aichat']);
check(strpos($html, 'var ENABLED = true;') !== false && strpos($html, '"/admin.php/aichat"') !== false, '面板模板原生 PHP 渲染注入');

// 清理临时缓存
foreach (glob($cacheDir . '/*.cache') ?: [] as $f) { @unlink($f); }
foreach (glob($cacheDir . '-2/*.cache') ?: [] as $f) { @unlink($f); }
@rmdir($cacheDir);
@rmdir($cacheDir . '-2');

echo "\n=========================================\n";
echo " PASS {$pass} / FAIL {$fail}\n";
echo ($fail === 0 ? " ALL PASS\n" : " 存在失败项！\n");
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);