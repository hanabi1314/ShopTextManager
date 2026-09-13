-- ========================================================
-- 卖家多账号商品快速发布与管理系统 - 数据库建表与初始数据 SQL
-- 环境要求：MySQL 5.7 / 8.0+
-- 编码规则：utf8mb4 / utf8mb4_unicode_ci
--
-- ⚠️ 导入方式（重要）
-- 本脚本**不**创建数据库，请先选好要导入的目标库再执行：
--   宝塔面板：数据库 → 选中你的库 → 导入 → 上传本文件
--   命令行　：mysql -u 用户名 -p 你的库名 < schema.sql
--
-- 之所以去掉了 CREATE DATABASE / USE：宝塔用户的库名是自定义的，
-- 而面板的数据库账号通常没有 CREATE DATABASE 权限，硬编码这两句会直接
-- "Access denied" 导致一张表都建不出来（或建到了 xianyu_db，与 api.php 配置的库名不一致）。
-- ========================================================

-- 1. 游戏数据表 (games) - 支持中英文双名称
CREATE TABLE IF NOT EXISTS `games` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `game_name_cn` VARCHAR(100) NOT NULL COMMENT '游戏中文名称，如 幻兽帕鲁',
  `game_name_en` VARCHAR(100) DEFAULT '' COMMENT '游戏英文名称，如 Palworld',
  `cover_url` VARCHAR(255) DEFAULT '' COMMENT '游戏封面图片链接',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态：1启用，0禁用',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='游戏库表';

-- 2. 账号与模板表 (templates / users)
CREATE TABLE IF NOT EXISTS `templates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `account_key` VARCHAR(50) NOT NULL UNIQUE COMMENT '账号唯一标识符/登录名，如 admin, account_a',
  `template_text` TEXT NOT NULL COMMENT '文案模板，支持 {{VAR_1}}, {{VAR_2}} 占位符',
  `is_admin` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否为管理员：1是，0否',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='账号文案模板表';

-- 3. 已发布记录日志表 (published_logs)
CREATE TABLE IF NOT EXISTS `published_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `account_key` VARCHAR(50) NOT NULL COMMENT '账号标识符',
  `game_id` INT NOT NULL COMMENT '游戏ID',
  `published_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '发布时间',
  UNIQUE KEY `uniq_account_game` (`account_key`, `game_id`),
  INDEX `idx_account_key` (`account_key`),
  INDEX `idx_game_id` (`game_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='账号已发布隐藏日志表';

-- ========================================================
-- 定时自动发布功能相关表 (v2.0 新增)
-- ========================================================

-- 4. 闲鱼自动回复配置表 (xianyu_config) - 每个用户对应一个闲鱼账号的对接设置
CREATE TABLE IF NOT EXISTS `xianyu_config` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `account_key` VARCHAR(50) NOT NULL UNIQUE COMMENT '关联 templates.account_key',
  `xy_server_url` VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'xianyu-auto-reply 服务地址 (含端口，不含 /api/v1)',
  `xy_secret_key` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '分销秘钥',
  `xy_account_id` VARCHAR(80) NOT NULL DEFAULT '' COMMENT '闲鱼账号ID',
  `xy_account_remark` VARCHAR(100) DEFAULT '' COMMENT '闲鱼账号备注名 (仅用于界面显示)',
  `publish_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否启用定时发布：1启用 0关闭',
  `publish_times` VARCHAR(200) DEFAULT '09:00' COMMENT '每日定时发布时间，多个用逗号分隔如 09:00,12:00,18:00',
  `publish_price` DECIMAL(10,2) NOT NULL DEFAULT 9.90 COMMENT '默认发布价格',
  `publish_original_price` DECIMAL(10,2) DEFAULT 0 COMMENT '默认划线原价 (0表示不设)',
  `publish_address` VARCHAR(200) DEFAULT '' COMMENT '宝贝所在地关键词',
  `publish_quantity` INT NOT NULL DEFAULT 1 COMMENT '发布库存数量',
  `publish_shipping_method` VARCHAR(20) DEFAULT 'free' COMMENT '运费方式: free/distance/fixed/template/none',
  `publish_category_id` VARCHAR(64) DEFAULT '' COMMENT '平台末级分类ID (留空自动推荐)',
  `publish_category_name` VARCHAR(100) DEFAULT '' COMMENT '平台分类名称',
  `publish_channel_cat_id` VARCHAR(64) DEFAULT '' COMMENT '频道分类ID',
  `publish_channel_cat_name` VARCHAR(100) DEFAULT '' COMMENT '频道分类名称',
  `publish_leaf_id` VARCHAR(64) DEFAULT '' COMMENT '叶子分类ID',
  `publish_tb_cat_id` VARCHAR(64) DEFAULT '' COMMENT '淘宝分类ID',
  `image_source` VARCHAR(10) DEFAULT 'auto' COMMENT '图片来源: auto(自动搜索)/cover_url(使用封面)/custom(自定义URL)',
  `custom_image_url` TEXT COMMENT '自定义图片URL (image_source=custom 时使用)',
  `last_publish_at` DATETIME DEFAULT NULL COMMENT '上次自动发布时间',
  `last_publish_status` VARCHAR(20) DEFAULT '' COMMENT '上次发布状态: success/failed/idle',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  INDEX `idx_publish_enabled` (`publish_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='闲鱼自动回复定时发布配置表';

