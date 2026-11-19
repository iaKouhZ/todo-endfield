# 待办清单 · TODO LEDGER

一个前后台分离的网页版待办清单。PHP + SQLite，界面基于工作区的 **EDS（Editorial Design System）**：奶油纸底、墨黑文字、一支信号色、全直角。

全部时间按**北京时间（Asia/Shanghai）**处理，与服务器时区无关。

顶栏时钟是**实时的**：以服务端渲染时刻为基准，由前端按北京时间继续走秒（与浏览者所在时区无关）。跨过北京时间的午夜时会**原地重取**一次页面 —— 因为「近一周」「已逾期」这些分段是服务端算的，不重取的话昨天的「今天」会继续挂在近一周里。

页面之间走 **PJAX**：链接、筛选、表单提交都在原地换内容，浏览器不重新加载文档，所以滚动位置、明暗、折叠状态都是连续的。点开一张卡片只换右侧详情，左栏停在原处；换选项卡、分类或搜索词才回到顶部。顶边有一条 2px 的进度条作为唯一的加载反馈；任何一步失败都会退回整页跳转，不会卡住。GET 导航带请求序号与 `AbortController`，慢网下旧响应不会覆盖新页面；POST 写入串行，写入期间会排队最后一次 GET。`pageshow` 从往返缓存恢复时会重新请求服务端，避免跨天分段停留在旧日期。

静态资源都带内容指纹（`assets/app.js?v=ab12cd34`，取自文件修改时间）。线上有 CDN 缓存，不带指纹的话改完 JS 浏览器拿到的还是旧版本，看上去就像改动没生效。

---

## 配套 App

