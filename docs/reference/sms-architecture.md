# 架构设计：AI 对话查询业务数据（纯自建）

| 项目 | 内容 |
|------|------|
| 文档版本 | v1.0 |
| 创建日期 | 2026-10-12 |
| 前置文档 | `docs/ai-chat/PRD.md` v1.1、`docs/ai-chat-方案选型调研.md` |
| 技术路线 | **PHP 纯自建 + LLM Function Calling**，不引入 Dify / 任何外部编排服务 |
| 运行环境 | ThinkPHP 6 + **PHP 7.4.33**（语法需兼容 7.4，禁用 PHP 8 特性） |

---

## 1. 总体架构

```
┌──────────────────────────────────────────────────────────────┐
│  前端  app/admin/view/aichat/panel.html                       │
│  悬浮按钮 → 抽屉面板 → POST /admin.php/aichat/chat            │
└───────────────────────────┬──────────────────────────────────┘
                            │  JSON: {question, conversation_id}
┌───────────────────────────▼──────────────────────────────────┐
│  控制器  app/admin/controller/AiChat.php                      │
│  middleware: AdminCheck（复用现有登录 + 弱密码拦截）          │
│  职责：参数校验、限流、调用 Service、统一 JSON 返回            │
└───────────────────────────┬──────────────────────────────────┘
                            │
┌───────────────────────────▼──────────────────────────────────┐
│  编排  app/admin/service/AiChatService.php                    │
│  ★ Agent 循环：LLM ⇄ 工具，最多 max_tool_rounds 轮            │
└───┬──────────────┬──────────────┬──────────────┬─────────────┘
    │              │              │              │
┌───▼──────┐ ┌─────▼──────┐ ┌─────▼──────┐ ┌─────▼──────────┐
│ LlmClient│ │ToolRegistry│ │ToolExecutor│ │ConversationStore│
│ OpenAI   │ │ 工具定义 + │ │ ★权限守卫  │ │ 会话/消息/槽位  │
│ 兼容协议 │ │ 权限映射   │ │ 执行查询   │ │                │
└──────────┘ └────────────┘ └─────┬──────┘ └────────────────┘
                                  │
                    ┌─────────────┼─────────────┐
              ┌─────▼─────┐ ┌─────▼─────┐ ┌─────▼──────┐
              │Permission │ │  Masker   │ │AuditLogger │
              │  Guard    │ │  脱敏     │ │  审计日志   │
              └───────────┘ └───────────┘ └────────────┘
```

### 1.1 目录结构

```
config/aichat.php                        # 全部可配置项（LLM / 工具权限 / 脱敏 / 限流）
app/admin/controller/AiChat.php          # 控制器
app/admin/service/AiChatService.php      # Agent 循环编排
app/admin/service/aichat/
├── LlmClient.php                        # OpenAI 兼容协议客户端
├── ToolRegistry.php                     # 工具定义（JSON Schema）+ 权限映射
├── ToolExecutor.php                     # 工具执行分发 + 统一结果结构
├── PermissionGuard.php                  # 工具级 + 行级 + 字段级权限
├── Masker.php                           # 字段脱敏
├── ConversationStore.php                # 会话 / 消息 / 槽位
├── AuditLogger.php                      # 审计日志
└── StudentLocator.php                   # 学员定位（含行级权限前置校验）
app/admin/view/aichat/panel.html        # 前端面板
sql/20261012_ai_chat_tables.sql          # 建表脚本
```

---

## 2. 核心：Agent 循环

一期采用**阻塞式**循环（流式列为 P1），流程如下：

