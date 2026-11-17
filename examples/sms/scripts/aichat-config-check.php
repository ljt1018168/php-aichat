<?php
/*
 * @Description: 【sms3.0 参考】AI 对话配置校准检查脚本（ThinkPHP 宿主内运行）
 *              用法：把本文件放回宿主 scripts/ 后执行 php scripts/aichat-config-check.php
 *              作用：连上数据库，一次性定位 config/aichat.php 里所有"填错就静默失效"的配置项
 *
 * ⚠️ 必须在能访问数据库的机器上运行（本地若无 RDS 内网/VPN 会提示连接失败）。
 *
 * 检查 6 项：
 *   1. 配置完整性（不需要 DB）
 *   2. 数据库连接
 *   3. AI 三张表是否已建（user_id / tool_call_id 关键列）
 *   4. 学员主表关键字段（branch_id 是行级权限的命脉）
 *   5. ★ 工具 → 权限点 href 校准（填错 = 非超管看不到任何工具）
 *   6. ★ PendingSourceTool 数据源字段校准（择校/录取三表）
 *
 * 第 5、6 项会直接输出可粘贴回 config/aichat.php 的配置片段。
 */

chdir(dirname(__DIR__));
require dirname(__DIR__) . '/vendor/autoload.php';

$app = new \think\App();
$app->initialize();
$app->http;

$pass = 0;
$warn = 0;
$fail = 0;

function line(string $s = ''): void { echo $s . "\n"; }
function ok(string $s): void   { global $pass; $pass++; line("  [OK]   " . $s); }
function warn(string $s): void { global $warn; $warn++; line("  [WARN] " . $s); }
function bad(string $s): void  { global $fail; $fail++; line("  [FAIL] " . $s); }

/**
 * 安全查询：失败返回 null（表不存在 / 无权限 / 断连都归为 null）
 */
function safeQuery(string $sql, array $bind = [])
{
    try {
        return \think\facade\Db::query($sql, $bind);
    } catch (\Exception $e) {
        return null;
    }
}

function tableExists(string $table): ?bool
{
    $db = safeQuery('SELECT DATABASE() AS db');
    if ($db === null) { return null; }
    $dbName = $db[0]['db'] ?? '';
    $r = safeQuery(
        "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema=? AND table_name=?",
        [$dbName, $table]
    );
    if ($r === null) { return null; }
    return (int)($r[0]['c'] ?? 0) > 0;
}

