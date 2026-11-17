<?php
/*
 * @Description: 【sms3.0 参考实现】权限提供者 —— 工具级(RBAC) + 行级(分校隔离) + 字段级(脱敏) 的判定中心
 *               ★ 本类是安全的唯一收口：任何绕过它直达数据的路径都不允许存在
 *
 * ⚠️ 本示例依赖宿主 sms3.0 的 \app\admin\model\AdminAdmin 与登录 Session 结构，
 *    移植到其他项目时按自身权限体系重写，接口契约见 \aichat\contract\PermissionProvider。
 */

declare (strict_types = 1);

namespace examples\sms;

use aichat\contract\PermissionProvider;
use think\facade\Db;
use think\facade\Session;

class SmsPermissionProvider implements PermissionProvider
{
    // ==================== PermissionProvider 契约 ====================

    public function isSuper(int $userId): bool
    {
        return self::isSuperAdmin($userId);
    }

    public function canUseTool(int $userId, string $toolName): bool
    {
        return self::checkToolPermission($userId, $toolName);
    }

    public function canSeeFullPhone(int $userId): bool
    {
        if (self::isSuperAdmin($userId)) {
            return true;
        }
        $perms = (array)config('aichat.mask.phone_full_permission', []);
        if (empty($perms)) {
            return false;
        }
        $menu = self::userMenu($userId);
        foreach ($perms as $href) {
            if (self::menuHasHref($menu, (string)$href)) {
                return true;
            }
        }
        return false;
    }

    public function roleLabel(int $userId): string
    {
        if (self::isSuperAdmin($userId)) {
            return '（超级管理员，可见全部分校）';
        }
        if (self::classTeacherId($userId) !== null) {
            return '（班主任，仅可见本人名下学员）';
        }
        return '（仅可见本人负责分校的学员）';
    }

    // ==================== 超管口径 ====================

    /**
     * 是否超级管理员（与 sms3.0 AdminAdmin::permissions() 口径一致：id == 1）
     */
    public static function isSuperAdmin(int $userId): bool
    {
        return $userId === 1;
    }

    // ==================== 工具级权限（RBAC） ====================

    /**
     * 校验用户是否有权调用某工具
     *
     * 映射 sms3.0 admin_permission.href，不新建权限体系。
     * 安全默认值：href 未配置 或 库中不存在 → 非超管一律拒绝。
     */
    public static function checkToolPermission(int $userId, string $toolName): bool
    {
        if (self::isSuperAdmin($userId)) {
            return true;
        }

        $map  = (array)config('aichat.tool_permission', []);
        $href = isset($map[$toolName]) ? (string)$map[$toolName] : '';
        if ($href === '') {
            return false;   // 未配置映射 = 不可用
        }

        return self::menuHasHref(self::userMenu($userId), $href);
    }

    /**
     * 取用户权限菜单（优先 Session，零查询）
     *
     * Session admin.menu 由登录时 AdminAdmin::permissions() 写入，
     * 结构为 [permission_id => ['id'=>..,'href'=>..,'status'=>..]]
     */
    public static function userMenu(int $userId): array
    {
        $menu = Session::get('admin.menu');
        if (empty($menu)) {
            // Session 缺失（如 CLI / 长会话失效）时回退查库
            $menu = \app\admin\model\AdminAdmin::permissions($userId);
        }
        return is_array($menu) ? $menu : [];
    }

    /**
     * 菜单中是否包含某 href（且 status=1）
     */
    public static function menuHasHref(array $menu, string $href): bool
    {
        $href = strtolower(trim($href));
        foreach ($menu as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (isset($item['status']) && (int)$item['status'] !== 1) {
                continue;
            }
            $itemHref = isset($item['href']) ? strtolower(trim((string)$item['href'])) : '';
            if ($itemHref !== '' && $itemHref === $href) {
                return true;
            }
        }
        return false;
    }

    // ==================== 行级权限（分校隔离） ====================

