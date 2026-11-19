# EDS — Editorial Design System

从 `dsh-theme-endfield` 抽出的可复用设计系统：**奶油纸底、墨黑文字、一支信号色、全直角**。

三个文件，零依赖：

| 文件 | 作用 |
| --- | --- |
| [`tokens.css`](tokens.css) | 令牌层。六类决策：表面 / 线 / 文字、强调色家族、淡层阶梯、几何、字排、动效 / 高程 / 材质 / 装饰。 |
| [`components.css`](components.css) | 组件层。只读令牌，类名全部 `eds-*`，不含任何宿主 CSS-module 选择器。 |
| [`showcase.html`](showcase.html) | 直接用浏览器打开：样式指南 + **实时**实测表（切换配色 / 明暗，数字跟着变）。 |
| [`tokens.json`](tokens.json) | 机器可读导出（Design Tokens 格式），给 Figma / Style Dictionary / Tailwind 用。 |
| [`scripts/check-contrast.mjs`](scripts/check-contrast.mjs) | 颜色门禁。解析 `tokens.css`，按 2 套配色 × 2 种明暗重算 110 项。 |

```bash
open design/showcase.html                  # 看
node design/scripts/check-contrast.mjs     # 验（--verbose 打印每一项）
```

---

## 一、这套系统在说什么

不是「深色科技感」，而是**印刷编辑风**：一张有质感的纸、压在上面的墨黑字、以及一个用来做信号的高饱和色。

三条规则贯穿全篇：

1. **纸与墨是主体，强调色是信号。** 强调色只用于「需要被看见的那一处」——按钮、悬停、选中行、焦点环——而不是大面积铺色。
2. **全直角。** 圆角把界面推向「柔和产品」，直角推向「技术文档 / 工程图纸」。只有形状本身是圆的（状态点、头像、加载圈、胶囊）才保留圆形。
3. **数字等宽。** 让表格、耗时、百分比在跳动时不产生横向抖动。

一句话记住这套系统的气质：**它看起来像一张印坏了会很明显的纸，而不是一个可以随便调参数的界面。**

---

## 二、怎么用

```html
<html lang="zh-CN" data-eds-scheme="light" data-eds-palette="signal">
  <head>
    <link rel="stylesheet" href="design/tokens.css">
    <link rel="stylesheet" href="design/components.css">
  </head>
  <body class="eds-root eds-square">

    <!-- 一行开关 -->
    <div class="eds-row">
      <span class="eds-row__text eds-label">等高线背景
        <span class="eds-hint">跟随配色切换。</span>
      </span>
      <span class="eds-row__control">
        <button class="eds-button eds-button--solid">主要动作</button>
      </span>
    </div>

    <!-- 一个对话框 -->
    <div class="eds-band-dialog">
      <div class="eds-band-dialog__band">…</div>
    </div>
  </body>
</html>
```

切换配色或明暗 = 改 `<html>` 上的一个属性。不需要重新注册令牌、不需要 JS 重绘。

> **约束：`data-eds-scheme` 与 `data-eds-palette` 必须在同一个元素上。**
> `--eds-accent-text` 是按明暗分档的（亮色读深档、暗色读亮档）。如果 palette 属性落在带 scheme 的元素**后代**上，祖先已经解析好的派生值看不到这次覆盖。保持在 `<html>` 上最省事。

> **约束二：`components.css` 里没有裸 `body`、裸 `::selection`、裸 `*`。**
> 全部选择器都挂在 `.eds-root` 之下或 `eds-*` 类名上。设计系统一旦在导入那一刻就重设宿主，就不叫可复用了——而且那些声明会**继承**进页面任意位置注入的第三方节点。源主题为这条付过两次学费：`:root` 字体令牌覆盖把所有挂件换了脸；body 级 `font-feature-settings` 把等宽数字漏进了挂件。所以默认状态下 `components.css` 对宿主**零影响**。

---

## 三、颜色是量出来的，不是挑出来的

这是整套系统唯一不能妥协的一条，也是它最主要的价值。

`check-contrast.mjs` 会重算 **110 项**：2 套配色 × 2 种明暗 × 每个角色，各自对它**实际落在的表面**验算。跑一下：

