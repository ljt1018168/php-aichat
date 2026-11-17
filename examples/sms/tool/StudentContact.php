<?php
/*
 * @Description: 【sms3.0 参考实现】工具：学员联系方式（手机号脱敏）
 */

declare (strict_types = 1);

namespace examples\sms\tool;

use aichat\Masker;

class StudentContact extends BaseStudentTool
{
    public function name(): string
    {
        return 'get_student_contact';
    }

    public function label(): string
    {
        return '学员联系方式';
    }

    public function description(): string
    {
        return '查询学员的联系方式（手机号）。注意：手机号默认返回脱敏格式（如138****1234），这是系统安全策略，'
            . '即使用户要求完整显示也必须返回脱敏值，不可尝试其他方式获取完整号码。';
    }

    public function execute(int $userId, array $arguments, array $slots): array
    {
        $located = $this->locateStudent($userId, $arguments, $slots);
        if (!$located['result']['ok']) {
            return $located['result'];
        }
        $student = $located['student'];

        // 脱敏在 PHP 侧执行，与用户是否在提问中要求"完整显示"无关
        $masked = Masker::current()->maskRow(['phone' => $student['phone'] ?? ''], $userId);

        $row = [
            '学员ID' => (int)$student['id'],
            '姓名'   => $student['username'] ?? '',
            '手机号' => $masked['phone'] ?? '',
        ];

        return $this->withStudentSlots(self::reply(true, 'ok', '', ['rows' => [$row]], 'table'), $student);
    }
}