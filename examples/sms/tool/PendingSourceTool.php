<?php
/*
 * @Description: 【sms3.0 参考实现】工具：通用学员单表查询（择校/录取/考研成绩等）
 *              一个类通过构造参数注册多个工具实例，见 examples/sms/config/aichat.php。
 *              ★ 执行前做字段探测（SHOW COLUMNS），字段缺失返回"暂未接入"，绝不按猜测字段查询。
 */

declare (strict_types = 1);

namespace examples\sms\tool;

use aichat\Masker;
use examples\sms\StudentLocator;
use think\facade\Db;

class PendingSourceTool extends BaseStudentTool
{
    /** @var string */
    private $toolName;
    /** @var string */
    private $toolLabel;
    /** @var string */
    private $toolDescription;
    /** @var array ['table'=>表名, 'columns'=>必需字段] */
    private $source;

    public function __construct(string $name, string $label, string $description, array $source)
    {
        $this->toolName        = $name;
        $this->toolLabel       = $label;
        $this->toolDescription = $description;
        $this->source          = $source;
    }

    public function name(): string
    {
        return $this->toolName;
    }

    public function label(): string
    {
        return $this->toolLabel;
    }

    public function description(): string
    {
        return $this->toolDescription;
    }

    public function execute(int $userId, array $arguments, array $slots): array
    {
        $table   = isset($this->source['table']) ? (string)$this->source['table'] : '';
        $columns = isset($this->source['columns']) ? (array)$this->source['columns'] : [];

        if ($table === '' || empty($columns) || !StudentLocator::tableReady($table, $columns)) {
            return self::reply(false, 'disabled', '【' . $this->toolLabel . '】暂未接入 AI 对话，如有需要请联系技术同学。');
        }

        $located = $this->locateStudent($userId, $arguments, $slots);
        if (!$located['result']['ok']) {
            return $located['result'];
        }
        $student = $located['student'];

        $row = Db::name($table)->where('student_id', (int)$student['id'])->find();

        if (empty($row)) {
            return self::reply(
                false,
                'empty',
                '学员【' . $student['username'] . '】暂无' . $this->toolLabel . '记录。'
            );
        }

        $row = Masker::current()->maskRow(is_array($row) ? $row : $row->toArray(), $userId);

        return $this->withStudentSlots(
            self::reply(true, 'ok', '', ['rows' => [self::genericMap($row)]], 'table'),
            $student
        );
    }

    /**
     * 通用字段映射：未校准字段保留原名（不编造中文名）
     */
    private static function genericMap(array $row): array
    {
        $map = [
            'student_id'       => '学员ID',
            'school_name'      => '院校名称',
            'major_name'       => '专业名称',
            'admission_status' => '录取状态',
            'total_score'      => '总分',
        ];
        $out = [];
        foreach ($row as $k => $v) {
            $out[isset($map[$k]) ? $map[$k] : $k] = $v;
        }
        return $out;
    }
}