```text
ok    [light · signal] text.primary / surface.base          15.38:1 (>= 4.5, AA)
ok    [light · signal] accent-text / surface.base           5.35:1  (>= 4.5, AA)
ok    [light · signal] text.on-accent / accent              16.50:1 (>= 4.5, AA)
ok    [light · signal] decor.stroke                         1.29:1  (1.06–1.8, decor)
ok    [dark  · teal  ] surface ladder: "sunken" inverts     0.014 > 0.005 — #1e201d vs #101110
110 passed, 0 failed
```

它证明自己不是空跑：把亮色的强调文字改回 `#fff500`（原项目真实踩过的坑），门禁立刻报

```text
FAIL  [light · signal] accent-text / surface.base    1.07:1 #fff500 on #e8e8e2
FAIL  [light · signal] accent-text / surface.raised  1.02:1 #fff500 on #f2f2ec
```

### 三条最容易踩的推论

**1. 亮色下强调色作为文字必须「下沉」。**
在奶油纸上，`#fff500` 不是一种配色选择，而是一次抹除——只有 **1.07:1**。所以亮色模式里凡「强调色作为文字」的地方都换成同色相的深档：谷地黄 → `#6b5d00`（5.35:1），武陵青 → `#006a6a`（5.22:1）。暗色则相反，底是近黑，直接用原色。

**2. 同一个色在亮 / 暗下往往需要相反的调整。**
流光文字的中间亮带：谷地黄的亮带是**压暗**的 `#a08a00`（黄色向上已到顶，再亮就成白）；武陵青的亮带是**提亮**的 `#7ee7e7`（青色向下余量更小，更深的青只有 3.54:1）。同一个 AA 4.5:1 规则，两个相反的方向。

**3. 「装饰」和「前景」用两套标准。**
前景守 AA 4.5:1；装饰守「不抢眼但可见」的区间（约 1.06 ～ 1.6:1）。水印永远在正文之后，等高线在所有内容之下——它们的对比度数值控制的是**干扰强度**，不是可读性。

### 一条反直觉的：半透明淡层不能用 WCAG 比值判断

信号黄在奶油纸上是**亮度中性**的：16% 的黄叠上去，WCAG 比值算出来是 **1.00:1**（字面意义上的「不可见」），但屏幕上明显看得到——因为变化在**色度**上（蓝通道掉了 36/255）。

所以门禁用**通道位移**（Δ/255）而不是亮度比来判淡层：能察觉（≥ 8）但不能变成实心（< 64）。文字压在淡层上的对比度另算，那才是真正保护标签的东西。

```text
ok  [light · signal] wash.hover     Δ36/255 #eceabe on #e8e8e2  (8–64, wash)
ok  [light · signal] wash.selected  Δ50/255 #edebb0 on #e8e8e2  (8–64, wash)
ok  [light · signal] text.primary on wash.selected  15.41:1 (>= 4.5, AA)
```

---

## 四、几何：先清零，再恢复

直角不是逐个组件改，而是**先全部清零，再恢复该圆的**：

```css
.eds-square, .eds-square [class] { border-radius: 0; }

.eds-round-natural {
  border-radius: 999px;
  corner-shape: round;   /* 见下 */
}
```

清零是无限集合，恢复是**有限清单**（状态点、头像、加载圈、图标按钮、胶囊）。反过来做——「默认圆角、把看着不对的压平」——永远做不完。

### `corner-shape: round` 为什么必须一起写

宿主把 `corner-shape` 根设在 `superellipse(1.5)`（Chrome 139+ 把圆角画成方圆形）。`999px` **只有在形状是 round 时才画出一个圆**。少写这一行，胶囊两端就是方圆形，不是半圆。

### 胶囊轮廓：画出来，不要「圆角」

比 `corner-shape` 更狠的一条约束：宿主的 `corner-shape` 随时可能变。所以 `.eds-stadium` 的轮廓**不是**圆角，是两个径向圆盘 + 中间色带拼出来的：

