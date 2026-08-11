import express from "express";
import path from "path";
import { createServer as createViteServer } from "vite";

interface Game {
  id: number;
  game_name_cn: string;
  game_name_en: string;
  game_name?: string;
  is_active: number;
  is_published?: boolean;
}

interface TemplateAccount {
  id: number;
  account_key: string;
  template_text: string;
  is_admin?: boolean;
  created_at?: string;
}

interface PublishedLog {
  account_key: string;
  game_id: number;
}

async function startServer() {
  const app = express();
  const PORT = 3000;

  // CORS 及 OPTIONS 预检处理
  app.use((req, res, next) => {
    const origin = process.env.CORS_ALLOW_ORIGIN || "*";
    res.header("Access-Control-Allow-Origin", origin);
    res.header("Access-Control-Allow-Methods", "GET, POST, DELETE, OPTIONS");
    res.header("Access-Control-Allow-Headers", "Content-Type, Authorization, X-Requested-With");
    if (req.method === "OPTIONS") {
      return res.sendStatus(200);
    }
    next();
  });

  app.use(express.json());
  app.use(express.urlencoded({ extended: true }));

  // 内存数据服务 (与 MySQL 保持一致)
  let games: Game[] = [
    { id: 1, game_name_cn: "黑神话：悟空", game_name_en: "Black Myth: Wukong", is_active: 1 },
    { id: 2, game_name_cn: "幻兽帕鲁", game_name_en: "Palworld", is_active: 1 },
    { id: 3, game_name_cn: "艾尔登法环", game_name_en: "Elden Ring", is_active: 1 },
    { id: 4, game_name_cn: "赛博朋克 2077", game_name_en: "Cyberpunk 2077", is_active: 1 },
    { id: 5, game_name_cn: "绝地求生", game_name_en: "PUBG: BATTLEGROUNDS", is_active: 1 }
  ];

  let accounts: TemplateAccount[] = [
    {
      id: 1,
      account_key: "admin",
      template_text: "【{{VAR_1}} ({{VAR_2}}) Steam正品游戏安装包 离线版/中文版】\n⚡ 自动发货 | 包含全套完整安装包+DLC扩展+终身更新\n✅ 送详细视频教程+远程协助安装 简体中文 免Steam繁琐步骤\n✅ 拍下即发 极速下载 随时随地畅玩！",
      is_admin: true
    },
    {
      id: 2,
      account_key: "account_a",
      template_text: "【{{VAR_1}} ({{VAR_2}}) 纯净单机中文安装包】\n⚡ 官方正品Steam分流下载 | 解压即玩 | 无毒无捆绑\n✅ 包含最新版本全套DLC + 汉化补丁 + 详细图文教程\n💬 售后客服一对一指导，包教包会！"
    },
    {
      id: 3,
      account_key: "account_b",
      template_text: "🔥【{{VAR_1}} ({{VAR_2}}) Steam离线极速安装包】🔥\n✨ 告别下载慢！百度网盘/迅雷/直连高速分流！\n🛠️ 自带一键启动器与全成就解锁，随时畅玩！\n需要的直接联系，拍下秒发！"
    }
  ];

  let publishedLogs: PublishedLog[] = [];

  // ==========================================
  // API Routes (适配 api.php)
  // ==========================================

  app.all("/api.php", (req, res) => {
    try {
      const body = req.body || {};
      const query = req.query || {};
      const action = (query.action as string) || body.action || "";

      // 1. 免密登录
      if (action === "login") {
        const username = (((query.username as string) || body.username || "") as string).trim();
        if (!username) {
          return res.json({ status: "error", message: "请输入账号 Key (用户名)" });
        }

        const user = accounts.find(a => a.account_key === username);

        if (!user) {
          return res.json({ status: "error", message: "账号不存在，请联系管理员在控制台添加！" });
        }

        const isAdmin = !!(user.is_admin || user.account_key === "admin");
        return res.json({
          status: "success",
          user: {
            account_key: user.account_key,
            template_text: user.template_text,
            is_admin: isAdmin,
            role: isAdmin ? "admin" : "sub_account"
          }
        });
      }

      // 2. 获取所有账号及模板
      if (action === "get_accounts") {
        const accountsData = accounts.map(a => ({
          ...a,
          is_admin: !!(a.is_admin || a.account_key === "admin")
        }));
        return res.json({ status: "success", accounts: accountsData });
      }

      // 3. 获取游戏列表
      if (action === "get_games") {
        const account_key = (query.account_key as string) || "admin";
        const publishedIds = publishedLogs
          .filter(l => l.account_key === account_key)
          .map(l => l.game_id);

        const result = games.map(g => ({
          ...g,
          game_name: g.game_name_en ? `${g.game_name_cn} (${g.game_name_en})` : g.game_name_cn,
          is_published: publishedIds.includes(g.id)
        }));

        return res.json({ status: "success", games: result });
      }

      // 4. 管理员仪表板统计
      if (action === "get_dashboard_stats") {
        const totalGames = games.filter(g => g.is_active === 1).length;
        const totalAccounts = accounts.length;
        const totalPublishes = publishedLogs.length;

        const account_details = accounts.map(a => {
          const publishedCount = publishedLogs.filter(p => p.account_key === a.account_key).length;
          return {
            account_key: a.account_key,
            published_count: publishedCount
          };
        });

        return res.json({
          status: "success",
          stats: {
            total_games: totalGames,
            total_accounts: totalAccounts,
            total_publishes: totalPublishes,
            account_details
          }
        });
      }

      // 5. 发布游戏
      if (action === "publish_game") {
        const { account_key, game_id } = body;
        if (!account_key || !game_id) {
          return res.status(400).json({ status: "error", message: "参数缺失" });
        }

        if (!publishedLogs.some(l => l.account_key === account_key && l.game_id === Number(game_id))) {
          publishedLogs.push({ account_key, game_id: Number(game_id) });
        }

        return res.json({ status: "success", message: "已成功发布并记入归档！" });
      }

      // 5b. 取消隐藏 / 恢复单个游戏
      if (action === "unpublish_game") {
        const { account_key, game_id } = body;
        if (!account_key || !game_id) {
          return res.status(400).json({ status: "error", message: "参数缺失" });
        }

        publishedLogs = publishedLogs.filter(l => !(l.account_key === account_key && l.game_id === Number(game_id)));
        return res.json({ status: "success", message: "已成功取消隐藏，恢复至待发布列表！" });
      }

      // 6. 重置游戏列表显示
      if (action === "reset_account_games") {
        const { account_key } = body;
        if (!account_key) {
          return res.status(400).json({ status: "error", message: "参数缺失" });
        }

        publishedLogs = publishedLogs.filter(l => l.account_key !== account_key);
        return res.json({ status: "success", message: "账号发布日志已重置，游戏列表已恢复显示！" });
      }

      // 7. 更新模板
      if (action === "update_template") {
        const { account_key, template_text } = body;
        if (!account_key) {
          return res.status(400).json({ status: "error", message: "参数缺失" });
        }

        const acc = accounts.find(a => a.account_key === account_key);
        if (acc) {
          acc.template_text = template_text;
        }

        return res.json({ status: "success", message: "模板修改成功" });
      }

      // 8. 批量导入商品 (格式: [参数1]+[参数2]，支持 \n 换行)
      if (action === "import_games") {
        const { import_text } = body;
        if (!import_text || !import_text.trim()) {
          return res.json({ status: "error", message: "导入内容不能为空" });
        }

        // 支持真实换行与字面量 \n 字符
        const normalizedText = import_text.replace(/\\n/g, "\n");
        const lines = normalizedText.split(/\r?\n/);
        let count = 0;
        let skippedCount = 0;
        let nextId = games.length > 0 ? Math.max(...games.map(g => g.id)) + 1 : 1;
        const seenInBatch = new Set<string>();

        for (const line of lines) {
          const trimmed = line.trim();
          if (!trimmed) continue;

          let cn = "";
          let en = "";

          // 仅采用 [参数1]+[参数2] 格式解析 (如 [黑神话：悟空]+[Black Myth: Wukong])
          const match = trimmed.match(/^\[(.*?)\](?:\s*\+\s*\[(.*?)\])?$/);
          if (match) {
            cn = match[1].trim();
            en = (match[2] || "").trim();
          } else {
            const flexMatch = trimmed.match(/\[(.*?)\](?:\s*\+\s*\[(.*?)\])?/);
            if (flexMatch) {
              cn = flexMatch[1].trim();
              en = (flexMatch[2] || "").trim();
            } else {
              cn = trimmed;
              en = "";
            }
          }

          if (cn) {
            const key = `${cn}|||${en}`;
            const existsInDb = games.some(g => g.game_name_cn === cn && g.game_name_en === en);
            if (existsInDb || seenInBatch.has(key)) {
              skippedCount++;
              continue;
            }
            seenInBatch.add(key);

            games.push({
              id: nextId++,
              game_name_cn: cn,
              game_name_en: en,
              is_active: 1
            });
            count++;
          }
        }

        if (count === 0 && skippedCount > 0) {
          return res.json({ status: "info", message: `导入列表中所有商品（共 ${skippedCount} 款）均已存在于库中，未重复添加` });
        } else if (skippedCount > 0) {
          return res.json({ status: "success", message: `成功导入 ${count} 款新商品，自动过滤并跳过 ${skippedCount} 款重复商品` });
        } else {
          return res.json({ status: "success", message: `成功批量导入 ${count} 款商品` });
        }
      }

      // 9. 添加单个商品
      if (action === "add_game") {
        const { game_name_cn, game_name_en } = body;
        if (!game_name_cn || !game_name_cn.trim()) {
          return res.json({ status: "error", message: "商品主名称不能为空" });
        }

        const cn = game_name_cn.replace(/\\n/g, "\n").trim();
        const en = (game_name_en || "").replace(/\\n/g, "\n").trim();

        const exists = games.some(g => g.game_name_cn === cn && g.game_name_en === en);
        if (exists) {
          return res.json({ status: "error", message: "该商品（名称与型号完全一致）已存在，无需重复添加！" });
        }

        const nextId = games.length > 0 ? Math.max(...games.map(g => g.id)) + 1 : 1;

        games.push({
          id: nextId,
          game_name_cn: cn,
          game_name_en: en,
          is_active: 1
        });

        return res.json({ status: "success", message: "商品添加成功" });
      }

      // 10. 删除游戏
      if (action === "delete_game") {
        const { game_id } = body;
        const gid = Number(game_id);
        games = games.filter(g => g.id !== gid);
        publishedLogs = publishedLogs.filter(l => l.game_id !== gid);
        return res.json({ status: "success", message: "游戏已彻底从数据库与已隐藏记录中删除" });
      }

      // 11. 添加账号
      if (action === "add_account") {
        const { account_key, template_text, is_admin } = body;
        if (!account_key) {
          return res.status(400).json({ status: "error", message: "账号标识 (用户名) 不能为空" });
        }

        if (accounts.some(a => a.account_key === account_key.trim())) {
          return res.status(400).json({ status: "error", message: "账号标识已存在" });
        }

        accounts.push({
          id: Date.now(),
          account_key: account_key.trim(),
          template_text: template_text || "【{{VAR_1}} ({{VAR_2}}) Steam正品游戏安装包 离线版/中文版】\n⚡ 自动发货 | 包含全套完整安装包+DLC扩展+终身更新\n✅ 送详细视频教程+远程协助安装 简体中文 免Steam繁琐步骤",
          is_admin: !!is_admin
        });

        return res.json({ status: "success", message: "账号创建成功" });
      }

      // 12. 设置或取消管理员权限 (保证至少保留一个管理员)
      if (action === "toggle_admin") {
        const { account_key, is_admin } = body;
        const targetKey = (account_key || "").trim();
        if (!targetKey) {
          return res.status(400).json({ status: "error", message: "参数缺失" });
        }

        const targetAcc = accounts.find(a => a.account_key === targetKey);
        if (!targetAcc) {
          return res.json({ status: "error", message: "未找到该账号" });
        }

        const totalAdmins = accounts.filter(a => !!(a.is_admin || a.account_key === "admin")).length;
        const currentTargetIsAdmin = !!(targetAcc.is_admin || targetAcc.account_key === "admin");

        if (!is_admin && currentTargetIsAdmin && totalAdmins <= 1) {
          return res.json({ status: "error", message: "操作失败：系统中至少需要保留一个管理员账号！" });
        }

        targetAcc.is_admin = !!is_admin;
        const msg = targetAcc.is_admin ? "已成功设置为管理员！" : "已取消该账号的管理员权限！";
        return res.json({ status: "success", message: msg, is_admin: targetAcc.is_admin });
      }

      // 13. 删除账号 (若目标为管理员，确保系统中至少保留一个管理员)
      if (action === "delete_account") {
        const { account_key } = body;
        const targetKey = (account_key || "").trim();
        const acc = accounts.find(a => a.account_key === targetKey);
        if (!acc) {
          return res.json({ status: "error", message: "未找到该账号" });
        }

        const isTargetAdmin = !!(acc.is_admin || acc.account_key === "admin");
        const totalAdmins = accounts.filter(a => !!(a.is_admin || a.account_key === "admin")).length;

        if (isTargetAdmin && totalAdmins <= 1) {
          return res.status(400).json({ status: "error", message: "无法删除：系统中至少需要保留一个管理员账号！" });
        }

        accounts = accounts.filter(a => a.account_key !== targetKey);
        publishedLogs = publishedLogs.filter(l => l.account_key !== targetKey);
        return res.json({ status: "success", message: "账号及相关记录已成功删除" });
      }

      // 14. 修改账号 Key (用户名)
      if (action === "update_account_key") {
        const { old_account_key, new_account_key } = body;
        const oldKey = (old_account_key || "").trim();
        const newKey = (new_account_key || "").trim();

        if (!oldKey || !newKey) {
          return res.status(400).json({ status: "error", message: "新旧账号 Key 不能为空" });
        }

        if (oldKey !== newKey) {
          if (accounts.some(a => a.account_key === newKey)) {
            return res.status(400).json({ status: "error", message: "该账号 Key 已存在，请使用其他名称" });
          }

          const acc = accounts.find(a => a.account_key === oldKey);
          if (acc) {
            acc.account_key = newKey;
          }

          publishedLogs.forEach(l => {
            if (l.account_key === oldKey) {
              l.account_key = newKey;
            }
          });
        }

        return res.json({ status: "success", message: "账号名称修改成功！", new_account_key: newKey });
      }

      return res.status(404).json({ status: "error", message: "未知的 action 参数" });
    } catch (err) {
      console.error("API handler error:", err);
      return res.status(500).json({ status: "error", message: "服务器内部接口错误" });
    }
  });

  // Vite middleware
  if (process.env.NODE_ENV !== "production") {
    const vite = await createViteServer({
      server: { middlewareMode: true },
      appType: "spa",
    });
    app.use(vite.middlewares);
  } else {
    const distPath = path.join(process.cwd(), "dist");
    app.use(express.static(distPath));
    app.get("*", (req, res) => {
      res.sendFile(path.join(distPath, "index.html"));
    });
  }

  app.listen(PORT, "0.0.0.0", () => {
    console.log(`Server running on http://localhost:${PORT}`);
  });
}

startServer();