```
  用户提问
     │
     ▼
┌─────────────────────────────────────────┐
│ 1. 限流检查（30 次/分钟/用户）           │
│ 2. 载入会话历史（最近 10 轮）            │
│ 3. 构造 messages: system + history + Q   │
│ 4. 注入工具 Schema（仅当前用户有权工具） │  ← ★ 权限前置
└─────────────────┬───────────────────────┘
                  ▼
        ┌─── LLM 推理（第 1 次）───┐
        └───────────┬──────────────┘
                    ▼
            有 tool_calls？
           ╱                 ╲
        否                      是
         │                       │
         ▼                       ▼
   直接返回文本          ┌──────────────────────┐
                        │ 逐个执行工具          │
                        │  · 工具级权限二次校验  │  ← ★ 不信任 LLM
                        │  · 行级权限校验       │
                        │  · 执行查询           │
                        │  · 脱敏               │
                        │  · 写审计日志         │
                        └───────────┬──────────┘
                                    ▼
                        追加 tool 消息 → 回到 LLM（第 N 次）
                                    │
                        达到 max_tool_rounds？→ 强制结束，返回已得结果
                                    ▼
                            最终文本 → 存消息 → 更新槽位 → 返回
```

### 2.1 为什么工具列表要按权限裁剪

`ToolRegistry::schemasFor($adminId)` 只返回该用户有权调用的工具。

**双重收益**：

1. **省 token** —— 9 个工具全量 Schema 约 2,000+ tokens/次，按权限裁剪后通常只剩 3–5 个；
2. **消除幻觉调用** —— 模型看不到无权工具，从源头杜绝"调用一个不该调用的工具"。

但**这不是权限保障**，只是优化。真正的权限在 `PermissionGuard` —— **LLM 返回的工具名一律视为不可信输入**，执行前二次校验。

> 这一条是架构的硬约束：**任何绕过 `PermissionGuard` 直达数据的路径都不允许存在。**

---

## 3. 权限守卫（★ 核心）

三层权限，按执行顺序：

### 3.1 工具级（RBAC）

```php
PermissionGuard::checkToolPermission($adminId, $toolName)
```

映射关系配置在 `config/aichat.php` 的 `tool_permission`，值为现有 `admin_permission.href`：

```php
'tool_permission' => [
    'search_student'   => '/admin.php/student/index',
    'get_exam_scores'  => '/admin.php/exam_score/index',
    ...
],
```

判定逻辑：

| 条件 | 结果 |
|------|------|
| `admin_id == 1`（超管） | 全部放行 |
| Session `admin.menu` 中存在该 href 且 `status=1` | 放行 |
| 其余 | 拒绝，返回 `deny_tool` |

> 复用 `Session::get('admin.menu')`（登录时由 `AdminAdmin::permissions()` 写入，已含全部权限点），**零额外查询**。Session 缺失时回退 `AdminAdmin::permissions($adminId)`。

### 3.2 行级（分校隔离 + 班主任本人学员）

```php
PermissionGuard::visibleBranchIds($adminId)   // 返回数组；超管返回 null 表示"全部"
PermissionGuard::checkRowPermission($branchId, $visible)  // bool
PermissionGuard::classTeacherId($adminId)     // 班主任返回 teacher_id；否则 null
PermissionGuard::applyClassTeacherScope($query, $teacherId)  // 注入"仅本人学员"条件
```

| 场景 | 处理 |
|------|------|
| `admin_id == 1` | 返回 `null`，**跳过 `branch_id` 过滤**（不依赖 `admin_admin_branch` 是否有记录） |
| 其他管理员，Session `admin.branchs` 非空 | 按此过滤 |
| 其他管理员，Session 缺失 | 回退 `AdminAdmin::branchs($adminId)` |
| 其他管理员，结果为空数组 | **拒绝查询**（`deny_row`），而非返回空结果 |
| 班主任（非超管 + `teacher_id` + 角色4） | 在分校条件之上再限定 `stu_teacher` 中本人名下学员（AND） |

**班主任口径**：`stu_teacher` 中 `status=1` 且 `effectuate_time<=今天` 的最新一条记录（`MAX(id)`），`teacher_id` 等于当前账号关联的教师ID；与后台 `StudyDataService` / `ExamScoreService` 完全一致。多角色账号只要含班主任角色，同样收窄。

