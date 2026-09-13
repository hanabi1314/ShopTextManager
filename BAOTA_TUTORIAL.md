# 宝塔面板 (aaPanel) 部署教程 🛠️

**ShopTextManager 在宝塔 Linux 面板上的完整安装指南** / Baota Panel Deployment & Installation Guide

> 🚀 **赞助推荐 / Sponsor**
> **NodeHK VPN** — 面向跨境电商、外贸与游戏节点加速的高速稳定 VPN 服务：
> 👉 [**www.nodehk.shop**](https://www.nodehk.shop/#/auth?invite=uu1fzn9t)

本教程覆盖两种后端模式，重点讲最稳定的 **PHP + MySQL** 方式。全文约 15 分钟可完成。

---

## 📑 目录 / Table of Contents

1. [环境准备 / Prerequisites](#s1-prerequisites)
2. [创建数据库并导入结构 / Database](#s2-database)
3. [创建站点并上传源码 / Site Setup](#s3-site-setup)
4. [方案一：PHP + MySQL 部署（推荐）](#s4-php-mysql)
5. [方案二：Node.js 部署（仅本地开发）](#s5-nodejs)
6. [SSL 与安全加固 / SSL & Hardening](#s6-ssl)
7. [排错指南 / Troubleshooting](#s7-troubleshooting)
8. [部署验收清单 / Post-Deploy Checklist](#s8-checklist)
9. [🇺🇸 English Guide](#en-guide)

---

<a id="s1-prerequisites"></a>
## 1. 环境准备 / Prerequisites

登录宝塔 → **【软件商店】**，安装：

| 软件 | 版本要求 | 备注 |
| :--- | :--- | :--- |
| Web 服务器 | Nginx 1.20+ | 或 Apache |
| 数据库 | MySQL 5.7 / 8.0 | |
| PHP | 7.4 或 8.0 / 8.1 / 8.2 | 需 `pdo_mysql` 扩展（默认已开） |
| Node.js | 18.x / 20.x | **仅方案二需要** |

**PHP 扩展**：

- **必需 `curl`** —— 自动联网搜图和调用 xianyu-auto-reply 都依赖它。
  在【软件商店 → 已安装的 PHP → 安装扩展】中确认已勾选；缺了会提示「服务器未安装或未启用 PHP curl 扩展」。
- **建议 `mbstring`** —— 用于中文按字符截断，缺失时代码有降级处理，不影响主流程。

自检命令：

```bash
php -m | grep -E 'pdo_mysql|curl|mbstring'
```

---

<a id="s2-database"></a>
## 2. 创建数据库并导入结构 / Database

1. 左侧 **【数据库】** → **【添加数据库】**
2. 填写：
   - **数据库名**：`xianyu_db`（可自定义）
   - **用户名**：`xianyu_user`（可自定义）
   - **密码**：设置一个强密码并**妥善保存**
   - **访问权限**：`本地服务器`
3. 点【确定】
4. 在列表中找到新库，点右侧 **【导入】**
5. 上传项目根目录的 `schema.sql` 并执行
6. 导入成功后应生成 **5 张表**：`games`、`templates`、`published_logs`、`xianyu_config`、`xianyu_publish_logs`

> ⚠️ 若报 `Access denied` 或 `No database selected`：`schema.sql` **不会自动建库**，
> 必须在数据库列表中先选中你的库再点【导入】。命令行等价写法：
> `mysql -u 用户名 -p 你的库名 < schema.sql`

> 💡 **升级用户**：已有旧版本数据库无需手工改表。`api.php` 首次访问会自动补齐缺失的表与列。

---

<a id="s3-site-setup"></a>
## 3. 创建站点并上传源码 / Site Setup

1. 左侧 **【网站】** → **【添加站点】**
2. 填写：
   - **域名**：你的域名（无域名可填服务器 IP + 端口）
   - **PHP 版本**：选择已安装的 PHP 7.4 或 8.x（**不要选"纯静态"**，否则 `api.php` 不解析）
   - **根目录**：自动生成，如 `/www/wwwroot/yourdomain.com`
3. 点【提交】
4. 进入根目录，**删除**默认的 `index.html` 和 `404.html`
5. 上传项目全部源码到根目录，确保 `index.html`、`api.php`、`schema.sql` 在**根目录**下

---

<a id="s4-php-mysql"></a>
## 4. 方案一：PHP + MySQL 部署（推荐）

省资源、可持久化、**唯一支持定时发布到闲鱼**的模式。

### 第一步：配置数据库连接

二选一即可。

**方式 A：`.env` 文件（推荐）**

1. 根目录复制 `.env.example` 为 `.env`
2. 填写：

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=xianyu_db
DB_USER=xianyu_user
DB_PASS=你的数据库密码
```

`api.php` 内置零依赖 `.env` 解析，自动优先读取。

**方式 B：直接编辑 `api.php` 默认值**

在文件管理器中打开 `api.php`，修改 **第 90–94 行**：

```php
$db_host = getenv('DB_HOST') ?: '127.0.0.1';
$db_port = getenv('DB_PORT') ?: '3306';
$db_name = getenv('DB_NAME') ?: 'xianyu_db';     // 宝塔创建的数据库名
$db_user = getenv('DB_USER') ?: 'xianyu_user';   // 宝塔创建的数据库用户名
$db_pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '你的数据库密码';
```

> ⚠️ 旧版教程标注的行号有误，实际位置是 **90–94 行**。

保存后立即生效，无需重启。

### 第二步：访问与初始登录

浏览器打开你的域名或 IP：

- 前端页面自动加载，顶部可切换【暗黑模式】与【中文 / English】
- 输入 `admin` 进入超级管理员，或 `account_a` / `account_b` 进入普通账号

> 🔐 **安全提示（重要）**：初始管理员账号为 `admin`。部署成功后请立即进入
> **【管理员控制台】→【用户名与模板管理】** 修改 `admin` 的账号 Key，或新增你的专属管理员账号。
> 改名后，原 `admin` 将无法登录。

### 第三步：配置定时任务（启用自动发布）

这是自动发闲鱼的核心。到点后系统会：选出该账号下一个未发布商品 → **自动联网搜图并上传** → 发布到闲鱼。

1. 左侧 **【计划任务】** → 任务类型选 `访问 URL`
2. **执行周期** 选 `每 1 分钟`
3. **URL** 填写（换成你的域名；若部署在子目录需带上子目录）：

```
http://你的域名/api.php?action=run_scheduled_publish
```

4. 点【添加任务】，然后点右侧【执行】手动跑一次验证

> 每分钟访问是安全的：系统内部按配置的时间点精确匹配，并自带去重与账号级并发锁。

接着在 **【管理员控制台】→【定时发布】** 页签为每个账号配置：

- `xianyu-auto-reply` 服务地址（含端口，不含 `/api/v1`）、分销秘钥、闲鱼账号 ID
- 打开「启用定时发布」
- 设置发布时间（如 `09:00,18:00`）与价格

> 📌 页签名称是「**定时发布**」，不是「闲鱼发布配置」。

**发布行为要点**：

| 问题 | 行为 |
| :--- | :--- |
| 选题顺序 | 按商品 `id` **升序**，先入库先发 |
| 是否随机 | 否，严格顺序 |
| 已发布商品 | 跳过（按账号 + 商品记录） |
| 全部发完 | 状态转 `idle`，不循环重发 |

> 💡 `schema.sql` 预置了 `account_a` 已发布 1、2 号商品，所以它的首次定时发布从 **3 号** 开始。
> 想从 1 号开始，先在「单个商品管理」的「已归档隐藏的商品」区取消隐藏这两个商品。

### 第四步：保护 `.env`（重要）

用 `.env` 存密码时必须禁止外部访问。仓库已自带 `.htaccess`：

- **Apache**：自动生效
- **Nginx**：`.htaccess` 不生效，需在站点配置 `server{}` 内手动加入

```nginx
autoindex off;
location ~ /\.                         { deny all; }
location = /.env                       { deny all; }
location ~* \.(sql|md|log|ts|example)$ { deny all; }
location ^~ /node_modules/             { deny all; }
```

---

<a id="s5-nodejs"></a>
## 5. 方案二：Node.js 部署（仅本地开发）

> ⚠️ **不建议生产使用**。Node.js 后端 (`server.ts`) **不接数据库**，数据在进程内存里、重启即丢失，
> 也**无法真正发布商品到闲鱼**。它只用于本地改前端时免装 PHP。

如果仍需使用：

> 💡 **原理**：`npm run build` 会把前端打包为 `dist/index.html`、服务端打包为 `dist/server.cjs`。
> `NODE_ENV=production` 时 Express 会在 `3000` 端口同时托管静态资源与 API，再由 Nginx 反向代理到 80/443。

1. 【软件商店】安装 **【Node.js 版本管理器】** 或 **【PM2 管理器】**
2. 在管理器中安装 Node.js LTS（`v18.x` 或 `v20.x`）
3. SSH 进入站点根目录安装依赖：

```bash
cd /www/wwwroot/你的站点目录
npm install
```

4. 构建并以后台方式启动：

```bash
# 构建前端与服务端，生成 dist/ 与 dist/server.cjs
npm run build

# 用 PM2 后台运行
NODE_ENV=production pm2 start dist/server.cjs --name "shop-text-manager"
```

5. 宝塔 **【网站】→ 设置 → 反向代理 → 添加反向代理**：
   - **代理名称**：`shoptext-proxy`
   - **目标 URL**：`http://127.0.0.1:3000`

---

<a id="s6-ssl"></a>
## 6. SSL 与安全加固 / SSL & Hardening

**免费 SSL（HTTPS）**

1. 宝塔【网站】→ 站点右侧【设置】→【SSL】
2. 选 **Let's Encrypt**，勾选域名，点【申请】
3. 申请成功后勾选 **【强制 HTTPS】** 并保存

**放行端口**

- 宝塔【安全】中放行 `80`、`443`（Node 模式再加 `3000`）
- 同时在云厂商（阿里云 / 腾讯云 / 华为云）**安全组**中放行同样端口

**限制调度接口（建议）**

`api.php` 无服务端鉴权。建议只允许本机访问 `run_scheduled_publish`，例如在站点 Nginx 配置中：

```nginx
location = /api.php {
    if ($arg_action = "run_scheduled_publish") {
        allow 127.0.0.1;
        deny all;
    }
}
```

> 加了这条之后，Cron 仍然要继续用 `http://127.0.0.1/...` 访问（不能换成外网域名，否则会被拒绝）。

---

<a id="s7-troubleshooting"></a>
## 7. 排错指南 / Troubleshooting

### Q1：提示「数据库连接失败: SQLSTATE[HY000] [2002]」

- **原因**：`.env` / `api.php` 中账号密码错误，或 MySQL 未启动
- **解决**：在宝塔【数据库】确认 MySQL 运行中，核对 `DB_USER` 与 `DB_PASS`

### Q2：切换账号或修改管理员提示「操作失败或接口异常」

- **原因**：`pdo_mysql` 未启用，或文件权限不足
- **解决**：PHP 设置中启用 `pdo_mysql`；确保 `api.php` 权限为 `644`

### Q3：发布报「请先根据商品描述重新选择完整的平台商品分类」

- **原因**：闲鱼要求 **频道分类 ID + 频道分类名称 + 淘宝分类 ID** 三者齐全
- **解决**：在【定时发布】配置里点 **【智能获取分类】**，选一个贴合商品的分类保存；
  或勾选 **「每单按商品描述自动匹配分类」** 让系统自动获取
- 若点获取后失败：服务器到 xianyu-auto-reply 分类接口不通，在服务器执行
  `curl -I http://你的xianyu服务地址/api/v1/external/category/recommend` 确认

### Q4：发布报「未能获取商品图片」

- **解决**：在【手动发布商品到闲鱼】弹窗里先点 **【测试联网搜图】**，
  它会逐个图源（Bing / DuckDuckGo / Wikimedia）给出成败并实际下载一张验证
- 三个都失败说明服务器出网被拦截：执行 `curl -I https://www.bing.com` 确认
- 临时方案：在【单个商品管理】给商品手动填封面图 URL

### Q5：定时任务不触发

- 用【执行】按钮手动跑一次，看返回结果
- 确认 Cron URL 与实际部署路径一致（子目录别忘了带）
- 注意：只有**当前分钟命中配置的时间点**才会真的发布，不命中属正常跳过

### Q6：页面 500 或整站打不开

- 若主机只开放 `AllowOverride FileInfo`，`.htaccess` 中的 `Options` / `Require` 会导致整目录 500
- 本仓库的 `.htaccess` 已只使用 `RedirectMatch`（依赖 `mod_alias`）；仍出问题时先重命名 `.htaccess` 排查

---

<a id="s8-checklist"></a>
## 8. 部署验收清单 / Post-Deploy Checklist

| # | 检查项 | 预期 |
| :-- | :--- | :--- |
| 1 | 打开站点根路径 | 前端页面正常显示 |
| 2 | 访问 `api.php?action=get_games` | 返回 JSON 商品数据 |
| 3 | 访问 `/.env` | **404** |
| 4 | 访问 `/schema.sql` | **404** |
| 5 | 用 `admin` 登录 | 能进入管理员控制台 |
| 6 | 已改掉默认 `admin` 账号名 | ✅ |
| 7 | 已配置 HTTPS 并强制跳转 | ✅ |
| 8 | 定时发布：【测试连接】能拉到闲鱼账号 | ✅ |
| 9 | 定时发布：【手动发布指定商品】成功 | ✅ |
| 10 | 计划任务【执行】一次返回 JSON | ✅ |

---

<a id="en-guide"></a>
# 🇺🇸 English Guide

This section walks through deploying **ShopTextManager** on **aaPanel / Baota Linux Panel**. Full CN version above.

> 🚀 **Sponsor**: **NodeHK VPN** — fast, stable VPN for e-commerce, sellers, and gaming nodes:
> 👉 [**www.nodehk.shop**](https://www.nodehk.shop/#/auth?invite=uu1fzn9t)

### 1. Prerequisites

Install from **App Store**:

| Software | Version | Notes |
| :--- | :--- | :--- |
| Web server | Nginx 1.20+ | or Apache |
| Database | MySQL 5.7 / 8.0 | |
| PHP | 7.4 or 8.0 / 8.1 / 8.2 | Needs `pdo_mysql` (on by default) |
| Node.js | 18.x / 20.x | **Only for Option 2** |

**PHP extensions**: `curl` is **required** (online image search + xianyu-auto-reply calls); `mbstring` is recommended.

```bash
php -m | grep -E 'pdo_mysql|curl|mbstring'
```

### 2. Database Setup & Schema Import

1. **Database** → **Add Database**
2. Fill in: name `xianyu_db`, user `xianyu_user`, a strong password (save it), access `Local Server`
3. Click **Submit**, then **Import** on the new database
4. Upload and import `schema.sql` — this creates **5 tables**: `games`, `templates`, `published_logs`, `xianyu_config`, `xianyu_publish_logs`

> ⚠️ `schema.sql` does **not** create the database. Select your database first, or run
> `mysql -u USER -p YOUR_DB < schema.sql` from the shell.
>
> 💡 **Upgrading?** No manual migration needed — `api.php` self-heals missing tables/columns on first request.

### 3. Website Creation & File Upload

1. **Website** → **Add Site**
2. Domain (or IP + port), **PHP version 7.4 / 8.x** — do **not** choose "pure static", or `api.php` won't execute
3. Click **Submit**
4. Delete the generated `index.html` and `404.html`
5. Upload all project files to the site **root**

### 4. Option 1: PHP + MySQL (Recommended)

Lightweight, durable, and the **only mode that supports scheduled Xianyu publishing**.

#### Step 1 — Configure the DB connection

**Option A: `.env` (recommended)**

Copy `.env.example` to `.env` in the site root:

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=xianyu_db
DB_USER=xianyu_user
DB_PASS=YOUR_DB_PASSWORD
```

`api.php` has a zero-dependency `.env` parser that loads this automatically.

**Option B: Edit `api.php` defaults**

Open `api.php` and change **lines 90–94**:

```php
$db_host = getenv('DB_HOST') ?: '127.0.0.1';
$db_port = getenv('DB_PORT') ?: '3306';
$db_name = getenv('DB_NAME') ?: 'xianyu_db';     // your DB name
$db_user = getenv('DB_USER') ?: 'xianyu_user';   // your DB user
$db_pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'YOUR_DB_PASSWORD';
```

> ⚠️ Older revisions of this guide cited the wrong line numbers. It is **lines 90–94**.

Changes take effect immediately — no restart needed.

#### Step 2 — Access & First Login

Open your domain or IP:

- The frontend loads; switch **Dark / Light** and **中文 / English** from the header
- Sign in as `admin` (super admin), or `account_a` / `account_b`

> 🔐 **Security notice**: the initial admin account is `admin`. Right after deployment, go to
> **Admin Console → User & Template Manager** and rename it or add your own admin.
> The old `admin` login is rejected afterwards.

#### Step 3 — Set Up the Scheduled Task

At each configured time the system picks the account's next unpublished product → **searches and uploads an image online** → publishes it to Xianyu.

1. **Scheduled Tasks** → task type `Visit URL`
2. Period: **every 1 minute**
3. URL (replace with your domain; include the subdirectory if you deployed into one):

```
http://your-domain/api.php?action=run_scheduled_publish
```

4. Click **Add**, then **Execute** once to verify

> Calling it every minute is safe: the scheduler matches configured times precisely and has dedup plus a per-account lock.

Then configure each account in **Admin Console → Scheduled Publish**:

- xianyu-auto-reply server URL (with port, without `/api/v1`), secret key, Xianyu account ID
- Enable scheduled publishing
- Set publish times (e.g. `09:00,18:00`) and prices

> 📌 The tab is **"定时发布 / Scheduled Publish"**.

**Publishing behavior**:

| Question | Behavior |
| :--- | :--- |
| Selection order | By product `id` **ascending** — oldest first |
| Random? | No. Strictly sequential |
| Already published? | Skipped (tracked per account + product) |
| All published? | Status becomes `idle`; no loop |

> 💡 `schema.sql` pre-marks products 1 and 2 as published for `account_a`, so its first scheduled publish starts at **product 3**.
> Un-hide them in Product Manager's "Archived / Hidden" area to start from 1.

#### Step 4 — Protect `.env`

- **Apache**: `.htaccess` (included) works automatically
- **Nginx**: `.htaccess` is ignored — add to your `server{}` block:

```nginx
autoindex off;
location ~ /\.                         { deny all; }
location = /.env                       { deny all; }
location ~* \.(sql|md|log|ts|example)$ { deny all; }
location ^~ /node_modules/             { deny all; }
```

### 5. Option 2: Node.js (Local Development Only)

> ⚠️ **Not for production.** `server.ts` has **no database** — data lives in memory and is lost on restart,
> and it **cannot actually publish to Xianyu**.

> 💡 `npm run build` emits `dist/index.html` and `dist/server.cjs`. With `NODE_ENV=production`,
> Express serves both on port `3000`, and Nginx reverse-proxies to 80/443.

1. Install **Node.js Version Manager** or **PM2 Manager** from App Store
2. Install Node.js LTS (v18.x / v20.x)
3. SSH into the site root:

```bash
cd /www/wwwroot/your_site_directory
npm install
```

4. Build and launch:

```bash
npm run build
NODE_ENV=production pm2 start dist/server.cjs --name "shop-text-manager"
```

5. **Website → Settings → Reverse Proxy → Add**:
   - Name: `shoptext-proxy`
   - Target URL: `http://127.0.0.1:3000`

### 6. SSL & Hardening

**Free SSL**: **Website → Settings → SSL → Let's Encrypt** → select domain → **Apply** → enable **Force HTTPS**.

**Open ports**: allow `80` and `443` (plus `3000` for Node mode) in both aaPanel **Security** and your cloud provider's security group.

**Restrict the scheduler endpoint (recommended)** — `api.php` has no server-side auth, so limit `run_scheduled_publish` to localhost:

```nginx
location = /api.php {
    if ($arg_action = "run_scheduled_publish") {
        allow 127.0.0.1;
        deny all;
    }
}
```

> After adding this, keep the cron URL as `http://127.0.0.1/...` — using the public domain would be blocked.

### 7. Troubleshooting

**Q1: "Database Connection Failed: SQLSTATE[HY000] [2002]"**
Wrong credentials in `.env` / `api.php`, or MySQL isn't running. Verify MySQL status in aaPanel and re-check `DB_USER` / `DB_PASS`.

**Q2: "Operation failed or API exception" when switching accounts**
`pdo_mysql` missing or file permissions wrong. Enable `pdo_mysql` in PHP settings and set `api.php` to `644`.

**Q3: "请先根据商品描述重新选择完整的平台商品分类"**
Xianyu requires **channel category ID + channel category name + Taobao category ID** together.
Click **Fetch Category** in the Scheduled Publish config and pick a matching category, or enable
**"Auto-match category per item"**. If Fetch fails, check connectivity:
`curl -I http://your-xianyu-host/api/v1/external/category/recommend`

**Q4: "未能获取商品图片" / no image found**
Click **Test Image Search** in the manual-publish dialog — it reports each provider
(Bing / DuckDuckGo / Wikimedia) and actually downloads one to verify.
If all three fail, outbound network is blocked: `curl -I https://www.bing.com`.
Workaround: set a cover image URL in Product Manager.

**Q5: Scheduled task never publishes**
Run it manually via **Execute** and inspect the JSON. Confirm the URL matches your deployment path.
Note that it only publishes when the **current minute matches** a configured time — silent skips are normal.

**Q6: Site returns 500**
On hosts allowing only `AllowOverride FileInfo`, `Options` / `Require` directives break the whole directory.
This repo's `.htaccess` uses only `RedirectMatch` (`mod_alias`); if it still fails, temporarily rename `.htaccess` to isolate.

### 8. Post-Deploy Checklist

| # | Check | Expected |
| :-- | :--- | :--- |
| 1 | Open site root | Frontend renders |
| 2 | `api.php?action=get_games` | Returns JSON |
| 3 | `/.env` | **404** |
| 4 | `/schema.sql` | **404** |
| 5 | Log in as `admin` | Admin Console opens |
| 6 | Default `admin` renamed | ✅ |
| 7 | HTTPS enabled & forced | ✅ |
| 8 | Test Connection returns Xianyu accounts | ✅ |
| 9 | Manual Publish of one product succeeds | ✅ |
| 10 | Scheduled task Execute returns JSON | ✅ |

---

🎉 **部署完成 / Congratulations — ShopTextManager is live on aaPanel!**
