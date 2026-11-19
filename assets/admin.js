/* ============================================================================
   后台专属交互
   ----------------------------------------------------------------------------
   · 全选 / 单项选择 → 批量条计数
   · 删除分类：把名称、数量、id 填进纸带对话框（对话框里是真实 <form>）

   PJAX 替换 body 后这些节点都是新的，所以注册到 TL.onPageInit，
   每次换页重新绑定；文档级委托监听则只绑一次。
   ========================================================================= */
(function () {
  'use strict';

  if (!window.TL || typeof window.TL.onPageInit !== 'function') return;

  /* ------------------------------------------------------------ 删除分类对话框 */
  var pendingCatButton = null;

  function catDialog() {
    return document.getElementById('cat-dialog');
  }

  /* ------------------------------------------------------------ 子任务编辑：只绑一次（避免 PJAX 每次 init 叠加监听） */
  function subitemNextIndex(container) {
    var max = -1;
    container.querySelectorAll('.tl-subitems-row').forEach(function (row) {
      var t = row.querySelector('input[name^="subitem_title["]');
      if (!t || !t.name) return;
      var m = t.name.match(/\[(\d+)\]/);
      if (m) max = Math.max(max, parseInt(m[1], 10));
    });
    return max + 1;
  }

  function addSubitemRow(container) {
    var template = document.getElementById('subitem-row-template');
    if (!template) return;
    var index = subitemNextIndex(container);
    var row = template.content.cloneNode(true);
    row.querySelectorAll('[name]').forEach(function (input) {
      input.name = input.name.replace('[0]', '[' + index + ']');
    });
    container.appendChild(row);
  }

  document.addEventListener('click', function (event) {
    var addBtn = event.target.closest('[data-subitem-add-row]');
    if (addBtn) {
      var editorId = addBtn.getAttribute('data-subitem-editor');
      var editor = editorId && document.getElementById(editorId);
      if (editor) {
        event.preventDefault();
        addSubitemRow(editor);
      }
      return;
    }
    var rm = event.target.closest('[data-subitem-remove]');
    if (rm) {
      var row = rm.closest('.tl-subitems-row');
      var parent = row && row.parentElement;
      if (!row || !parent || !parent.closest('[data-subitems-editor]')) return;
      event.preventDefault();
      row.remove();
      if (!parent.querySelector('.tl-subitems-row')) {
        addSubitemRow(parent);
      }
    }
  });

  document.addEventListener('click', function (event) {
    var link = event.target.closest('[data-open-cat-delete]');
    if (!link) return;
    var dialog = catDialog();
    if (!dialog) return;
    event.preventDefault();
    pendingCatButton = link;
    var nameLabel = dialog.querySelector('[data-cat-name-label]');
    var countLabel = dialog.querySelector('[data-cat-count-label]');
    var idField = dialog.querySelector('[data-cat-id-field]');
    if (idField) idField.value = link.getAttribute('data-cat-id') || '';
    if (nameLabel) nameLabel.textContent = link.getAttribute('data-cat-name') || '';
    if (countLabel) countLabel.textContent = link.getAttribute('data-cat-count') || '0';
    if (typeof dialog.showModal === 'function') {
      if (!dialog.open) dialog.showModal();
    } else {
      dialog.setAttribute('open', '');
    }
  });

  function closeCatDialog() {
    pendingCatButton = null;
    var dialog = catDialog();
    if (!dialog) return;
    if (typeof dialog.close === 'function' && dialog.open) {
      dialog.close();
    } else {
      dialog.removeAttribute('open');
    }
  }

  document.addEventListener('click', function (event) {
    if (event.target.closest('[data-cat-cancel]')) {
      closeCatDialog();
      return;
    }
    var dialog = catDialog();
    if (dialog && event.target === dialog) closeCatDialog();
  });

  /* ------------------------------------------------------------ 表格批量选择 */
  window.TL.onPageInit(function () {
    var bulk = document.querySelector('[data-admin-bulk]');
    var checkAll = document.querySelector('[data-check-all]');
    var items = Array.prototype.slice.call(document.querySelectorAll('[data-check-item]'));
    function refresh() {
      var checked = items.filter(function (box) { return box.checked; });
      if (bulk) bulk.hidden = checked.length === 0;
      var counter = document.querySelector('[data-admin-count]');
      if (counter) counter.textContent = String(checked.length);
      if (checkAll) {
        checkAll.checked = items.length > 0 && checked.length === items.length;
        checkAll.indeterminate = checked.length > 0 && checked.length < items.length;
      }
    }

    items.forEach(function (box) { box.addEventListener('change', refresh); });

    if (checkAll) {
      checkAll.addEventListener('change', function () {
        items.forEach(function (box) { box.checked = checkAll.checked; });
        refresh();
      });
    }
    refresh();
  });

  /* 整行点击 = 编辑：复选框、删除按钮、行内链接自己处理，不代行。
     注意别把 form 写进排除列表 —— 整张表就包在批量表单里，那会把所有单元格都挡掉。 */
  function rowFromEvent(target) {
    if (!target) return null;
    if (target.closest('input, button, a, select, textarea, label')) return null;
    return target.closest('[data-row-link]');
  }

  function openRow(row) {
    var url = row && row.getAttribute('data-row-link');
    if (!url) return;
    if (window.TL && window.TL.pjax) window.TL.pjax.visit(url);
    else window.location.href = url;
  }

  document.addEventListener('click', function (event) {
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    var row = rowFromEvent(event.target);
    if (!row) return;
    event.preventDefault();
    openRow(row);
  });

  /* 手机端：真正的滑动手势结束后，浏览器有一小段「惯性滚动」。
     这段时间里落下的触摸会被系统吞掉，既不产生 click、也不派发 touchstart，
     用户看到的就是「滑完点不动」。所以在 touchend 里自己判断轻点：
     按下到抬起位移很小、时间很短，就当作点击，直接进编辑。 */
  (function () {
    var start = null;

    document.addEventListener('touchstart', function (event) {
      if (event.touches.length !== 1) { start = null; return; }
      var row = rowFromEvent(event.target);
      if (!row) { start = null; return; }
      var t = event.touches[0];
      start = { row: row, x: t.clientX, y: t.clientY, at: Date.now(), moved: false };
    }, { passive: true });

    document.addEventListener('touchmove', function (event) {
      if (!start) return;
      var t = event.touches[0];
      if (Math.abs(t.clientX - start.x) > 10 || Math.abs(t.clientY - start.y) > 10) start.moved = true;
    }, { passive: true });

    document.addEventListener('touchend', function (event) {
      var s = start;
      start = null;
      if (!s || s.moved) return;
      if (Date.now() - s.at > 700) return;
      /* 浏览器随后会补一个 click；标记一下避免重复触发。 */
      s.row.dataset.touchHandled = '1';
      window.setTimeout(function () { delete s.row.dataset.touchHandled; }, 400);
      openRow(s.row);
    }, { passive: true });

    /* 若 touchend 已经处理过，click 就不要再走一遍。 */
    document.addEventListener('click', function (event) {
      var row = event.target.closest ? event.target.closest('[data-row-link]') : null;
      if (row && row.dataset.touchHandled === '1') {
        event.preventDefault();
        event.stopPropagation();
      }
    }, true);
  })();

})();
