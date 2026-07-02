// 常時表示カレンダー（管理・公開で共用）。
//   1) 選択モード   [data-cal="<id>"]            … 管理画面。日付クリックで #<id>-date / #<id>-label を更新。
//                                                  data-marks（JSON）で open/closed/block のドットを表示。
//   2) 空き状況モード [data-cal-availability]      … 公開予約画面。月ごとに api から各日の状態（free/full/
//                                                  closed/out）を取得しドット表示。日付クリックで枠を取得して
//                                                  data-panel の要素にリロードなしで描画。
(function () {
  var WD = ['日', '月', '火', '水', '木', '金', '土'];
  function pad(n) { return String(n).padStart(2, '0'); }
  function iso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }

  // 汎用の月カレンダーを host に描画する。
  //   opts.decorate(cell, key, cur)     … 各日セルの装飾（選択中ハイライト等）
  //   opts.onClick(key, cur, rerender)  … 日付クリック時（無効日には付かない）
  //   opts.onMonth(view, cells)         … 月を描画し終えた後（cells: {YYYY-MM-DD: buttonEl}）
  function makeCalendar(host, opts) {
    var today = new Date(); today.setHours(0, 0, 0, 0);
    var view = new Date(today.getFullYear(), today.getMonth(), 1);

    function navBtn(text, fn) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'cal-nav'; b.textContent = text; b.onclick = fn;
      return b;
    }

    function render() {
      host.innerHTML = '';
      var head = document.createElement('div'); head.className = 'cal-head';
      head.appendChild(navBtn('‹', function () { view.setMonth(view.getMonth() - 1); render(); }));
      var title = document.createElement('span'); title.className = 'cal-title';
      title.textContent = view.getFullYear() + '年 ' + (view.getMonth() + 1) + '月';
      head.appendChild(title);
      head.appendChild(navBtn('›', function () { view.setMonth(view.getMonth() + 1); render(); }));
      host.appendChild(head);

      var grid = document.createElement('div'); grid.className = 'cal-grid';
      WD.forEach(function (w, i) {
        var c = document.createElement('div');
        c.className = 'cal-wd' + (i === 0 ? ' sun' : i === 6 ? ' sat' : '');
        c.textContent = w; grid.appendChild(c);
      });
      var lead = new Date(view.getFullYear(), view.getMonth(), 1).getDay();
      for (var i = 0; i < lead; i++) grid.appendChild(document.createElement('div'));

      var dim = new Date(view.getFullYear(), view.getMonth() + 1, 0).getDate();
      var cells = {};
      for (var d = 1; d <= dim; d++) {
        var cur = new Date(view.getFullYear(), view.getMonth(), d);
        var key = iso(cur);
        var cell = document.createElement('button');
        cell.type = 'button'; cell.className = 'cal-day'; cell.textContent = d;
        if (cur.getDay() === 0) cell.classList.add('sun');
        if (cur.getDay() === 6) cell.classList.add('sat');
        if (cur < today) { cell.disabled = true; cell.classList.add('is-past'); }
        if (opts.decorate) opts.decorate(cell, key, cur);
        if (!cell.disabled && opts.onClick) {
          cell.onclick = (function (k, c) { return function () { opts.onClick(k, c, render); }; })(key, cur);
        }
        cells[key] = cell;
        grid.appendChild(cell);
      }
      host.appendChild(grid);
      if (opts.onMonth) opts.onMonth(view, cells);
    }

    render();
    return { rerender: render };
  }

  // ===== 1) 管理: 選択モード =====
  document.querySelectorAll('[data-cal]').forEach(function (host) {
    var id = host.getAttribute('data-cal');
    var hidden = document.getElementById(id + '-date');
    var label = document.getElementById(id + '-label');
    var marks = {};
    try { marks = JSON.parse(host.getAttribute('data-marks') || '{}'); } catch (e) {}
    makeCalendar(host, {
      decorate: function (cell, key) {
        if (hidden && key === hidden.value) cell.classList.add('is-sel');
        if (marks[key]) cell.classList.add('mark-' + marks[key]);
      },
      onClick: function (key, cur, rerender) {
        if (hidden) hidden.value = key;
        if (label) label.textContent = key;
        rerender();
      }
    });
  });

  // ===== 2) 公開: 空き状況モード =====
  document.querySelectorAll('[data-cal-availability]').forEach(function (host) {
    var api = host.getAttribute('data-api');
    var panel = document.getElementById(host.getAttribute('data-panel'));
    var capacity = parseInt(host.getAttribute('data-capacity') || '1', 10);
    var selected = host.getAttribute('data-selected') || null;
    var cache = {}; // 'YYYY-MM' -> { 'YYYY-MM-DD': status }

    makeCalendar(host, {
      decorate: function (cell, key) {
        if (key === selected) cell.classList.add('is-sel');
      },
      onClick: function (key, cur, rerender) {
        selected = key;
        rerender();
        loadSlots(key);
      },
      onMonth: function (view, cells) {
        applyMonth(view.getFullYear() + '-' + pad(view.getMonth() + 1), cells);
      }
    });

    function paint(days, cells) {
      Object.keys(cells).forEach(function (key) {
        var st = days[key];
        if (!st) return;
        if (st === 'out') { cells[key].disabled = true; cells[key].classList.add('is-out'); return; }
        cells[key].classList.add('mark-' + st);
        if (st === 'closed') cells[key].classList.add('is-closed');
      });
    }

    function applyMonth(month, cells) {
      if (cache[month]) { paint(cache[month], cells); return; }
      fetch(api + '?month=' + month)
        .then(function (r) { return r.json(); })
        .then(function (data) { cache[month] = data.days || {}; paint(cache[month], cells); })
        .catch(function () {});
    }

    function loadSlots(date) {
      if (!panel) return;
      panel.innerHTML = '<h2>' + date + ' の空き状況</h2><p class="empty">読み込み中…</p>';
      fetch(api + '?date=' + date)
        .then(function (r) { return r.json(); })
        .then(function (data) { renderSlots(date, data); })
        .catch(function () {
          panel.innerHTML = '<h2>' + date + ' の空き状況</h2>'
            + '<p class="error">空き状況を取得できませんでした。時間をおいて再度お試しください。</p>';
        });
    }

    function renderSlots(date, data) {
      var slots = data.slots || [];
      var cap = data.capacity || capacity;
      var html = '<h2>' + date + ' の空き状況</h2>';
      if (data.closed) {
        html += '<p class="empty">この日は休診日です。別の日をお選びください。</p>';
      } else if (!slots.length) {
        html += '<p class="empty">この日に空いている枠はありません。別の日をお選びください。</p>';
      } else {
        html += '<ul class="slots">';
        slots.forEach(function (s) {
          html += '<li><a href="reserve.php?date=' + encodeURIComponent(date)
            + '&time=' + encodeURIComponent(s.time) + '">' + s.time;
          if (cap > 1) html += '<span class="remaining">残' + (s.remaining | 0) + '</span>';
          html += '</a></li>';
        });
        html += '</ul>';
      }
      panel.innerHTML = html;
    }
  });
})();
