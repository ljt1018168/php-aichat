<?php
/*
 * @Description: 【sms3.0 参考实现】工具：学员基本信息
 */

declare (strict_types = 1);

namespace examples\sms\tool;

use aichat\Masker;
use examples\sms\StudentLocator;

class StudentBasic extends BaseStudentTool
{
    public function name(): string
    {
        return 'get_student_basic';
    }

    public function label(): string
    {
        return '学员基本信息';
    }

    public function description(): string
    {
        return '查询学员的基本信息：姓名、性别、计划考研届数、服务状态（正常/休学/退学/流转）、所属分校、是否已分配班主任。'
            . '当用户问"某某是什么情况/报名状态/基本信息/哪届的"时使用。';
    }

    public function execute(int $userId, array $arguments, array $slots): array
    {
        $located = $this->locateStudent($userId, $arguments, $slots);
        if (!$located['result']['ok']) {
            return $located['result'];
        }
        $student = Masker::current()->maskRow($located['student'], $userId);

        $row = [
            '学员ID'     => (int)$student['id'],
            '姓名'       => $student['username'] ?? '',
            '性别'       => StudentLocator::sexText((int)($student['sex'] ?? 0)),
            '计划考研届数' => $student['year'] ?? '',
            '服务状态'   => StudentLocator::serviceStatusText((int)($student['service_status'] ?? 0)),
            '所属分校'   => StudentLocator::branchName((int)($student['branch_id'] ?? 0)),
            '已分配班主任' => ((int)($student['is_banding'] ?? 2) === 1) ? '是' : '否',
        ];

        return $this->withStudentSlots(self::reply(true, 'ok', '', ['rows' => [$row]], 'table'), $student);
    }
}