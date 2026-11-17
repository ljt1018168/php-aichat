-- ============================================================
-- think-aichat —— 建表脚本（MySQL 5.7+ / 8.0）
-- 表名不带前缀；若宿主配置了 database.prefix，请自行给表名加前缀
-- ============================================================

-- ----------------------------
-- 1. 会话表
-- ----------------------------
CREATE TABLE IF NOT EXISTS `ai_chat_conversation` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '会话ID',
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '用户ID（会话归属，强隔离依据）',
  `title` varchar(100) NOT NULL DEFAULT '' COMMENT '会话标题（取首条提问前30字）',
  `slots` varchar(1000) NOT NULL DEFAULT '' COMMENT '槽位JSON（指代消解上下文）',
  `slot_update_time` int unsigned NOT NULL DEFAULT 0 COMMENT '槽位更新时间戳，用于 TTL 判断',
  `last_active_time` int unsigned NOT NULL DEFAULT 0 COMMENT '最后活跃时间',
  `create_time` int unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
  `is_deleted` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '是否删除：0否 1是',
  PRIMARY KEY (`id`),
  KEY `idx_user_active` (`user_id`, `last_active_time`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='AI对话-会话表';

-- ----------------------------
-- 2. 消息表
-- ----------------------------
CREATE TABLE IF NOT EXISTS `ai_chat_message` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '消息ID',
  `conversation_id` int unsigned NOT NULL DEFAULT 0 COMMENT '会话ID',
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '用户ID（冗余，便于直接按用户清理）',
  `role` varchar(20) NOT NULL DEFAULT '' COMMENT '角色：system/user/assistant/tool',
  `content` text COMMENT '消息内容（tool 角色时为工具返回JSON）',
  `tool_calls` text COMMENT '助手发起的工具调用JSON',
  `tool_name` varchar(50) NOT NULL DEFAULT '' COMMENT 'tool 角色对应的工具名',
  `tool_call_id` varchar(64) NOT NULL DEFAULT '' COMMENT '工具调用ID，必须与对应 assistant tool_call 的 id 一致，否则多轮重建历史时兼容层会报错',
  `token_estimate` int unsigned NOT NULL DEFAULT 0 COMMENT '内容长度估算，用于窗口裁剪',
  `create_time` int unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_conv_id` (`conversation_id`, `id`),
  KEY `idx_user_time` (`user_id`, `create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='AI对话-消息表';

-- ----------------------------
-- 3. 审计日志表
-- ⚠️ 本表含业务敏感信息与提问原文，需与业务数据同级管控，
--    不得在任何无权限校验的接口中暴露。
-- ----------------------------
CREATE TABLE IF NOT EXISTS `ai_chat_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '日志ID',
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '操作人',
  `conversation_id` int unsigned NOT NULL DEFAULT 0 COMMENT '会话ID',
  `question` varchar(500) NOT NULL DEFAULT '' COMMENT '用户原始提问',
  `tool_called` varchar(100) NOT NULL DEFAULT '' COMMENT '调用的工具（多个逗号分隔）',
  `params` varchar(1000) NOT NULL DEFAULT '' COMMENT '查询参数JSON（不含敏感结果）',
  `reason` varchar(30) NOT NULL DEFAULT '' COMMENT '结果类型：ok/no_permission_tool/no_permission_row/not_found/empty/error/disabled/blocked/rate_limited',
  `response_summary` varchar(1000) NOT NULL DEFAULT '' COMMENT '响应摘要（脱敏后）',
  `latency_ms` int unsigned NOT NULL DEFAULT 0 COMMENT '耗时（毫秒）',
  `prompt_tokens` int unsigned NOT NULL DEFAULT 0 COMMENT '提示tokens（厂商返回则记录）',
  `completion_tokens` int unsigned NOT NULL DEFAULT 0 COMMENT '完成tokens',
  `create_time` int unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`, `create_time`),
  KEY `idx_reason` (`reason`, `create_time`),
  KEY `idx_conv` (`conversation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='AI对话-审计日志表';