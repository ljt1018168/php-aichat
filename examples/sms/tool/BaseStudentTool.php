<?php
/*
 * @Description: 【sms3.0 参考实现】学员工具基类 —— 定位学员（参数优先，槽位兜底）+ 槽位回填
 */

declare (strict_types = 1);

namespace examples\sms\tool;

use aichat\tool\BaseTool;
use examples\sms\StudentLocator;

abstract class BaseStudentTool extends BaseTool
{
    /**
     * 定位学员：优先用参数，其次用槽位
     *
     * @return array ['result' => 统一结果, 'student' => 学员行|null]
     */
    protected function locateStudent(int $userId, array $arguments, array $slots): array
    {
        $params = [];

        if (!empty($arguments['student_id'])) {
            $params['student_id'] = (int)$arguments['student_id'];
        } elseif (!empty($arguments['name'])) {
            $params['name'] = (string)$arguments['name'];
        } elseif (!empty($arguments['phone'])) {
            $params['phone'] = (string)$arguments['phone'];
        } elseif (!empty($slots['student_id'])) {
            // 指代消解："他的成绩呢？"
            $params['student_id'] = (int)$slots['student_id'];
        } elseif (!empty($slots['student_name'])) {
            $params['name'] = (string)$slots['student_name'];
        }

        if (empty($params)) {
            return [
                'result'  => self::reply(false, 'missing_param', '请告诉我你要查询哪位学员（姓名或手机号）。'),
                'student' => null,
            ];
        }

        $result = StudentLocator::locate($userId, $params);
        if (!$result['ok']) {
            return ['result' => $result, 'student' => null];
        }

        return ['result' => $result, 'student' => $result['data']['student']];
    }

    /**
     * 给成功结果回填槽位（供下一轮指代消解）
     */
    protected function withStudentSlots(array $result, array $student): array
    {
        $result['slots'] = [
            'student_id'   => (int)$student['id'],
            'student_name' => (string)($student['username'] ?? ''),
        ];
        return $result;
    }

    /**
     * 学员定位类工具的通用参数
     */
    public function parameters(): array
    {
        return [
            'student_id' => ['type' => 'integer', 'description' => '学员ID，从 search_student 获取'],
            'name'       => ['type' => 'string', 'description' => '学员姓名。若只有姓名没有ID，直接传姓名即可'],
        ];
    }

    protected static function formatTime($time): string
    {
        if (empty($time)) {
            return '-';
        }
        if (is_numeric($time)) {
            return date('Y-m-d H:i', (int)$time);
        }
        return (string)$time;
    }
}