**注入方式**：所有学员查询在进入 Model 层之前就带上 `whereIn('branch_id', $visible)`，由 `StudentLocator` 统一收口；班主任条件在 SQL 层注入（先于 `limit`），不在结果集上过滤。单条定位因候选量小（≤5）才用后置过滤，并在过滤后区分"不在本分校"与"不在本人名下"。

### 3.3 字段级（脱敏）

`Masker` 在**工具返回后、送入 LLM 之前**执行：

| 字段 | 规则 | 完整查看条件 |
|------|------|-------------|
| `phone` | `138****1234` | 超管，或持有配置的"完整手机号"权限 |
| `id_card` | 一律不返回 | 超管返回前 6 后 4 |
| `address` | 一律不返回 | — |
| `username`（姓名） | 完整 | 无需额外权限 |

> **硬约束**：LLM 上下文里出现的任何学员数据，都必须是 `Masker` 处理过的输出。工具实现不得绕过 `Masker` 直接返回原始行。

### 3.4 失败原因的判定与返回（四种"查不到" + 重名澄清）

`ToolExecutor` 统一返回结构：

```php
[
    'ok'       => true|false,
    'reason'   => 'no_permission_tool' | 'no_permission_row' | 'not_found' | 'empty' | 'ambiguous' | 'error' | 'disabled' | 'missing_param',
    'message'  => '面向用户的中文提示',
    'data'     => [...],           // 结构化数据
    'render'   => 'table' | 'text' // 前端渲染方式建议
]
```

| reason | 触发条件 | message 模板 |
|--------|---------|-------------|
| `no_permission_tool` | 工具级校验失败 | 你没有查看【{域}】的权限，如需访问请联系分校管理员。 |
| `no_permission_row` | 行级校验失败 | 学员【{姓名}】不属于你负责的分校 / 不在你负责的学员名单内，无法查看。 |
| `not_found` | 查询无匹配记录 | 未找到姓名为【{关键词}】的学员，请确认姓名是否准确。 |
| `empty` | 对象存在但该域无记录 | 学员【{姓名}】暂无{域}记录。 |
| `ambiguous` | 命中多位同名学员（**不属于"查不到"，是待澄清**） | 找到 {N} 位同名学员，请确认是哪一位： |

**`ambiguous` 的约定（2026-10-26）**：`ok=false` + `data` 为候选名单（已脱敏、已过分校与班主任过滤）。
调用方按失败路径原样透传，**不得**当"已定位"去读 `data['student']`（会崩）；模型侧必须展示候选表请用户选择，**严禁自行选一位**继续查。
早期实现误用 `ok=true`，导致 `locateStudent()` 把候选结果当成功定位。

**落地保障**：`reason` 写入审计日志，验收时可按 `reason` 分布验证"无权限被误报为不存在"的比例必须为 0。

---

## 4. 工具设计

### 4.1 一期工具清单

| 工具 | 数据源 | 状态 | 说明 |
|------|--------|------|------|
| `search_student` | `stu_info_basic` | ✅ 可实施 | 按姓名/手机号/学员ID 检索 |
| `get_student_basic` | `stu_info_basic` | ✅ 可实施 | 姓名、性别、届数、服务状态、分校 |
| `get_student_contact` | `stu_info_basic` | ✅ 可实施 | 手机号（默认脱敏） |
| `get_course_progress` | `stu_info_course` | ⚠️ 字段待校准 | 课程进度 |
| `get_exam_scores` | `exam_student_score` | ✅ 可实施 | 按 `crm_stu_id_ex` / `phone` 关联 |
| `get_attendance` | `offline_class_lesson_attendance` | ✅ 可实施 | 按 `student_id` 关联 |
| `get_choose_school_plan` | `stu_choose_school_first/second` | ⚠️ 未接入 | 配置驱动 + 字段探测 |
| `get_admission_result` | `stu_enter_admission_result` | ⚠️ 未接入 | 配置驱动 + 字段探测 |
| `get_enter_grades` | `stu_enter_grades` | ⚠️ 未接入 | 配置驱动 + 字段探测 |

