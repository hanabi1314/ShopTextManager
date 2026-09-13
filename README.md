# ShopTextManager 🚀

**多平台商品文案管理与一键发布系统** / Multi-Platform Product Listing & Copywriting Manager

[🇨🇳 中文文档](#cn) | [🇺🇸 English Documentation](#en)

> 🚀 **赞助推荐 / Sponsor**
> **NodeHK VPN** — 面向跨境电商、外贸加速、海外平台运营与游戏节点的高速稳定 VPN 服务：
> 👉 [**www.nodehk.shop**](https://www.nodehk.shop/#/auth?invite=uu1fzn9t)

---

## 📑 目录 / Table of Contents

**中文**：[项目简介](#1-项目简介) · [核心特性](#2-核心特性) · [快速开始](#3-快速开始5-分钟) · [项目结构](#4-项目结构) · [生产部署](#5-生产部署php--mysql) · [定时发布到闲鱼](#6-定时自动发布到闲鱼) · [安全建议](#7-安全建议) · [常见问题](#8-常见问题速查)

**English**: [Overview](#1-overview) · [Key Features](#2-key-features) · [Quick Start](#3-quick-start-5-minutes) · [Project Structure](#4-project-structure) · [Production Deployment](#5-production-deployment-php--mysql) · [Scheduled Publishing](#6-scheduled-auto-publish-to-xianyu) · [Security Notes](#7-security-notes) · [FAQ](#8-faq--troubleshooting)

---

<a id="cn"></a>
# 🇨🇳 中文文档

## 1. 项目简介

ShopTextManager 面向卖家、跨境电商、二手商家与虚拟商品/游戏安装包卖家，解决一件事：**把商品批量变成可发布的文案，并自动发到闲鱼。**

核心是 **Preset-First（开箱即用预设 + 渐进式扩展）** 架构：默认启用「🎮 Steam 游戏安装包 / 虚拟商品」预设，登录即用、零配置；需要时可在管理员控制台一键切换为「📱 数码二手 / 3C」「👗 服饰潮牌 / 中古」或「⚙️ 通用电商 / 自定义」。

两种后端模式：

| 模式 | 用途 | 数据落点 |
| :--- | :--- | :--- |
| **PHP + MySQL**（`api.php`） | **生产环境，唯一推荐** | MySQL，持久化 |
| **Node.js**（`server.ts`） | 本地改前端时免装 PHP | 进程内存，**重启即丢失，不能真正发布** |

> ⚠️ Node.js 模式没有接数据库，也无法调用闲鱼发布接口。生产请务必用 PHP + MySQL。

---

## 2. 核心特性

- **🌙 明暗主题 / 🌐 中英双语**：全界面一键切换，偏好存 localStorage。
- **🛡️ 管理员权限管理**：可授予/撤销管理员，内置「最少保留一个管理员」保护；支持新建、改名、删除账号（改名/删除会级联同步闲鱼配置与发布记录）。
- **🎯 场景预设**：4 套行业套件，切换后表格列名、输入提示、文案变量、搜图建议词实时联动。
- **📋 一键复制 / 批量导出**：单条复制或整页批量复制生成的商品描述。
- **🖼️ 自动联网搜图**：无需逐个手动配图，依次尝试 Bing → DuckDuckGo → Wikimedia，每个图源再用中英文关键词重试，结果按关键词缓存 6 小时。
- **⏰ 定时自动发布**：对接 [xianyu-auto-reply](https://github.com/zhinianboke/xianyu-auto-reply)，到点自动发商品到闲鱼。

### 存储分层

| 层级 | 介质 | 内容 |
| :--- | :--- | :--- |
| 核心业务库 | MySQL | 商品、文案模板、发布归档、账号权限 |
| 内存体验库 | Node.js 内存 | 本地开发测试数据，重启归零 |
| 前端偏好层 | localStorage | 主题、语言、当前账号 Key（**不存业务数据**） |

---

## 3. 快速开始（5 分钟）

```bash
# 1) 导入数据库结构
mysql -u root -p 你的库名 < schema.sql

# 2) 配置数据库连接（推荐 .env）
cp .env.example .env

# 3) 把 index.html / api.php / schema.sql 等文件放到站点根目录
#    浏览器打开站点即可
```

首次登录可用默认账号：

| 账号 | 角色 |
| :--- | :--- |
| `admin` | 超级管理员 |
| `account_a` / `account_b` | 普通子账号 |

> 🔐 **部署后第一件事**：进入 **管理员控制台 → 用户名与模板管理**，把 `admin` 改名或新增你自己的管理员账号。改名后原 `admin` 将无法登录。

---

## 4. 项目结构

```
├── index.html        # 前端单页应用（明暗主题 + 中英 i18n + 管理员控制台）
├── api.php           # 生产后端：PHP + MySQL，含闲鱼定时发布全部逻辑
├── schema.sql        # MySQL 建表与种子数据（5 张表）
├── server.ts         # 本地开发用的 Node.js 内存后端（不接数据库）
├── .htaccess         # Apache 访问控制：拦截 .env / .git / .sql / .md
├── .env.example      # 环境变量示例
├── BAOTA_TUTORIAL.md # 宝塔面板部署教程（中英双语）
├── package.json      # Node.js 依赖与脚本
├── tsconfig.json     # TypeScript 配置
└── vite.config.ts    # 前端构建配置
```

**npm 脚本**：`npm run dev`（本地开发）· `npm run lint`（类型检查）· `npm run build`（构建到 `dist/`）· `npm start`（运行构建产物）

---

## 5. 生产部署（PHP + MySQL）

### 5.1 环境要求

| 项目 | 要求 |
| :--- | :--- |
| PHP | 7.4 或 8.0/8.1/8.2 |
| 扩展（必需） | `pdo_mysql`、`curl` |
| 扩展（建议） | `mbstring`（中文按字符截断，缺失时有降级处理） |
| MySQL | 5.7 或 8.0 |
| Web 服务器 | Nginx 或 Apache |

自检：`php -m | grep -E 'pdo_mysql|curl|mbstring'`

### 5.2 导入数据库

```bash
mysql -u 用户名 -p 你的库名 < schema.sql
```

> `schema.sql` **不会**自动建库，请先手动创建数据库再导入。

导入后得到 5 张表：`games`、`templates`、`published_logs`、`xianyu_config`、`xianyu_publish_logs`。
旧版本升级无需手工改表——`api.php` 首次访问会自动补齐缺失的表与列。

### 5.3 配置数据库连接

**方式 A（推荐）**：复制 `.env.example` 为 `.env` 并填写：

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=xianyu_db
DB_USER=你的数据库用户
DB_PASS=你的数据库密码
```

`api.php` 内置零依赖 `.env` 解析，自动优先读取。

**方式 B**：直接编辑 `api.php` **第 90–94 行**的默认值：

```php
$db_host = getenv('DB_HOST') ?: '127.0.0.1';
$db_port = getenv('DB_PORT') ?: '3306';
$db_name = getenv('DB_NAME') ?: 'xianyu_db';
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'root';
```

> ⚠️ 上一版文档标注的行号有误，实际位置是 **90–94 行**。

### 5.4 校验部署

| 检查项 | 预期 |
| :--- | :--- |
| 打开站点根路径 | 正常显示前端页面 |
| 访问 `api.php?action=get_games` | 返回 JSON |
| 访问 `/.env` | 404（被 `.htaccess` 拦截） |

若你在 Nginx 上，`.htaccess` 不生效，请手动在 `server{}` 中加入：

```nginx
autoindex off;
location ~ /\.                        { deny all; }
location ~* \.(sql|md|log|ts|example)$ { deny all; }
location ^~ /node_modules/            { deny all; }
```

---

## 6. 定时自动发布到闲鱼

对接 [xianyu-auto-reply](https://github.com/zhinianboke/xianyu-auto-reply)：一个 ShopTextManager 账号对应一个闲鱼账号，管理员为每个账号配置对接信息与发布时间，Cron 每分钟触发一次调度。

### 6.1 工作机制（重要）

**触发链路**：Cron 每分钟请求 `run_scheduled_publish` → 找出所有 `publish_enabled = 1` 的账号 → 当前北京时间（精确到分钟）命中该账号配置的发布时间点 → 选商品 → 发布。

**商品如何选取、会不会重复发**：

| 问题 | 实际行为 |
| :--- | :--- |
| 选题顺序 | 按 `games.id` **升序**取第一个未发布的，即「先入库先发」 |
| 是否随机 | **否**，严格顺序，发一个剔除一个 |
| 已发布过的商品 | **跳过**。依据 `published_logs` 表的 `(账号, 商品)` 记录，发布成功后写入 |
| 同一商品、不同账号 | 可以各自发布（去重是按账号维度） |
| 全部发完 | 状态转 `idle`，**不会循环重发** |
| 想重新发一遍 | 在「单个商品管理」的「已归档隐藏的商品」区**取消隐藏**（回到待发布列表），或用 `reset_account_games` 清空该账号归档 |

**两层去重，缺一不可**：

1. **同分钟去重** —— 比较 `last_publish_at` 是否为「今天 + 这一分钟」，防止 Cron 意外重复触发。
2. **商品去重** —— `published_logs` 持久化记录，跨天、跨重启都有效。

**其他保障**：

- **账号级并发锁**：发布前取 MySQL 咨询锁 `GET_LOCK`，上一次没跑完则本次跳过，避免重叠调用重复发同一商品。
- **墙钟预算 50 秒**：单个慢账号不会饿死后面的账号，超时的账号下一分钟继续。
- **异常隔离**：单个账号抛异常不影响其他账号（`catch (Throwable)`）。

> 💡 **种子数据的小陷阱**：`schema.sql` 预置了 `account_a` 已发布 1、2 号商品。所以 `account_a` 的首次定时发布会从 **3 号商品**开始。想从 1 号开始，先清空该账号的归档记录。

### 6.2 前置条件

- 已部署并运行 [xianyu-auto-reply](https://github.com/zhinianboke/xianyu-auto-reply)
- 在其「个人设置 → 分销管理」取得**分销秘钥 secret_key**
- 已在其中添加并登录对应的闲鱼账号
- PHP 已启用 `curl` 与 `mbstring`

### 6.3 配置步骤

1. 登录管理员 → **管理员控制台 → 定时发布** 页签
2. 点「刷新配置列表」，在目标账号行点「配置」
3. 填写对接信息：
   - **服务地址**：xianyu-auto-reply 的访问地址，**含端口、不含 `/api/v1`**，如 `http://127.0.0.1:8080`
   - **分销秘钥**：上一步取得的 secret_key
   - 点「测试连接」，系统会拉取可用闲鱼账号列表，从下拉框选择
4. 定时设置：勾选「启用定时自动发布」，填**发布时间**（多个用英文逗号分隔，如 `09:00,12:00,18:00`）
5. 商品参数：售价、原价、库存、宝贝所在地、运费方式
6. 图片来源：自动搜图 / 使用商品封面图 URL / 自定义图片 URL
7. **平台分类**（推荐点「智能获取分类」）：
   - 闲鱼要求 **频道分类 ID + 频道分类名称 + 淘宝分类 ID** 三者同时存在，缺任一都会被拒绝并报「请先根据商品描述重新选择完整的平台商品分类」。
   - 点「智能获取分类」从 xianyu-auto-reply 拉取候选，选中后字段自动填好。
   - 勾选「每单按商品描述自动匹配分类」后，即使此处留空，发布时也会按描述自动获取并回写；不勾选则固定用手工填写的分类。
8. 点「保存配置」

> 页签名称是「**定时发布**」，配置弹窗标题是「闲鱼定时发布配置」。

### 6.4 配置 Cron

```bash
# 编辑 crontab
crontab -e

# 每分钟触发一次（把域名换成你的实际地址）
* * * * * curl -s "https://你的域名/api.php?action=run_scheduled_publish" > /dev/null 2>&1
```

> **宝塔面板**：在「计划任务」里添加「访问 URL」类型任务，周期选「每 1 分钟」，URL 填上面的地址。每分钟访问是安全的——系统内部按时间点精确匹配，并自带去重与并发锁。
>
> **子目录部署**时 URL 需带上子目录，例如 `https://你的域名/ShopTextManager/api.php?action=run_scheduled_publish`。

### 6.5 手动发布与日志

在「定时发布」页签：

- **手动发布指定商品**：选账号和商品立即发布，适合先跑通再挂定时
- **手动触发定时调度**：立即执行一次调度检查（**只有当前时间命中配置的时间点才会真的发布**，不命中则静默跳过）

「发布日志」页签可查看：发布时间、账号、商品名、触发方式（定时/手动）、状态、结果消息与闲鱼商品链接。

### 6.6 对外接口调用顺序

| 步骤 | 接口 | 说明 |
| :--- | :--- | :--- |
| 1 | `POST /api/v1/external/enabled-accounts` | 测试连接时获取闲鱼账号列表 |
| 2 | `POST /api/v1/external/category/recommend` | 分类不完整或开启自动匹配时，获取分类推荐 |
| 3 | `POST /api/v1/external/publish/media` | 上传商品图片，换取 media_id |
| 4 | `POST /api/v1/external/publish/single` | 发布单品到闲鱼 |

### 6.7 相关数据表

| 表 | 说明 |
| :--- | :--- |
| `xianyu_config` | 每账号的对接配置（服务地址、秘钥、账号、发布时间、价格、分类等） |
| `xianyu_publish_logs` | 每次发布的执行日志（状态、消息、商品链接、接口响应） |
| `published_logs` | 已发布归档 `(账号, 商品)`，**定时发布的去重依据** |

### 6.8 常见问题

| 症状 | 原因 | 处理 |
| :--- | :--- | :--- |
| 「请先根据商品描述重新选择完整的平台商品分类」 | 分类三件套缺项 | 点「智能获取分类」选择，或勾选自动匹配 |
| 「未能获取商品图片」 | 无封面图且联网搜图失败 | 用「手动发布」弹窗里的「测试联网搜图」自查 |
| 所有图源都失败 | 服务器无法出网或 `curl` 未启用 | `curl -I https://www.bing.com` 验证；检查 PHP 扩展 |
| 只有部分图源成功 | 搜索引擎对服务器 IP 限流 | 正常，系统自动降级到下一个可用图源 |
| 媒体上传失败 | xianyu-auto-reply 不可达、秘钥/账号不匹配或账号掉线 | 核对服务地址与秘钥，确认闲鱼账号已登录 |
| 发布失败 code=40009 | 闲鱼侧异常 | 查 xianyu-auto-reply 日志，多为 Cookie 过期需重新登录 |
| 定时任务不触发 | Cron 未配置或 URL 不对 | 手动 `curl` 一次接口看是否可访问、是否返回 JSON |
| 「该账号上一次发布仍在执行中」 | 并发锁生效，上次未跑完 | 正常保护行为，无需处理 |
| 重复发布 | `published_logs` 被手工清除 | 该表是去重依据，请勿随意清空 |

---

## 7. 安全建议

- **改掉默认 `admin`**：部署后立刻改名或新增专属管理员。
- **`.env` 必须不可外网访问**：仓库自带 `.htaccess`（Apache 自动生效），Nginx 需按 5.4 手动配置。
- **`api.php` 没有服务端鉴权**（设计如此），请：
  - 用防火墙/反向代理限制 `run_scheduled_publish` 只能本机 Cron 调用；
  - 全站启用 HTTPS（宝塔可免费申请 Let's Encrypt）。
- **定期备份数据库**，尤其是 `published_logs`（丢了会导致重复发布）。

---

## 8. 常见问题速查

| 问题 | 处理 |
| :--- | :--- |
| 数据库连接失败 `SQLSTATE[HY000] [2002]` | 检查 MySQL 是否运行，核对 `.env` / `api.php` 的账号密码 |
| 操作报「接口异常」 | 确认 `pdo_mysql` 已启用，`api.php` 权限 644 |
| 页面打不开、500 | 检查 `.htaccess` 是否被主机支持；不支持时删掉它并在 Nginx 层配置 |
| 中文乱码/截断异常 | 安装 `mbstring` 扩展 |
| 改前端不生效 | 清除浏览器缓存；确认访问的是 PHP 站点而不是旧的 Node 端口 |

---

<a id="en"></a>
# 🇺🇸 English Documentation

## 1. Overview

ShopTextManager turns a product list into publish-ready copy — and can publish it to Xianyu automatically on a schedule. Built for cross-border sellers, second-hand merchants, and digital/game-package resellers.

It follows a **Preset-First Architecture**: the **"🎮 Steam Game Package / Virtual Goods"** preset ships enabled, so it works out of the box. Switch to **"📱 Digital & 3C"**, **"👗 Fashion & Vintage"**, or **"⚙️ General / Custom"** in one click from the Admin Console.

| Mode | Purpose | Persistence |
| :--- | :--- | :--- |
| **PHP + MySQL** (`api.php`) | **Production — the only supported mode** | MySQL, durable |
| **Node.js** (`server.ts`) | Local frontend dev without PHP | In-memory, **lost on restart, cannot publish** |

> ⚠️ The Node.js backend has no database and cannot call Xianyu. Use PHP + MySQL in production.

---

## 2. Key Features

- **🌙 Dark / Light theme** and **🌐 Chinese / English** toggle, persisted in localStorage.
- **🛡️ Admin role management** with a "keep at least one admin" safety guard; create / rename / delete accounts (rename & delete cascade to Xianyu config and logs).
- **🎯 Scene presets**: 4 industry profiles; switching updates column labels, input hints, copy variables, and image-search keywords.
- **📋 One-click copy** and **batch page export** of generated descriptions.
- **🖼️ Automatic online image search** — no manual cover URLs needed: Bing → DuckDuckGo → Wikimedia, each retried with CN/EN keywords, cached per keyword for 6 hours.
- **⏰ Scheduled auto-publish** to Xianyu via [xianyu-auto-reply](https://github.com/zhinianboke/xianyu-auto-reply).

### Storage layers

| Layer | Medium | Contents |
| :--- | :--- | :--- |
| Business DB | MySQL | Products, templates, publish archive, roles |
| In-memory | Node.js process | Local dev fixtures, reset on restart |
| UI preferences | localStorage | Theme, language, active account Key (**no business data**) |

---

## 3. Quick Start (5 minutes)

```bash
# 1) Import the schema
mysql -u root -p YOUR_DB < schema.sql

# 2) Configure the DB connection (recommended)
cp .env.example .env

# 3) Place index.html / api.php / schema.sql in your site root, then open it
```

Default accounts:

| Account | Role |
| :--- | :--- |
| `admin` | Super admin |
| `account_a` / `account_b` | Regular users |

> 🔐 **First thing after deployment**: go to **Admin Console → User & Template Manager** and rename `admin` or add your own admin account. Once renamed, the old `admin` login is rejected.

---

## 4. Project Structure

```
├── index.html        # SPA frontend (dark mode, i18n, admin console)
├── api.php           # Production backend: PHP + MySQL, all Xianyu logic
├── schema.sql        # MySQL schema + seed data (5 tables)
├── server.ts         # Node.js in-memory backend for local dev (no DB)
├── .htaccess         # Apache access control: blocks .env / .git / .sql / .md
├── .env.example      # Environment variable template
├── BAOTA_TUTORIAL.md # aaPanel / Baota deployment guide (CN & EN)
├── package.json      # Node.js dependencies & scripts
├── tsconfig.json     # TypeScript config
└── vite.config.ts    # Frontend build config
```

**npm scripts**: `npm run dev` (dev server) · `npm run lint` (type check) · `npm run build` (build to `dist/`) · `npm start` (run build output)

---

## 5. Production Deployment (PHP + MySQL)

### 5.1 Requirements

| Item | Requirement |
| :--- | :--- |
| PHP | 7.4 or 8.0 / 8.1 / 8.2 |
| Extensions (required) | `pdo_mysql`, `curl` |
| Extensions (recommended) | `mbstring` (char-safe Chinese truncation; degrades gracefully) |
| MySQL | 5.7 or 8.0 |
| Web server | Nginx or Apache |

Verify with: `php -m | grep -E 'pdo_mysql|curl|mbstring'`

### 5.2 Import the database

```bash
mysql -u USER -p YOUR_DB < schema.sql
```

> `schema.sql` does **not** create the database. Create it first, then import.

This creates 5 tables: `games`, `templates`, `published_logs`, `xianyu_config`, `xianyu_publish_logs`.
Upgrading from an older version needs no manual migration — `api.php` self-heals missing tables and columns on first request.

### 5.3 Configure the DB connection

**Option A (recommended)**: copy `.env.example` to `.env`:

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=xianyu_db
DB_USER=YOUR_DB_USER
DB_PASS=YOUR_DB_PASSWORD
```

`api.php` ships a zero-dependency `.env` parser and reads it automatically.

**Option B**: edit the defaults at **lines 90–94** of `api.php`:

```php
$db_host = getenv('DB_HOST') ?: '127.0.0.1';
$db_port = getenv('DB_PORT') ?: '3306';
$db_name = getenv('DB_NAME') ?: 'xianyu_db';
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'root';
```

> ⚠️ Earlier versions of this document cited the wrong line numbers. The real location is **lines 90–94**.

### 5.4 Verify the deployment

| Check | Expected |
| :--- | :--- |
| Open site root | Frontend renders |
| `api.php?action=get_games` | Returns JSON |
| `/.env` | 404 (blocked by `.htaccess`) |

On Nginx, `.htaccess` is ignored — add this to your `server{}` block:

```nginx
autoindex off;
location ~ /\.                        { deny all; }
location ~* \.(sql|md|log|ts|example)$ { deny all; }
location ^~ /node_modules/            { deny all; }
```

---

## 6. Scheduled Auto-Publish to Xianyu

Integrates [xianyu-auto-reply](https://github.com/zhinianboke/xianyu-auto-reply). One ShopTextManager account maps to one Xianyu account; admins configure credentials and publish times per account, and a cron job triggers the scheduler every minute.

### 6.1 How It Works (important)

**Trigger chain**: cron calls `run_scheduled_publish` every minute → finds every account with `publish_enabled = 1` → checks whether the current Beijing time (minute precision) matches one of that account's configured times → picks a product → publishes.

**Product selection & duplicate handling**:

| Question | Actual behavior |
| :--- | :--- |
| Selection order | First unpublished product by `games.id` **ascending** — oldest first |
| Random? | **No.** Strictly sequential; each published item leaves the queue |
| Already published? | **Skipped.** Based on `published_logs` `(account, product)` rows written after each success |
| Same product, different account | Allowed — dedup is per account |
| All products published? | Status becomes `idle`; **no loop, no republish** |
| Want to republish? | **Un-hide** it in Product Manager's "Archived / Hidden" area, or clear that account's archive via `reset_account_games` |

**Two layers of dedup, both required**:

1. **Same-minute guard** — compares `last_publish_at` against "today + this minute", protecting against accidental duplicate cron runs.
2. **Product dedup** — `published_logs` persists across days and restarts.

**Other safeguards**:

- **Per-account advisory lock** (`GET_LOCK`) — if the previous run is still going, this one is skipped.
- **50-second wall-clock budget** — a slow account can't starve the ones behind it; skipped accounts resume next minute.
- **Exception isolation** — one account's failure never breaks the loop (`catch (Throwable)`).

> 💡 **Seed-data gotcha**: `schema.sql` pre-marks products 1 and 2 as published for `account_a`, so its first scheduled publish starts at **product 3**. Clear that account's archive if you want to start from 1.

### 6.2 Prerequisites

- A running [xianyu-auto-reply](https://github.com/zhinianboke/xianyu-auto-reply) instance
- A **secret_key** from its "Personal Settings → Distribution Management"
- At least one Xianyu account added and logged in there
- PHP `curl` and `mbstring` enabled

### 6.3 Setup Steps

1. Log in as admin → **Admin Console → Scheduled Publish** tab
2. Click "Refresh" to load accounts, then "Configure" on the target row
3. Fill in the connection details:
   - **Server URL**: xianyu-auto-reply address, **with port, without `/api/v1`** — e.g. `http://127.0.0.1:8080`
   - **Secret key**: the secret_key from step 6.2
   - Click **Test Connection**; the account list loads, then pick the Xianyu account
4. Schedule: enable scheduled publishing and set **publish times** (comma-separated, e.g. `09:00,12:00,18:00`)
5. Product parameters: price, original price, stock, item location, shipping method
6. Image source: auto search / product cover URL / custom image URL
7. **Platform category** (use "Fetch Category"):
   - Xianyu requires **channel category ID + channel category name + Taobao category ID** together. Missing any one is rejected with "请先根据商品描述重新选择完整的平台商品分类".
   - "Fetch Category" pulls candidates from xianyu-auto-reply and fills the fields for you.
   - With "Auto-match category per item" enabled, publishing auto-resolves the category from the description and writes it back; otherwise the manual values are used as-is.
8. Click **Save**

> The tab is named **"定时发布 / Scheduled Publish"**; the modal title is "闲鱼定时发布配置 / Xianyu Scheduled Publish Config".

### 6.4 Set Up Cron

```bash
crontab -e

# Every minute (replace with your real URL)
* * * * * curl -s "https://your-domain/api.php?action=run_scheduled_publish" > /dev/null 2>&1
```

> **aaPanel users**: add a scheduled task of type "Visit URL", period "every 1 minute", URL as above. Hitting it every minute is safe — the scheduler matches configured times precisely and deduplicates internally.
>
> If deployed in a **subdirectory**, include it: `https://your-domain/ShopTextManager/api.php?action=run_scheduled_publish`.

### 6.5 Manual Publishing & Logs

In the "Scheduled Publish" tab:

- **Manual Publish** — pick an account and product to publish immediately; use it to validate the pipeline before enabling the schedule
- **Trigger Scheduler Now** — runs one scheduler pass (it **only publishes if the current minute matches a configured time**, otherwise it silently skips)

The "Publish Logs" tab shows time, account, product, trigger type (scheduled/manual), status, message, and the resulting Xianyu item URL.

### 6.6 Outbound API Call Sequence

| Step | Endpoint | Purpose |
| :--- | :--- | :--- |
| 1 | `POST /api/v1/external/enabled-accounts` | Fetch Xianyu accounts (Test Connection) |
| 2 | `POST /api/v1/external/category/recommend` | Category recommendation (when incomplete or auto-match on) |
| 3 | `POST /api/v1/external/publish/media` | Upload image, get media_id |
| 4 | `POST /api/v1/external/publish/single` | Publish the item |

### 6.7 Relevant Tables

| Table | Purpose |
| :--- | :--- |
| `xianyu_config` | Per-account connection config (URL, secret, account, times, price, category, …) |
| `xianyu_publish_logs` | Execution log per publish (status, message, item URL, raw response) |
| `published_logs` | Publish archive `(account, product)` — **the dedup source of truth** |

### 6.8 Troubleshooting

| Symptom | Cause | Fix |
| :--- | :--- | :--- |
| "请先根据商品描述重新选择完整的平台商品分类" | Category trio incomplete | Click "Fetch Category", or enable auto-match |
| "未能获取商品图片" / no image | No cover URL and online search failed | Use "Test Image Search" in the manual-publish dialog |
| All image providers fail | No outbound network or `curl` missing | `curl -I https://www.bing.com`; check PHP extensions |
| Only some providers succeed | Search engines rate-limiting your IP | Normal — it falls through to the next provider |
| Media upload fails | xianyu-auto-reply unreachable, bad secret/account, or account offline | Verify URL and secret; re-login the Xianyu account |
| Publish fails with code=40009 | Xianyu-side error | Check xianyu-auto-reply logs; usually an expired cookie |
| Schedule never fires | Cron missing or wrong URL | `curl` the endpoint manually and confirm it returns JSON |
| "Previous publish still running" | Advisory lock is holding | Protective behavior, no action needed |
| Duplicate publishing | `published_logs` was cleared manually | That table is the dedup source — don't wipe it |

---

## 7. Security Notes

- **Rename the default `admin`** right after deployment.
- **`.env` must not be publicly reachable** — `.htaccess` covers Apache; configure Nginx manually per 5.4.
- **`api.php` has no server-side authentication** (by design), so:
  - Restrict `run_scheduled_publish` to localhost cron via firewall / reverse proxy;
  - Enable HTTPS site-wide (aaPanel offers free Let's Encrypt).
- **Back up the database regularly**, especially `published_logs` — losing it causes republishing.

---

## 8. FAQ / Troubleshooting

| Issue | Fix |
| :--- | :--- |
| `SQLSTATE[HY000] [2002]` | Check MySQL is running and credentials in `.env` / `api.php` |
| "Operation failed / API exception" | Ensure `pdo_mysql` is enabled and `api.php` is `644` |
| Site returns 500 | Check whether your host supports `.htaccess`; if not, remove it and configure at the Nginx level |
| Broken/truncated Chinese text | Install the `mbstring` extension |
| Frontend changes not showing | Clear browser cache; make sure you're hitting the PHP site, not a stale Node port |

---

## 📜 License

MIT License. Free for personal and commercial use.