客户端源码及下载见 [flutter-todo](https://github.com/iaKouhZ/flutter-todo)。

---

## 快速开始

```bash
# 需要 PHP 8.1+，并启用 pdo_sqlite、sqlite3、mbstring、ctype
read -r -s -p '设置登录口令: ' TODO_AUTH_PASSWORD; printf '\n'
export TODO_AUTH_PASSWORD
export TODO_COOKIE_SECRET="$(php -r 'echo bin2hex(random_bytes(32));')"
php -S 127.0.0.1:8000 router.php
```

> 用 `router.php` 而不是 `-t .`：PHP 自带的开发服务器不读 `.htaccess`，
> `router.php` 复刻了同一套规则（伪静态映射 + 屏蔽 `data/`），本地与线上行为一致。

打开 <http://127.0.0.1:8000/>，用上面设置的口令登录。

- 前后台**同一个密码**，登录一次即可同时进入前台与后台
- 首次运行自动创建 `data/todo.sqlite` 并写入三个示例分类

---

## 伪静态与访问保护

地址形如 `/tracked/task/12/`，不再是 `index.php?tab=tracked&task=12`。

| 地址 | 入口 | 说明 |
| --- | --- | --- |
| `/` | `index.php` | 前台默认视图（追踪中） |
| `/tracked/` `/planned/` `/done/` `/all/` | `index.php?tab=…` | 前台四个选项卡 |
| `/tracked/task/12/` | `index.php?tab=tracked&task=12` | 选中某条 |
| `/tracked/task/12/2026-10-24/` | 同上 + `date` | 选中重复任务的那一次发生 |
| `/admin/` | `admin.php?view=tasks` | 后台默认视图 |
| `/admin/tasks/` `/admin/categories/` `/admin/system/` | `admin.php?view=…` | 后台三个视图 |
| `/admin/tasks/12/` | `admin.php?view=tasks&edit=12` | 后台编辑某条 |
| `/login/` `/logout/` `/action/` | `login.php` / `logout.php` / `action.php` | 登录 / 退出 / 写操作入口 |

**筛选条件留在查询串里**（分类、搜索、状态、分页、周次范围）——它们可以任意组合，
塞进路径只会得到一堆长得吓人的地址。`?cat=3&q=周报&page=2` 照旧可用。

### 三份服务器配置

| 文件 | 用途 |
| --- | --- |
| `.htaccess` | Apache（需 `mod_rewrite`，站点目录允许 `AllowOverride FileInfo`） |
| `deploy/nginx.conf` | nginx（1Panel：网站 → 站点目录 → 伪静态，即 `/www/sites/<站点>/rewrite/<站点>.conf`） |
| `router.php` | 本地 `php -S` 开发服务器 |

三处规则必须与 `lib/core.php` 里的地址映射保持一致，改了一处就要同步另外三处。

**nginx 有三个坑**（`deploy/nginx.conf` 里已按正确写法给出）：

1. 正则里带 `{ }` 必须加引号 —— nginx 的配置解析器把 `{` 当成「配置块开始」，
   `(\d{4}-\d{2}-\d{2})` 会被截断成 `(\d`，报
   `pcre2_compile() failed: missing closing parenthesis`。
2. `rewrite` 里要重新写一遍正则 —— location 的捕获组不保证能直接用在
   rewrite 的替换串里。
3. **别用 `try_files /index.php` 转发到 PHP** —— try_files 命中文件后不重新选
   location，拿当前 location 的 content handler 处理；当前 location 没有
   `fastcgi_pass`，静态模块就会把 `index.php` 当普通文件吐出来，浏览器下载到
   的是 PHP 源码（`Content-Type: application/octet-stream`）。
   要转发就得用 `rewrite ... last` 或 `index`，它们会重新选 location，
   落到 `location ~ \.php$` 上。

nginx 片段里刻意**不写** `location /`：面板的站点配置通常已经有一个，
再写一个会报 `duplicate location /`。

### 屏蔽敏感路径

`data/` 是运行时数据（SQLite 库及其 `-wal` / `-shm` 文件），
规则会直接 403，不给 PHP 解释器任何机会。同时屏蔽的还有：

- `config.php` —— 里面有口令和 cookie 密钥
- `lib/` —— 内部实现
- `*.sqlite` / `*.log` / `*.ini` / `*.bak` / `*.sql` / `*.json` —— 数据库与备份
- 隐藏文件（`.git` / `.env` / `.htaccess`）
- 其余不存在的路径一律 403，目录列表因此也开不了

### 服务器不支持 rewrite 怎么办

把 `config.php` 里的 `app.pretty_url` 改成 `false`，地址退回 `index.php?…` 的老样子，
功能完全不变（服务器配置留着也不影响，老地址照样能开）。

---

## 访问控制

| 设置 | 位置 | 说明 |
| --- | --- | --- |
| 口令 | `TODO_AUTH_PASSWORD` 环境变量 | 前后台共用。更改口令后，已签发的登录 Cookie 失效 |
| Cookie 签名密钥 | `TODO_COOKIE_SECRET` 环境变量 | 使用独立、随机的密钥，更换后需重新登录 |
| 前台公开 | `config.php` → `auth.public` | `true` = 前台免密公开展示；后台**始终**需要口令 |
| 登录状态 | 签名 cookie | `tl_admin`（12 小时）、`tl_front`（30 天），HMAC 签名，`HttpOnly` + `SameSite=Lax` |

未登录访问前台（`/` 或 `/tracked/`）会跳到登录页；未登录访问后台（`/admin/`）同样。所有写操作都校验 CSRF token 与后台登录态。

---

## 前台

单个主视图 **追踪中**（它就是时间线，也是进行中），固定四段顺序：

| 段 | 内容 | 默认 |
| --- | --- | --- |
| 01 置顶 | 已置顶的未完成事项，无论截止日落在哪个时间段都收在这里 | 展开 |
| 02 已逾期 | 已截止但未完成，最多 10 条 | 展开 |
| 03 近一周 | 今天至 7 天后 | 展开 |
| 04 未设截止日与远期 | 先列未设截止日的待办，再按截止时间升序列出超出 `front.week_to` 的待办 | 展开 |

置顶段没有置顶项时整段不出现，后面的编号顺延，所以平时仍然是 01 已逾期 起头。
置顶段是一条**扁平列表**（不按分类分组，否则时间顺序会被打散），按截止时间升序：已逾期 → 近一周 → 更晚 → 未设置截止日。
置顶项已经从原时间段里剔除，不会重复出现。

已逾期和近一周段**先按分类分组**（跟随后台的分类顺序，未分类最后），**分类内再按截止时间**排序。

逾期单独成段而不是混进「近一周」——否则今天的排期看上去会像已经过期。**每一段、每一段内的每个分类都可以折叠**，折叠状态存在 localStorage，刷新后保持。

其余选项卡：

| 选项卡 | 内容 | 分段 |
| --- | --- | --- |
| 规划中 | 未完成且**没有**追踪标记（与追踪中互补，不重叠） | 01 置顶 / 02 已逾期 / 03 未逾期，都可折叠，空段自动折叠 |
| 已完成 | 已完成的事项 | 按分类归组 |
| 全部 | 全部未删除的事项 | 按分类归组 |

上面的 **追踪中** 主视图包含：**未完成且已追踪**（`tracked=1`）—— 与任务 `status` 是进行中还是规划中无关，只要未完成且打了追踪就会出现在时间线里。

选项卡角标按**整条任务**计数；追踪中各分段内的「N 条」按**展开后的发生条目**计。标记为「已完成」会同时取消追踪；恢复为未完成不会自动恢复追踪。

「规划中」的逾期判定看截止日是否早于今天；没有截止日的事项归入「未逾期」——它没有排期，不算错过。
置顶段在「追踪中」与「规划中」各管各的：置顶 + 有追踪标记 → 追踪中的置顶段，置顶 + 无追踪标记 → 规划中的置顶段。

左右两栏：

- **左**：任务列表。支持按分类筛选、按「摘要 / 详情 / 分类」搜索
- **右**：任务详情，上中下三段 —— 上=摘要（标题），中=详情（后台留空则显示标题），下=操作区
  - 操作区**只在后台登录时出现**，密集排布于右下角：`标记为完成/未完成`、`添加/移除追踪`、`置顶/取消置顶`

重复任务在时间范围内逐日展开：设成「每天」的待办会从今天一直排到窗口最后一天。勾掉其中某一天只记录那一次发生，整串任务不会消失。月重复按「锚点日号 + 每月夹取」展开（如 1-31 → 2-28 → 3-31）。整串已完成时，详情里按整条任务操作，避免把「恢复未完成」误当成单日操作；单日完成必须带 `date` 且该日在规则序列上。

**子任务**：在后台编辑或前台详情（已登录）维护，每条独立勾选完成；主任务标为已完成时，所有子任务同步为已完成；主任务从已完成改回进行中/规划时，子任务勾选一并清空。

---

## 后台

三个视图：

### 01 待办事项
- 新增 / 编辑 / 删除
- 字段：摘要（必填）、详情（可省略）、分类、截止日期、截止时间（可只设日期，也可都不设）、重复频率（每 X 天 / 周 / 月）
- 状态：进行中 / 规划中 / 已完成
- 多选后一键设置状态或删除（批量与行内删除分属独立表单，避免嵌套）
- 按状态、分类、关键词、是否追踪筛选；分页

### 02 分类设置
- 新增 / 重命名 / 删除
- 删除时可选：**一并删除**分类下的待办，或**全部归为未分类**

### 03 维护
- 一键清除**已完成且完成时间超过 7 天**的待办（阈值 `config.php` → `purge_days`）
- 数据概况与配置摘要
- 退出后台

- 注销全部设备可立即使已有网页登录会话失效。Flutter 里保存了密码的设备可以自动恢复连接。
- 同一 IP 连续输错 5 次会锁定 15 分钟。反向代理部署由 Web 服务器负责配置可信来源的真实客户端地址。
- 维护页支持 JSON 和 SQLite 备份。JSON 导入会覆盖任务数据，并保留当前登录配置与会话状态。若要上传接近 10 MB 的备份，PHP 的 `upload_max_filesize` 应至少为 `10M`，且 `post_max_size` 应更大。

### 从 1.0.0 迁移已有数据

准备一份独立的 1.0.1 目录，并在迁移期间暂停旧站写入。先备份旧库及其 WAL，再用新版代码读取旧库的**副本**导出 JSON。

1. 在新版目录把 `config.php` 中的 `db` 临时指向旧库副本。
2. 执行 `php -r 'require "lib/core.php"; require "lib/backup.php"; echo json_encode(tl_backup_export_json(), JSON_THROW_ON_ERROR);' > todo-backup.json`。
3. 将 `db` 恢复为指向一个全新库，启动新版并在「维护」页导入该 JSON。核对分类、任务和完成记录后，再切换部署。

旧库的表约束和会话表通过创建新库获得。请保留旧库备份用于回退。

---

## 目录结构

```
config.php              口令、时区、展示上限
index.php               前台（追踪中 / 规划中 / 已完成 / 全部）
admin.php               后台工作台
action.php              所有写操作（POST + CSRF）
login.php / logout.php  登录与退出
lib/core.php            配置、北京时间、SQLite、cookie 签名、CSRF、flash、站内地址生成
lib/tasks.php           分类、待办、重复展开、发生记录、批量、清理
lib/feed.php            追踪中的分段编排（置顶区、逾期、近一周、未设截止日）与分类分组
lib/view.php            共用的视图片段（顶栏、任务行、详情、对话框）
assets/app.css          应用层样式（只读 EDS 令牌）
assets/app.js           PJAX 导航、批量选择、确认对话框、明暗切换、折叠、移动端抽屉
assets/admin.js         后台表格选择与分类删除对话框
assets/eds/             EDS 运行时样式（tokens.css / components.css），页面实际引用的就是这份
design/                 EDS 说明、showcase、对比度门禁；同名 CSS 副本仅供本地阅读（不上传）
.htaccess               Apache：伪静态映射 + 屏蔽 data/ 等敏感路径
deploy/nginx.conf       nginx：同一套规则的 nginx 写法
router.php              本地 php -S 用的路由脚本（复刻上面两套规则）
data/todo.sqlite        SQLite 数据（首次运行创建）
```

---

## 数据模型

```
categories   id, name, position, created_at
tasks        id, title, detail, category_id, due_date, due_time,
             status, repeat_unit, repeat_interval, tracked, pinned,
             completed_at, created_at, updated_at
task_occurrences  task_id, occur_date, completed_at   -- 重复任务的单次完成记录
task_subitems     id, task_id, title, due_date, due_time, done,
                  completed_at, position, created_at, updated_at
```

`task_occurrences` 是这套设计的关键：一条「每天」的任务只存一行，勾掉某一天只往这张表写一行，而不是把整串任务标成完成。

时间一律以 `YYYY-MM-DD` / `HH:MM` 文本存储，比较与展开都在北京时间下进行。

---

## 设计系统

页面只使用 EDS 的令牌与组件，不重写任何 `eds-*` 规则：

- 全直角：`.eds-square` 清零，只有状态点与胶囊保留圆形
- 强调色只出现在需要被看见的地方：活动选项卡、选中行、唯一提交键
- 确认框是通栏**纸带**而非卡片：左右到屏幕边缘、只有上下留暗场，胶囊骑在纸带下缘
- 明暗切换写在 `<html>` 上（`data-eds-scheme`），配色由 `data-eds-palette` 切换

---

## 说明

- 删除 `data/todo.sqlite` 即可从空库重新开始
- 生产部署时，为 PHP 进程设置并持久化 `TODO_AUTH_PASSWORD` 与 `TODO_COOKIE_SECRET`，并使用 HTTPS。任一变量未设置时无法登录。

## 许可证

本项目采用 [MIT 许可证](LICENSE)。
