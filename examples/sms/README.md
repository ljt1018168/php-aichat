# sms3.0 学员查询适配器（参考实现）

本目录是 php-aichat 在 **sms3.0 学员管理系统**（ThinkPHP 6 多应用）中的完整业务适配实现，
从生产代码移植而来，作为「如何把自身业务接入通用核心」的参考。

> ⚠️ 本目录代码依赖 sms3.0 宿主的类与表结构（`\app\admin\model\AdminAdmin`、
> `stu_info_basic`、`stu_teacher`、`base_branch`、`exam_student_score` 等），
> **不能脱离宿主直接运行**。移植到其他项目时，请按自身权限体系与数据模型重写。

## 目录结构

```
examples/sms/
├── SmsPermissionProvider.php   权限提供者：RBAC(href 映射) + 分校隔离 + 班主任收窄 + 完整手机号判定
├── StudentLocator.php          学员定位：先查后判权限，区分 not_found / no_permission_row / ambiguous
├── config/aichat.php           业务侧配置覆盖项（工具注册、权限点映射、术语表、敏感词）
├── tool/
│   ├── BaseStudentTool.php     学员工具基类：定位（参数优先/槽位兜底）+ 槽位回填
│   ├── SearchStudent.php       search_student          学员检索
│   ├── StudentBasic.php        get_student_basic       基本信息
│   ├── StudentContact.php      get_student_contact     联系方式（脱敏）
│   ├── CourseProgress.php      get_course_progress     课程进度（字段探测）
│   ├── ExamScores.php          get_exam_scores         考试成绩
│   ├── Attendance.php          get_attendance          出勤记录
│   └── PendingSourceTool.php   通用单表查询（构造参数注册多实例：择校/录取/考研成绩）
└── scripts/
    └── aichat-config-check.php 原项目配置体检脚本（连库校准 href / 表字段，需在宿主根目录运行）
```

## 在 sms3.0 中接线

1. **建表**：执行包内 `sql/install.sql`。
   注意：原 sms3.0 三张表的用户列名为 `admin_id`，本包统一为 `user_id`；
   若沿用旧表，请执行 `ALTER TABLE ... CHANGE admin_id user_id ...` 或自行改回列名并同步修改核心代码。

2. **配置**：宿主 `config/aichat.php` 返回 `array_merge(包默认配置, 本目录 config/aichat.php 返回数组)`，
   并在 `.env` 追加 `[AICHAT]` 段（ENABLE / API_BASE / API_KEY / MODEL）。

3. **自动加载**：把本目录放入宿主可加载路径（如 `extend/examples/sms`），
   并在 composer.json 的 autoload psr-4 中注册 `"examples\\": "extend/examples/"`。

4. **控制器**：宿主 `app/admin/controller/Aichat.php` 继承包内 ThinkPHP 适配器控制器并挂宿主中间件：

   ```php
   class Aichat extends \aichat\adapter\thinkphp\controller\Aichat
   {
       protected $middleware = ['AdminCheck'];
   }
   ```

   适配器内部经 `ThinkPHPBridge::make()` 组装核心：TP 的 Db 连接取 PDO、
   Cache/Log 经 PSR 桥接注入，核心本身不碰任何 TP facade。

   ⚠️ 类名必须是 `Aichat`（TP6 按 URL 段 `aichat` 经 Str::studly 定位类文件，Linux 大小写敏感）。

5. **前端**：复制包内 `resources/assets/ai-float.{js,css}` 到宿主静态目录，
   在主框架页引入前设置：

   ```html
   <script>
   window.AI_FLOAT_CONFIG = {
       chatUrl: '/admin.php/aichat/chat', clearUrl: '/admin.php/aichat/clear',
       toolsUrl: '/admin.php/aichat/tools', panelUrl: '/admin.php/aichat/panel',
       quickQuestions: [
           { q: '找一下学员', label: '学员检索' },
           { q: '最近一次考试成绩', label: '考试成绩' }
       ],
       subtitle: '一句话查学员 / 学习 / 择校'
   };
   </script>
   ```

6. **System Prompt（可选）**：如需保留原 sms3.0 的业务化提示词
   （"先调用 search_student 拿 student_id" 等），配置 `aichat.system_prompt` 覆盖默认模板，
   原文见 `docs/reference/sms-architecture.md`。

## 关键安全设计（移植时务必保留）

- **四态区分**：`no_permission_tool / no_permission_row / not_found / empty` 严格分开，
  绝不把"无权限"表述成"没有数据"。
- **先查后判**：单条定位先不带权限查询，命中后再按分校/班主任过滤，才能区分"不存在"与"无权限"。
- **重名候选同样过滤**：ambiguous 候选列表只保留可见分校，避免跨分校信息泄露。
- **脱敏在 PHP 侧**：与用户是否在提问中要求"完整显示"无关，LLM 永远接触不到未脱敏原文。
  工具内通过 `Masker::current()->maskRow(...)` 取核心注册的脱敏器（ChatHandler 构造时自动注册）。
- **字段探测**：结构未确认的表先 `SHOW COLUMNS` 探测，缺字段返回"暂未接入"，不猜测字段。