    /**
     * 取用户可见分校ID列表
     *
     * @return array|null null 表示"全部可见"（超管）；空数组表示"一个都看不了"
     *
     * ⚠️ 空数组 ≠ 全部。非超管返回空数组时，调用方必须拒绝查询，
     *    而不是查出空结果 —— 否则就会把"无权限"表述成"数据不存在"。
     */
    public static function visibleBranchIds(int $userId)
    {
        if (self::isSuperAdmin($userId)) {
            return null;    // 超管：全部可见，且不依赖 admin_admin_branch 是否有记录
        }

        $branchs = Session::get('admin.branchs');
        if (empty($branchs) || !is_array($branchs)) {
            $branchs = \app\admin\model\AdminAdmin::branchs($userId);
        }

        $ids = [];
        foreach ((array)$branchs as $v) {
            $v = (int)$v;
            if ($v > 0) {
                $ids[] = $v;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * 校验某条数据（按其 branch_id）是否在可见范围内
     *
     * @param int        $branchId 数据的分校ID
     * @param array|null $visible  visibleBranchIds() 的返回值
     */
    public static function checkRowPermission(int $branchId, $visible): bool
    {
        if ($visible === null) {
            return true;    // 超管
        }
        if (empty($visible)) {
            return false;   // 非超管且无可见分校
        }
        return in_array($branchId, $visible, true);
    }

    /**
     * 行级过滤是否可跳过（超管）
     */
    public static function skipBranchFilter($visible): bool
    {
        return $visible === null;
    }

    // ==================== 行级权限（班主任 → 本人名下学员） ====================

    /**
     * 是否班主任账号
     *
     * 口径与 sms3.0 BaseService::isClassTeacherRole() 保持一致：
     * 非超管 + 关联 teacher_id + 拥有班主任角色(role_id=config('config.classTeacherRole'))。
     * 多角色账号只要含班主任角色也按班主任收窄，与成绩/学习数据模块一致。
     */
    public static function isClassTeacher(int $userId): bool
    {
        return self::classTeacherId($userId) !== null;
    }

    /**
     * 取班主任关联的 teacher_id；非班主任返回 null
     *
     * teacher_id 优先取登录 Session，缺失时回查 admin_admin，避免长会话失效后越权。
     */
    public static function classTeacherId(int $userId): ?int
    {
        if ($userId <= 0 || self::isSuperAdmin($userId)) {
            return null;
        }

        // 同一请求内多次判定只查一次库
        static $cache = [];
        if (array_key_exists($userId, $cache)) {
            return $cache[$userId];
        }

        $teacherId = (int)Session::get('admin.teacher_id');
        if ($teacherId <= 0) {
            $teacherId = (int)\app\admin\model\AdminAdmin::where('id', $userId)->value('teacher_id');
        }
        if ($teacherId <= 0) {
            return $cache[$userId] = null;
        }

        $hasRole = Db::name('admin_admin_role')
            ->where('admin_id', $userId)
            ->where('role_id', (int)(config('config.classTeacherRole') ?: 4))
            ->find();

        return $cache[$userId] = $hasRole ? $teacherId : null;
    }

    /**
     * 给学员主表查询注入"仅本人名下学员"条件（SQL 层过滤，先于 limit 生效）
     *
     * 口径：stu_teacher 中 status=1 且 effectuate_time<=今天 的最新一条记录，
     * teacher_id 等于当前班主任 —— 与 StudyDataService / ExamScoreService 完全一致。
     *
     * @param mixed  $query           think\db\Query
     * @param int    $teacherId
     * @param string $studentIdColumn 学员ID列（带表别名时传 "a.id"）
     * @return mixed
     */
    public static function applyClassTeacherScope($query, int $teacherId, string $studentIdColumn = 'id')
    {
        if ($teacherId <= 0) {
            return $query;
        }

        return $query->whereRaw($studentIdColumn . ' IN (' . self::classTeacherStudentIdSql($teacherId) . ')');
    }

    /**
     * 取候选学员中真正归属该班主任的学员ID
     *
     * 用于单条定位后置过滤（候选最多 5 条，避免为每条候选各查一次库）。
     *
     * @param int   $teacherId
     * @param int[] $studentIds
     * @return int[]
     */
    public static function filterOwnedStudentIds(int $teacherId, array $studentIds): array
    {
        $studentIds = array_values(array_unique(array_filter(array_map('intval', $studentIds))));
        if ($teacherId <= 0 || empty($studentIds)) {
            return [];
        }

        $date = date('Y-m-d');
        $rows = Db::name('stu_teacher')->alias('st')
            ->join(
                "(SELECT MAX(id) AS max_id, student_id
                    FROM stu_teacher
                   WHERE status = 1 AND effectuate_time <= '{$date}'
                   GROUP BY student_id) latest",
                'st.id = latest.max_id'
            )
            ->where('st.teacher_id', $teacherId)
            ->whereIn('st.student_id', $studentIds)
            ->column('st.student_id');

        return array_map('intval', $rows);
    }

    /**
     * 班主任名下学员ID子查询（与 filterOwnedStudentIds 同一口径，供 SQL 层 whereIn 使用）
     */
    private static function classTeacherStudentIdSql(int $teacherId): string
    {
        $date = date('Y-m-d');
        return "SELECT st.student_id
                  FROM stu_teacher st
                  JOIN (SELECT MAX(id) AS max_id, student_id
                          FROM stu_teacher
                         WHERE status = 1 AND effectuate_time <= '{$date}'
                         GROUP BY student_id) latest
                    ON st.id = latest.max_id
                 WHERE st.teacher_id = " . (int)$teacherId;
    }
}