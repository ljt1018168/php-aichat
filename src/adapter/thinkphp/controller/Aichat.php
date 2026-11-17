<?php
/*
 * @Description: ThinkPHP 6 控制器基类 —— 五个动作：panel / chat / conversations / clear / tools
 *
 * 宿主接入（多应用模式，以 admin 应用为例）：
 *   app/admin/controller/Aichat.php
 *     class Aichat extends \aichat\adapter\thinkphp\controller\Aichat
 *     {
 *         protected $middleware = [\app\admin\middleware\AdminCheck::class];
 *     }
 *
 * ⚠️ 登录校验必须由宿主中间件完成；本基类只从 Session 取 userId，不做鉴权。
 * ⚠️ 类名注意：TP6 多应用 URL aichat → Str::studly → Aichat（不是 AiChat），Linux 大小写敏感。
 */

declare (strict_types = 1);

namespace aichat\adapter\thinkphp\controller;

use aichat\adapter\thinkphp\ThinkPHPBridge;
use aichat\support\Template;
use think\facade\Session;
use think\Request;
use think\Response;

class Aichat
{
    /**
     * 面板页（原生 PHP 模板，不依赖 think-view）
     */
    public function panel(): Response
    {
        $html = Template::render(__DIR__ . '/../../../../resources/views/panel.phtml', [
            'enable'   => (bool)config('aichat.enable', false),
            'api_base' => rtrim((string)config('aichat.api_base', '/aichat'), '/'),
        ]);
        return Response::create($html, 'html');
    }

    /**
     * 提问
     */
    public function chat(Request $request): Response
    {
        $question       = (string)$request->post('question', '');
        $conversationId = (int)$request->post('conversation_id', 0);
        return json(ThinkPHPBridge::make()->ask($this->userId(), $question, $conversationId));
    }

    /**
     * 会话列表
     */
    public function conversations(): Response
    {
        return json(ThinkPHPBridge::make()->conversations($this->userId()));
    }

    /**
     * 清空会话
     */
    public function clear(Request $request): Response
    {
        $conversationId = (int)$request->post('conversation_id', 0);
        return json(ThinkPHPBridge::make()->clear($this->userId(), $conversationId));
    }

    /**
     * 当前用户可用工具
     */
    public function tools(): Response
    {
        return json(ThinkPHPBridge::make()->availableTools($this->userId()));
    }

    /**
     * 从 Session 取当前用户ID（键名可配：aichat.session_user_key）
     */
    protected function userId(): int
    {
        return (int)Session::get((string)config('aichat.session_user_key', 'admin.id'), 0);
    }
}