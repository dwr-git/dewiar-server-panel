(function () {
  'use strict';
  var meta = document.querySelector('meta[name=csrf]');
  var csrf = meta ? meta.content : '';
  var dlg = document.getElementById('dlg');
  var title = document.getElementById('dlg-title');
  var text = document.getElementById('dlg-text');
  var log = document.getElementById('dlg-log');
  var ok = document.getElementById('dlg-ok');
  var cancel = document.getElementById('dlg-cancel');
  var pending = null;
  var done = false;
  var success = false;

  /* Действия с подтверждением */
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('button[data-action]');
    if (!b) return;
    pending = { action: b.dataset.action, arg: b.dataset.arg, next: b.dataset.next || '' };
    done = false;
    success = false;
    title.textContent = b.dataset.title || 'Подтвердите действие';
    text.textContent = b.dataset.text || '';
    log.hidden = true;
    log.textContent = '';
    ok.hidden = false;
    ok.disabled = false;
    ok.textContent = 'Выполнить';
    cancel.disabled = false;
    cancel.textContent = 'Отмена';
    dlg.showModal();
  });

  if (cancel) {
    cancel.addEventListener('click', function () {
      dlg.close();
      if (!done) return;
      if (success && pending.next) location.href = pending.next;
      else location.reload();
    });
    ok.addEventListener('click', function () {
      if (!pending) return;
      ok.disabled = true;
      cancel.disabled = true;
      ok.textContent = 'Выполняется…';
      var body = new URLSearchParams({ action: pending.action, arg: pending.arg });
      fetch('?p=api', { method: 'POST', headers: { 'X-CSRF': csrf }, body: body })
        .then(function (r) { return r.json(); })
        .catch(function () { return { ok: false, log: 'Нет ответа от панели. Проверьте соединение и повторите.' }; })
        .then(function (res) {
          success = !!res.ok;
          text.textContent = res.ok ? 'Готово.' : 'Не получилось. Подробности ниже.';
          log.textContent = res.log || '';
          log.hidden = !res.log;
          ok.hidden = true;
          cancel.disabled = false;
          cancel.textContent = 'Закрыть';
          done = true;
        });
    });
  }

  /* Поиск по карточкам */
  var q = document.getElementById('q');
  if (q) {
    q.addEventListener('input', function () {
      var v = q.value.trim().toLowerCase();
      document.querySelectorAll('.card').forEach(function (c) {
        c.hidden = v !== '' && c.dataset.names.indexOf(v) === -1;
      });
    });
  }

  /* Графики: вертикальная направляющая и подсказка со значениями */
  function fmtRate(b) {
    var u = ['Б/с', 'КБ/с', 'МБ/с', 'ГБ/с'], i = 0;
    while (b >= 1024 && i < 3) { b /= 1024; i++; }
    return (i > 0 && b < 10 ? b.toFixed(1) : Math.round(b)).toString().replace('.', ',') + ' ' + u[i];
  }
  function fmt(v, unit) {
    if (unit === 'pct') return Math.round(v) + '%';
    if (unit === 'rate') return fmtRate(v);
    return (v < 10 ? v.toFixed(2) : v.toFixed(1)).replace('.', ',');
  }
  function two(n) { return (n < 10 ? '0' : '') + n; }

  document.querySelectorAll('svg.plot').forEach(function (svg) {
    var pts = JSON.parse(svg.dataset.pts);
    var g = JSON.parse(svg.dataset.g);
    var labels = JSON.parse(svg.dataset.labels);
    var unit = svg.dataset.unit;
    var guide = svg.querySelector('.guide');
    var tip = svg.parentNode.querySelector('.tip');

    function hide() {
      guide.setAttribute('visibility', 'hidden');
      tip.hidden = true;
    }
    svg.addEventListener('mousemove', function (ev) {
      var r = svg.getBoundingClientRect();
      var x = (ev.clientX - r.left) / r.width * g.w;
      var t = g.from + (x - g.x0) / (g.x1 - g.x0) * (g.to - g.from);
      var best = null, bd = Infinity;
      for (var i = 0; i < pts.length; i++) {
        var d = Math.abs(pts[i][0] - t);
        if (d < bd) { bd = d; best = pts[i]; }
      }
      if (!best) return hide();
      var gx = g.x0 + (best[0] - g.from) / (g.to - g.from) * (g.x1 - g.x0);
      guide.setAttribute('x1', gx);
      guide.setAttribute('x2', gx);
      guide.setAttribute('visibility', 'visible');
      var dt = new Date(best[0] * 1000);
      var when = (g.long ? two(dt.getDate()) + '.' + two(dt.getMonth() + 1) + ' ' : '') + two(dt.getHours()) + ':' + two(dt.getMinutes());
      var lines = [when];
      for (var k = 1; k < best.length; k++) {
        lines.push((labels.length > 1 ? labels[k - 1] + ': ' : '') + fmt(best[k], unit));
      }
      tip.textContent = lines.join('\n');
      tip.hidden = false;
      var pct = gx / g.w * 100;
      tip.style.left = pct + '%';
      tip.style.transform = pct > 70 ? 'translateX(-105%)' : 'translateX(8px)';
    });
    svg.addEventListener('mouseleave', hide);
  });
})();
