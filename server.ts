import express from "express";
import path from "path";
import { createServer as createViteServer } from "vite";

interface Game {
  id: number;
  game_name_cn: string;
  game_name_en: string;
  game_name?: string;
  cover_url?: string;
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

  // dev 模式下闲鱼配置的进程内存储（仅内存，重启即丢）
  const devXianyuConfigs: Record<string, any> = {};
  // 必须与 api.php / schema.sql 的 xianyu_config 列保持一致，
  // 否则 dev 模式下前端拿不到这些字段，联调出来的界面和 PHP 生产环境不一致。
  const devXianyuDefault = (accountKey: string) => ({
    account_key: accountKey,
    xy_server_url: "",
    xy_secret_key: "",
    xy_account_id: "",
    xy_account_remark: "",
    publish_enabled: 0,
    publish_times: "09:00",
    publish_price: "9.90",
    publish_original_price: "0",
    publish_address: "",
    publish_quantity: 1,
    publish_shipping_method: "free",
    publish_category_id: "",
    publish_category_name: "",
    publish_channel_cat_id: "",
    publish_channel_cat_name: "",
    publish_leaf_id: "",
    publish_tb_cat_id: "",
    image_source: "auto",
    custom_image_url: "",
    last_publish_at: null,
    last_publish_status: "",
  });

  // 与 api.php 的 maskSecretKey() 保持一致：
  // dev 模式若不脱敏，前端在 dev 下"看起来正常"，到生产才发现秘钥被脱敏导致回填逻辑有问题。
  const maskSecretKeyDev = (key: string): string => {
    if (!key) return "";
    if (key.length <= 8) return "*".repeat(key.length);
    return key.slice(0, 3) + "*".repeat(6) + key.slice(-3);
  };

