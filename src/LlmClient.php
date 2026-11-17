<?php
/*
 * @Description: LLM 客户端 —— OpenAI 兼容协议 + Function Calling（纯 curl，框架无关）
 *
 * ⚠️ 重要：百炼 compatible-mode 实测不返回新式 tool_calls，而是旧式 function_call。
 *    只做新式解析会导致工具永不执行（线上已踩）。本类同时兼容两种协议。
 *    参考：https://help.aliyun.com/zh/model-studio/qwen-function-calling
 */

declare (strict_types = 1);

namespace aichat;

use aichat\support\Http;

class LlmClient
{
    /** @var Config */
    private $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * 发起一次对话
     *
     * @param array $messages 完整 messages（system + 历史 + user）
     * @param array $tools    本次可用的 tools schema（按权限裁剪后）
     * @return array ['ok'=>bool, 'content'=>string, 'tool_calls'=>array, 'usage'=>array, 'error'=>string]
     */
    public function chat(array $messages, array $tools = []): array
    {
        $apiKey = (string)$this->config->get('aichat.llm.api_key', '');
        if ($apiKey === '') {
            return $this->fail('未配置 LLM api_key');
        }

        $baseUrl = rtrim((string)$this->config->get('aichat.llm.base_url', ''), '/');
        $model   = (string)$this->config->get('aichat.llm.model', 'qwen-plus');
        $timeout = (int)$this->config->get('aichat.llm.timeout', 60);

        $payload = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => (float)$this->config->get('aichat.llm.temperature', 0.3),
        ];
        if (!empty($tools)) {
            $payload['tools']       = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $resp = Http::postJson(
            $baseUrl . (string)$this->config->get('aichat.llm.chat_path', '/chat/completions'),
            [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            $payload,
            $timeout
        );

        if (!$resp['ok']) {
            $detail = '';
            if (!empty($resp['body'])) {
                $decoded = json_decode($resp['body'], true);
                $detail  = $decoded['error']['message'] ?? $decoded['message'] ?? mb_substr($resp['body'], 0, 200);
            }
            $hint = $resp['status'] > 0 ? ('HTTP ' . $resp['status'] . ' ') : '';
            return $this->fail('LLM 请求失败：' . $hint . ($detail ?: $resp['error']));
        }

        $data = json_decode($resp['body'], true);
        if (!is_array($data)) {
            return $this->fail('LLM 返回格式异常');
        }
        if (isset($data['error'])) {
            return $this->fail('LLM 返回错误：' . ($data['error']['message'] ?? json_encode($data['error'], JSON_UNESCAPED_UNICODE)));
        }

        $choice  = $data['choices'][0]['message'] ?? [];
        $content = (string)($choice['content'] ?? '');

        return [
            'ok'         => true,
            'content'    => $content,
            'tool_calls' => self::extractToolCalls($choice),
            'usage'      => $data['usage'] ?? [],
            'error'      => '',
        ];
    }

    /**
     * 兼容两种工具调用协议（纯函数，保持静态便于单测）
     *
     * - 新式：message.tool_calls = [{id, type:'function', function:{name, arguments}}]
     * - 旧式：message.function_call = {name, arguments}（百炼 compatible-mode 实测走这条）
     *
     * @return array 统一为 [['id'=>..., 'name'=>..., 'arguments'=>array], ...]
     */
    public static function extractToolCalls(array $message): array
    {
        $result = [];

        // 新式
        if (!empty($message['tool_calls']) && is_array($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $i => $call) {
                $fn = $call['function'] ?? [];
                if (empty($fn['name'])) {
                    continue;
                }
                $result[] = [
                    'id'        => (string)($call['id'] ?? ('call_' . $i)),
                    'name'      => (string)$fn['name'],
                    'arguments' => self::decodeArguments($fn['arguments'] ?? '{}'),
                    'raw'       => $call,
                ];
            }
        }

        // 旧式（仅在新式为空时兜底，避免重复执行）
        if (empty($result) && !empty($message['function_call']['name'])) {
            $result[] = [
                'id'        => 'call_legacy_0',
                'name'      => (string)$message['function_call']['name'],
                'arguments' => self::decodeArguments($message['function_call']['arguments'] ?? '{}'),
                'raw'       => null,
            ];
        }

        return $result;
    }

    /**
     * arguments 可能是 JSON 字符串或已解析数组
     */
    private static function decodeArguments($arguments): array
    {
        if (is_array($arguments)) {
            return $arguments;
        }
        $decoded = json_decode((string)$arguments, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function fail(string $error): array
    {
        return [
            'ok'         => false,
            'content'    => '',
            'tool_calls' => [],
            'usage'      => [],
            'error'      => $error,
        ];
    }
}