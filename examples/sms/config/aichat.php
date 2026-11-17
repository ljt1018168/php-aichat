<?php
/*
 * @Description: 【sms3.0 参考配置】业务侧覆盖项 —— 与包内 config/aichat.php 合并使用
 *              （宿主 config/aichat.php = array_merge(包默认配置, 本文件返回数组)）
 *
 * ⚠️ tool_permission 的 href 需连生产库核对 admin_permission 表实际值后校准；
 *    href 在库中不存在时，该工具对所有非超管用户不可用（安全默认值）。
 */

use examples\sms\SmsPermissionProvider;
use examples\sms\tool\Attendance;
use examples\sms\tool\CourseProgress;
use examples\sms\tool\ExamScores;
use examples\sms\tool\PendingSourceTool;
use examples\sms\tool\SearchStudent;
use examples\sms\tool\StudentBasic;
use examples\sms\tool\StudentContact;

return [

    // 接入 sms3.0 自身 RBAC / 分校隔离 / 班主任收窄
    'permission_provider' => SmsPermissionProvider::class,

    // 登录用户ID的 Session 键（sms3.0 后台登录写入 admin.id）
    'session_user_key' => 'admin.id',

    // 前端接口前缀（sms3.0 后台入口为 admin.php）
    'api_base' => '/admin.php/aichat',

    // ==================== 工具注册 ====================
    'tools' => [
        SearchStudent::class,
        StudentBasic::class,
        StudentContact::class,
        CourseProgress::class,
        ExamScores::class,
        Attendance::class,
        ['class' => PendingSourceTool::class, 'args' => [
            'get_choose_school_plan',
            '择校方案',
            '查询学员的择校方案（报考院校、专业等）。当用户问"报的哪个学校/志愿/择校方案/目标院校"时使用。',
            [
                'table'   => 'stu_choose_school_first',
                'columns' => ['id', 'student_id', 'school', 'major', 'year', 'master_type', 'college', 'direction', 'learn_style', 'status'],
            ],
        ]],
        ['class' => PendingSourceTool::class, 'args' => [
            'get_admission_result',
            '录取结果',
            '查询学员的录取结果。当用户问"录取了吗/录取结果/考上没有/上岸了吗"时使用。',
            [
                'table'   => 'stu_enter_admission_result',
                'columns' => ['id', 'student_id', 'school', 'major', 'status', 'is_enter', 'year'],
            ],
        ]],
        ['class' => PendingSourceTool::class, 'args' => [
            'get_enter_grades',
            '考研成绩',
            '查询学员的考研成绩（初试/复试分数）。当用户问"考研多少分/初试成绩/上岸分数"时使用。',
            [
                'table'   => 'stu_enter_grades',
                'columns' => ['id', 'student_id', 'total_score'],
            ],
        ]],
    ],

    // ==================== 工具 → 权限点映射 ====================
    // 值为 sms3.0 admin_permission.href（与后台菜单一致）
    'tool_permission' => [
        'search_student'          => '/admin.php/student/index',
        'get_student_basic'       => '/admin.php/student/index',
        'get_student_contact'     => '/admin.php/student/index',
        'get_course_progress'     => '/admin.php/student/index',
        'get_exam_scores'         => '/admin.php/examScore/studentScoreList',
        'get_attendance'          => '/admin.php/OfflineClass/attendance',
        'get_choose_school_plan'  => '/admin.php/student/index',
        'get_admission_result'    => '/admin.php/student/index',
        'get_enter_grades'        => '/admin.php/student/index',
    ],

    // ==================== 脱敏补充 ====================
    'mask' => [
        'idcard_return'  => false,
        'address_return' => false,
        // 可查看完整手机号的权限点（href）；超管默认可见
        'phone_full_permission' => [],
    ],

    // ==================== 明确拒绝的域 ====================
    'security' => [
        'blocked_keywords' => [
            '薪资', '工资', '薪酬', '提成', '课时费', '结算', '财务报表', '成本',
        ],
    ],

    // ==================== 业务术语表 ====================
    'glossary' => [
        'service_status'  => '1=正常，2=休学，3=退学，4=流转，5=删除',
        'sex'             => '1=男，2=女',
        'year'            => '计划考研届数，如 2026 表示 26 届',
        'branch_id'       => '所属分校ID',
        'attendance.type' => '1=出勤，2=请假',
        'is_banding'      => '是否分配班主任：1=是，2=否',
    ],
];