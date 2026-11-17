<?php
/**
 * LLM 连通性自检 —— 独立脚本，不依赖任何框架，php scripts/llm-selftest.php 直接运行
 *
 * 用法：
 *   php scripts/llm-selftest.php --base-url=https://api.deepseek.com --key=sk-xxx --model=deepseek-chat
 *   或使用环境变量：AICHAT_API_BASE / AICHAT_API_KEY / AICHAT_MODEL
 *
 * ⚠️ 注意：部分厂商的 /v1/models 端点不校验鉴权（错误 Key 也返回 200），
 *    能否真正调用必须看 /chat/completions —— 本脚本直接测后者。
 */

declare (strict_types = 1);

if (PHP_SAPI !== 'cli') {
    exit("本脚本仅限 CLI 运行\n");
}

// ---- 解析参数（CLI 优先，环境变量兜底）----
$opts = getopt('', ['base-url:', 'key:', 'model:', 'path:', 'timeout:']);

$baseUrl = rtrim((string)($opts['base-url'] ?? getenv('AICHAT_API_BASE') ?: 'https://dashscope.aliyuncs.com/compatible-mode/v1'), '/');
$apiKey  = (string)($opts['key'] ?? getenv('AICHAT_API_KEY') ?: '');
$model   = (string)($opts['model'] ?? getenv('AICHAT_MODEL') ?: 'qwen-plus');
$path    = (string)($opts['path'] ?? '/chat/completions');
$timeout = (int)($opts['timeout'] ?? 60);

echo "==== LLM 自检 ====\n";
echo "端点: {$baseUrl}{$path}\n";
echo "模型: {$model}\n";
echo "Key : " . ($apiKey === '' ? '(未配置)' : substr($apiKey, 0, 6) . str_repeat('*', 8) . substr($apiKey, -4)) . "\n\n";

if ($apiKey === '') {
    echo "[FAIL] 未配置 API Key（--key 或环境变量 AICHAT_API_KEY）\n";
    exit(1);
}

$body = [
    'model'       => $model,
    'messages'    => [['role' => 'user', 'content' => '连通性测试，请只回复：OK']],
    'temperature' => 0,
    'max_tokens'  => 8,
    'stream'      => false,
];

$start = microtime(true);
$ch = curl_init($baseUrl . $path);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => $timeout,
    CURLOPT_CONNECTTIMEOUT => 10,
]);
$raw    = curl_exec($ch);
$errno  = curl_errno($ch);
$error  = curl_error($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$latency = (int)round((microtime(true) - $start) * 1000);

echo "HTTP {$status}，耗时 {$latency}ms\n";

if ($errno !== 0 || $raw === false) {
    echo "[FAIL] 网络错误：{$error}（errno={$errno}）\n";
    echo "排查：网络/代理是否可达该端点；timeout 是否过小。\n";
    exit(1);
}

$decoded = json_decode((string)$raw, true);
if (!is_array($decoded)) {
    echo "[FAIL] 响应无法解析为 JSON：\n" . substr((string)$raw, 0, 500) . "\n";
    exit(1);
}

if (isset($decoded['error'])) {
    $msg = is_array($decoded['error']) ? (string)($decoded['error']['message'] ?? '未知错误') : (string)$decoded['error'];
    echo "[FAIL] 服务返回错误：{$msg}\n";
    echo "排查：Key 是否正确/有余额；base_url 与 Key 类型是否匹配（如百炼正式 Key 配 compatible-mode 端点）；model 是否存在。\n";
    exit(1);
}

$content = (string)($decoded['choices'][0]['message']['content'] ?? '');
if ($content === '') {
    echo "[FAIL] 返回结构异常（无 choices[0].message.content）：\n" . substr((string)$raw, 0, 500) . "\n";
    exit(1);
}

echo "回复: {$content}\n";
if (!empty($decoded['usage'])) {
    echo "用量: prompt={$decoded['usage']['prompt_tokens']} completion={$decoded['usage']['completion_tokens']}\n";
}
echo "\n[PASS] LLM 可正常调用。\n";
exit(0);