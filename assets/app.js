/* ============================================================================
   待办清单 · 交互
   ----------------------------------------------------------------------------
   页面用 PJAX 导航：链接与表单被拦截，取回新 HTML 后原地替换 <body>，
   再重新初始化页面级交互。全局监听只绑定一次，所以替换后不会重复绑定。

   分工：
     TL.initGlobal()  只跑一次 —— 文档级委托监听、主题、确认对话框、PJAX 本身
     TL.initPage()    每次替换后都跑 —— 批量条、折叠、投放区、移动端抽屉、时钟
   ========================================================================= */
(function () {
  'use strict';

  var TL = window.TL = window.TL || {};
  var root = document.documentElement;
  var pageHooks = [];
  var globalReady = false;
  var pageReady = false;
  /* 本次替换的目标地址。swap 发生在 pushState 之前，
     所以 initPage 里要判断「即将变成什么地址」，而不是当前地址栏。 */
  var pendingUrl = '';
  /* 每次换页 +1。钩子记着自己上次在哪个「页」跑过，
     因此同一份脚本跨页装载时也只会跑一次，不会重复绑定。 */
  var pageGen = 0;
  var navToken = 0;
  var postBusy = false;
  var pendingGetUrl = null;

  TL.onPageInit = function (fn) {
    var hook = { fn: fn, ranGen: -1 };
    pageHooks.push(hook);
    if (pageReady) {
      hook.ranGen = pageGen;
      try { fn(); } catch (e) { /* 单个模块出错不影响其他交互 */ }
    }
  };

  function runPageHooks() {
    pageHooks.forEach(function (hook) {
      if (hook.ranGen === pageGen) return;
      hook.ranGen = pageGen;
      try { hook.fn(); } catch (e) { /* 单个模块出错不影响其他交互 */ }
    });
  }

  /* ---------------------------------------------------------------- 主题 */
  function applyScheme(value) {
    root.setAttribute('data-eds-scheme', value);
    document.querySelectorAll('[data-tl-scheme]').forEach(function (button) {
      button.setAttribute('aria-pressed', button.getAttribute('data-tl-scheme') === value ? 'true' : 'false');
    });
    document.cookie = 'tl_scheme=' + encodeURIComponent(value) + '; path=/; max-age=' + (60 * 60 * 24 * 365) + '; samesite=lax';
  }

  TL.applyScheme = applyScheme;

  /* ------------------------------------------------------------- 批量选择 */
  function refreshBulk() {
    var boxes = Array.prototype.slice.call(document.querySelectorAll('[data-bulk-check]'));
    var checked = boxes.filter(function (box) { return box.checked; });
    var bulkBar = document.getElementById('bulk-bar');
    if (bulkBar) bulkBar.hidden = checked.length === 0;
    var counter = document.querySelector('[data-bulk-count]');
    if (counter) counter.textContent = String(checked.length);
    boxes.forEach(function (box) {
      var row = box.closest('.tl-row');
      if (row) row.classList.toggle('tl-row--selected', box.checked);
    });
  }

  /* ---------------------------------------------------------------- 对话框 */
  var pendingForm = null;
  var pendingSubmitter = null;
  var pendingHref = null;

  function confirmElements() {
    var dialog = document.getElementById('confirm-dialog');
    return {
      dialog: dialog,
      title: dialog && dialog.querySelector('[data-confirm-title]'),
      message: dialog && dialog.querySelector('[data-confirm-message]')
    };
  }

  function openConfirm(options) {
    var parts = confirmElements();
    if (!parts.dialog) return;
    pendingForm = options.form || null;
    pendingSubmitter = options.submitter || null;
    pendingHref = options.href || null;
    if (parts.title) parts.title.textContent = options.title || '确认操作';
    if (parts.message) parts.message.textContent = options.message || '';
    if (typeof parts.dialog.showModal === 'function') {
      if (!parts.dialog.open) parts.dialog.showModal();
    } else {
      parts.dialog.setAttribute('open', '');
    }
  }

  function closeConfirm() {
    pendingForm = null;
    pendingSubmitter = null;
    pendingHref = null;
    var parts = confirmElements();
    if (!parts.dialog) return;
    if (typeof parts.dialog.close === 'function' && parts.dialog.open) {
      parts.dialog.close();
    } else {
      parts.dialog.removeAttribute('open');
    }
  }

  /* --------------------------------------------------------------- 路由
     伪静态开关与应用前缀都写在 <html> 上（lib/view.php 输出），
     所以前端不用知道站点装在哪个目录。 */
  var pretty = root.getAttribute('data-tl-pretty') === '1';
  var basePath = root.getAttribute('data-tl-base') || '';

  function escapeRe(text) {
    return String(text).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }

  /* 伪静态页面长这样（去掉应用前缀后）：
       /                      前台
       /tracked/              前台某个选项卡
       /tracked/task/12/      前台选中某条（后面还可以跟一次发生的日期）
       /admin/tasks/          后台
       /admin/tasks/12/       后台编辑某条
     登录页换布局，仍走整页跳转。 */
  var PRETTY_PAGE = new RegExp(
    '^' + escapeRe(basePath) +
    '/(?:$|(?:tracked|planned|done|all)(?:/task/\\d+(?:/\\d{4}-\\d{2}-\\d{2})?)?/?$' +
    '|admin(?:/(?:tasks|categories|system)(?:/\\d+)?)?/?$)'
  );

  /* 「选中了一条」在伪静态下写在路径里，在老地址下写在查询串里。 */
  function hasTask(url) {
    return pretty ? /\/task\/\d+/.test(url.pathname) : url.searchParams.has('task');
  }

  function hasEdit(url) {
    return pretty ? /\/admin\/tasks\/\d+/.test(url.pathname) : url.searchParams.has('edit');
  }

  /* --------------------------------------------------------------- PJAX */
  var pjax = TL.pjax = {
    enabled: true,
    /* 只接管前台与后台；登录页换布局，整页跳转更省事。 */
    pages: pretty ? PRETTY_PAGE : /(^|\/)(index|admin)\.php$/,
    bar: null,
    busy: false
  };

  function isSameOrigin(url) {
    return url.origin === window.location.origin;
  }

  function isPjaxPage(url) {
    return isSameOrigin(url) && pjax.pages.test(url.pathname);
  }

  function ensureBar() {
    if (pjax.bar) return pjax.bar;
    var bar = document.createElement('div');
    bar.className = 'tl-pjax-bar';
    bar.setAttribute('aria-hidden', 'true');
    root.appendChild(bar);
    pjax.bar = bar;
    return bar;
  }

  function startBar() {
    var bar = ensureBar();
    /* 重新加 --active 之前先摘掉 --done，否则上一条的淡出动画会压住它 */
    bar.classList.remove('tl-pjax-bar--done');
    void bar.offsetWidth;
    bar.classList.add('tl-pjax-bar--active');
  }

  function stopBar() {
    var bar = ensureBar();
    bar.classList.remove('tl-pjax-bar--active');
    bar.classList.add('tl-pjax-bar--done');
  }

  function scrollContainer() {
    if (window.matchMedia('(max-width: 980px)').matches) return null;
    return document.querySelector('.tl-main__scroll') || document.querySelector('.tl-admin-main');
  }

  function currentScroll() {
    var box = scrollContainer();
    return box ? box.scrollTop : window.scrollY;
  }

  function applyScroll(value) {
    var box = scrollContainer();
    if (box) box.scrollTop = value;
    else window.scrollTo(0, value);
  }

  /* 「列表上下文」= 决定列表内容的那些参数。
     两个地址的上下文相同（只是换了选中的那一行），列表不该重排，
     滚动位置保持；换了选项卡 / 分类 / 搜索词才回顶部。
     否则点一张卡片，左栏会整个跳回开头，看起来就像整页刷新了。 */
  var LIST_PARAMS = ['tab', 'view', 'status', 'cat', 'q', 'tracked', 'page'];

  /* 伪静态下选中项在路径里：前台 /task/12/、后台 /admin/tasks/12/。
     摘掉这一段，剩下的路径才是「列表上下文」——换了选项卡路径就变了，
     换了一条选中路径不该变。 */
  function normalizePath(path) {
    if (!pretty) return path;
    var out = path
      .replace(/\/task\/\d+(?:\/\d{4}-\d{2}-\d{2})?\/?$/, '/')
      .replace(/(\/admin\/tasks)\/\d+\/?$/, '$1/');
    /* 末尾斜杠统一：/tracked 与 /tracked/ 是同一页，别算成两个上下文。 */
    return out.length > 1 ? out.replace(/\/+$/, '') + '/' : out;
  }

  function listKey(url) {
    var u = new URL(url, window.location.href);
    /* 伪静态下选项卡与视图已经在路径里，不必再从查询串里取。 */
    var params = pretty
      ? LIST_PARAMS.filter(function (name) { return name !== 'tab' && name !== 'view'; })
      : LIST_PARAMS;
    return normalizePath(u.pathname) + '?' + params.map(function (name) {
      return name + '=' + (u.searchParams.get(name) || '');
    }).join('&');
  }

  /* 把新文档接过来：只换 body 的子树，<html> 上的属性手动同步。 */
  function swap(doc, url, options) {
    var next = doc.body;
    if (!next) return Promise.resolve(false);

    pendingUrl = url || '';
    var old = document.querySelector('.tl-detail');
    if (old) old.classList.remove('tl-detail--open');
    closeConfirm();

    document.body.replaceChildren.apply(document.body, Array.prototype.slice.call(next.childNodes)
      .filter(function (node) {
        /* 新文档里的 <script> 不能照搬：搬进来会再执行一次 app.js，
           全局监听就重复绑定了。需要的脚本由 loadScripts 按需补装。 */
        return !(node.nodeType === 1 && node.tagName === 'SCRIPT');
      }));

    ['data-eds-scheme', 'data-eds-palette', 'data-tl-pretty', 'data-tl-base',
     'data-tl-day', 'data-tl-tz', 'data-tl-rendered'].forEach(function (name) {
      var value = doc.documentElement.getAttribute(name);
      if (value !== null) root.setAttribute(name, value);
    });
    document.title = doc.title;

    /* 每个页面自己的脚本（后台的 admin.js）在 PJAX 下不会自动执行，
       按新文档里 <script src> 的顺序补装，装完再初始化。 */
    return loadScripts(doc).then(function () {
      if (TL.clock) TL.clock.sync();
      pageGen++;
      initPage();
      runPageHooks();
      var target = options.restore === true ? options.scroll : 0;
      applyScroll(target);
      /* 图片 / 字体可能推迟布局，等一帧再对齐一次，避免停在半途。 */
      window.requestAnimationFrame(function () { applyScroll(target); });

      /* 窄屏是单栏堆叠：编辑表单排在列表下面，点了卡片不滚过去就等于「没反应」。
         只在「刚进入编辑」时滚，且只滚一次。 */
      if (options.revealForm !== false && isNarrow() && hasEdit(new URL(url, window.location.href))) {
        var form = document.querySelector('.tl-card--raised');
        if (form) {
          window.requestAnimationFrame(function () {
            form.scrollIntoView({ block: 'start', behavior: 'smooth' });
          });
        }
      }

      /* 焦点交给标题，读屏能听出页面变了，但视觉上没有跳转感。 */
      if (options.focus !== false) {
        var heading = document.querySelector('.tl-masthead__title');
        if (heading) {
          heading.setAttribute('tabindex', '-1');
          heading.focus({ preventScroll: true });
        }
      }
      return true;
    }).catch(function () {
      window.location.href = url || window.location.href;
      return false;
    });
  }

  function isNarrow() {
    return window.matchMedia('(max-width: 980px)').matches;
  }

  /* 同一份脚本只装一次；并行 fetch，按出现顺序 await。 */
  var loadedScripts = {};
  (function seedScripts() {
    document.querySelectorAll('script[src]').forEach(function (tag) {
      loadedScripts[new URL(tag.src, window.location.href).href] = true;
    });
  })();

  function loadScripts(doc) {
    var jobs = [];
    doc.querySelectorAll('script[src]').forEach(function (tag) {
      var src = new URL(tag.getAttribute('src'), new URL(doc.URL || window.location.href)).href;
      if (loadedScripts[src]) return;
      loadedScripts[src] = true;
      jobs.push(new Promise(function (resolve, reject) {
        var el = document.createElement('script');
        el.src = tag.getAttribute('src');
        el.async = false;
        el.onload = function () { resolve(); };
        el.onerror = function () {
          delete loadedScripts[src];
          reject(new Error('script failed: ' + src));
        };
        document.head.appendChild(el);
      }));
    });
    return Promise.all(jobs);
  }

  function navigate(url, options) {
    options = options || {};
    if (postBusy && options.force !== true) {
      pendingGetUrl = url;
      return false;
    }
    if (pjax.busy && options.force !== true) return false;
    pjax.busy = true;
    var myToken = ++navToken;
    var ac = new AbortController();
    pjax.abortNav = function () { try { ac.abort(); } catch (e) {} };

    /* 同上下文（点卡片换选中项）→ 左栏保持原地；
       换了选项卡 / 分类 / 搜索 / 翻页 → 回顶部重新开始看。 */
    var sameContext = listKey(url) === listKey(window.location.href);
    var keepScroll = options.restore !== true && sameContext;
    var scroll = options.restore === true ? options.scroll : currentScroll();
    startBar();

    fetch(url, {
      credentials: 'same-origin',
      redirect: 'follow',
      headers: { 'X-Requested-With': 'pjax' },
      signal: ac.signal
    }).then(function (response) {
      if (myToken !== navToken) return null;
      var type = response.headers.get('content-type') || '';
      if (!response.ok || type.indexOf('text/html') === -1) throw new Error('not html');
      return response.text().then(function (html) {
        if (myToken !== navToken) return null;
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var finalUrl = response.url || url;
        return swap(doc, finalUrl, {
          restore: options.restore === true || keepScroll,
          scroll: scroll,
          focus: options.focus
        }).then(function (ok) {
          if (myToken !== navToken) return;
          if (!ok) throw new Error('no body');
          if (options.push !== false) {
            var state = { pjax: true, scroll: keepScroll || options.restore === true ? scroll : 0 };
            if (options.replace === true) window.history.replaceState(state, '', finalUrl);
            else window.history.pushState(state, '', finalUrl);
          }
        });
      });
    }).catch(function () {
      /* 任何意外都退回整页跳转，宁可闪一下也不要卡住 */
      if (options.replace === true) window.location.replace(url);
      else window.location.href = url;
    }).then(function () {
      pjax.busy = false;
      stopBar();
      if (pendingGetUrl && !postBusy) {
        var next = pendingGetUrl;
        pendingGetUrl = null;
        navigate(next, { force: true, focus: options.focus });
      }
    });
  }

  pjax.visit = navigate;

  /* 时钟跨天时用它原地重取，避免整页刷新。 */
  pjax.refresh = function () {
    navigate(window.location.href, { push: false, restore: true, scroll: currentScroll(), force: true, focus: false });
  };

  function shouldSkipLink(link, event) {
    if (!link || !link.getAttribute('href')) return true;
    if (link.hasAttribute('data-no-pjax')) return true;
    if (link.target && link.target !== '_self') return true;
    if (link.hasAttribute('download')) return true;
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return true;
    if (event.button !== 0) return true;
    var href = link.getAttribute('href');
    if (href.charAt(0) === '#') return true;
    var url = new URL(link.href, window.location.href);
    if (!isPjaxPage(url)) return true;
    return false;
  }

  /* 点的是当前这一页（比如再点一次已选中的卡片）：内容已经对了。
     这里必须 preventDefault —— 交还给浏览器会整页重载，滚动就归零了。 */
  function isSamePage(url) {
    return url.pathname === window.location.pathname && url.search === window.location.search;
  }

  /* ------------------------------------------------- 全局监听（只绑一次） */
  function initGlobal() {
    if (globalReady) return;
    globalReady = true;

    window.history.scrollRestoration = 'manual';

    /* 主题：委托，替换后依然有效 */
    document.addEventListener('click', function (event) {
      var button = event.target.closest('[data-tl-scheme]');
      if (!button) return;
      applyScheme(button.getAttribute('data-tl-scheme'));
    });

    /* 批量复选框 */
    document.addEventListener('change', function (event) {
      if (event.target && event.target.matches('[data-bulk-check]')) refreshBulk();
    });
    document.addEventListener('click', function (event) {
      if (event.target && event.target.matches('[data-bulk-check]')) event.stopPropagation();
      var clear = event.target.closest('[data-bulk-clear]');
      if (clear) {
        document.querySelectorAll('[data-bulk-check]').forEach(function (box) { box.checked = false; });
        refreshBulk();
      }
    });

    /* 追踪 / 置顶开关的文字跟随状态 */
    document.addEventListener('change', function (event) {
      var input = event.target;
      if (!input || !input.matches('.tl-switch input')) return;
      var text = input.parentElement.querySelector('.tl-switch__text');
      if (!text) return;
      text.textContent = input.checked ? (text.dataset.on || '开启') : (text.dataset.off || '关闭');
    });

    /* 确认对话框的按钮 */
    document.addEventListener('click', function (event) {
      var ok = event.target.closest('[data-confirm-ok]');
      if (ok) {
        var form = pendingForm;
        var submitter = pendingSubmitter;
        var href = pendingHref;
        closeConfirm();
        if (form) {
          form.dataset.confirmed = '1';
          if (typeof form.requestSubmit === 'function') form.requestSubmit(submitter || undefined);
          else form.submit();
        } else if (href) {
          var url = new URL(href, window.location.href);
          if (isPjaxPage(url)) navigate(url.href);
          else window.location.href = href;
        }
        return;
      }
      if (event.target.closest('[data-confirm-cancel]')) closeConfirm();
      var dialog = event.target.closest('#confirm-dialog');
      if (dialog && event.target === dialog) closeConfirm();
    });

    /* 链接级确认 */
    document.addEventListener('click', function (event) {
      var link = event.target.closest('a[data-confirm]');
      if (!link) return;
      var message = link.getAttribute('data-confirm');
      if (!message) return;
      event.preventDefault();
      openConfirm({
        title: link.getAttribute('data-confirm-title') || '确认操作',
        message: message,
        href: link.getAttribute('href')
      });
    });

    /* 表单级确认（先于 PJAX 注册，因此先拿到 submit） */
    document.addEventListener('submit', function (event) {
      var form = event.target;
      if (!(form instanceof HTMLFormElement)) return;
      if (form.dataset.confirmed === '1') {
        delete form.dataset.confirmed;
        return;
      }
      var submitter = event.submitter;
      var message = (submitter && submitter.getAttribute('data-confirm')) || form.getAttribute('data-confirm');
      if (!message) return;
      event.preventDefault();
      openConfirm({
        title: form.getAttribute('data-confirm-title') || '确认操作',
        message: message,
        form: form,
        submitter: submitter || null
      });
    });

    /* PJAX：链接 */
    document.addEventListener('click', function (event) {
      if (event.defaultPrevented) return;
      var link = event.target.closest('a[href]');
      if (!link || shouldSkipLink(link, event)) return;
      event.preventDefault();
      /* 已经是这一页了（再点一次选中项）：什么都不用做，更不要整页重载。 */
      if (isSamePage(new URL(link.href, window.location.href))) return;
      navigate(link.href);
    });

    /* PJAX：表单 */
    document.addEventListener('submit', function (event) {
      if (event.defaultPrevented) return;
      var form = event.target;
      if (!(form instanceof HTMLFormElement)) return;
      if (form.hasAttribute('data-no-pjax')) return;
      if (form.dataset.confirmed === '1') return;
      /* 只在本身就是 PJAX 页面时接管，别把登录页之类卷进来 */
      if (!pjax.pages.test(window.location.pathname)) return;

      var action = new URL(form.getAttribute('action') || window.location.href, window.location.href);
      if (!isSameOrigin(action)) return;

      var method = (form.getAttribute('method') || 'GET').toUpperCase();
      event.preventDefault();
      startBar();

      if (method === 'GET') {
        var fd = new FormData(form);
        var url = new URL(action.href);
        url.search = new URLSearchParams(fd).toString();
        navigate(url.href);
        return;
      }

      /* POST：跟着重定向走，最后落在哪个页面就把哪个地址写进历史 */
      if (postBusy) return;
      postBusy = true;
      if (pjax.abortNav) pjax.abortNav();
      navToken++;
      var submitter = event.submitter;
      var body = new FormData(form);
      if (submitter && submitter.name) {
        body.append(submitter.name, submitter.getAttribute('value') || '');
      }
      fetch(action.href, {
        method: 'POST',
        body: body,
        credentials: 'same-origin',
        redirect: 'follow',
        headers: { 'X-Requested-With': 'pjax' }
      }).then(function (response) {
        var type = response.headers.get('content-type') || '';
        if (!response.ok || type.indexOf('text/html') === -1) throw new Error('not html');
        return response.text().then(function (html) {
          var doc = new DOMParser().parseFromString(html, 'text/html');
          return swap(doc, response.url, { focus: true }).then(function (ok) {
            if (!ok) throw new Error('no body');
            /* 表单重定向后的落地地址：换掉当前历史条目，避免「后退」回到提交前的表单页 */
            window.history.replaceState({ pjax: true, scroll: 0 }, '', response.url);
          });
        });
      }).catch(function () {
        /* 请求可能已经落库（响应只是没能解析），重发会造成重复数据。
           所以退回重新加载当前页面，让看到的始终是服务端的真实状态。 */
        window.location.reload();
      }).then(function () {
        postBusy = false;
        stopBar();
        if (pendingGetUrl) {
          var next = pendingGetUrl;
          pendingGetUrl = null;
          navigate(next, { force: true });
        }
      });
    });

    /* 自动提交的下拉框走 requestSubmit，才能被上面的 submit 监听接住 */
    document.addEventListener('change', function (event) {
      var field = event.target;
      if (!field || !field.matches('[data-auto-submit]')) return;
      var form = field.closest('form');
      if (!form) return;
      if (typeof form.requestSubmit === 'function') form.requestSubmit();
      else form.submit();
    });

    /* 前进 / 后退 */
    window.addEventListener('popstate', function (event) {
      var state = event.state || {};
      if (!state.pjax) {
        pjax.busy = true;
        startBar();
        pjax.visit(window.location.href, { push: false, restore: true, scroll: state.scroll || 0, focus: false, force: true });
        return;
      }
      pjax.visit(window.location.href, { push: false, restore: true, scroll: state.scroll || 0, focus: false, force: true });
    });

    /* 滚动位置记进当前历史条目，返回时能回到原处 */
    window.addEventListener('scroll', function () {
      var state = window.history.state || {};
      if (!state.pjax) return;
      var next = { pjax: true, scroll: currentScroll() };
      window.history.replaceState(next, '', window.location.href);
    }, { passive: true });

    var mainScroll = null;
    document.addEventListener('scroll', function (event) {
      var box = event.target;
      if (!box || !box.classList || !box.classList.contains('tl-main__scroll')) return;
      mainScroll = { pjax: true, scroll: box.scrollTop };
      var state = window.history.state || {};
      if (state.pjax) window.history.replaceState(mainScroll, '', window.location.href);
    }, true);

    /* 表单被浏览器还原（刷新后回填）时，让开关文字也跟上 */
    document.addEventListener('pageshow', function (event) {
      if (!event.persisted) return;
      runPageHooks();
      navigate(window.location.href, { push: false, restore: true, scroll: currentScroll(), force: true, focus: false });
    });
  }

  /* ------------------------------------------------ 页面级初始化（可重入） */
  function initPage() {
    refreshBulk();

    /* 折叠分段 */
    var saved = (function () {
      try { return JSON.parse(localStorage.getItem('tl_folds') || '{}') || {}; } catch (e) { return {}; }
    })();

    function writeFolds(state) {
      try { localStorage.setItem('tl_folds', JSON.stringify(state)); } catch (e) {}
    }

    function applyFold(section, open) {
      var body = section.querySelector(':scope > .tl-fold__body');
      var toggle = section.querySelector(':scope > .tl-track-head [data-fold-toggle], :scope > [data-fold-toggle]');
      if (body) body.hidden = !open;
      if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      section.setAttribute('data-fold-open', open ? '1' : '0');
    }

    document.querySelectorAll('[data-fold-section], [data-fold-group]').forEach(function (section) {
      var id = section.getAttribute('data-fold-id') || '';
      var open = Object.prototype.hasOwnProperty.call(saved, id)
        ? !!saved[id]
        : section.getAttribute('data-fold-open') === '1';
      applyFold(section, open);

      var toggle = section.querySelector(':scope > .tl-track-head [data-fold-toggle], :scope > [data-fold-toggle]');
      if (!toggle) return;
      toggle.addEventListener('click', function () {
        var next = section.getAttribute('data-fold-open') !== '1';
        applyFold(section, next);
        var state;
        try { state = JSON.parse(localStorage.getItem('tl_folds') || '{}') || {}; } catch (e) { state = {}; }
        state[id] = next;
        writeFolds(state);
      });
    });

    /* 窄屏：看板 / 筛选折叠 */
    var narrow = window.matchMedia('(max-width: 980px)');
    document.querySelectorAll('[data-mobile-panel]').forEach(function (toggle) {
      var name = toggle.getAttribute('data-mobile-panel');
      var body = document.querySelector('[data-mobile-body="' + name + '"]');
      if (!body) return;

      function sync() {
        if (!narrow.matches) {
          body.hidden = false;
          toggle.setAttribute('aria-expanded', 'true');
          return;
        }
        var state;
        try { state = JSON.parse(localStorage.getItem('tl_mobile_panels') || '{}') || {}; } catch (e) { state = {}; }
        var open = state[name] === true;
        body.hidden = !open;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      }

      toggle.addEventListener('click', function () {
        var next = toggle.getAttribute('aria-expanded') !== 'true';
        body.hidden = !next;
        toggle.setAttribute('aria-expanded', next ? 'true' : 'false');
        var state;
        try { state = JSON.parse(localStorage.getItem('tl_mobile_panels') || '{}') || {}; } catch (e) { state = {}; }
        state[name] = next;
        try { localStorage.setItem('tl_mobile_panels', JSON.stringify(state)); } catch (e) {}
      });

      if (narrow.addEventListener) narrow.addEventListener('change', sync);
      sync();
    });

    /* 移动端详情抽屉。
       注意不能用 window.location.search：PJAX 是在 swap 之后才 pushState 的，
       这个时刻地址栏还停在上一页。用 swap 传进来的目标地址判断。 */
    var detail = document.getElementById('detail');
    if (detail) {
      var currentUrl = new URL(pendingUrl || window.location.href, window.location.href);
      if (narrow.matches && hasTask(currentUrl)) {
        detail.classList.add('tl-detail--open');
      } else {
        detail.classList.remove('tl-detail--open');
      }
      var closeButton = document.createElement('button');
      closeButton.type = 'button';
      closeButton.className = 'eds-button eds-button--icon tl-detail__close';
      closeButton.setAttribute('aria-label', '关闭详情');
      closeButton.textContent = '✕';
      closeButton.addEventListener('click', function () {
        detail.classList.remove('tl-detail--open');
        var url = new URL(window.location.href);
        if (pretty) {
          /* 伪静态下选中项在路径里：把 /task/12/ 这一段摘掉。 */
          url.pathname = url.pathname.replace(/\/task\/\d+(?:\/\d{4}-\d{2}-\d{2})?\/?$/, '/');
        }
        ['task', 'date'].forEach(function (key) { url.searchParams.delete(key); });
        window.history.replaceState(window.history.state, '', url.toString());
      });
      detail.appendChild(closeButton);
    }

    if (TL.clock) TL.clock.sync();
    pageReady = true;
  }

  TL.initPage = initPage;

  /* ============================================================ 实时时钟
     两个必须遵守的前提：
     1. 时间永远按北京时间显示，不看用户的本地时区 —— 页面上所有相对日期
        （今天 / 3小时后 / 1天2小时后）都是 PHP 按北京时间算的，时钟必须跟上它。
     2. 跨天必须重算。分段口径（近一周、已逾期）在服务端算，过了午夜不重取的话，
        昨天的「今天」仍挂在近一周里，而它其实已经逾期。PJAX 下这次重取是原地
        完成的，不会整页刷新。
     ============================================================ */
  (function () {
    var tz = root.getAttribute('data-tl-tz') || 'Asia/Shanghai';
    var serverDay = root.getAttribute('data-tl-day') || '';
    var renderedAt = parseInt(root.getAttribute('data-tl-rendered') || '0', 10);
    var baseEpoch = 0;
    var basePerf = (typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now();

    var timeFmt, dayFmt, metaFmt;
    try {
      timeFmt = new Intl.DateTimeFormat('en-GB', { timeZone: tz, hour: '2-digit', minute: '2-digit', hour12: false });
      dayFmt = new Intl.DateTimeFormat('en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit' });
      metaFmt = new Intl.DateTimeFormat('zh-CN', { timeZone: tz, month: 'numeric', day: 'numeric' });
    } catch (e) {
      /* 极老的浏览器：退化成静态时间，宁可不动也不要显示错的时间 */
      TL.clock = { sync: function () {}, tick: function () {} };
      return;
    }

    function nowEpoch() {
      var perf = (typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now();
      return baseEpoch + (perf - basePerf);
    }

    function todayKey(epoch) {
      /* en-CA 的格式正好是 YYYY-MM-DD，可以与服务端 data-tl-day 直接比较 */
      return dayFmt.format(new Date(epoch));
    }

    function weekdayCn(date, timeZone) {
      var names = ['日', '一', '二', '三', '四', '五', '六'];
      var wd = new Intl.DateTimeFormat('en-US', { timeZone: timeZone, weekday: 'short' }).format(date);
      var map = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 };
      return '星期' + names[map[wd]] ?? '';
    }

    var reloading = false;

    function sync() {
      tz = root.getAttribute('data-tl-tz') || 'Asia/Shanghai';
      serverDay = root.getAttribute('data-tl-day') || '';
      renderedAt = parseInt(root.getAttribute('data-tl-rendered') || '0', 10);
      var clock = document.querySelector('[data-clock]');
      var epoch = clock ? parseInt(clock.getAttribute('data-epoch') || '0', 10) : renderedAt;
      if (!epoch) epoch = Date.now();
      baseEpoch = epoch;
      basePerf = (typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now();
      reloading = false;
      tick();
    }

    function tick() {
      /* 每帧重新取节点：PJAX 替换过 body，缓存的引用会失效。 */
      var clock = document.querySelector('[data-clock]');
      var timeNode = clock && clock.querySelector('[data-clock-time]');
      var metaNode = clock && clock.querySelector('[data-clock-meta]');

      var epoch = nowEpoch();

      /* 跨天：原地重取一次，让服务端重算所有相对日期与分段。 */
      if (!reloading && serverDay && todayKey(epoch) !== serverDay) {
        reloading = true;
        if (TL.pjax) TL.pjax.refresh();
        else window.location.reload();
        return;
      }

      if (!timeNode) return;
      var date = new Date(epoch);
      var hm = timeFmt.format(date);
      if (timeNode.textContent !== hm) timeNode.textContent = hm;
      timeNode.setAttribute('datetime', hm);

      if (metaNode) {
        var text = metaFmt.format(date) + ' · ' + weekdayCn(date, tz);
        if (metaNode.textContent !== text) metaNode.textContent = text;
      }
    }

    TL.clock = { sync: sync, tick: tick };
    sync();
    window.setInterval(tick, 1000);

    /* 休眠标签页的定时器会被浏览器节流到分钟级，从后台切回来时先补一次。
       另外页面从往返缓存恢复时也补一次（bfcache 里脚本是冻住的）。 */
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'visible') tick();
    });
    window.addEventListener('pageshow', function (event) {
      if (event.persisted) sync();
    });

    /* 时钟由 performance.now() 推进，与系统时间无关；但跨天判断用的是墙上时间换算，
       所以系统时间被改动后也要重新对齐一次基准。 */
    window.addEventListener('focus', tick);
  })();

  /* ------------------------------------------------------------------ 启动 */
  function boot() {
    initGlobal();
    pageGen++;
    initPage();
    runPageHooks();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