### 4.2 未接入数据源的处理（★ 禁止猜测字段）

择校类三张表在代码中**零引用**，字段结构无法从代码确认（数据库外网不可达）。

处理方式：

1. 表名字段映射写入 `config/aichat.php` 的 `pending_sources`；
2. `ToolExecutor` 执行前调用 `StudentLocator::tableReady($table, $requiredColumns)`：
   - 用 `SHOW COLUMNS FROM` 探测，**结果按表名缓存 300 秒**；
   - 必需字段缺失 → 返回 `reason = 'disabled'`，message："该数据域暂未接入 AI 对话，如有需要请联系技术同学。";
3. **绝不**按猜测字段写死查询。

> 校准方式：连上生产库执行 `SHOW CREATE TABLE xxx`，把真实字段填进配置即可启用，无需改代码。

### 4.3 工具 Schema 示例

```json
{
  "name": "get_exam_scores",
  "description": "查询指定学员的考试成绩记录。当用户询问某学员考了多少分、成绩如何、排名时使用。",
  "parameters": {
    "type": "object",
    "properties": {
      "student_id": {"type": "integer", "description": "学员ID，从 search_student 结果获取；若用户只给了姓名，先调 search_student"},
      "limit": {"type": "integer", "description": "返回条数，默认5"}
    },
    "required": ["student_id"]
  }
}
```

**工具描述质量直接决定意图准确率**。每个工具的描述里包含：用途、触发场景（"当用户问…时使用"）、参数获取方式。

---

## 5. 多轮上下文

### 5.1 存储

| 表 | 作用 |
|----|------|
| `ai_chat_conversation` | 会话：`id`, `admin_id`, `title`, `last_active_time` |
| `ai_chat_message` | 消息：`conversation_id`, `role`, `content`, `tool_calls`, `tool_name`, `tool_call_id`, `create_time` |
| `ai_chat_log` | 审计：`admin_id`, `question`, `tool`, `reason`, `latency_ms` |

> **重建历史序列必须合法**：取最近 N 条后，需从两头裁掉孤儿消息——
> ① 窗口截断切掉了 `assistant(tool_calls)`，留下孤立的 `tool` 消息；
> ② 上一轮异常中断，残留没有 `tool` 回复的 `assistant(tool_calls)`。
> 这两类都会被 OpenAI 兼容端直接拒绝（多轮对话第二轮起崩溃），由 `ConversationStore::history()` 统一清理。

### 5.2 槽位（指代消解）

槽位存在 `ai_chat_conversation.slots`（JSON）：

```json
{"student_id": 12345, "student_name": "张三", "branch_id": 3}
```

- 学员被成功定位后写入；
- 后续轮次若 LLM 未传 `student_id`，`ToolExecutor` 自动从槽位补；
- 定位到**新学员**时刷新槽位；
- 会话超过 `slot_ttl`（默认 24h）自动清空。

**用户隔离的硬实现**：`ConversationStore` 所有方法第一行都是 `WHERE admin_id = ?`。查不到即视为不存在，不返回"无权限"（避免会话 ID 枚举探测）。

---

## 6. 数据库设计

见 `sql/20261012_ai_chat_tables.sql`。三张表：

| 表 | 关键索引 |
|----|---------|
| `ai_chat_conversation` | `idx_admin_active(admin_id, last_active_time)` |
| `ai_chat_message` | `idx_conv_id(conversation_id, id)` |
| `ai_chat_log` | `idx_admin_time(admin_id, create_time)`, `idx_reason(reason)` |

> 日志表含学员信息，**纳入与业务数据同级的访问控制**，不在任何公开接口中暴露。

---

## 7. 非功能设计

