# AGENTS.md

给后续接手这个仓库的代理 / 开发者的工作约定。**只写约定，不写功能说明书**（功能见 `README.md`）。

---

## 收尾流程（每次改完必须执行）

一轮改动做完、自测通过之后，按顺序做完这三件事再交付：

### 1. 删除数据库

```bash
rm -f data/todo.sqlite data/todo.sqlite-wal data/todo.sqlite-shm
```

- 数据库是**运行时产物**，不进版本库（`.gitignore` 已忽略 `data/`），也不该留在工作区。
- 库里装的是演示待办，不是源码。留着它，下次跑起来会看到上一轮的残留，容易把「测试数据」误当成真实状态。
- 删掉之后首次访问会自动重建空库并写入默认分类（学习 / 生活 / 事务），这是预期行为。

### 2. 自测

删库前后都要保证页面能正常打开：

```bash
# 语法检查
for f in lib/*.php *.php; do php -l "$f"; done
node --check assets/app.js
node --check assets/admin.js
```

删库后重新访问一次页面，确认能自动建库、不报 500。

### 3. 提交

```bash
git add -A
git commit -m "<type>: <一句话说明>"
```

- commit message 用中文或英文都行，但**一句话讲清改了什么**，例如 `feat: 待办置顶`、`fix: 窄屏丢失截止时间`。
- 不要提交 `data/` 下的任何东西，也不要为了提交去 `git add -f` 它。
- 使用环境变量提供登录口令与 Cookie 签名密钥，禁止将其实际值提交到仓库。

---

## 环境

- **PHP 8.1+，必须启用 `pdo_sqlite`、`sqlite3`、`mbstring`、`ctype`。**
- 本地起服务：`php -S 127.0.0.1:8000 router.php`（`router.php` 复刻 `.htaccess` 的规则；PHP 自带的开发服务器不读 `.htaccess`）。
- 本地测试前设置 `TODO_AUTH_PASSWORD` 和 `TODO_COOKIE_SECRET`。前后台共用同一个口令。
- 时间**一律按北京时间**（`Asia/Shanghai`），与服务器时区无关。新写的代码不要用 `date()` 裸取本地时间，用 `tl_now()` / `tl_today()`。

---

## 代码约定

### 目录分工

| 路径 | 职责 |
| --- | --- |
| `config.php` | 唯一的配置入口（口令、时区、上限、伪静态开关） |
| `lib/core.php` | 配置读取、北京时间、SQLite、登录 cookie、CSRF、flash、站内地址生成 |
| `lib/tasks.php` | 分类、待办、重复展开、发生记录、批量、清理 |
| `lib/feed.php` | 前台分段编排（置顶 / 逾期 / 近一周 / 未设截止日） |
| `lib/view.php` | 共用视图片段（顶栏、任务行、详情、对话框） |
| `assets/app.js` | 全局交互 + PJAX |
| `assets/admin.js` | 后台专属交互 |
| `.htaccess` / `deploy/nginx.conf` / `router.php` | 伪静态映射 + 屏蔽 `data/` 等敏感路径（三处规则要同步） |

### 站内地址（伪静态）

- **不要在模板里手写 `index.php?…` / `admin.php?…`**：用 `tl_front_url()` / `tl_admin_url()` / `tl_login_url()` / `tl_logout_url()` / `tl_action_url()`。
- 地址是**绝对路径**（带应用前缀 `/todo` 之类），不能写相对地址 —— 页面在 `/tracked/` 目录下时，相对地址会被解析到 `/tracked/assets/…`。
- 路径只放「这一页是什么」，筛选条件（分类 / 搜索 / 状态 / 分页）留在查询串里。
- 改了地址映射，`.htaccess`、`deploy/nginx.conf`、`router.php`、`lib/core.php` **四处一起改**。
- 服务器不支持 rewrite 时：`config.php` 的 `app.pretty_url = false`，地址退回 `index.php?…`，功能不变。
- 改 `deploy/nginx.conf` 时记住 nginx 的三个坑：**正则带 `{ }` 必须加引号**（解析器把 `{` 当配置块开头，否则报 `pcre2_compile() failed: missing closing parenthesis`）；**`rewrite` 里要重写一遍正则**（location 的捕获组不保证能用在替换串里）；**别用 `try_files /index.php` 转发到 PHP**（try_files 不重新选 location，会把 index.php 当静态文件吐出去，源码直接能被下载，改用 `rewrite ... last` 或 `index`）。片段里不写 `location /`，免得和面板自带的重复。

### 数据库

- 只有**一份**建表 SQL，在 `tl_db_migrate()` 里，全部 `CREATE TABLE IF NOT EXISTS` / `CREATE INDEX IF NOT EXISTS`。
- **不要写惰性升级**（`PRAGMA user_version` 版本号 + `table_info` 探测 + `ALTER TABLE ADD COLUMN`）。要加列就改建表语句；本地删库重跑即可，不背老数据。
- 重复任务 = 一条任务 + 若干「发生」（`task_occurrences`），不要为每一天各建一行。

### 前端

- 页面之间走 **PJAX**：链接、筛选、表单都原地换内容。所以：
  - 全局监听用**文档级事件委托**，只绑一次（`initGlobal`）；
  - 依赖具体节点的初始化放进 `TL.onPageInit()` / `initPage()`，每次换页重跑；
  - 新页面的 `<script>` 由 `loadScripts()` 按需补装，别把 `<script>` 搬进 body。
- 桌面端（≥981px）是应用外壳，滚动发生在 `.tl-main__scroll` 内部；窄屏交还文档滚动。
- 样式只读 EDS 令牌，不重写 `eds-*` 组件规则。页面内的常规操作按钮使用直角矩形，且文字在各配色 / 明暗下都要可读。
- 二次确认（含删除分类）沿用 v1.0.0 的通栏纸带样式。浅色纸带横贯屏幕，上下保留遮罩。深色「取消」和强调色「确认」使用带内描边和尾部圆形图标的胶囊按钮，跨在纸带下缘。
- **EDS 的两份 CSS 有两处存放位置，别搞混：**
  - 页面运行时引用的是 `assets/eds/tokens.css`、`assets/eds/components.css`。**云端只部署 `assets/`，`design/` 不上传**，所以页面只能指向 `assets/eds/`，指向 `design/` 线上必 404。
  - `design/` 下也保留同名副本，供 showcase、对比度门禁和本地阅读用；它不参与部署。
  - 改动样式时**两份都要同步**（`assets/eds/` 与 `design/`），否则本地看着对、线上或 showcase 是旧的。
- 静态资源一律用 `tl_asset()` 输出地址，带内容指纹；线上 CDN 会缓存好几天，不带指纹改了也不生效。

### 文案

- 避免不必要的辅助标签与说明。让布局本身发挥解释作用。
- 不写无意义的说明性废话。空状态、提示语能省则省。
- 保留有信息量的东西：时间、数量、分类名、`#id`。

---

## 测试

- E2E 用 Playwright（`/tmp/pw` 或临时目录里装一份即可）。
- **测试绝不要跑在真实数据上。** 涉及删除、清理、批量改状态的流程，先切到临时库：改 `config.php` 的 `db` 指向 `/tmp/xxx.sqlite`，或直接复制一份库再测。这个仓库里的 `data/todo.sqlite` 删掉就没了。
- 涉及跨天 / 相对日期的逻辑，用 `config.php` 的 `app.clock` 固定时间验证。
- 收尾时按上面的流程删库 + 提交。
