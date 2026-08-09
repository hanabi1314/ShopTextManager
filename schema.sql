-- ========================================================
-- 咸鱼多账号商品快速发布与管理系统 - 数据库建表与初始数据 SQL
-- 环境要求：MySQL 5.7 / 8.0+
-- 编码规则：utf8mb4 / utf8mb4_unicode_ci
-- ========================================================

CREATE DATABASE IF NOT EXISTS `xianyu_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `xianyu_db`;

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
  `template_text` TEXT NOT NULL COMMENT '文案模板，支持 {{GAME_CN}}, {{GAME_EN}} 占位符',
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
INSERT INTO `templates` (`id`, `account_key`, `template_text`) VALUES
(1, 'admin', '【{{VAR_1}} ({{VAR_2}}) Steam正品游戏安装包 离线版/中文版】\n⚡ 自动发货 | 包含全套完整安装包+DLC扩展+终身更新\n✅ 送详细视频教程+远程协助安装 简体中文 免Steam繁琐步骤\n✅ 拍下即发 极速下载 随时随地畅玩！'),
(2, 'account_a', '【{{VAR_1}} ({{VAR_2}}) 纯净单机中文安装包】\n⚡ 官方正品Steam分流下载 | 解压即玩 | 无毒无捆绑\n✅ 包含最新版本全套DLC + 汉化补丁 + 详细图文教程\n💬 售后客服一对一指导，包教包会！'),
(3, 'account_b', '🔥【{{VAR_1}} ({{VAR_2}}) Steam离线极速安装包】🔥\n✨ 告别下载慢！百度网盘/迅雷/直连高速分流！\n🛠️ 自带一键启动器与全成就解锁，随时畅玩！\n需要的直接联系，拍下秒发！')
ON DUPLICATE KEY UPDATE `template_text` = VALUES(`template_text`);
