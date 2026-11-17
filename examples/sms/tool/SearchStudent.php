<?php
/*
 * @Description: 【sms3.0 参考实现】工具：学员检索
 */

declare (strict_types = 1);

namespace examples\sms\tool;

use aichat\tool\BaseTool;
use examples\sms\StudentLocator;

class SearchStudent extends BaseTool
{
    public function name(): string
    {
        return 'search_student';
    }

    public function label(): string
    {
        return '学员信息';
    }

    public function description(): string
    {
        return '按姓名、手机号或学员ID检索学员，返回学员列表（含学员ID、姓名、分校、届数、服务状态）。'
            . '当用户要找某个学员、问"有没有这个人"、或只给了姓名/手机号时使用。'
            . '返回的 student_id 需用于后续其他工具的调用。';
    }

    public function parameters(): array
    {
        return [
            'name'      => ['type' => 'string', 'description' => '学员姓名，支持部分匹配，如"张"'],
            'phone'     => ['type' => 'string', 'description' => '学员手机号，11位'],
            'branch_id' => ['type' => 'integer', 'description' => '分校ID，可选。用户未提分校时不要传'],
            'limit'     => ['type' => 'integer', 'description' => '返回条数，默认10，最多50'],
        ];
    }

    public function execute(int $userId, array $arguments, array $slots): array
    {
        return StudentLocator::search($userId, $arguments);
    }
}