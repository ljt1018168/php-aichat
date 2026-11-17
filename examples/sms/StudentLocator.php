<?php
/*
 * @Description: 【sms3.0 参考实现】学员定位 —— 行级权限校验的唯一收口
 *              ★ 关键设计：先"不带权限"查，命中后再判分校，
 *                这样才能区分「不存在」与「无权限」，避免把无权限表述成数据不存在
 *
 * ⚠️ 依赖宿主 sms3.0 的 stu_info_basic / base_branch 表结构，移植时按自身数据模型重写。
 */

declare (strict_types = 1);

namespace examples\sms;

use aichat\Masker;
use think\facade\Cache;
use aichat\tool\BaseTool;
use think\facade\Db;

class StudentLocator
{
    /**
     * 定位单个学员（含行级权限校验）
     *
     * @param int   $userId
     * @param array $params 支持 student_id / name / phone
     * @return array 统一结果结构（见 BaseTool::reply）
     */
    public static function locate(int $userId, array $params): array
    {
        $studentId = isset($params['student_id']) ? (int)$params['student_id'] : 0;
        $name      = isset($params['name']) ? trim((string)$params['name']) : '';
        $phone     = isset($params['phone']) ? trim((string)$params['phone']) : '';

        if ($studentId <= 0 && $name === '' && $phone === '') {
            return BaseTool::reply(
                false,
                'missing_param',
                '请先告诉我学员的姓名或手机号。'
            );
        }

        // ---- 第一步：不带权限过滤查询（用于区分 not_found 与 no_permission_row）----
        $query = Db::name('stu_info_basic')->where('service_status', '<>', 5);

        if ($studentId > 0) {
            $query->where('id', $studentId);
        } elseif ($phone !== '') {
            $query->where('phone', $phone);
        } else {
            $query->where('username', $name);
        }

        $rows = $query->limit(5)->select()->toArray();

        if (empty($rows)) {
            $keyword = $studentId > 0 ? ('ID为' . $studentId . '的学员') : ('【' . ($name !== '' ? $name : $phone) . '】');
            return BaseTool::reply(
                false,
                'not_found',
                '未找到' . $keyword . '，请确认信息是否准确。'
            );
        }

        // ---- 第二步：命中后按分校过滤（重名候选同样只保留可见分校，避免跨分校信息泄露）----
        $visible = SmsPermissionProvider::visibleBranchIds($userId);

        if (empty($visible) && !SmsPermissionProvider::skipBranchFilter($visible)) {
            // 非超管且未配置任何可见分校 → 拒绝查询，而不是返回空
            return BaseTool::reply(
                false,
                'no_permission_row',
                '你当前没有配置可访问的分校，如需查询学员数据请联系分校管理员。'
            );
        }

        if (!SmsPermissionProvider::skipBranchFilter($visible)) {
            $rows = array_values(array_filter($rows, function (array $r) use ($visible): bool {
                return SmsPermissionProvider::checkRowPermission((int)$r['branch_id'], $visible);
            }));
        }

        if (empty($rows)) {
            return BaseTool::reply(
                false,
                'no_permission_row',
                self::describeSubject($studentId, $name, $phone) . '不属于你负责的分校，无法查看。'
            );
        }

        // ---- 第三步：班主任再收窄到本人名下学员（分校与班主任是 AND 关系）----
        $teacherId = SmsPermissionProvider::classTeacherId($userId);
        if ($teacherId !== null) {
            $ownedIds = SmsPermissionProvider::filterOwnedStudentIds($teacherId, array_column($rows, 'id'));
            $rows = array_values(array_filter($rows, function (array $r) use ($ownedIds): bool {
                return in_array((int)$r['id'], $ownedIds, true);
            }));

            if (empty($rows)) {
                return BaseTool::reply(
                    false,
                    'no_permission_row',
                    self::describeSubject($studentId, $name, $phone) . '不在你负责的学员名单内，无法查看。'
                );
            }
        }

        if (count($rows) > 1) {
            // 重名：返回候选列表让用户澄清。
            // ok=false：上层 locateStudent()/各工具按失败路径原样透传本结果，
            // 模型按系统提示词转述 message 并展示候选表；
            // 若用 ok=true 会被误当成"已定位到学员"而取 data['student'] 崩溃。
            // reason=ambiguous 供审计区分。
            return BaseTool::reply(
                false,
                'ambiguous',
                '找到 ' . count($rows) . ' 位同名学员，请确认是哪一位：',
                self::buildCandidates($userId, $rows),
                'table'
            );
        }

        $student = $rows[0];

        return BaseTool::reply(
            true,
            'ok',
            '',
            ['student' => $student],
            'text'
        );
    }