```css
background:
  radial-gradient(circle at var(--cap) 50%,            fill calc(var(--cap) - .5px), transparent var(--cap)),
  radial-gradient(circle at calc(100% - var(--cap)) 50%, fill calc(var(--cap) - .5px), transparent var(--cap)),
  linear-gradient(fill, fill) var(--cap) 0 / calc(100% - 2 * var(--cap)) 100% no-repeat;
border-radius: 0;
```

没有圆角给任何 `corner-shape` 去裁，**轮廓就是渐变本身**。`--eds-stadium-cap` 声明为 `@property` 的注册 `<length>`，所以它能和盒子高度走同一条 420ms 曲线插值——`cap(t) = height(t)/2`，开合读起来是**一个物体在变形**，不是两套布局在交换。

代价：`--eds-stadium-cap` 必须等于高度的一半，改高度要一起改。

---

## 五、组件地图

| 类 | 是什么 | 一条要点 |
| --- | --- | --- |
| `.eds-row` | 唯一的布局原语 | 最后一行去掉分隔线——下一组标题自己的横线就是分隔 |
| `.eds-group-head` | `01 主题 / THEME` 编辑风组标题 | 4px 强调竖线 + 编号 + 拉丁行；全拉丁语言下**删掉**第二行，而不是重复 |
| `.eds-button` | 按钮 | `--solid` 保留给一个表面上的**唯一提交动作** |
| `.eds-capsule` | 对话框里的胶囊对 | `✕` 取消、`◎` 确认；3px 内描边 + 尾字圆盘是这套语言的签名 |
| `.eds-chip` | 状态小标 | `--inverse` 用于压在实心强调行上、否则会融进去的场合 |
| `.eds-stadium` | 画出来的胶囊 | 见上 |
| `.eds-meter` | 启动轨道 | 39px 读数是系统在静止状态下唯一「喊」的一处 |
| `.eds-scrim-plate` | 全屏大字 | 两层都 `pointer-events:none`；点击关闭绑在 **document 捕获阶段** |
| `.eds-glass` | 有边界的磨砂 | 有 fixed 子元素的卡片**只上色、不加滤镜**——见下 |
| `.eds-band-dialog` | 通栏纸带对话框 | 纸带在暗色下仍然是亮的 |
| `.eds-table` | 表格 | 悬停用**混色**不用叠层，结果是一个实色，文字对比度可量 |
| `.eds-toggle` | 两态开关 | 用文字显示**当前状态**，不让人靠滑块位置猜 |
| `.eds-watermark` | 水印 | 用 `content` 画字，不是文本节点——翻译器改不了它 |

### 两个「少写一行就出错」的地方

**实心强调底必须自己指定文字色。** 填色来自主题、文字色留给宿主 = 纸白字压在信号黄上 = **1.05:1**，不可见。原项目真的发过这个 bug。所以 `.eds-button--solid` 显式写 `color: var(--eds-text-on-accent)`。

**有 `position: fixed` 子元素的卡片不能加 `backdrop-filter`。** 任何 filter 都会让元素成为 fixed 后代的包含块。发送按钮的 tooltip 按视口坐标定位，卡片一模糊，tooltip 的坐标系就变成了缩小的卡片盒——被推出右边缘，还会让它的 `ResizeObserver` 反复重测（肉眼可见的抖动）。所以卡片拿玻璃**填充**，只有停靠面板拿真模糊。

---

## 六、动效

三条曲线、九个时长，全部是量出来的：

| 令牌 | 值 | 用途 |
| --- | --- | --- |
| `--eds-ease-morph` | `cubic-bezier(.22,.72,.2,1)` | 形状变化（胶囊开合，420ms） |
| `--eds-ease-slam` | `cubic-bezier(.16,1,.3,1)` | 入场砸落（3000ms 一条时间线含停留与淡出） |
| `--eds-ease-sweep` | `cubic-bezier(.33,1,.68,1)` | 扫光收尾（520ms + 620ms） |
| `--eds-dur-micro` | 120ms | 悬停反馈。按钮本身**不加**过渡，颜色要跟着指针立刻走 |

**减少动态效果 = 直接给终态，不是缩短动画。** 这是两件不同的事，且前者可以断言、后者不能。