  // 内存数据服务 (与 MySQL 保持一致)
  let games: Game[] = [
    { id: 1, game_name_cn: "黑神话：悟空", game_name_en: "Black Myth: Wukong", cover_url: "", is_active: 1 },
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

  app.all("/api.php", async (req, res) => {
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

        // 与 PHP 一致：只返回 is_active=1 的商品，且 cover_url 恒定为空字符串（不是 undefined）。
        // 否则 dev 下能渲染、生产却少了字段，属于"联调骗人"的差异。
        const result = games
          .filter(g => (g as any).is_active !== 0)
          .map(g => ({
            ...g,
            cover_url: (g as any).cover_url ?? "",
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
        // 与 api.php 一致：非法 id 返回 400，否则 dev 下"删什么都成功"，掩盖真实错误路径
        if (!Number.isFinite(gid) || gid <= 0) {
          return res.status(400).json({ status: "error", message: "参数错误" });
        }
        const before = games.length;
        games = games.filter(g => g.id !== gid);
        if (games.length === before) {
          return res.status(404).json({ status: "error", message: "商品不存在" });
        }
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

      // 15. 更新游戏封面图片 URL
      if (action === "update_game_cover") {
        const { game_id, cover_url } = body;
        const game = games.find(g => g.id === Number(game_id));
        if (!game) return res.json({ status: "error", message: "商品不存在" });
        (game as any).cover_url = cover_url || "";
        return res.json({ status: "success", message: "封面图片已更新" });
      }

      // 16. 获取闲鱼定时发布配置 (dev 模式: 内存态，便于联调前端开关)
      if (action === "get_xianyu_configs") {
        return res.json({
          status: "success",
          configs: accounts.map(a => {
            const merged = {
              ...devXianyuDefault(a.account_key),
              ...(devXianyuConfigs[a.account_key] || {}),
            };
            const rawSecret = String(merged.xy_secret_key || "");
            return {
              account_key: a.account_key,
              is_admin: !!(a.is_admin || a.account_key === "admin"),
              ...merged,
              // 与 PHP 一致：回显脱敏秘钥 + 是否已配置的标志
              xy_secret_key: maskSecretKeyDev(rawSecret),
              xy_secret_key_set: rawSecret ? 1 : 0,
              xy_configured: !!(merged.xy_server_url && merged.xy_account_id) ? 1 : 0,
            };
          })
        });
      }

      // 17. 保存闲鱼配置 (dev 模式: 写入内存，回读 publish_enabled 供前端即时刷新)
      if (action === "save_xianyu_config") {
        const key = String(body.account_key || "");
        if (!key) return res.json({ status: "error", message: "账号不能为空" });
        const prev = devXianyuConfigs[key] || devXianyuDefault(key);
        devXianyuConfigs[key] = {
          ...prev,
          ...body,
          account_key: key,
          publish_enabled: body.publish_enabled ? 1 : 0,
          publish_times: String(body.publish_times || "09:00"),
          publish_price: String(body.publish_price ?? "9.90"),
        };
        return res.json({
          status: "success",
          message: "闲鱼定时发布配置已保存 (dev 模式仅保存在内存中)",
          publish_enabled: devXianyuConfigs[key].publish_enabled,
        });
      }

      // 18. 测试闲鱼连接 (dev 模式模拟)
      if (action === "test_xianyu_connection") {
        const { xy_server_url, xy_secret_key } = body;
        if (!xy_server_url || !xy_secret_key) {
          // 与 api.php 一致：参数缺失是 400，不是 200 + status:"error"
          return res.status(400).json({ status: "error", message: "服务地址和分销秘钥不能为空" });
        }
        try {
          const resp = await fetch(xy_server_url.replace(/\/$/, "") + "/api/v1/external/enabled-accounts", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ secret_key: xy_secret_key }),
          });
          const data = await resp.json();
          if (data.success) {
            const accounts = data.data?.accounts || [];
            const total = data.data?.total ?? accounts.length;
            const enabledTotal = data.data?.enabled_total ?? accounts.filter((a: any) => a.enabled).length;
            const hint = enabledTotal < total ? `（其中 ${enabledTotal} 个启用、${total - enabledTotal} 个已禁用；禁用账号同样可用于发布）` : "";
            return res.json({
              status: "success",
              message: `连接成功！该分销秘钥下共 ${total} 个闲鱼账号${hint ? "，" + hint : ""}`,
              accounts,
              total,
              enabled_total: enabledTotal,
              disabled_total: total - enabledTotal,
            });
          }
          return res.json({ status: "error", message: data.message || "连接失败" });
        } catch (e: any) {
          return res.json({ status: "error", message: "连接失败: " + e.message });
        }
      }

      // 17b. 快速启用/停用某用户的定时发布 (dev 模式模拟)
      if (action === "toggle_xianyu_publish") {
        const { account_key, publish_enabled } = body;
        if (!account_key) return res.json({ status: "error", message: "账号不能为空" });
        const enabled = publish_enabled ? 1 : 0;
        const prev = devXianyuConfigs[account_key] || devXianyuDefault(account_key);
        devXianyuConfigs[account_key] = { ...prev, publish_enabled: enabled };
        return res.json({ status: "success", publish_enabled: enabled, message: enabled ? "已启用定时发布" : "已停用定时发布" });
      }

      // 19. 手动发布 (dev 模式模拟)
      if (action === "manual_publish") {
        return res.json({ status: "success", message: "dev 模式不支持实际发布，请在生产环境 (PHP) 中使用", data: {} });
      }

      // 19.1 自检：测试联网搜图 (dev 模式模拟)
      // 联网搜图依赖 PHP 的 curl + 搜索引擎解析，dev 的 Node 后端并未实现，
      // 这里返回明确说明，避免前端因为拿不到该 action 而报「未知的 action 参数」。
      if (action === "test_image_search") {
        return res.json({
          status: "error",
          message: "dev 模式未实现联网搜图，请在生产环境 (PHP + curl) 中使用该功能",
          data: { keywords: [], providers: [], candidate_count: 0, candidates: [], downloaded: null },
        });
      }

      // 20. 获取闲鱼发布日志
      if (action === "get_xianyu_publish_logs") {
        return res.json({ status: "success", logs: [], total: 0, page: 1, page_size: 20, total_pages: 1 });
      }

      // 21. 定时发布调度入口 (dev 模式模拟)
      if (action === "run_scheduled_publish") {
        return res.json({ status: "success", message: "dev 模式定时发布调度入口已触发 (无实际发布)", results: [] });
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
