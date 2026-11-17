<?php
/*
 * @Description: 【sms3.0 参考实现】工具：出勤记录
 */

declare (strict_types = 1);

namespace examples\sms\tool;

use think\facade\Db;

class Attendance extends BaseStudentTool
{
    public function name(): string
    {
        return 'get_attendance';
    }

    public function label(): string
    {
        return '出勤记录';
    }

    public function description(): string
    {
        return '查询学员的出勤记录，含出勤/请假状态、所属课程、时间。'
            . '当用户问"出勤怎么样/缺勤几次/有没有请假/到课情况"时使用。';
    }

    public function parameters(): array
    {
        return array_merge(parent::parameters(), [
            'limit' => ['type' => 'integer', 'description' => '返回条数，默认10，按时间倒序'],
        ]);
    }

    public function execute(int $userId, array $arguments, array $slots): array
    {
        $located = $this->locateStudent($userId, $arguments, $slots);
        if (!$located['result']['ok']) {
            return $located['result'];
        }
        $student = $located['student'];

        $limit = isset($arguments['limit']) ? (int)$arguments['limit'] : 10;
        $limit = max(1, min($limit, 50));

        $rows = Db::name('offline_class_lesson_attendance')
            ->where('student_id', (int)$student['id'])
            ->order('create_time', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        if (empty($rows)) {
            return self::reply(
                false,
                'empty',
                '学员【' . $student['username'] . '】暂无出勤记录。'
            );
        }

        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                '时间'   => self::formatTime($r['create_time'] ?? ''),
                '课程ID' => $r['lesson_id'] ?? '',
                '状态'   => ((int)($r['type'] ?? 1) === 1) ? '出勤' : '请假',
                '来源'   => self::attendanceSourceText((int)($r['source'] ?? 1)),
            ];
        }

        return $this->withStudentSlots(
            self::reply(true, 'ok', '学员【' . $student['username'] . '】的出勤记录如下。', ['rows' => $list], 'table'),
            $student
        );
    }

    private static function attendanceSourceText(int $source): string
    {
        $map = [
            1 => '学员H5',
            2 => '后台',
            3 => '教务H5',
            4 => '超学',
            5 => '长期请假',
        ];
        return isset($map[$source]) ? $map[$source] : '未知';
    }
}