### 7.1 降级

| 故障 | 处理 |
|------|------|
| LLM 超时 / 网络失败 | 返回"AI 服务暂时不可用，请稍后重试"，**不影响后台其他功能**；写 error 日志 |
| LLM 返回非法 JSON | 同上，不抛异常到用户 |
| 工具查询异常 | 单工具返回 `reason=error`，其余工具结果正常返回 |
| 达到 `max_tool_rounds` | 用已有结果生成答复，并提示"信息可能不完整" |

### 7.2 限流

Redis（项目已有 `setRedis`/`getRedis`）：`aichat:rl:{admin_id}` 计数，60 秒窗口，默认 30 次。超限返回明确提示。

### 7.3 超时

`curl_post()` 默认 `timeout=5`，**调 LLM 必须显式传 60s**（这是复用该函数时最容易踩的坑）。

### 7.4 成本

| 项 | 值 |
|----|----|
| 单次请求 token | System（术语表 + 工具 Schema）约 800–2,500；历史 10 轮约 1,500；合计约 3,000–5,000 |
| 月成本估算 | 20 人 × 10 问/天 × 22 天 ≈ 4,400 问 × 4,000 tokens ≈ 1,760 万 tokens/月，DeepSeek 量级约 **¥30–50/月** |

---

## 8. 安全边界（Checklist）

- [x] LLM 不接触数据库凭据
- [x] LLM 不接触未脱敏原文（`Masker` 收口）
- [x] LLM 返回的工具名视为不可信，执行前二次校验
- [x] 行级权限在查询构造前注入，不在结果过滤
- [x] 无权限 ≠ 数据不存在（`reason` 严格区分）
- [x] 非超管 `branchs()` 为空 → 拒绝查询，而非返回空
- [x] 会话按 `admin_id` 强隔离
- [x] 所有工具只读，无 INSERT/UPDATE/DELETE 路径
- [x] 薪资类表不出现在任何工具的数据源中
- [x] 审计日志记录全部请求（含被拒）

---

## 9. 实施顺序

| 阶段 | 内容 | 状态 |
|------|------|------|
| S1 | 建表 SQL + `config/aichat.php` | 已完成 |
| S2 | `Masker` / `PermissionGuard` / `StudentLocator` | 已完成 |
| S3 | `ToolRegistry` / `ToolExecutor` | 已完成 |
| S4 | `LlmClient` / `ConversationStore` / `AuditLogger` | 已完成 |
| S5 | `AiChatService` Agent 循环 + 控制器 + 路由 | 已完成 |
| S6 | 前端面板 | 已完成 |
| S7 | 权限映射 href 校准 + 择校表字段校准 | ⏳ **需连生产库确认** |
| S8 | 评测集（每意图 ≥20 条）+ 回归测试 | ⏳ 待业务提供问法 |

---

## 10. 关键决策与权衡

| 决策 | 选择 | 理由 | 放弃的方案 |
|------|------|------|-----------|
| 编排方式 | 纯自建 Function Calling | 9 个固定工具不需要可视化编排；省 13 容器运维与公网暴露面 | Dify（Cloud/自部署） |
| 查询方式 | 预定义工具，**不让 LLM 写 SQL** | 106 张表 + `branch_id` 非语义化 + Text-to-SQL 准确率 60–80% | Text-to-SQL |
| 权限时机 | 检索**之前**（工具列表裁剪 + 执行前校验） | 避免"权限过滤导致空 → 表述为不存在"的自信错误 | 执行后过滤 |
| 脱敏位置 | PHP 侧 `Masker` | LLM 不接触原文，不依赖模型自觉 | LLM 侧指令约束 |
| 流式 | 一期阻塞，P1 再上 SSE | 降低一期复杂度；阻塞 P95 已在 8s 内 | 一期即流式 |
| 未确认数据源 | 字段探测 + 明确 disabled 提示 | 不猜测字段，不产生错误数据 | 按推测字段硬写 |

