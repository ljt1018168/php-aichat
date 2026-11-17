<?php
/*
 * @Description: Laravel 控制器 —— 五个动作：panel / chat / conversations / clear / tools
 *
 * 宿主接入：
 *   routes/web.php（放在 auth 中间件组内）
 *     Route::middleware(['web', 'auth'])->prefix('aichat')->group(function () {
 *         Route::get('panel',          [\aichat\adapter\laravel\AichatController::class, 'panel']);
 *         Route::post('chat',          [\aichat\adapter\laravel\AichatController::class, 'chat']);
 *         Route::get('conversations',  [..., 'conversations']);
 *         Route::post('clear',         [..., 'clear']);
 *         Route::get('tools',          [..., 'tools']);
 *     });
 *
 * ⚠️ 登录校验由宿主路由中间件完成；userId 默认取 Auth::id()，可重写 userId() 改口径。
 * ⚠️ POST 路由需豁免 CSRF（VerifyCsrfToken $except）或自行在面板 fetch 中携带 X-CSRF-TOKEN。
 */

declare (strict_types = 1);

namespace aichat\adapter\laravel;

use aichat\support\Template;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class AichatController
{
    /**
     * 面板页
     */
    public function panel(): Response
    {
        $html = Template::render(__DIR__ . '/../../../resources/views/panel.phtml', [
            'enable'   => (bool)config('aichat.enable', false),
            'api_base' => rtrim((string)config('aichat.api_base', '/aichat'), '/'),
        ]);
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * 提问
     */
    public function chat(Request $request): JsonResponse
    {
        return response()->json(LaravelBridge::make()->ask(
            $this->userId(),
            (string)$request->input('question', ''),
            (int)$request->input('conversation_id', 0)
        ));
    }

    /**
     * 会话列表
     */
    public function conversations(): JsonResponse
    {
        return response()->json(LaravelBridge::make()->conversations($this->userId()));
    }

    /**
     * 清空会话
     */
    public function clear(Request $request): JsonResponse
    {
        return response()->json(LaravelBridge::make()->clear(
            $this->userId(),
            (int)$request->input('conversation_id', 0)
        ));
    }

    /**
     * 当前用户可用工具
     */
    public function tools(): JsonResponse
    {
        return response()->json(LaravelBridge::make()->availableTools($this->userId()));
    }

    /**
     * 当前用户ID（默认 Laravel Auth；宿主可继承重写）
     */
    protected function userId(): int
    {
        return (int)Auth::id();
    }
}