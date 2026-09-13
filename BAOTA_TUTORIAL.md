# ShopTextManager - 宝塔面板 (aaPanel) 部署与安装详细教程 🛠️
## Baota Panel Deployment & Installation Guide

本教程将引导你如何在 **宝塔 Linux 面板 (aaPanel)** 上快速部署 **ShopTextManager（商品文案管理与快速发布系统）**。

> 🚀 **赞助推荐 / Sponsor**:
> **NodeHK VPN** — 专为跨境电商、外贸及游戏节点加速打造的高速稳定 VPN 节点服务：👉 [**www.nodehk.shop**](https://www.nodehk.shop/#/auth?invite=uu1fzn9t)

系统同时支持 **PHP + MySQL (推荐生产环境)** 和 **Node.js (Express)** 两种后端运行模式。本教程重点讲解最常用、最稳定的 **PHP + MySQL 极简部署方式** 以及 **Node.js 部署方式**。

---

## 📑 目录 / Table of Contents
1. [环境准备 / Environment Setup](#1-环境准备)
2. [创建 MySQL 数据库与导入数据 / Database Creation](#2-创建-mysql-数据库与导入数据)
3. [创建网站与上传源码 / Site Setup & File Upload](#3-创建网站与上传源码)
4. [方案一：PHP + MySQL 部署模式 (推荐) / PHP Mode](#4-方案一php--mysql-部署模式-推荐)
5. [方案二：Node.js 部署模式 (备选) / Node.js Mode](#5-方案二nodejs-部署模式-备选)
6. [SSL 证书绑定与安全性设置 / SSL & Security](#6-ssl-证书绑定与安全性设置)
7. [常见问题与排错指南 / Troubleshooting](#7-常见问题与排错指南)
8. [🇺🇸 English Guide - aaPanel Deployment](#-english-guide---aapanel-deployment)

---

## 1. 环境准备

登录宝塔面板，前往 **【软件商店】**，安装以下基础软件：

- **Web 服务器**：Nginx (推荐 Nginx 1.20+)
- **数据库**：MySQL (推荐 5.7 或 8.0)
- **PHP**：PHP 7.4 或 PHP 8.0/8.1/8.2（需安装 `pdo_mysql` 扩展，默认开启）
- **PHP 扩展（必需）**：`curl` —— 「自动联网搜图上传」和调用 xianyu-auto-reply 都依赖它。
  在【软件商店】-> 已安装的 PHP -> 【安装扩展】里确认 `curl` 已勾选；缺少它发布时会提示「服务器未安装或未启用 PHP curl 扩展」。
- **PHP 扩展（建议）**：`mbstring`（用于中文按字符截断，缺失时代码有降级处理，不影响使用）
- **Node.js**（若选择 Node.js 模式）：在软件商店安装 **【Node.js 版本管理器】** 或 **【PM2 管理器】**

---

## 2. 创建 MySQL 数据库与导入数据

1. 打开宝塔面板左侧菜单 **【数据库】** -> 点击 **【添加数据库】**。
2. 填写数据库信息：
   - **数据库名**：`xianyu_db`（可自定义）
   - **用户名**：`xianyu_user`（可自定义）
   - **密码**：设置一个安全的密码（务必保存好）
   - **访问权限**：默认 `本地服务器` 即可。
3. 点击 **【确定】** 创建数据库。
4. 在数据库列表中找到刚创建的数据库，点击右侧的 **【导入】** 按钮。
5. 上传并导入项目根目录下的 `schema.sql` 文件。
6. 导入成功后，数据库中会创建 **5 张表**：`games`、`templates`、`published_logs`（基础）
   以及 `xianyu_config`、`xianyu_publish_logs`（v2.0 定时发布功能）。

> ⚠️ 如果导入报错 `Access denied` 或 `No database selected`：本脚本**不会**自动建库，
> 请确认是在【数据库】列表里选中了你的库之后再点【导入】。命令行导入请用
> `mysql -u 用户名 -p 你的库名 < schema.sql`。

---

## 3. 创建网站与上传源码

1. 打开宝塔面板左侧菜单 **【网站】** -> 点击 **【添加站点】**。
2. 填写站点信息：
   - **域名**：输入你的域名（如 `shoptext.yourdomain.com`，如果没有域名也可以输入服务器 IP 及端口）。
   - **PHP 版本**：选择已安装的 PHP 7.4 或 PHP 8.x。
   - **根目录**：系统会自动生成（例如 `/www/wwwroot/shoptext.yourdomain.com`）。
3. 点击 **【提交】** 完成建站。
4. 进入该站点的根目录，删除默认生成的 `index.html` 和 `404.html`。
5. 将本项目的全部源码上传至该站点根目录中（确保 `index.html`、`api.php` 等文件在根目录下）。

---

## 4. 方案一：PHP + MySQL 部署模式 (推荐)

PHP 模式非常省资源，适合大多数宝塔服务器环境。

### 第一步：配置数据库信息 (支持两种方式)

系统支持 **环境变量配置文件 (`.env`)** 或 **直接编辑 `api.php`**，你可以选择任意一种方式：

- **方式 A：使用 `.env` 配置文件（推荐）**
  1. 在根目录下复制 `.env.example` 并重命名为 `.env`。
  2. 打开 `.env` 文件，填写你的数据库连接参数：
     ```env
     DB_HOST=127.0.0.1
     DB_PORT=3306
     DB_NAME=xianyu_db
     DB_USER=xianyu_user
     DB_PASS=你的数据库密码
     ```
  `api.php` 已内置轻量级 `.env` 解析模块，会自动优先读取 `.env` 中的配置。

- **方式 B：直接编辑 `api.php` 默认值**
  如果在宝塔面板中习惯直接双击修改代码，可在面板文件管理器中打开 `api.php`，修改第 25~30 行的默认变量值：
  ```php
  $db_host = getenv('DB_HOST') ?: '127.0.0.1';
  $db_port = getenv('DB_PORT') ?: '3306';
  $db_name = getenv('DB_NAME') ?: 'xianyu_db';     // 宝塔创建的数据库名
  $db_user = getenv('DB_USER') ?: 'xianyu_user';   // 宝塔创建的数据库用户名
  $db_pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '你的数据库密码';
  ```
保存配置后即可生效。

### 第二步：测试与访问
直接在浏览器打开你的域名或 IP：
- 系统会自动加载 `index.html` 前端页面。
- 支持在顶部导航栏一键切换【暗黑模式】与【中英文 (English / 中文)】！
- 输入默认管理员账号 `admin` 或默认子账号 `account_a` 即可直接登录进入系统！

> 🔐 **安全安全与修改提示（重要）**：
> 系统默认初始管理员账号为 `admin`。部署成功后，请务必立即在 **【管理员控制台】 -> 【用户名与模板管理】** 中修改 `admin` 账号 Key（用户名），或重新添加您的专属管理员账号。更改后，系统将拒绝使用原 `admin` 登录，有效保障您的管理权限安全！

### 第三步：配置定时任务（启用"定时自动发布商品"）

这是 v2.0 的核心功能。到点后系统会自动挑选该账号下一个未发布商品 → **自动联网搜索图片并上传** → 发布到闲鱼。

1. 宝塔面板左侧 **【计划任务】** -> **任务类型** 选 `访问URL`。
2. **执行周期** 选 `每1分钟`（系统内部按配置的发布时间点精确匹配，并自带去重与并发锁，每分钟访问是安全的）。
3. **URL** 填写（把域名换成你自己的）：

```
http://你的域名/api.php?action=run_scheduled_publish
```

4. 点【添加任务】后，可点右侧【执行】手动跑一次验证。

然后在前台 **【管理员控制台】->【闲鱼发布配置】** 里为每个账号：填 `xianyu-auto-reply` 服务地址、分销秘钥、闲鱼账号 ID，打开"启用定时发布"开关，设置发布时间（如 `09:00,18:00`）与价格即可。

> 🔍 **发布报"请先根据商品描述重新选择完整的平台商品分类"怎么办？**
> 闲鱼要求 **频道分类 ID + 频道分类名称 + 淘宝分类 ID** 三者齐全才会受理发布。
> 在【闲鱼发布配置】里点 **【智能获取分类】**，从候选里选一个贴合商品的分类，保存即可。
> 若想让系统每次按商品描述自动匹配，勾上 **「每单按商品描述自动匹配分类」**。
> 如果点获取后提示失败，说明服务器到 `xianyu-auto-reply` 的分类推荐接口不通，
> 在服务器上执行 `curl -I http://你的xianyu服务地址/api/v1/external/category/recommend` 确认。

> 🔍 **发布报"未能获取商品图片"怎么办？**
> 在【手动发布商品到闲鱼】弹窗里先点 **【测试联网搜图】**，它会逐个图源（Bing / DuckDuckGo / Wikimedia）
> 给出成败并实际下载一张验证。若三个都失败，说明服务器出网被防火墙拦截 —— 在服务器上执行
> `curl -I https://www.bing.com` 确认；临时解决办法是在【单个商品管理】里给商品手动填封面图 URL。

### 第四步：保护 .env（重要）

若你用 `.env` 存放数据库密码，务必禁止外部访问（仓库已自带 `.htaccess`）：

- **Apache**：自动生效。
- **Nginx**：在站点配置 `server{}` 内加入：
  ```
  location ~ /\. { deny all; }
  location = /.env { deny all; }
  location ~* \.(sql|log)$ { deny all; }
  ```

---

## 5. 方案二：Node.js 部署模式 (仅用于本地开发/联调，**不建议生产**)

> ⚠️ **Node.js 后端 (`server.ts`) 没有接数据库**，所有数据都存在进程内存里，重启即丢失，
> 也无法真正发布商品到闲鱼。它只用于本地改前端时免装 PHP。
> **生产环境请务必使用方案一（PHP + MySQL）。**

如果你希望使用 Node.js (`server.ts` / `server.cjs`) 运行服务端：

> 💡 **Node.js 静态资源托管原理**：`npm run build` 会将前端网页打包到 `dist/index.html` 并将服务端打包为 `dist/server.cjs`。在 `NODE_ENV=production` 模式下，Node 服务会自动开启静态资源托管，将前端与 API 服务统一挂载在 `3000` 端口上，再通过 Nginx 反向代理提供标准 80/443 端口访问。

1. 打开宝塔面板 **【软件商店】** -> 安装 **【Node.js版本管理器】**。
2. 在 Node.js 管理器中安装 Node.js LTS 版本（如 `v18.x` 或 `v20.x`）。
3. 使用宝塔终端（SSH）进入网站根目录，安装依赖：
   ```bash
   cd /www/wwwroot/你的站点目录
   npm install
   ```
4. 编译前端与后端服务并启动：
   ```bash
   # 打包前端与服务端 (构建生成 dist/ 静态页面和 dist/server.cjs)
   npm run build

   # 使用 PM2 挂载后台运行 (生产环境配置 NODE_ENV=production)
   NODE_ENV=production pm2 start dist/server.cjs --name "shop-text-manager"
   ```
5. 在宝塔 **【网站】** 设置中，找到 **【反向代理】** -> **【添加反向代理】**：
   - **代理名称**：`shoptext-proxy`
   - **目标 URL**：`http://127.0.0.1:3000`
   - 保存即可。

---

## 6. SSL 证书绑定与安全性设置

1. **配置免费 SSL (HTTPS)**：
   - 进入宝塔 **【网站】** -> 点击站点右侧 **【设置】** -> 选择 **【SSL】** 选项卡。
   - 选择 **【Let's Encrypt】**，勾选你的域名，点击 **【申请】**。
   - 申请成功后勾选 **【强制 HTTPS】** 并保存，提高访问安全性。

2. **防火墙/安全组端口开启**：
   - 如果使用 IP 端口访问，需在宝塔面板 **【安全】** 中放行对应端口（如 `80`, `443`, `3000` 等）。
   - 同时也需要在云服务器提供商（阿里云/腾讯云/华为云）的安全组规则中放行相应端口。

---

## 7. 常见问题与排错指南

### Q1: 提示“数据库连接失败: SQLSTATE[HY000] [2002]”？
- **原因**：`api.php` 中的数据库账号密码配置错误，或者 MySQL 服务未启动。
- **解决**：检查宝塔面板中 MySQL 运行状态，并在 `api.php` 中核对 `$db_user` 与 `$db_pass`。

### Q2: 切换账号或修改管理员提示“操作失败或接口异常”？
- **原因**：PHP 的 `PDO` 扩展未开启或文件权限不足。
- **解决**：进入宝塔 PHP 设置 -> 【软件管理】-> 确认开启了 `pdo_mysql`；确保 `api.php` 拥有 644 文件读取权限。

---

🎉 **恭喜！你已成功在宝塔面板部署 ShopTextManager 商品文案管理与快速发布系统！**

---

<a id="-english-guide---aapanel-deployment"></a>
## 🇺🇸 English Guide - aaPanel Deployment

This section guides you step-by-step through deploying **ShopTextManager** on **aaPanel / Baota Linux Panel**.

> 🚀 **Sponsor**:
> **NodeHK VPN** — High-speed and stable VPN network acceleration for e-commerce, sellers, and gaming nodes: 👉 [**www.nodehk.shop**](https://www.nodehk.shop/#/auth?invite=uu1fzn9t)

The system supports both **PHP + MySQL (Recommended for Production)** and **Node.js (Express)** backend running modes.

---

### 1. Prerequisites & Software Installation
Log into aaPanel -> **App Store** and install:
- **Web Server**: Nginx (1.20+ recommended)
- **Database**: MySQL (5.7 or 8.0)
- **PHP**: PHP 7.4 or PHP 8.0/8.1/8.2 (Ensure `pdo_mysql` extension is enabled)
- **Node.js** (Optional, if using Node.js mode): Install **Node.js Version Manager** or **PM2 Manager** from App Store

---

### 2. MySQL Database Setup & Schema Import
1. Go to **Database** in aaPanel -> Click **Add Database**.
2. Enter database details:
   - **Database Name**: `xianyu_db` (or custom name)
   - **Username**: `xianyu_user` (or custom username)
   - **Password**: Set a secure password
   - **Access Rights**: `Local Server` (127.0.0.1)
3. Click **Submit** to create the database.
4. Locate the newly created database in the list and click **Import**.
5. Upload and import `schema.sql` from the root of the project directory.
6. Upon successful import, `games`, `templates`, and `published_logs` tables will be created with default seed data.

---

### 3. Website Creation & Code Upload
1. Go to **Website** in aaPanel -> Click **Add Site**.
2. Fill in site configuration:
   - **Domain**: Enter your domain (or server IP with port).
   - **PHP Version**: Select PHP 7.4 or PHP 8.x.
   - **Root Directory**: Auto-generated (e.g. `/www/wwwroot/yourdomain.com`).
3. Click **Submit**.
4. Access the site root directory and remove default `index.html` and `404.html` files.
5. Upload all project source files to the site root directory.

---

### 4. Option 1: PHP + MySQL Deployment Mode (Recommended)

#### Step 1: Configure Database Connection (Two Flexible Options)

The system supports both **`.env` Environment File** and **Direct `api.php` Editing**:

- **Option A: Use `.env` Configuration File (Recommended)**
  1. Copy `.env.example` to `.env` in the root directory.
  2. Open `.env` and set your database credentials:
     ```env
     DB_HOST=127.0.0.1
     DB_PORT=3306
     DB_NAME=xianyu_db
     DB_USER=xianyu_user
     DB_PASS=YOUR_DB_PASSWORD
     ```
  `api.php` includes a zero-dependency `.env` parser that automatically loads these settings.

- **Option B: Direct Editing in `api.php`**
  If you prefer double-clicking files directly in aaPanel File Manager, open `api.php` and modify the default fallback values around lines 84–88:
  ```php
  $db_host = getenv('DB_HOST') ?: '127.0.0.1';
  $db_port = getenv('DB_PORT') ?: '3306';
  $db_name = getenv('DB_NAME') ?: 'xianyu_db';     // Your database name
  $db_user = getenv('DB_USER') ?: 'xianyu_user';   // Your database username
  $db_pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'YOUR_DB_PASSWORD';
  ```
Save the file.

#### Step 2: Test & Access
Open your domain or IP in the browser:
- The `index.html` single-page application will load automatically.
- Switch seamlessly between **Dark / Light Mode** and **English / Chinese** from the top header navigation.
- Log in with default admin account `admin` or user account `account_a`.

> 🔐 **Security & Customization Notice (Important)**:
> The initial default administrator account is `admin`. After successful deployment, please log in immediately and navigate to **"Admin Console" -> "User & Template Manager"** to change the `admin` username key or add your custom administrator account. Once updated, the system will reject logins with the old `admin` username to protect your system security.

---

### 5. Option 2: Node.js Deployment Mode (Alternative)

If you prefer running the backend via Node.js (`server.ts` / `server.cjs`):

> 💡 **Node.js Static Asset Hosting**: `npm run build` compiles the SPA frontend into `dist/index.html` and the server into `dist/server.cjs`. In `NODE_ENV=production` mode, Express automatically serves static assets from `dist/` on port `3000`, which aaPanel's Nginx reverse proxy routes to standard ports 80/443.

1. Install **Node.js Version Manager** from aaPanel App Store.
2. Install Node.js LTS (v18.x or v20.x).
3. Open SSH Terminal, navigate to site root, and install dependencies:
   ```bash
   cd /www/wwwroot/your_site_directory
   npm install
   ```
4. Build frontend and backend server, then launch with PM2:
   ```bash
   # Build frontend static files & backend bundle (generates dist/ and dist/server.cjs)
   npm run build

   # Launch with PM2 in production mode
   NODE_ENV=production pm2 start dist/server.cjs --name "shop-text-manager"
   ```
5. Go to aaPanel **Website** -> Site Settings -> **Reverse Proxy** -> **Add Reverse Proxy**:
   - **Proxy Name**: `shoptext-proxy`
   - **Target URL**: `http://127.0.0.1:3000`
   - Save proxy settings.

---

### 6. SSL Certificate & Security Setup
1. **Free SSL (HTTPS)**:
   - Go to aaPanel **Website** -> Site Settings -> **SSL** tab.
   - Select **Let's Encrypt**, select your domain, and click **Apply**.
   - Enable **Force HTTPS** after issuance for encrypted traffic.

2. **Firewall / Security Group Ports**:
   - Ensure ports `80`, `443`, and `3000` (if using Node.js directly) are opened in both aaPanel **Security** tab and your cloud provider's security group (AWS, AliCloud, Tencent Cloud).

---

### 7. Troubleshooting & FAQ

#### Q1: "Database Connection Failed: SQLSTATE[HY000] [2002]"?
- **Cause**: Incorrect database username/password in `api.php` or MySQL service is not running.
- **Solution**: Check MySQL status in aaPanel, and verify `$db_user` & `$db_pass` in `api.php`.

#### Q2: "Operation failed or API exception" when changing accounts?
- **Cause**: PHP `pdo_mysql` extension is missing or file permissions are insufficient.
- **Solution**: Check PHP App Store settings to ensure `pdo_mysql` is enabled; grant `644` read permissions to `api.php`.

---

🎉 **Congratulations! You have successfully deployed ShopTextManager on aaPanel / Baota Panel!**
