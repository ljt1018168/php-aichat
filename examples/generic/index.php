<?php
/*
 * @Description: 通用接入示例 —— 裸 PHP / 任意框架（Yii、Symfony、CodeIgniter、自研…）
 *
 * 核心只要求四样东西：Config 数组、PDO、PSR-16 缓存、PSR-3 日志。
 * 本文件演示最小接线（含登录态占位）；生产环境请替换：
 *   - userId()：换成你系统的真实登录态
 *   - FileCache：换成 Redis/Memcached 的 PSR-16 实现（文件缓存仅限单机）
 *   - ErrorLogLogger：换成 Monolog 等 PSR-3 实现
 *
 * 运行：php -S 127.0.0.1:8090 index.php（先设环境变量 AICHAT_DSN/AICHAT_API_KEY）
 */

declare (strict_types = 1);

require __DIR__ . '/../../vendor/autoload.php';

use aichat\ChatHandler;
use aichat\Config;
use aichat\support\ErrorLogLogger;
use aichat\support\FileCache;
use aichat\support\Template;

session_start();

// ---------- 1. 配置：包内模板 + 环境覆盖 ----------
$items = require __DIR__ . '/../../config/aichat.php';
$items['enable']         = true;
$items['allow_all_tools'] = true;   // 演示用；生产请配置 permission_provider 或 tool_users
$items['llm']['api_key'] = getenv('AICHAT_API_KEY') ?: '';
$config = new Config($items);

// ---------- 2. 基础设施 ----------
$pdo = new PDO(
    getenv('AICHAT_DSN') ?: 'mysql:host=127.0.0.1;dbname=demo;charset=utf8mb4',
    getenv('AICHAT_DB_USER') ?: 'root',
    getenv('AICHAT_DB_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$cache  = new FileCache(sys_get_temp_dir() . '/aichat-cache');
$logger = new ErrorLogLogger();

// ---------- 3. 组装（表前缀按宿主实际填写） ----------
$handler = new ChatHandler($config, $pdo, $cache, $logger, null, '');

// ---------- 4. 登录态（演示占位：真实系统必须换成自己的鉴权） ----------
function userId(): int
{
    return (int)($_SESSION['user_id'] ?? 0);
}
if (userId() <= 0) {
    $_SESSION['user_id'] = 1;   // ⚠️ 仅演示！生产删掉这行并接入真实登录
}

// ---------- 5. 极简路由 ----------
$action = $_GET['action'] ?? 'panel';
header('Content-Type: application/json; charset=utf-8');

switch ($action) {
    case 'chat':
        echo json_encode($handler->ask(userId(), (string)($_POST['question'] ?? ''), (int)($_POST['conversation_id'] ?? 0)), JSON_UNESCAPED_UNICODE);
        break;
    case 'conversations':
        echo json_encode($handler->conversations(userId()), JSON_UNESCAPED_UNICODE);
        break;
    case 'clear':
        echo json_encode($handler->clear(userId(), (int)($_POST['conversation_id'] ?? 0)), JSON_UNESCAPED_UNICODE);
        break;
    case 'tools':
        echo json_encode($handler->availableTools(userId()), JSON_UNESCAPED_UNICODE);
        break;
    case 'panel':
    default:
        header('Content-Type: text/html; charset=utf-8');
        echo Template::render(__DIR__ . '/../../resources/views/panel.phtml', [
            'enable'   => true,
            'api_base' => '?action=',   // 本示例用 query 路由：?action=chat
        ]);
        break;
}