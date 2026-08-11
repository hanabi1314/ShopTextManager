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
├── index.html        # 前端单页应用 (支持 Dark Mode & 中英文 i18n 切换 & 赞助展示)
├── server.ts         # Node.js Express 内存后端服务 (支持本地开发与 Live Preview)
├── api.php           # 生产环境 PHP RESTful API 后端 (支持 MySQL 交互)
├── schema.sql        # MySQL 数据库建表与初始化结构脚本
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
- **Option B (Direct Config)**: Edit database connection parameters directly in `api.php` (lines 18–22).

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

## 📜 License
MIT License. Open source and free for commercial and personal multi-account management use.
