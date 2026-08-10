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
6. 导入成功后，数据库中会自动创建 `games`、`templates` 和 `published_logs` 三张表及初始化数据。

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

### 第一步：修改 `api.php` 数据库配置
在宝塔面板的文件管理器中打开 `api.php`，找到顶部的数据库连接参数进行修改：

```php
// 修改为你的宝塔数据库信息
$db_host = '127.0.0.1';
$db_port = '3306';
$db_name = 'xianyu_db';     // 宝塔创建的数据库名
$db_user = 'xianyu_user';   // 宝塔创建的数据库用户名
$db_pass = '你的数据库密码'; // 宝塔创建的数据库密码
```
保存文件即可。

### 第二步：测试与访问
直接在浏览器打开你的域名或 IP：
- 系统会自动加载 `index.html` 前端页面。
- 支持在顶部导航栏一键切换【暗黑模式】与【中英文 (English / 中文)】！
- 输入默认管理员账号 `admin` 或默认子账号 `account_a` 即可直接登录进入系统！

---

## 5. 方案二：Node.js 部署模式 (备选)

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

#### Step 1: Configure `api.php` Database Settings
Open `api.php` in aaPanel File Manager and edit the connection parameters at the top:

```php
$db_host = '127.0.0.1';
$db_port = '3306';
$db_name = 'xianyu_db';     // Your database name
$db_user = 'xianyu_user';   // Your database username
$db_pass = 'YOUR_DB_PASSWORD'; // Your database password
```
Save the file.

#### Step 2: Test & Access
Open your domain or IP in the browser:
- The `index.html` single-page application will load automatically.
- Switch seamlessly between **Dark / Light Mode** and **English / Chinese** from the top header navigation.
- Log in with default admin account `admin` or user account `account_a`.

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
