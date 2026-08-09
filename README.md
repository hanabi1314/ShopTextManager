# 闲鱼与多平台商品文案快速发布与管理系统 🚀
## Xianyu & Multi-Platform Product Listing & Copywriting Management System

[中文文档](#-中文文档) | [English Documentation](#-english-documentation)

---

<a id="-中文文档"></a>
## 🇨🇳 中文文档

一个专为闲鱼（咸鱼）商家、二手卖家、Steam游戏安装包卖家、虚拟商品及多账号团队打造的高效商品快速发布、动态文案模板套用与一键搜图管理系统。

核心采用 **“开箱即用预设 + 渐进式扩展” (Preset-First Architecture)** 架构：默认开启 **“🎮 咸鱼 Steam游戏安装包/虚拟商品”** 预设，打开即用、0配置点击；同时支持一秒切换为 **“📱 数码二手/3C”**、**“👗 服饰潮牌/中古”** 或 **“⚙️ 通用电商/自定义”** 模式，全面适配各类商品发布需求！

---

### 🌟 核心设计理念：开箱即用预设 + 渐进式扩展 (Preset-First)

#### 1. 🎯 默认开箱即用（0 额外配置，极速体验）
- 系统默认开启 **“🎮 Steam游戏安装包/虚拟商品模式”**。
- 打开即可直接使用，默认变量统一归一化为 `{{VAR_1}}` (主名称/中文), `{{VAR_2}}` (副名称/英文), `{{VAR}}` (完整组合名)。
- 点击“一键复制并搜图”直接快捷打开游戏封面/海报图片搜索。

#### 2. 🎛️ 管理员一键场景套件切换 (Scene Profiles)
在 Admin 管理员控制台中，可随心切换 4 大内置场景套件，UI 标签、示例提示、模版变量与搜图引擎后缀将**自动无缝重载**：
- **🎮 Steam游戏安装包模式 (默认预设)**：包含 `{{VAR_1}}` (中文名/主名称), `{{VAR_2}}` (英文名/副名称), `{{VAR}}` (完整组合名)。
- **📱 数码二手/3C电子模式**：包含 `{{VAR_1}}` (品牌/设备名), `{{VAR_2}}` (规格型号), `{{VAR}}` (组合全名)。
- **👗 服饰潮牌/中古闲置模式**：包含 `{{VAR_1}}` (品牌/商品名), `{{VAR_2}}` (颜色/尺码/细节), `{{VAR}}` (组合全名)。
- **⚙️ 通用电商/自由自定义模式**：包含 `{{VAR_1}}` (主标题), `{{VAR_2}}` (副标题/参数), `{{VAR}}` (组合全名)。

---

### 🌟 核心功能特性

#### 1. 🔑 免密账号管理与角色权限隔离
- **免密一键登录**：只需输入账号名称（如 `admin` 或子账号名字 `account_a`）即可快速登录切换。
- **角色权限隔离**：
  - **`admin` (超级管理员)**：拥有全局最高控制权限，包含切换场景套件、查看全局运行数据仪表板、批量/单个增删商品、新增/删除子账号、管理全站用户及模板。
  - **子账号 (普通商家账号)**：拥有专属商品清单，支持独立修改自身的专属文案模板、一键套用文案、一键批量复制、控制本账号商品的发布与隐藏状态。

#### 2. 📊 Admin 全局控制台仪表板
- **运行数据概览**：实时统计全站商品库总数、已有账号总数、累计商品发布总次数。
- **场景模式切换 (Scene Mode Selector)**：支持一键在 4 种预设模式间无缝切换，实时重置 UI 字段与提示文案。
- **账号发布明细**：列表直观对比各个子账号的商品发布进度与已隐藏归档数量。
- **一键批量导入商品**：支持在控制台直接粘贴商品清单，严格采用 `[参数1]+[参数2]` 规范格式解析（例如 `[黑神话：悟空]+[Black Myth: Wukong]`），支持 `\n` 换行符一键批量入库。

#### 3. 📝 智能文案模板与多场景变量替换
- 支持全场景变量映射与后置兼容，多套变量标签同步支持。
- **英文/副标题空值智能清洗**：当商品无副标题或英文名时，自动智能清理模版中围绕 `{{GAME_EN}}` / `{{MODEL}}` / `{{SIZE}}` 的空括号 `()` 或多余空格，确保文案始终整洁无瑕。
- **历史修改一键恢复**：模板弹窗编辑支持【恢复修改前】快捷按钮，轻松撤销未保存的改动。

#### 4. 🚀 一键批量复制文案与多引擎一键搜图
- **一键复制**：点击“复制文案”直接将生成的对应咸鱼商品描述复制到剪贴板，并带有高亮反馈提示。
- **批量复制**：支持点击“批量复制本页文案”，一次性导出当前页面全部展示商品的描述。
- **多引擎搜图**：内置 Bing、百度图片、Google 搜图引擎快捷入口，根据当前场景模式自动拼接最佳搜图词（如海报封面、外观实拍图、细节图等）。
- **发布自动隐藏**：确认发布后该商品自动在当前账号视图隐藏，避免重复发布。提供页码下拉框快速翻页，支持一键恢复全部隐藏商品。

---

### 🛠️ 项目文件架构

```
├── index.html        # 前端单页应用 (采用硬朗高对比黑黄酷炫风 UI & Preset-First 场景套件引擎)
├── server.ts         # Node.js Express 内存后端服务 (支持本地开发与 Live Preview)
├── api.php           # 生产环境 PHP RESTful API 后端 (支持 MySQL 交互)
├── schema.sql        # MySQL 数据库建表与初始化结构脚本
├── BAOTA_TUTORIAL.md # 宝塔面板零基础一键部署教程
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
访问 `http://localhost:3000`，输入 `admin` 体验超级管理员控制台（支持切换 Preset 场景模式），或输入 `account_a` 体验卖家流线型发布管理！

---

<a id="-english-documentation"></a>
## 🇺🇸 English Documentation

An efficient multi-account product listing, dynamic copy template generator, and image search management system designed for Xianyu (Ebay/Fleamarket platform) merchants, digital resellers, and multi-account operations teams.

Built with **Preset-First Architecture**: Defaults to the **Game Server / Virtual Product** preset with zero setup, while supporting instant switching to **Digital/3C**, **Fashion**, or **Custom E-commerce** modes!

---

### 🌟 Core Philosophy: Preset-First Architecture

1. **Zero Configuration Default**: Out-of-the-box support for virtual goods and game server rentals with zero extra clicks.
2. **Scene Profiles**: Admin console supports 1-click toggling between 4 pre-configured presets (Game, Digital, Fashion, Custom) with instant UI and variable auto-reloading.

---

### 🛠️ Tech Stack & Deployment

- **Frontend**: Single Page Application inside `index.html` with Scene Profile Engine and dark/yellow high-contrast UI.
- **Backend (Node.js)**: `server.ts` powered by Express for dev mode.
- **Backend (PHP)**: `api.php` providing production-ready PDO MySQL RESTful API integration.
- **Database**: MySQL 5.7 / 8.0 schema provided in `schema.sql`.

---

## 📜 License
MIT License. Open source and free for commercial and personal multi-account management use.