function columnsOf(string $table): array
{
    $r = safeQuery('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
    if ($r === null) { return []; }
    $out = [];
    foreach ($r as $row) { $out[] = strtolower((string)$row['Field']); }
    return $out;
}

line("=========================================");
line(" AI 对话 配置校准检查（php-aichat）");
line("=========================================");
line("配置源: config/aichat.php");
line("开关  : " . (config('aichat.enable') ? '已开启' : '未开启（不影响本检查）'));
line();

// ---------- 1. 配置完整性（不依赖 DB）----------
line("--- 1. 配置完整性 ---");
$config   = new \aichat\Config((array)config('aichat'));
$registry = new \aichat\ToolRegistry($config, new \examples\sms\SmsPermissionProvider());
$tools    = $registry->all();
$toolNames = array_keys($tools);
line("  已注册工具 " . count($toolNames) . " 个: " . implode(', ', $toolNames));

$permMap = (array)config('aichat.tool_permission', []);

$missPerm = array_values(array_diff($toolNames, array_keys($permMap)));
if (empty($missPerm)) { ok("每个工具都配置了权限映射"); }
else { bad("以下工具缺少 tool_permission 映射: " . implode(', ', $missPerm)); }

$extraPerm = array_values(array_diff(array_keys($permMap), $toolNames));
if (!empty($extraPerm)) { warn("tool_permission 中存在已不注册的工具: " . implode(', ', $extraPerm)); }

$blocked = (array)config('aichat.security.blocked_keywords', []);
if (empty($blocked)) { warn("security.blocked_keywords 为空，薪资/财务类提问会走到 LLM"); }
else { ok("敏感域拦截词 " . count($blocked) . " 个"); }

$providerClass = (string)config('aichat.permission_provider', '');
if ($providerClass === \examples\sms\SmsPermissionProvider::class) { ok("权限提供者已指向 SmsPermissionProvider"); }
else { warn("permission_provider 未指向 SmsPermissionProvider（当前: " . ($providerClass ?: '内置配置驱动') . "）"); }
line();

// ---------- 2. 数据库连接 ----------
line("--- 2. 数据库连接 ---");
$dbOk = false;
try {
    \think\facade\Db::query('SELECT 1');
    $dbOk = true;
    ok("数据库连接正常");
} catch (\Exception $e) {
    bad("数据库连接失败: " . mb_substr($e->getMessage(), 0, 120));
    line("  >> 本脚本需访问数据库。请在能连上 RDS 的机器上执行。");
}
line();

if (!$dbOk) {
    line("=========================================");
    line(" 结果：OK {$pass} / WARN {$warn} / FAIL {$fail}");
    line(" 数据库不可用，第 3~6 项未检查。");
    line("=========================================");
    exit($fail === 0 ? 0 : 1);
}

// ---------- 3. AI 三张表 ----------
line("--- 3. AI 对话三张表 ---");
$aiTables = [
    'ai_chat_conversation' => '会话表',
    'ai_chat_message'      => '消息表',
    'ai_chat_log'          => '审计日志表',
];
$aiMiss = [];
foreach ($aiTables as $t => $label) {
    $exists = tableExists($t);
    if ($exists === true) { ok("{$t} ({$label}) 已建"); }
    else { bad("{$t} ({$label}) 不存在"); $aiMiss[] = $t; }
}
if (!empty($aiMiss)) {
    line("  >> 执行包内建表脚本：sql/install.sql");
}
// 通用化后用户列是 user_id（不是 admin_id）
foreach ($aiTables as $t => $label) {
    if (tableExists($t) !== true) { continue; }
    $cols = columnsOf($t);
    if (in_array('user_id', $cols, true)) { ok("{$t} 含 user_id 列"); }
    else { bad("{$t} 缺少 user_id 列（若为 admin_id 说明建的是旧版表结构）"); }
}
// 消息表必须有 tool_call_id（多轮对话关键）
if (tableExists('ai_chat_message')) {
    $cols = columnsOf('ai_chat_message');
    if (in_array('tool_call_id', $cols, true)) { ok("ai_chat_message 含 tool_call_id 列（多轮必需）"); }
    else { bad("ai_chat_message 缺少 tool_call_id 列，第二轮对话会报错"); }
}
line();

// ---------- 4. 学员主表字段 ----------
line("--- 4. 学员主表 stu_info_basic ---");
$stuCols = columnsOf('stu_info_basic');
if (empty($stuCols)) {
    bad("未取到 stu_info_basic 字段（表不存在或无权限）");
} else {
    $required = [
        'id'             => '学员ID',
        'username'       => '姓名',
        'phone'          => '手机号',
        'branch_id'      => '★分校ID（行级权限依据）',
        'service_status' => '服务状态',
    ];
    foreach ($required as $col => $label) {
        if (in_array($col, $stuCols, true)) { ok("{$col} —— {$label}"); }
        else { bad("缺少字段 {$col} —— {$label}"); }
    }
    if (in_array('cid', $stuCols, true) && !in_array('branch_id', $stuCols, true)) {
        bad("该表只有 cid 没有 branch_id！配置口径需改为 cid（当前代码按 branch_id 写）");
    }
}
line();

// ---------- 5. 权限点 href 校准（★）----------
line("--- 5. 工具 → 权限点 href 校准（★ 关键）---");
$perms = safeQuery("SELECT id, title, href, pid FROM admin_permission WHERE status = 1 ORDER BY id ASC");
if ($perms === null) {
    bad("无法读取 admin_permission 表");
} else {
    line("  库中可用权限点(status=1) 共 " . count($perms) . " 个");
    line();

    $hrefIndex = [];
    foreach ($perms as $p) {
        $hrefIndex[strtolower(trim((string)$p['href']))] = $p;
    }

    // 工具名 → 用于猜测的关键词（英文关键词 + 中文标签）
    $hints = [
        'search_student'         => ['student', 'stu', '学员', '学生'],
        'get_student_basic'      => ['student', 'stu', '学员', '学生'],
        'get_student_contact'    => ['student', 'stu', '学员', '学生'],
        'get_course_progress'    => ['course', 'progress', '课程', '进度'],
        'get_exam_scores'        => ['exam', 'score', '成绩', '考试'],
        'get_attendance'         => ['attend', 'offline', 'class', '出勤', '考勤', '课'],
        'get_choose_school_plan' => ['choose', 'school', '择校', '院校'],
        'get_admission_result'   => ['admission', 'enter', '录取', '上岸'],
        'get_enter_grades'       => ['enter', 'grade', 'score', '考研', '成绩', '初试'],
    ];

    $suggestions = [];
    foreach ($toolNames as $tool) {
        $cfgHref = isset($permMap[$tool]) ? (string)$permMap[$tool] : '';
        $cfgKey  = strtolower(trim($cfgHref));

        if ($cfgHref !== '' && isset($hrefIndex[$cfgKey])) {
            ok(sprintf("%-24s → %-34s (库中 id=%s, %s)",
                $tool, $cfgHref, $hrefIndex[$cfgKey]['id'], $hrefIndex[$cfgKey]['title']));
            $suggestions[$tool] = $cfgHref;
            continue;
        }

        // 未命中：给候选
        $words = isset($hints[$tool]) ? $hints[$tool] : [$tool];
        $cands = [];
        foreach ($perms as $p) {
            $href  = strtolower((string)$p['href']);
            $title = (string)$p['title'];
            $score = 0;
            foreach ($words as $w) {
                if (strpos($href, strtolower($w)) !== false) { $score += 3; }
                if (strpos($title, $w) !== false)           { $score += 2; }
            }
            if ($score > 0) { $cands[] = ['score' => $score, 'href' => $p['href'], 'title' => $title, 'id' => $p['id']]; }
        }
        usort($cands, function ($a, $b) { return $b['score'] - $a['score']; });
        $cands = array_slice($cands, 0, 3);

        if ($cfgHref === '') {
            bad(sprintf("%-24s 未配置 href → 非超管一律拒绝", $tool));
        } else {
            bad(sprintf("%-24s 配置了 %s，但库中无该 href → 非超管一律拒绝", $tool, $cfgHref));
        }
        if (empty($cands)) {
            line("           (库中无相似权限点，可能该域尚未建菜单，建议先挂到学员管理)");
        } else {
            foreach ($cands as $c) {
                line(sprintf("           候选 id=%-4s %-34s %s", $c['id'], $c['href'], $c['title']));
            }
            $suggestions[$tool] = $cands[0]['href'];
            line("           >> 建议值: " . $cands[0]['href']);
        }
    }

    line();
    line("  可直接粘贴的 tool_permission 片段：");
    line("  -------------------------------------------------------------");
    line("  'tool_permission' => [");
    foreach ($toolNames as $tool) {
        $v = isset($suggestions[$tool]) ? $suggestions[$tool] : '';
        line(sprintf("      '%s'%s => '%s',", $tool, str_repeat(' ', max(1, 24 - strlen($tool))), $v));
    }
    line("  ],");
    line("  -------------------------------------------------------------");
    line("  ⚠️ 候选为启发式匹配，必须人工确认后再写入。填错会导致非超管看不到工具。");
}
line();

// ---------- 6. PendingSourceTool 数据源 ----------
line("--- 6. PendingSourceTool 数据源字段校准（择校/录取）---");
$pending = [];
foreach ((array)config('aichat.tools', []) as $entry) {
    if (is_array($entry) && ($entry['class'] ?? '') === \examples\sms\tool\PendingSourceTool::class) {
        $args = (array)($entry['args'] ?? []);
        // args: [name, label, description, source]
        $pending[(string)($args[0] ?? '')] = [
            'label'   => (string)($args[1] ?? ''),
            'table'   => (string)($args[3]['table'] ?? ''),
            'columns' => (array)($args[3]['columns'] ?? []),
        ];
    }
}
if (empty($pending)) {
    line("  无待校准数据源");
} else {
    foreach ($pending as $tool => $def) {
        $table  = $def['table'];
        $need   = $def['columns'];
        $label  = $def['label'] ?: $tool;
        $exists = tableExists($table);

        line("  【{$label}】 {$tool} -> {$table}");
        if ($exists !== true) {
            bad("  表 {$table} 不存在，该工具将一直返回「暂未接入」");
            continue;
        }
        $cols = columnsOf($table);
        if (empty($cols)) {
            bad("  取不到 {$table} 字段");
            continue;
        }
        $missing = [];
        foreach ($need as $c) {
            if (!in_array(strtolower((string)$c), $cols, true)) { $missing[] = $c; }
        }
        if (empty($missing)) {
            ok("  必需字段齐全: " . implode(', ', $need));
        } else {
            bad("  缺失字段: " . implode(', ', $missing) . " → 该工具返回「暂未接入」");
            line("       实际字段: " . implode(', ', $cols));
        }
        line();
    }
    line("  >> 校准方式：把 config/aichat.php 里对应 PendingSourceTool 注册项 args 的 columns 改成实际存在的字段名。");
}

line();
line("=========================================");
line(" 结果：OK {$pass} / WARN {$warn} / FAIL {$fail}");
if ($fail === 0) {
    line(" 配置已就绪。下一步：php scripts/llm-selftest.php 验证 LLM。");
} else {
    line(" 存在未通过项，请按上方提示校准 config/aichat.php 后重跑。");
}
line("=========================================");

exit($fail === 0 ? 0 : 1);