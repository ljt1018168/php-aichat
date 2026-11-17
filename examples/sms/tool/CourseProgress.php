<?php
/*
 * @Description: 【sms3.0 参考实现】工具：课程进度（字段探测，缺字段返回"暂未接入"）
 */

declare (strict_types = 1);

namespace examples\sms\tool;

use aichat\Masker;
use examples\sms\StudentLocator;
use think\facade\Db;

class CourseProgress extends BaseStudentTool
{
    public function name(): string
    {
        return 'get_course_progress';
    }

    public function label(): string
    {
        return '课程进度';
    }

    public function description(): string
    {
        return '查询学员的课程进度与课时情况（已排课时、已上课时等）。'
            . '当用户问"课上了多少/还剩多少课时/课程进度"时使用。';
    }

    public function execute(int $userId, array $arguments, array $slots): array
    {
        $located = $this->locateStudent($userId, $arguments, $slots);
        if (!$located['result']['ok']) {
            return $located['result'];
        }
        $student = $located['student'];

        // 字段未从代码确认，先探测再查；缺字段返回"暂未接入"，不猜测
        if (!StudentLocator::tableReady('stu_info_course', ['student_id'])) {
            return self::reply(
                false,
                'disabled',
                '该数据域（课程进度）暂未接入 AI 对话，如有需要请联系技术同学。'
            );
        }

        $row = Db::name('stu_info_course')->where('student_id', (int)$student['id'])->find();
        if (empty($row)) {
            return self::reply(
                false,
                'empty',
                '学员【' . $student['username'] . '】暂无课程进度记录。'
            );
        }

        $row = Masker::current()->maskRow(is_array($row) ? $row : $row->toArray(), $userId);

        return $this->withStudentSlots(
            self::reply(true, 'ok', '', ['rows' => [self::mapCourseFields($row)]], 'table'),
            $student
        );
    }

    /**
     * 课程进度字段映射（未知的保留原名交给模型理解）
     */
    private static function mapCourseFields(array $row): array
    {
        $map = [
            'student_id'    => '学员ID',
            'already_hours' => '已完成课时',
            'total_hours'   => '总课时',
            'rest_hours'    => '剩余课时',
            'used_hours'    => '已用课时',
            'surplus_hours' => '剩余课时',
        ];
        $out = [];
        foreach ($row as $k => $v) {
            $out[isset($map[$k]) ? $map[$k] : $k] = $v;
        }
        return $out;
    }
}