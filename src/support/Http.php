<?php
/*
 * @Description: HTTP 客户端 —— 自包含 curl 实现，替代宿主项目的全局助手函数
 */

declare (strict_types = 1);

namespace aichat\support;

class Http
{
    /**
     * POST JSON
     *
     * @param string $url
     * @param array  $headers        形如 ['Content-Type: application/json', ...]
     * @param string $body           已编码的请求体
     * @param int    $timeout        整体超时（秒）。⚠️ 调 LLM 必须显式放大，5 秒会稳定超时
     * @param int    $connectTimeout 连接阶段超时（秒）
     * @return array ['ok'=>bool, 'status'=>int, 'body'=>string, 'error'=>string]
     */
    public static function postJson(string $url, array $headers, string $body, int $timeout = 60, int $connectTimeout = 10): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $raw === false) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $error ?: 'curl error ' . $errno];
        }

        return ['ok' => true, 'status' => $status, 'body' => (string)$raw, 'error' => ''];
    }
}