还有一条写 CSS 时的陷阱：**过渡必须声明在状态变化后仍然存在的规则上。** 把 `transition` 和 `opacity: 0` 一起塞进状态选择器，移除状态时会连过渡一起删掉——那一帧直接从 0 跳到 1，用户看到的是一次闪烁，不是一次淡入。

---

## 七、几处「故意的」

| 决定 | 为什么 |
| --- | --- |
| 菜单**不透明** | 半透明菜单面压在背景上、模糊又不合成时，会读成「没有背景」，正文直接透过来 |
| 暗色遮罩更重（80%） | 应用默认黑 50% 压在 `#101110` 上读起来是灰雾，不是黑场，纸带浮不起来 |
| 大字的遮罩 alpha 0.55 | 实测：0.55 是白色文字在两种亮色表面上都通过 AA 的第一档。0.28 在暗色好看，亮色只有 2.29:1 |
| 暗色水印降到 0.085 | 给近黑加亮度，比从奶油色减亮度响得多。同样的 0.13 在纸上像耳语，在墨上像喊 |
| 禁用态逐部件画 | 胶囊骑在两种底色上，整块半透明黄压在纸上发灰、压在遮罩上发橄榄，会读成「灰色」 |
| 表格悬停用混色而非叠层 | `color-mix` 得到一个实色，文字对比度可以被真正量出来；叠层只能猜 |
| 选中断言「不是空跑」 | 如果两套配色的强调色碰巧相同，切换就是无效的——门禁专门断言它们不同 |
| 装饰层用 `isolation: isolate` | 没有层叠上下文时 `z-index:-1` 会逃到最近的祖先上下文，画进父级不透明底色**后面**（即完全不可见） |

---

## 八、这份提取丢掉了什么

**故意丢掉的：宿主征服。**

`src/styles/theme.css` 有 2,658 行，其中约一半是 `.Xyz123_sidebarCol` 这类针对某个应用版本内部类名的覆盖规则——包括针对某次发版重命名而写的补丁、「这个选择器在某个版本会命中第三方插件的 popup，所以要加词界」这类注释。

那是**对外来 DOM 的征服**，不是设计。设计系统如果把它一起发布，等于把宿主的内部结构冻结进自己的 API——每次宿主改版，你的设计系统就坏一片。

**保留下来的：** 三样东西——**令牌的取值与它们背后的对比度算式**、**几何策略**（先清零再恢复、画胶囊而不是圆角）、**几条「少写一行就出错」的实现纪律**。这三样换了宿主仍然成立。

---

## 九、在 DSH 主题里回写

这套系统与源主题是**同源而非替代**：

| 设计系统 | 源主题 |
| --- | --- |
| `--eds-surface-*` | `--dsw-alias-bg-*`（经 `theme.overrideTokens`） |
| `--eds-text-*` | `--dsw-alias-label-*` |
| `--eds-accent` | `--edge-accent` + `body.theme-endfield-wuling` |
| `--eds-wash-*` | `rgba(var(--edge-accent-rgb), α)` |
| `--eds-decor-*` | `--edge-wm-alpha` / canvas 8 位 hex 描边 |
| `.eds-stadium` | `[data-endfield-balance]` 的渐变轮廓 |

源主题仍然负责「把令牌接到宿主上」这件脏活（`theme.overrideTokens`、选择器、`MutationObserver`）；设计系统负责**取值、几何、纪律**这三件可迁移的事。想回写时，改的是 `src/styles/theme.css` 与 `src/client/41-mount-tokens.js`，然后跑原来的 `npm test`。

> 注意：`design/` **不在** `package.json` 的 `files` 白名单里，所以不会进 npm 发布产物。它是给读代码的人用的，不是运行时依赖。如果希望它随包发布，把它加进 `files`——但要先想清楚是否愿意承诺这套 API 的兼容性。

---

## 十、素材归属

本设计系统的**代码与令牌取值**来自 `dsh-theme-endfield` 的原创部分（MIT）。

视觉语言参考《明日方舟：终末地》（Arknights: Endfield）官网的工业编辑风格；相关游戏名称、标识与视觉素材版权归**鹰角网络（Hypergryph）**所有，仅用于学习与展示，不在 MIT 许可覆盖范围内。本设计系统是一个**非官方同人作品**，与鹰角网络无隶属、赞助或背书关系。