---

## 11. 部署清单

### 11.1 四步上线

| # | 操作 | 命令 / 位置 |
|---|------|------------|
| 1 | 建表 | 执行 `sql/20261012_ai_chat_tables.sql` |
| 2 | 配 LLM | 在 `.env` 末尾追加下方配置段 |
| 3 | 校准配置 | `php scripts/aichat-config-check.php`（权限 href + 待接入字段，见 11.5） |
| 4 | 验证 LLM | `php scripts/aichat-llm-selftest.php`（Function Calling 必过，见 11.4） |
| 5 | 开开关 | `.env` 中 `AICHAT.ENABLE = true`（也可先不开，用超管自测） |
| 6 | 访问 | 浏览器打开 `/admin.php/aichat/panel` |

> 第 3 步必须在能连数据库的机器上跑；第 4 步需 Key 已生效。两个脚本全绿再开开关。

### 11.2 .env 配置段（已追加骨架，需填 Key）

```ini
[AICHAT]
ENABLE = false
API_BASE = https://dashscope.aliyuncs.com/compatible-mode/v1
API_KEY = sk-xxxxxxxxxxxxxxxx
MODEL = qwen-plus
```

> ⚠️ `API_KEY` 属敏感凭证。`.env` 已在 `.gitignore` 第 7 行，确认不要提交。
> 换厂商时只需改 `API_BASE` / `MODEL`，无需改代码。

### 11.3 ⚠️ 不能用百炼 Coding Plan 的 Key（实测，2026-10-12）

阿里云百炼 **Coding Plan 套餐的 Key（`sk-sp-` 前缀）不可用于本系统**，实测结果：

| 端点 + 模型 | 结果 |
|---|---|
| `coding.dashscope.aliyuncs.com/v1` + `qwen3.7-plus` | ❌ `Coding Plan is currently only available for Coding Agents` |
| `dashscope.aliyuncs.com/compatible-mode/v1` + `qwen-plus` | ❌ `Incorrect API key provided` |
| `dashscope.aliyuncs.com/compatible-mode/v1` + `qwen-max` | ❌ `Incorrect API key provided` |

**解读**：
- Key 本身**有效**（返回的是用途限制错误，不是 `invalid_api_key`），但服务端按用途锁定，**只允许编程 Agent 客户端调用**；
- 该 Key 也不属于正式百炼，无法在 `compatible-mode` 端点使用；
- 这是服务条款层面的限制，**换端点、换模型都绕不过，也不应绕过**。

**正确做法**：开通阿里云百炼**正式 API Key**（`sk-` 前缀）+ `https://dashscope.aliyuncs.com/compatible-mode/v1`，模型选 `qwen-plus`（成本低、中文好、支持 Function Calling）。数据仍在阿里云内，与 RDS/OSS 同厂商，合规上最干净。

备选：DeepSeek（`https://api.deepseek.com` + `deepseek-chat`），月成本 ¥30–50 量级。

### 11.4 换 Key 后先跑自检

```bash
php scripts/aichat-llm-selftest.php
```

覆盖 4 项：配置完整性 → 鉴权与纯对话 → **Function Calling** → 工具结果回灌（`tool_call_id` 匹配 + 四态语义传达）。全部 PASS 才说明这个 LLM 能用于本系统。

> ⚠️ 排查经验：部分厂商的 `/v1/models` **不校验鉴权**（用瞎编的 Key 也返回 200）。
> 判断 Key 是否真的能用，**必须打 `/chat/completions`**，看 models 列表会被误导。

### 11.5 配置校准检查脚本（★ 连库一键定位）

上线前有两项配置**填错不会报错，只会静默失效**（非超管看不到任何工具 / 三个工具一直提示"暂未接入"）。已提供脚本一次性定位：

```bash
php scripts/aichat-config-check.php
```