    /**
     * 检索学员列表（带行级过滤），用于 search_student
     */
    public static function search(int $userId, array $params): array
    {
        $name    = isset($params['name']) ? trim((string)$params['name']) : '';
        $phone   = isset($params['phone']) ? trim((string)$params['phone']) : '';
        $branchId = isset($params['branch_id']) ? (int)$params['branch_id'] : 0;
        $limit   = isset($params['limit']) ? (int)$params['limit'] : 10;
        $limit   = max(1, min($limit, 50));

        $visible = SmsPermissionProvider::visibleBranchIds($userId);

        if (empty($visible) && !SmsPermissionProvider::skipBranchFilter($visible)) {
            return BaseTool::reply(
                false,
                'no_permission_row',
                '你当前没有配置可访问的分校，如需查询学员数据请联系分校管理员。'
            );
        }

        $query = Db::name('stu_info_basic')->where('service_status', '<>', 5);

        if ($name !== '') {
            $query->where('username', 'like', '%' . $name . '%');
        }
        if ($phone !== '') {
            $query->where('phone', $phone);
        }

        // 行级过滤：在查询构造阶段注入，不在结果过滤
        if (!SmsPermissionProvider::skipBranchFilter($visible)) {
            if ($branchId > 0) {
                // 用户指定了分校：需先确认该分校在可见范围内
                if (!SmsPermissionProvider::checkRowPermission($branchId, $visible)) {
                    return BaseTool::reply(
                        false,
                        'no_permission_row',
                        '你无权访问该分校的数据，如需访问请联系分校管理员。'
                    );
                }
                $query->where('branch_id', $branchId);
            } else {
                $query->whereIn('branch_id', $visible);
            }
        } elseif ($branchId > 0) {
            $query->where('branch_id', $branchId);
        }

        // 班主任：在分校范围内再收窄到本人名下学员（SQL 层过滤，先于 limit 生效）
        $teacherId = SmsPermissionProvider::classTeacherId($userId);
        $branchQuery = null;
        if ($teacherId !== null) {
            // 保留一份未加班主任条件的查询，仅用于区分"本分校无此人"与"有但不归我"
            $branchQuery = clone $query;
            $query = SmsPermissionProvider::applyClassTeacherScope($query, $teacherId);
        }

        $rows = $query->limit($limit)->select()->toArray();

        if (empty($rows)) {
            if ($teacherId !== null && $branchQuery !== null && ($name !== '' || $phone !== '')) {
                if ((clone $branchQuery)->value('id')) {
                    return BaseTool::reply(
                        false,
                        'no_permission_row',
                        self::describeSubject(0, $name, $phone) . '不在你负责的学员名单内，无法查看。'
                    );
                }
            }
            $keyword = $name !== '' ? $name : $phone;
            return BaseTool::reply(
                false,
                'not_found',
                '未找到与【' . $keyword . '】匹配的学员，请确认信息是否准确。'
            );
        }

        return BaseTool::reply(
            true,
            'ok',
            '共找到 ' . count($rows) . ' 位学员。',
            ['students' => self::buildCandidates($userId, $rows)],
            'table'
        );
    }

    /**
     * 构造"查询对象"的中文描述，用于 not_found / no_permission_row 提示
     */
    private static function describeSubject(int $studentId, string $name, string $phone): string
    {
        if ($studentId > 0) {
            return 'ID为' . $studentId . '的学员';
        }
        if ($name !== '') {
            return '学员【' . $name . '】';
        }
        return '手机号为【' . $phone . '】的学员';
    }

    /**
     * 构造候选列表（含状态中文映射 + 脱敏）
     */
    private static function buildCandidates(int $userId, array $rows): array
    {
        $list = [];
        foreach ($rows as $row) {
            $row = Masker::current()->maskRow($row, $userId);
            $list[] = [
                'student_id'    => (int)$row['id'],
                '姓名'          => $row['username'] ?? '',
                '手机号'        => $row['phone'] ?? '',
                '分校'          => self::branchName((int)($row['branch_id'] ?? 0)),
                '届数'          => $row['year'] ?? '',
                '服务状态'      => self::serviceStatusText((int)($row['service_status'] ?? 0)),
            ];
        }
        return $list;
    }

    // ==================== 未接入数据源的字段探测 ====================
    /**
     * 检查某表的必需字段是否都存在
     *
     * 用于"代码中零引用"的表：不猜测字段，
     * 字段缺失时返回"暂未接入"，而不是报错或返回错误数据。
     */
    public static function tableReady(string $table, array $requiredColumns): bool
    {
        if ($table === '' || empty($requiredColumns)) {
            return false;
        }

        $columns = self::tableColumns($table);
        if (empty($columns)) {
            return false;   // 表都不存在
        }

        foreach ($requiredColumns as $col) {
            if (!in_array(strtolower((string)$col), $columns, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 取表字段名（小写数组），结果缓存 300 秒
     */
    public static function tableColumns(string $table): array
    {
        $cacheKey = 'aichat:columns:' . $table;
        $cached = Cache::get($cacheKey);
        if (!empty($cached) && is_array($cached)) {
            return $cached;
        }

        try {
            $rows = Db::query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
        } catch (\Throwable $e) {
            return [];
        }

        $columns = [];
        foreach ($rows as $r) {
            $columns[] = strtolower($r['Field']);
        }

        Cache::set($cacheKey, $columns, 300);
        return $columns;
    }

    // ==================== 状态值映射 ====================

    public static function serviceStatusText(int $status): string
    {
        $map = [
            1 => '正常',
            2 => '休学',
            3 => '退学',
            4 => '流转',
            5 => '删除',
        ];
        return isset($map[$status]) ? $map[$status] : ('未知(' . $status . ')');
    }

    public static function sexText(int $sex): string
    {
        $map = [1 => '男', 2 => '女'];
        return isset($map[$sex]) ? $map[$sex] : '未知';
    }

    /**
     * 分校名称（查不到返回ID）
     */
    public static function branchName(int $branchId): string
    {
        if ($branchId <= 0) {
            return '-';
        }
        try {
            $name = Db::name('base_branch')->where('cid', $branchId)->value('name');
        } catch (\Throwable $e) {
            return (string)$branchId;
        }
        return $name ? (string)$name : (string)$branchId;
    }
}