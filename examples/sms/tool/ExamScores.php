<?php
/*
 * @Description: 【sms3.0 参考实现】工具：考试成绩
 */

declare (strict_types = 1);

namespace examples\sms\tool;

use think\facade\Db;

class ExamScores extends BaseStudentTool
{
    public function name(): string
    {
        return 'get_exam_scores';
    }

    public function label(): string
    {
        return '考试成绩';
    }

    public function description(): string
    {
        return '查询学员的考试成绩记录，含分数、全国排名、分校排名、考试状态。'
            . '当用户问"考了多少分/成绩怎么样/排名多少/最近一次模考"时使用。';
    }

    public function parameters(): array
    {
        return array_merge(parent::parameters(), [
            'limit' => ['type' => 'integer', 'description' => '返回条数，默认5，按时间倒序'],
        ]);
    }

    public function execute(int $userId, array $arguments, array $slots): array
    {
        $located = $this->locateStudent($userId, $arguments, $slots);
        if (!$located['result']['ok']) {
            return $located['result'];
        }
        $student = $located['student'];

        $limit = isset($arguments['limit']) ? (int)$arguments['limit'] : 5;
        $limit = max(1, min($limit, 20));

        $query = Db::name('exam_student_score');

        // 关联口径：优先 crm_stu_id_ex，缺失时用 phone 兜底（两字段在 stu_info_basic 均存在）
        $crmId = (int)($student['crm_stu_id_ex'] ?? 0);
        if ($crmId > 0) {
            $query->where('crm_stu_id_ex', $crmId);
        } else {
            $phone = (string)($student['phone'] ?? '');
            if ($phone === '') {
                return self::reply(false, 'empty', '学员【' . $student['username'] . '】暂无考试成绩记录。');
            }
            $query->where('phone', $phone);
        }

        $rows = $query->order('create_time', 'desc')->limit($limit)->select()->toArray();

        if (empty($rows)) {
            return self::reply(
                false,
                'empty',
                '学员【' . $student['username'] . '】暂无考试成绩记录。'
            );
        }

        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                '考试时间' => self::formatTime($r['create_time'] ?? ''),
                '分数'     => $r['score'] ?? '',
                '全国排名' => $r['national_ranking'] ?? '-',
                '分校排名' => $r['area_ranking'] ?? '-',
                '状态'     => self::examStatusText((int)($r['status'] ?? 1)),
            ];
        }

        return $this->withStudentSlots(
            self::reply(true, 'ok', '学员【' . $student['username'] . '】的考试成绩如下。', ['rows' => $list], 'table'),
            $student
        );
    }

    private static function examStatusText(int $status): string
    {
        $map = [0 => '未出勤', 1 => '正常出勤', 2 => '已提交凭证'];
        return isset($map[$status]) ? $map[$status] : '未知';
    }
}