| # | 检查项 | 说明 |
|---|--------|------|
| 1 | 配置完整性 | 9 个工具是否都配了权限映射与中文域名（不依赖 DB） |
| 2 | 数据库连接 | 失败时给出清晰提示并跳过 3~6 项 |
| 3 | AI 三张表 | `ai_chat_conversation` / `ai_chat_message` / `ai_chat_log` 是否建；`tool_call_id` 列是否存在 |
| 4 | 学员主表字段 | `stu_info_basic` 是否含 `branch_id`（行级权限命脉）；若只有 `cid` 会明确报警 |
| 5 | ★ 权限点 href | 逐个工具核对 `admin_permission` 中是否存在该 href，未命中时按关键词+标题打分给出 **top3 候选**，并输出可粘贴的 `tool_permission` 片段 |
| 6 | ★ 待接入数据源 | 择校/录取三表是否存在、必需字段是否齐全，缺失时打印**实际字段**并给出启发式映射建议 |

输出示例（第 5 项）：

```
  [FAIL] get_exam_scores  配置了 /admin.php/exam/index，但库中无该 href → 非超管一律拒绝
           候选 id=12    /admin.php/exam_score/index      考试成绩
           候选 id=31    /admin.php/enter_grades/index    考研成绩
           >> 建议值: /admin.php/exam_score/index
```

> ⚠️ 候选为**启发式匹配**（href 命中关键词 +3，title 命中 +2），必须人工确认后再写入配置。

#### 顺带确认：href 填原值即可

`AdminAdmin::permissions()` 中有 `$permissions[$p['id']]['href'] = is_url($p['href']) ?? $p['href'];`，而 `is_url()`（`app/common.php:73`）**只对 `http(s)://` 开头的字符串返回值，其余情况无 return（返回 null）**——因此 `??` 兜底后 Session 中存的就是**数据库原始 href**。

结论：`config/aichat.php` 的 `tool_permission` 直接填 `admin_permission.href` 的数据库原值即可口径一致。

### 11.6 上线前必须校准的两项（★ 否则功能不完整）

| # | 校准项 | 为什么 | 怎么做 |
|---|--------|--------|--------|
| 1 | `config/aichat.php` 中 `tool_permission` 的 href | 现有 `admin_permission` 表的实际 href 值未核实。填错 = 非超管看不到任何工具 | 跑 `scripts/aichat-config-check.php` 第 5 项，按输出的候选修正 |
| 2 | `config/aichat.php` 中 `pending_sources` 的字段 | 择校/录取/考研成绩三表代码中零引用，字段未知。未校准时这三个工具返回"暂未接入" | 跑同上脚本第 6 项，按打印的实际字段填 `columns` |

**校准后无需改代码**，改配置即生效。

### 11.7 入口接入方式

当前为独立页面 `/admin.php/aichat/panel`。若要做成后台右下角悬浮按钮，在公共布局中加一个 iframe 或直接引入该页面即可：

```html
<a href="/admin.php/aichat/panel" target="_blank">AI 数据助手</a>
```

---

## 12. 变更历史

| 版本 | 日期 | 变更内容 |
|------|------|---------|
| v1.0 | 2026-10-12 | 初稿，基于 PRD v1.1 纯自建路线 |
| v1.0 | 2026-10-12 | 补充第 11 节部署清单（.env 配置、两项待校准项、入口接入） |
| v1.1 | 2026-10-12 | 新增 `scripts/aichat-config-check.php`；11.5 记录该脚本用法与 `is_url()` href 口径确认 |
| v1.1 | 2026-10-12 | 11.3 记录百炼 Coding Plan Key 实测结论（不可用于业务系统） |
| v1.2 | 2026-10-26 | ① 3.2 行级权限新增班主任收窄（`stu_teacher` 最新有效记录，与分校 AND）；② 3.4 `ambiguous` 语义修正为 `ok=false` + 候选数据，补重名澄清约定；③ 5.1 补历史重建的孤儿消息清理约束 |
