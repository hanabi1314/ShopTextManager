# ShopTextManager - 多平台商品文案快速发布与管理系统 🚀
## Multi-Platform Product Listing & Copywriting Management System

[中文文档](#-中文文档) | [English Documentation](#-english-documentation)

---

> 🚀 **赞助推荐 / Sponsor**:
> **NodeHK VPN** — 专为跨境电商、外贸加速、海外平台运营及游戏节点打造的高速稳定 VPN / 节点服务。
> 官方网站：👉 [**www.nodehk.shop**](https://www.nodehk.shop/#/auth?invite=uu1fzn9t)

---

<a id="-中文文档"></a>
## 🇨🇳 中文文档

**ShopTextManager** 是一个专为卖家、跨境电商、二手商家、虚拟商品/游戏安装包卖家及多账号团队打造的高效商品快速发布、动态文案模板套用、多语言切换与一键搜图管理系统。

核心采用 **“开箱即用预设 + 渐进式扩展” (Preset-First Architecture)** 架构：默认开启 **“🎮 Steam游戏安装包/虚拟商品”** 预设，打开即用、0配置点击；同时支持一秒切换为 **“📱 数码二手/3C”**、**“👗 服饰潮牌/中古”** 或 **“⚙️ 通用电商/自定义”** 模式，全面适配各类商品发布需求！

---

### 🌟 核心设计理念与新功能特性

#### 1. 🌙 灰暗/明亮模式 (Dark Mode) & 🌐 中英文双语一键切换
- **主题切换**：支持点击顶部导航栏【暗黑模式 / 明亮模式】按钮，全局极速响应深色或浅色主题，自动持久化存储用户的视觉偏好。
- **中英文国际化 (i18n)**：全系统界面及动态表格原生支持中英文（Chinese / English）一键无缝切换，方便跨境外贸团队及国际化商家使用。

#### 2. 🛡️ 完善的管理员权限管理与安全防封禁
- **设置/取消管理员 (Admin Management)**：超级管理员可在后台直接设定或取消任意子账号的管理员权限，且系统具备**最少保留一个管理员 (Safety Guard)** 逻辑，防止因误操作导致全站失控。
- **重名防护与账号清理**：后台支持创建新账号（可选是否设为管理员）、重命名账号或彻底删除离职账号。
- **🔐 修改默认管理员 Username 提醒（重要）**：系统初始管理员账号为 `admin`。系统部署成功后，请务必立即在 **【管理员控制台】 -> 【用户名与模板管理】** 中修改 `admin` 账号 Key（用户名），或重新添加您的专属管理员账号。更名后，系统将拒绝使用原 `admin` 登录，保障系统权限安全。

#### 3. 🎯 默认开箱即用 (Preset-First 架构)
- 系统默认开启 **“🎮 Steam游戏安装包/虚拟商品模式”**。
- 打开即可直接使用，默认变量统一归一化为 `{{VAR_1}}` (主名称/中文), `{{VAR_2}}` (副名称/英文), `{{VAR}}` (完整组合名)。
- 支持在 Admin 管理员控制台中一键切换 4 大预设套件 (游戏/虚拟, 数码/3C, 服饰/中古, 通用/自定义)。

#### 4. 🚀 一键批量复制文案与多引擎一键搜图
- **一键复制**：点击“复制文案”直接将生成的对应商品描述复制到剪贴板，并带有高亮反馈提示。
- **批量复制**：支持点击“批量复制本页文案”，一次性导出当前页面全部展示商品的描述。
- **多引擎搜图**：内置 Bing、百度图片、Google 搜图引擎快捷入口，自动匹配最佳搜图词。

#### 5. 💾 数据存储架构与隔离机制 (Storage Isolation & Configuration)
系统采用职责清晰的分层存储架构，**彻底避免存储混淆或数据误用**：

| 存储层级 | 存储介质 | 存储内容 | 隔离与配置说明 |
| :--- | :--- | :--- | :--- |
| **核心业务库** | MySQL 数据库 (生产模式) | 商品数据、自定义文案模板、归档发布日志、管理员账号信息 | 核心数据**统一保存在数据库**中，不依赖浏览器。通过 `.env` 或 `api.php` 设置数据库连接。 |
| **内存体验库** | Node.js 内存 (开发/预览模式) | 快速体验测试数据 | 用于零配置本地开发或 Node.js 环境体验，重启服务即恢复初始预设。 |
| **前端偏好层** | 浏览器 LocalStorage | 深色/浅色主题状态、当前登录账号 KEY、界面语言偏好 (i18n) | **仅用于保存 UI 界面偏好**，绝不混入核心商品数据。即使清除浏览器缓存也不影响数据库数据。 |

##### ⚙️ 数据库设置方式：
- **方式 A (环境变量配置，推荐)**：拷贝 `.env.example` 为 `.env`，修改 `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` 参数。
- **方式 B (直接修改配置)**：直接编辑 `api.php` 第 18~22 行的数据库连接变量。

---

### 🛠️ 项目文件架构

```
├── index.html        # 前端单页应用 (支持 Dark Mode & 中英文 i18n 切换 & 赞助展示 & 定时发布配置)
├── server.ts         # Node.js Express 内存后端服务 (支持本地开发与 Live Preview)
├── api.php           # 生产环境 PHP RESTful API 后端 (支持 MySQL 交互 & 闲鱼定时发布)
├── schema.sql        # MySQL 数据库建表与初始化结构脚本 (含定时发布相关表)
├── BAOTA_TUTORIAL.md # 宝塔面板零基础一键部署教程 (中英文)
├── package.json      # Node.js 项目依赖与运行脚本
├── tsconfig.json     # TypeScript 配置文件
└── .env.example      # 环境变量声明示例
```

---

### 🚀 部署与数据库安装指南

> 💡 **宝塔 Linux 面板 (aaPanel) 用户？**
> 我们为你准备了零基础图文安装指南：👉 [**点击查看宝塔面板一键安装与部署教程 (BAOTA_TUTORIAL.md)**](./BAOTA_TUTORIAL.md)

#### 1. 导入数据库（生产环境 PHP + MySQL）
在服务器 MySQL 数据库中执行 `schema.sql` 脚本：
```bash
mysql -u root -p < schema.sql
```

#### 2. 配置 PHP 数据库连接
打开 `api.php` 文件，修改顶部数据库配置：
```php
$db_host = '127.0.0.1';
$db_port = '3306';
$db_user = 'root';
$db_pass = 'your_password';
$db_name = 'xianyu_db';
```

#### 3. 本地开发与测试运行 (Node.js 环境)
安装依赖并启动开发服务器：
```bash
npm install
npm run dev
```
访问 `http://localhost:3000`，输入 `admin` 体验超级管理员控制台！

---

### ⏰ 定时自动发布商品到闲鱼 (v2.0 新功能)

ShopTextManager 支持对接 [xianyu-auto-reply](https://github.com/hanabi1314/xianyu-auto-reply) 项目，实现**定时自动发布商品到闲鱼**。每个 ShopTextManager 用户对应一个闲鱼账号，管理员可在后台为每个用户配置 xianyu-auto-reply 对接信息、每日发布时间等参数。

#### 工作原理

1. 管理员在 ShopTextManager 后台为每个用户配置 xianyu-auto-reply 服务地址、分销秘钥(secret_key)、闲鱼账号 ID、发布时间、价格等参数
2. 服务器配置 Cron 定时任务，每分钟调用 ShopTextManager 的 `run_scheduled_publish` 接口
3. 到达设定时间时，系统自动从用户待发布列表中选取下一个商品
4. 获取商品图片：**优先使用封面图 URL；无封面图时自动联网搜索图片**（依次尝试 Bing → DuckDuckGo → Wikimedia，每个图源再用中英文关键词重试，结果按关键词缓存 6 小时。因此绝大多数商品无需手动设置封面图）
5. 将图片上传至 xianyu-auto-reply 服务器获取 media_id
6. 调用 xianyu-auto-reply 的公开单品发布 API 发布商品到闲鱼
7. 发布成功后自动将该商品标记为已发布（隐藏），并记录发布日志

#### 前置条件

- 已部署运行中的 [xianyu-auto-reply](https://github.com/hanabi1314/xianyu-auto-reply) 服务
- 在 xianyu-auto-reply 的「个人设置 → 分销管理」中获取了**分销秘钥 (secret_key)**
- 已在 xianyu-auto-reply 中添加了对应的闲鱼账号并登录
- **PHP 扩展要求**：`curl`（调用 xianyu-auto-reply 接口）与 `mbstring`（中文截断）
  - 缺失时接口会返回明确中文提示而非报错堆栈，可用 `php -m | grep -E 'curl|mbstring'` 检查
  - 宝塔面板可在「软件商店 → PHP 设置 → 安装扩展」中一键安装这两个扩展

#### 配置步骤

##### 第一步：在 ShopTextManager 后台配置用户闲鱼设置

1. 登录管理员账号，打开**管理员控制台**，切换到 **「定时发布」** 选项卡
2. 点击「刷新配置列表」加载所有用户
3. 点击用户行的 **「配置」** 按钮，在弹出模态框中填写：
   - **服务地址**：xianyu-auto-reply 的访问地址（含端口，不含 `/api/v1`），如 `http://127.0.0.1:8080`
   - **分销秘钥**：在 xianyu-auto-reply 个人设置-分销管理中获取
   - 点击 **「测试连接」** 按钮，系统会调用 xianyu-auto-reply 获取可用闲鱼账号列表
   - 在下拉框中选择对应的闲鱼账号
4. 设置定时发布参数：
   - 勾选 **「启用定时自动发布」**
   - 填写 **每日发布时间**，多个时间点用英文逗号分隔，如 `09:00,12:00,18:00`
5. 设置商品发布参数：售价、原价、库存、宝贝所在地、运费方式
6. 选择图片来源：自动搜图 / 使用商品封面图 URL / 自定义图片 URL
7. （可选）设置平台分类信息，留空则系统自动通过 xianyu-auto-reply 获取分类推荐
8. 点击 **「保存配置」**

##### 第二步：为商品设置封面图（可选但推荐）

在管理员控制台的「单个商品管理」选项卡中，可以为每个商品设置封面图 URL。设置后定时发布时将优先使用该封面图，避免搜图不确定性。

> 💡 也可通过 `api.php?action=update_game_cover` 接口批量设置。

##### 第三步：配置服务器 Cron 定时任务

在服务器上配置 Cron，每分钟调用 ShopTextManager 的定时调度接口：

```bash
# 编辑 crontab
crontab -e

# 添加以下行（将 URL 替换为你的 ShopTextManager 实际访问地址）
* * * * * curl -s "http://127.0.0.1/ShopTextManager/api.php?action=run_scheduled_publish" > /dev/null 2>&1
```

> ⚠️ **宝塔面板用户**：可在宝塔「计划任务」中添加「URL 定时任务」，设置为每分钟执行一次，URL 填 `http://127.0.0.1/ShopTextManager/api.php?action=run_scheduled_publish`

#### 手动发布（测试用）

在「定时发布」选项卡中：
- 点击 **「手动发布指定商品」**：选择用户和待发布商品，立即发布
- 点击 **「手动触发定时调度」**：立即执行一次定时检查（仅在设定时间点匹配时才发布）

#### 查看发布日志

切换到 **「发布日志」** 选项卡，可查看每次定时/手动发布的详细日志，包括：
- 发布时间、用户名、商品名称
- 触发方式（定时/手动）
- 发布状态（成功/失败）
- 结果消息和闲鱼商品链接

#### 数据库表说明

定时发布功能新增了两张数据库表（已包含在 `schema.sql` 中，旧版部署会自动创建）：

| 表名 | 说明 |
| :--- | :--- |
| `xianyu_config` | 每个用户的 xianyu-auto-reply 对接配置（服务地址、秘钥、账号、发布时间、价格等） |
| `xianyu_publish_logs` | 闲鱼发布执行日志（每次发布的状态、消息、商品链接等） |

#### xianyu-auto-reply 公开 API 调用流程

ShopTextManager 定时发布时会依次调用以下 xianyu-auto-reply 公开接口：

| 步骤 | API | 说明 |
| :--- | :--- | :--- |
| 1 | `POST /api/v1/external/enabled-accounts` | 测试连接时获取闲鱼账号列表 |
| 2 | `POST /api/v1/external/category/recommend` | 获取平台分类推荐（未预设分类时自动调用） |
| 3 | `POST /api/v1/external/publish/media` | 上传商品图片，获取 media_id |
| 4 | `POST /api/v1/external/publish/single` | 发布单品到闲鱼 |

> 📖 详细 API 文档请参考 [xianyu-auto-reply README](https://github.com/hanabi1314/xianyu-auto-reply) 的「公开商品发布相关接口」章节。

#### 联网搜图自检

发布依赖"自动联网搜图"（依次尝试 **Bing → DuckDuckGo → Wikimedia**，结果按关键词缓存 6 小时）。
若发布报「未能获取商品图片」，可在后台 **【手动发布商品到闲鱼】** 弹窗中点 **【测试联网搜图】**，
或直接调用接口排查：

```bash
curl -X POST "http://your-domain/api.php?action=test_image_search" \
  -H "Content-Type: application/json" -d '{"game_id": 1}'
```

返回里 `providers` 会逐个图源给出成败，`downloaded` 表示是否真的下载到了可用图片字节
（搜到 URL 不等于能用，所以这一步会实际下载校验）。

> 部署后请先确认 PHP 已启用 `curl` 扩展，且服务器能出网：`curl -I https://www.bing.com`

#### 常见问题

| 问题 | 原因 | 解决方案 |
| :--- | :--- | :--- |
| 图片下载失败 / 未能获取商品图片 | 商品无封面图且联网搜图未返回结果 | 先用「手动发布」弹窗里的 **测试联网搜图** 按钮自查；它会逐个图源给出成败，据此判断是外网不通还是图源被限 |
| 所有图源都失败 | 服务器出网被防火墙拦截，或 PHP curl 未启用 | 在服务器上执行 `curl -I https://www.bing.com` 验证出网；确认 php.ini 已启用 `curl` 扩展 |
| 只有部分图源成功 | 搜索引擎对服务器 IP 限流 | 属正常现象，系统会自动降级到下一个可用图源，无需处理 |
| 媒体上传失败 | xianyu-auto-reply 服务不可达或 secret_key/account_id 不匹配 | 检查服务地址和密钥配置，确保闲鱼账号已登录 |
| 发布失败 (code=40009) | 闲鱼接口发布异常 | 查看 xianyu-auto-reply 日志，可能是账号 Cookie 过期需重新登录 |
| 定时任务不触发 | Cron 未配置或 URL 不正确 | 检查 crontab 是否正常运行，手动 curl 测试接口是否可访问 |
| 重复发布 | published_logs 记录被清除 | 系统通过 published_logs 避免重复发布，请勿随意清除该表 |

---

<a id="-english-documentation"></a>
## 🇺🇸 English Documentation

**ShopTextManager** is an efficient multi-account product listing, dynamic copy template generator, i18n localization, and image search management system designed for cross-border e-commerce, eBay/Xianyu merchants, digital resellers, and multi-account operation teams.

It is powered by a **Preset-First Architecture**: enabled by default with the **"🎮 Steam Game Package / Virtual Goods"** scene preset for instant out-of-the-box usage. It also allows 1-second scene switching to **"📱 Digital & 3C"**, **"👗 Fashion & Vintage"**, or **"⚙️ General E-Commerce / Custom"** modes, fully adapting to any product listing workflow!

---

### 🌟 Key Features & Architecture

#### 1. 🌙 Dark Mode & 🌐 Chinese/English i18n Toggle
- **Theme Switcher**: Click the dark/light mode toggle in the top header for instant theme switching with automatic localStorage persistence.
- **Bilingual i18n**: Native support for seamless 1-click Chinese / English switching across all UI elements, dynamic modals, and tables—ideal for cross-border e-commerce teams.

#### 2. 🛡️ Robust Admin Role Management & Safety Guard
- **Grant / Revoke Admin**: Super admins can directly promote or demote any account in the full-featured Admin Console.
- **Safety Guard**: Built-in protection logic ensures that at least one admin account must remain active at all times, preventing accidental lockouts.
- **Account Operations**: Full management including creating accounts (with optional admin privileges), renaming account keys, and deleting inactive user accounts.
- **🔐 Change Default Admin Username Notice (Important)**: The system initial administrator account is `admin`. After successful deployment, please log in immediately and navigate to **"Admin Console" -> "User & Template Manager"** to change the `admin` username key or add your custom admin account. Once updated, logins with the old `admin` username will be rejected to protect your system security.

#### 3. 🎯 Preset-First Architecture (Zero Configuration Required)
- Ships with **"🎮 Steam Game Package / Virtual Goods Mode"** enabled by default.
- Standardized placeholder variables: `{{VAR_1}}` (Primary Name / Chinese), `{{VAR_2}}` (Secondary Name / English), and `{{VAR}}` (Combined Title).
- Easily switch between 4 industry scene profiles in the Admin Console (Game/Virtual, Digital/3C, Fashion/Vintage, General/Custom).

#### 4. 🚀 Single-Click Copy, Page Batch Export & Multi-Engine Image Search
- **Single-Click Copy**: Instant copy of rendered product descriptions to the clipboard with visual confirmation.
- **Batch Export**: Export copy templates for all items on the current page with a single click ("Batch Copy Page").
- **Multi-Engine Image Search**: Direct shortcuts to Google Images, Bing Images, and Baidu Images with automatically formatted search queries.

#### 5. 💾 Storage Architecture & Configuration
The system uses a strict tiered storage architecture to **prevent data confusion or accidental misuse**:

| Layer | Storage Medium | Stored Data | Isolation & Configuration |
| :--- | :--- | :--- | :--- |
| **Business DB** | MySQL Database (Production) | Products, copy templates, archive logs, user roles | Core business data is **stored centrally in MySQL**. Configured via `.env` or `api.php`. |
| **In-Memory** | Node.js Memory (Dev/Preview) | Test & demonstration data | Zero-config instant usage for local Node.js testing. Resets on server restart. |
| **UI Preferences** | Browser LocalStorage | Dark/Light theme state, active account KEY, i18n language | **Stores visual UI preferences only**. Never mixes with product business data. |

##### ⚙️ Database Configuration:
- **Option A (Environment Variables, Recommended)**: Copy `.env.example` to `.env` and set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`.
- **Option B (Direct Config)**: Edit database connection parameters directly in `api.php` (lines 84–88).

---

### 🛠️ Project File Structure

```
├── index.html        # Single-page app frontend (Dark Mode, i18n, & sponsor banner)
├── server.ts         # Express server for Node.js dev environment & Live Preview
├── api.php           # Production PHP RESTful API backend (MySQL PDO integration)
├── schema.sql        # MySQL database schema & initial data bootstrap script
├── BAOTA_TUTORIAL.md # Complete aaPanel / Baota Panel deployment guide (CN & EN)
├── package.json      # Node.js dependencies & scripts
├── tsconfig.json     # TypeScript configuration
└── .env.example      # Environment variable declarations
```

---

### 🚀 Deployment & Database Guide

> 💡 **Using aaPanel / Baota Linux Panel?**
> Check out the step-by-step visual tutorial: 👉 [**aaPanel Deployment Guide (BAOTA_TUTORIAL.md)**](./BAOTA_TUTORIAL.md)

#### 1. Database Import (Production PHP + MySQL)
Execute `schema.sql` on your MySQL server:
```bash
mysql -u root -p < schema.sql
```

#### 2. Configure PHP Database Connection
Open `api.php` and update the database settings at the top:
```php
$db_host = '127.0.0.1';
$db_port = '3306';
$db_user = 'root';
$db_pass = 'your_password';
$db_name = 'xianyu_db';
```

#### 3. Local Development & Testing (Node.js Environment)
Install dependencies and launch the dev server:
```bash
npm install
npm run dev
```
Open `http://localhost:3000` and enter `admin` to access the Super Admin Console!

---

### ⏰ Scheduled Auto-Publish to Xianyu (v2.0 New Feature)

ShopTextManager supports integration with [xianyu-auto-reply](https://github.com/hanabi1314/xianyu-auto-reply) for **scheduled automatic product publishing to Xianyu**. Each ShopTextManager user maps to one Xianyu account, with admins configuring xianyu-auto-reply connection details and daily publish times per user.

#### How It Works

1. Admin configures xianyu-auto-reply server URL, secret_key, Xianyu account ID, publish times, price, etc. for each user
2. A Cron job calls ShopTextManager's `run_scheduled_publish` API endpoint every minute
3. At the configured time, the system picks the next unpublished product for that user
4. Fetches a product image: cover_url first; when absent, it **searches online automatically** (Bing → DuckDuckGo → Wikimedia, each retried with CN/EN keywords; results cached for 6 hours). Manual cover URLs are therefore unnecessary for most products
5. Uploads the image to xianyu-auto-reply server to get a media_id
6. Calls xianyu-auto-reply's public single-publish API to publish the product
7. Marks the product as published and logs the result

#### Prerequisites

- A running [xianyu-auto-reply](https://github.com/hanabi1314/xianyu-auto-reply) service
- A **secret_key** obtained from xianyu-auto-reply's "Personal Settings → Distribution Management"
- At least one Xianyu account logged in on xianyu-auto-reply

#### Setup Steps

1. **Configure user Xianyu settings**: In Admin Console → "Scheduled Publish" tab, click "Configure" for each user
2. **Test connection**: Enter server URL and secret_key, click "Test Connection" to fetch available Xianyu accounts
3. **Set publish schedule**: Enable scheduled publishing, set daily publish times (e.g., `09:00,12:00,18:00`)
4. **Configure product parameters**: Price, address, shipping method, image source
5. **Set up Cron**: Add a crontab entry to call `api.php?action=run_scheduled_publish` every minute

```bash
* * * * * curl -s "http://your-domain/api.php?action=run_scheduled_publish" > /dev/null 2>&1
```

#### Manual Publishing & Logs

- Use "Manual Publish" to publish a specific product immediately for testing
- View publish history in the "Publish Logs" tab

---

## 📜 License
MIT License. Open source and free for commercial and personal multi-account management use.
