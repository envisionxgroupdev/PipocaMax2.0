/* Neve do tema de Natal: leve, pausa quando a aba não está visível e respeita "reduzir movimento" */
(function () {
  if (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  var me = document.currentScript, allowToggle = me && me.dataset.toggle === '1';
  var KEY = 'pm_snow_off', off = false;
  try { off = localStorage.getItem(KEY) === '1'; } catch (e) {}
  var cv = null, ctx, W, H, dpr, flakes = [], raf = 0, last = 0;
  var count = function () { return Math.round(Math.min(110, Math.max(30, (innerWidth * innerHeight) / 16000))); };
  var make = function (top) {
    var r = Math.random() * 2.4 + 0.8;
    return { x: Math.random() * W, y: top ? -10 : Math.random() * H, r: r, s: r * 0.35 + 0.25, w: Math.random() * Math.PI * 2, o: Math.random() * 0.5 + 0.45 };
  };
  var size = function () {
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    W = innerWidth; H = innerHeight;
    cv.width = W * dpr; cv.height = H * dpr;
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    var n = count();
    while (flakes.length < n) flakes.push(make(false));
    flakes.length = n;
  };
  var tick = function (t) {
    raf = requestAnimationFrame(tick);
    var dt = Math.min(50, t - (last || t)) / 16.7; last = t;
    ctx.clearRect(0, 0, W, H);
    ctx.fillStyle = '#fff';
    for (var i = 0; i < flakes.length; i++) {
      var f = flakes[i];
      f.y += f.s * dt; f.w += 0.01 * dt; f.x += Math.sin(f.w) * 0.35 * dt;
      if (f.y > H + 5) { flakes[i] = make(true); continue; }
      if (f.x > W + 5) f.x = -5; else if (f.x < -5) f.x = W + 5;
      ctx.globalAlpha = f.o;
      ctx.beginPath(); ctx.arc(f.x, f.y, f.r, 0, 6.283); ctx.fill();
    }
  };
  var start = function () {
    if (cv) return;
    cv = document.createElement('canvas'); cv.id = 'xmas-snow'; cv.setAttribute('aria-hidden', 'true');
    document.body.appendChild(cv); ctx = cv.getContext('2d'); flakes = []; size();
    last = 0; raf = requestAnimationFrame(tick);
  };
  var stop = function () { cancelAnimationFrame(raf); raf = 0; if (cv) { cv.remove(); cv = null; } };
  var go = function () {
    if (!off) start();
    if (allowToggle) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'xmas-snow-btn' + (off ? ' off' : '');
      b.title = off ? 'Ligar a neve' : 'Desligar a neve'; b.setAttribute('aria-label', b.title);
      b.addEventListener('click', function () {
        off = !off;
        try { localStorage.setItem(KEY, off ? '1' : '0'); } catch (e) {}
        b.classList.toggle('off', off); b.title = off ? 'Ligar a neve' : 'Desligar a neve'; b.setAttribute('aria-label', b.title);
        if (off) stop(); else start();
      });
      document.body.appendChild(b);
    }
    var rt;
    addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(function () { if (cv) size(); }, 200); });
    document.addEventListener('visibilitychange', function () {
      if (!cv) return;
      if (document.hidden) { cancelAnimationFrame(raf); raf = 0; } else if (!raf) { last = 0; raf = requestAnimationFrame(tick); }
    });
  };
  // Começa depois que a página carregou, para não pesar no carregamento
  var later = function () { (window.requestIdleCallback || function (f) { setTimeout(f, 300); })(go); };
  if (document.readyState === 'complete') later(); else addEventListener('load', later);
})();