-- 5. 闲鱼定时发布日志表 (xianyu_publish_logs) - 记录每次发布到闲鱼的结果
CREATE TABLE IF NOT EXISTS `xianyu_publish_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `account_key` VARCHAR(50) NOT NULL COMMENT '账号标识符',
  `game_id` INT NOT NULL COMMENT '游戏/商品ID',
  `game_name_cn` VARCHAR(100) DEFAULT '' COMMENT '商品中文名 (冗余便于查看)',
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT '发布状态: pending/success/failed',
  `trigger_type` VARCHAR(20) DEFAULT 'scheduled' COMMENT '触发方式: scheduled(定时)/manual(手动)',
  `message` TEXT COMMENT '发布结果消息或错误信息',
  `item_url` VARCHAR(500) DEFAULT '' COMMENT '发布后的商品链接',
  `item_id` VARCHAR(100) DEFAULT '' COMMENT '发布后的平台商品ID',
  `api_response` TEXT COMMENT 'xianyu-auto-reply API 原始响应 (JSON)',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间',
  INDEX `idx_account_key` (`account_key`),
  INDEX `idx_status` (`status`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='闲鱼定时发布执行日志表';

-- ========================================================
-- 插入示例初始数据
-- ========================================================

-- 插入默认游戏列表 (包含中文名称与英文名称)
INSERT INTO `games` (`id`, `game_name_cn`, `game_name_en`, `is_active`) VALUES
(1, '黑神话：悟空', 'Black Myth: Wukong', 1),
(2, '幻兽帕鲁', 'Palworld', 1),
(3, '艾尔登法环', 'Elden Ring', 1),
(4, '赛博朋克 2077', 'Cyberpunk 2077', 1),
(5, '绝地求生', 'PUBG: BATTLEGROUNDS', 1)
ON DUPLICATE KEY UPDATE `game_name_cn` = VALUES(`game_name_cn`), `game_name_en` = VALUES(`game_name_en`);

-- 插入默认账号模板 (包含 admin 超级管理员 和普通子账号模板)
INSERT INTO `templates` (`id`, `account_key`, `template_text`, `is_admin`) VALUES
(1, 'admin', '【{{VAR_1}} ({{VAR_2}}) Steam正品游戏安装包 离线版/中文版】\n⚡ 自动发货 | 包含全套完整安装包+DLC扩展+终身更新\n✅ 送详细视频教程+远程协助安装 简体中文 免Steam繁琐步骤\n✅ 拍下即发 极速下载 随时随地畅玩！', 1),
(2, 'account_a', '【{{VAR_1}} ({{VAR_2}}) 纯净单机中文安装包】\n⚡ 官方正品Steam分流下载 | 解压即玩 | 无毒无捆绑\n✅ 包含最新版本全套DLC + 汉化补丁 + 详细图文教程\n💬 售后客服一对一指导，包教包会！', 0),
(3, 'account_b', '🔥【{{VAR_1}} ({{VAR_2}}) Steam离线极速安装包】🔥\n✨ 告别下载慢！百度网盘/迅雷/直连高速分流！\n🛠️ 自带一键启动器与全成就解锁，随时畅玩！\n需要的直接联系，拍下秒发！', 0)
ON DUPLICATE KEY UPDATE `template_text` = VALUES(`template_text`), `is_admin` = VALUES(`is_admin`);

-- 插入默认已发布归档记录 (示例：account_a 已归档发布的商品)
INSERT IGNORE INTO `published_logs` (`account_key`, `game_id`) VALUES
('account_a', 1),
('account_a', 2);
