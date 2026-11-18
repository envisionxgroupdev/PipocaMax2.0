/* Pipoca Max — scripts do site */
(function () {
  'use strict';
  var PM = window.PM || { base: '', csrf: '' };
  var $ = function (s, c) { return (c || document).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };
  var api = function (path) { return PM.base + '/api.php?' + path; };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function toast(msg) {
    var t = document.createElement('div');
    t.className = 'toast';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 2600);
  }

  function post(action, data) {
    var fd = new FormData();
    fd.append('_csrf', PM.csrf);
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(api('action=' + action), { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { j._status = r.status; return j; }); });
  }

  /* Imagem quebrada -> imagem padrão */
  document.addEventListener('error', function (ev) {
    var t = ev.target;
    if (t && t.tagName === 'IMG' && !t.dataset.fb && t.closest('.card, .ep-thumb, .suggest, .detail-inner')) {
      t.dataset.fb = '1';
      t.src = PM.base + '/assets/img/placeholder.svg';
    }
  }, true);

  /* ---------- Topo ---------- */
  var topbar = $('#topbar');
  var scrollTick = false;
  function onScroll() {
    if (scrollTick) return;
    scrollTick = true;
    requestAnimationFrame(function () { scrollTick = false; if (topbar) topbar.classList.toggle('scrolled', window.scrollY > 30); });
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  /* ---------- Busca com sugestões ---------- */
  var search = $('.search');
  if (search) {
    var input = $('input', search);
    var box = null, timer = null, hl = -1;
    $('.search-toggle', search).addEventListener('click', function () {
      if (search.classList.contains('open') && input.value.trim()) { search.submit(); return; }
      search.classList.toggle('open');
      if (search.classList.contains('open')) input.focus();
    });
    input.addEventListener('blur', function () {
      setTimeout(function () {
        if (!input.value) search.classList.remove('open');
        if (box) { box.remove(); box = null; }
      }, 200);
    });
    input.addEventListener('input', function () {
      clearTimeout(timer);
      var q = input.value.trim();
      if (q.length < 2) { if (box) { box.remove(); box = null; } return; }
      timer = setTimeout(function () {
        fetch(api('action=suggest&q=' + encodeURIComponent(q))).then(function (r) { return r.json(); }).then(function (j) {
          if (!box) { box = document.createElement('div'); box.className = 'suggest'; search.appendChild(box); }
          hl = -1;
          box.innerHTML = (j.results || []).map(function (r) {
            return '<a href="' + esc(r.url) + '"><img src="' + esc(r.poster) + '" alt=""><div><b>' + esc(r.title) + '</b><small>' + esc(r.type) + (r.year ? ' • ' + esc(r.year) : '') + '</small></div></a>';
          }).join('') || '<a href="#" onclick="return false"><div><small>Nada encontrado</small></div></a>';
        }).catch(function () {});
      }, 220);
    });
    input.addEventListener('keydown', function (ev) {
      if (!box) return;
      var links = $$('a', box);
      if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
        ev.preventDefault();
        hl = (hl + (ev.key === 'ArrowDown' ? 1 : -1) + links.length) % links.length;
        links.forEach(function (a, i) { a.classList.toggle('hl', i === hl); });
      } else if (ev.key === 'Enter' && hl >= 0 && links[hl]) {
        ev.preventDefault();
        location.href = links[hl].href;
      }
    });
  }

  /* ---------- Menu do usuário ---------- */
  var um = $('.usermenu');
  if (um) {
    $('.avatar', um).addEventListener('click', function (ev) { ev.stopPropagation(); um.classList.toggle('open'); });
    document.addEventListener('click', function () { um.classList.remove('open'); });
  }

  /* ---------- Avisos (banners) ---------- */
  (function () {
    var LS = function (k, v) { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; } };
    var SS = function (k, v) { try { if (v === undefined) return sessionStorage.getItem(k); sessionStorage.setItem(k, v); } catch (e) { return null; } };
    var hit = function (id, t) { try { var d = new FormData(); d.append('a', id); d.append('t', t); navigator.sendBeacon ? navigator.sendBeacon(PM.base + '/api.php?action=ann_hit', d) : fetch(PM.base + '/api.php?action=ann_hit', { method: 'POST', body: d }); } catch (e) {} };
    var fitBottom = function () {
      var b = $('.ann-bottom');
      document.body.classList.toggle('ann-has-bottom', !!b);
      if (b) document.documentElement.style.setProperty('--ann-b', b.offsetHeight + 'px');
    };
    $$('[data-ann]').forEach(function (el) {
      var id = el.dataset.ann, pos = el.dataset.pos;
      if (LS('pm_ann_x:' + id)) { el.remove(); return; }
      if (pos === 'popup') {
        var f = el.dataset.freq;
        if ((f === 'once' && LS('pm_ann_seen:' + id)) || (f === 'session' && SS('pm_ann_seen:' + id))) { el.remove(); return; }
        setTimeout(function () {
          el.hidden = false; hit(id, 'view');
          LS('pm_ann_seen:' + id, '1'); SS('pm_ann_seen:' + id, '1');
          var x = $('[data-ann-close]', el); if (x) x.focus();
        }, Math.max(0, +el.dataset.delay || 0) * 1000);
        el.addEventListener('click', function (e) { if (e.target === el && $('[data-ann-close]', el)) close(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !el.hidden && $('[data-ann-close]', el)) close(); });
      } else {
        hit(id, 'view');
      }
      function close() {
        if (pos !== 'popup') LS('pm_ann_x:' + id, '1');
        el.remove();
        if (pos === 'top') document.documentElement.style.setProperty('--ann', '0px');
        fitBottom();
      }
      var x = $('[data-ann-close]', el); if (x) x.addEventListener('click', close);
      var c = $('[data-ann-click]', el); if (c) c.addEventListener('click', function () { hit(id, 'click'); if (pos === 'popup') el.remove(); });
    });
    fitBottom();
    var t = $('.ann-top');
    if (t) window.addEventListener('resize', function () { if (document.body.contains(t)) document.documentElement.style.setProperty('--ann', t.offsetHeight + 'px'); });
  })();

  /* ---------- Sininho ---------- */
  var bell = $('.bell');
  if (bell) {
    var last = +bell.dataset.last, guest = +bell.dataset.seen < 0, seen;
    try { seen = guest ? +(localStorage.getItem('pm_notif_seen') || 0) : +bell.dataset.seen; } catch (e) { seen = 0; }
    var unread = $$('.bell-list li', bell).filter(function (li) { return +li.dataset.id > seen; });
    unread.forEach(function (li) { li.classList.add('new'); });
    var cnt = $('.bell-count', bell);
    if (unread.length) { cnt.textContent = unread.length > 9 ? '9+' : unread.length; cnt.hidden = false; }
    $('.bell-btn', bell).addEventListener('click', function (ev) {
      ev.stopPropagation();
      if (um) um.classList.remove('open');
      bell.classList.toggle('open');
      if (bell.classList.contains('open') && last > seen) {
        seen = last; cnt.hidden = true;
        if (guest) { try { localStorage.setItem('pm_notif_seen', last); } catch (e) {} }
        else post('notif_seen', { id: last });
      }
    });
    $('.bell-drop', bell).addEventListener('click', function (ev) { ev.stopPropagation(); });
    document.addEventListener('click', function () { bell.classList.remove('open'); });
    if (um) $('.avatar', um).addEventListener('click', function () { bell.classList.remove('open'); });
  }

  /* ---------- Fileiras ---------- */
  var showImg = function (im) {
    if (!im.dataset.src) return;
    if (im.dataset.srcset) im.srcset = im.dataset.srcset;
    im.src = im.dataset.src;
    im.removeAttribute('data-src'); im.removeAttribute('data-srcset'); im.classList.remove('lz');
  };
  var loadRow = function (track) { $$('img.lz', track).forEach(showImg); };
  // Telas largas: mostra logo os cartões que já cabem na tela (leitura única, sem travar)
  var fillRow = function (t) {
    var lim = t.clientWidth * 1.3;
    $$('img.lz', t).filter(function (im) { return im.closest('.card').offsetLeft < lim; }).forEach(showImg);
  };
  if ('IntersectionObserver' in window) {
    var rowIO = new IntersectionObserver(function (ents) {
      ents.forEach(function (en) { if (en.isIntersecting) { rowIO.unobserve(en.target); fillRow(en.target); } });
    }, { rootMargin: '300px 0px' });
    $$('.row-track').forEach(function (t) { if ($('img.lz', t)) rowIO.observe(t); });
  } else {
    $$('.row-track').forEach(loadRow);
  }
  $$('.row-wrap').forEach(function (w) {
    var track = $('.row-track', w);
    var once = function () { loadRow(track); track.removeEventListener('scroll', once); w.removeEventListener('mouseenter', once); };
    track.addEventListener('scroll', once, { passive: true });
    w.addEventListener('mouseenter', once);
    track.addEventListener('touchstart', once, { passive: true });
    $$('.row-nav', w).forEach(function (b) {
      b.addEventListener('click', function () {
        var dir = b.classList.contains('next') ? 1 : -1;
        track.scrollBy({ left: dir * track.clientWidth * 0.85, behavior: 'smooth' });
      });
    });
  });

  /* ---------- Destaques (hero) ---------- */
  var hero = $('[data-hero]');
  if (hero) {
    var slides = $$('.hero-slide', hero), dots = $$('.hero-dots button', hero), cur = 0, iv;
    var loadBg = function (k) {
      var bg = slides[k] && slides[k].querySelector('.hero-bg[data-bg]');
      if (bg) { bg.setAttribute('style', bg.getAttribute('data-bg')); bg.removeAttribute('data-bg'); }
    };
    setTimeout(function () { loadBg(1); }, 4000); // adianta o próximo depois que a página carregou
    var go = function (i) {
      slides[cur].classList.remove('active'); if (dots[cur]) dots[cur].classList.remove('active');
      cur = (i + slides.length) % slides.length;
      loadBg(cur); loadBg((cur + 1) % slides.length);
      slides[cur].classList.add('active'); if (dots[cur]) dots[cur].classList.add('active');
    };
    var start = function () { clearInterval(iv); if (slides.length > 1) iv = setInterval(function () { go(cur + 1); }, 8000); };
    dots.forEach(function (d) { d.addEventListener('click', function () { go(+d.dataset.go); start(); }); });
    start();
  }

  /* ---------- Player ---------- */
  var hlsLoading = null;
  function loadHls() {
    if (window.Hls) return Promise.resolve(window.Hls);
    if (!hlsLoading) {
      hlsLoading = new Promise(function (res, rej) {
        var s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/hls.js@1.5.17/dist/hls.min.js';
        s.onload = function () { res(window.Hls); };
        s.onerror = rej;
        document.head.appendChild(s);
      });
    }
    return hlsLoading;
  }

  /* ----- Player próprio (Video.js): só para players do tipo "Player próprio" ----- */
  var pmpLoading = null;
  function loadPmPlayer() {
    if (window.PMPlayer) return Promise.resolve(window.PMPlayer);
    if (!pmpLoading) {
      pmpLoading = new Promise(function (res, rej) {
        var s = document.createElement('script');
        s.src = PM.base + '/assets/js/pmplayer.js?v=' + (PM.vjs && PM.vjs.v || '1');
        s.onload = function () { window.PMPlayer ? res(window.PMPlayer) : rej(); };
        s.onerror = rej;
        document.head.appendChild(s);
      });
      pmpLoading.catch(function () { pmpLoading = null; });
    }
    return pmpLoading;
  }

  function playIn(box, p) {
    if (box._vjs) { try { box._vjs.dispose(); } catch (e) {} box._vjs = null; }
    if (box._hls) { box._hls.destroy(); box._hls = null; }
    box.innerHTML = '<div class="player-msg"><div class="spinner"></div></div>';
    if (p && p.maint === 1) {
      box.innerHTML = p.soon
        ? '<div class="player-msg player-maint"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><b>' + esc(p.title || 'Em breve neste player') + '</b><span>' + esc(p.msg || '') + '</span></div>'
        : '<div class="player-msg player-maint"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.6 2.6-2.4-.6-.6-2.4z"/></svg><b>Player em manutenção</b><span>' + esc(p.msg || '') + '</span></div>';
      return;
    }
    if (!p || !/^https?:\/\//i.test(p.url || '')) { box.innerHTML = '<div class="player-msg">Player indisponível.</div>'; return; }
    if (p.kind === 'embed') {
      var f = document.createElement('iframe');
      f.src = p.url;
      f.setAttribute('allowfullscreen', '');
      f.setAttribute('allow', 'autoplay; fullscreen; encrypted-media; picture-in-picture');
      f.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox allow-presentation');
      f.setAttribute('frameborder', '0');
      f.setAttribute('scrolling', 'no');
      box.innerHTML = '';
      box.appendChild(f);
      return;
    }
    if (p.kind === 'videojs') {
      var go = function (pp) {
        // Player próprio; se não carregar, usa o player simples do navegador
        var fb = { url: pp.url, kind: /\.m3u8($|\?)/i.test(pp.url) ? 'hls' : 'video' };
        loadPmPlayer().then(function (P) { return P.play(box, pp, PM.vjs || {}); }).catch(function () { playNative(box, fb); });
      };
      if (!p.resolve) { go(p); return; }
      // Link vem da sua API: pede ao site o endereço do vídeo
      fetch(p.url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
        if (!j.url) { box.innerHTML = '<div class="player-msg">' + esc(j.error || 'Vídeo indisponível neste player. Tente outro.') + '</div>'; return; }
        go({ url: j.url, kind: 'videojs' });
      }).catch(function () { box.innerHTML = '<div class="player-msg">Vídeo indisponível neste player. Tente outro.</div>'; });
      return;
    }
    playNative(box, p);
  }

  function playNative(box, p) {
    var v = document.createElement('video');
    v.controls = true; v.autoplay = true; v.playsInline = true;
    v.setAttribute('playsinline', '');
    box.innerHTML = '';
    box.appendChild(v);
    v.addEventListener('error', function () {
      box.innerHTML = '<div class="player-msg">Não foi possível carregar este player. Tente outro.</div>';
    });
    if (p.kind === 'hls' && !v.canPlayType('application/vnd.apple.mpegurl')) {
      loadHls().then(function (Hls) {
        if (!Hls.isSupported()) { v.src = p.url; return; }
        var h = new Hls();
        box._hls = h;
        h.loadSource(p.url);
        h.attachMedia(v);
        h.on(Hls.Events.MANIFEST_PARSED, function () { v.play().catch(function () {}); });
        h.on(Hls.Events.ERROR, function (e, d) {
          if (d.fatal) box.innerHTML = '<div class="player-msg">Não foi possível carregar este player. Tente outro.</div>';
        });
      }).catch(function () { v.src = p.url; });
    } else {
      v.src = p.url;
      v.play().catch(function () {});
    }
  }

  function bindServers(wrap, box, autoplay) {
    var btns = $$('.server', wrap);
    btns.forEach(function (b) {
      b.addEventListener('click', function () {
        btns.forEach(function (x) { x.classList.remove('active'); });
        b.classList.add('active');
        playIn(box, JSON.parse(b.dataset.player));
      });
    });
    if (autoplay && btns[0]) {
      // começa pelo primeiro player que não está em manutenção
      var first = btns.find(function (b) { return JSON.parse(b.dataset.player).maint !== 1; }) || btns[0];
      btns.forEach(function (x) { x.classList.toggle('active', x === first); });
      playIn(box, JSON.parse(first.dataset.player));
    }
  }

  /* Tela cheia do player (com virada para horizontal no celular) */
  var fsIcon = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg>';
  var exitIcon = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5"/></svg>';
  var exitBtn = null;
  function attachExit(box) {
    if (!exitBtn) return;
    box.appendChild(exitBtn);
  }
  function exitExpanded(box) {
    box.classList.remove('expanded');
    document.body.classList.remove('player-open');
    var fsEl = document.fullscreenElement || document.webkitFullscreenElement;
    if (fsEl) { (document.exitFullscreen || document.webkitExitFullscreen).call(document); }
    try { if (screen.orientation && screen.orientation.unlock) screen.orientation.unlock(); } catch (e) {}
    if (exitBtn && exitBtn.parentNode !== document.body) document.body.appendChild(exitBtn);
  }
  function fakeFull(box) {
    box.classList.add('expanded');
    document.body.classList.add('player-open');
    attachExit(box);
  }
  function goFull(box) {
    var req = box.requestFullscreen || box.webkitRequestFullscreen;
    if (!req) { fakeFull(box); return; } // iPhone: o player ocupa a tela inteira
    attachExit(box);
    try {
      var r = req.call(box);
      var lock = function () { try { if (screen.orientation && screen.orientation.lock) screen.orientation.lock('landscape').catch(function () {}); } catch (e) {} };
      if (r && r.then) r.then(lock).catch(function () { fakeFull(box); }); else lock();
    } catch (e) { fakeFull(box); }
  }
  ['fullscreenchange', 'webkitfullscreenchange'].forEach(function (evn) {
    document.addEventListener(evn, function () {
      if (!(document.fullscreenElement || document.webkitFullscreenElement)) {
        $$('.player').forEach(function (b) { if (!b.classList.contains('expanded')) exitExpanded(b); });
      }
    });
  });
  $$('[data-player-box]').forEach(function (box) {
    var bar = document.createElement('div');
    bar.className = 'player-bar';
    bar.innerHTML = '<span class="player-tip">Dica: gire o celular para assistir maior</span><button type="button" class="btn btn-sm btn-glass" data-fs>' + fsIcon + ' Tela cheia</button>';
    box.parentNode.insertBefore(bar, box.nextSibling);
    bar.querySelector('[data-fs]').addEventListener('click', function () { goFull(box); });
    // Se trocar de player em tela cheia, o botão de sair continua lá
    new MutationObserver(function () {
      var full = box.classList.contains('expanded') || (document.fullscreenElement || document.webkitFullscreenElement) === box;
      if (full && exitBtn && exitBtn.parentNode !== box) box.appendChild(exitBtn);
    }).observe(box, { childList: true });
  });
  if ($('[data-player-box]')) {
    exitBtn = document.createElement('button');
    exitBtn.type = 'button'; exitBtn.className = 'player-exit'; exitBtn.setAttribute('aria-label', 'Sair da tela cheia');
    exitBtn.innerHTML = exitIcon;
    document.body.appendChild(exitBtn);
    exitBtn.addEventListener('click', function (ev) { ev.stopPropagation(); $$('.player').forEach(exitExpanded); });
  }
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') $$('.player.expanded').forEach(exitExpanded);
  });

  /* ---------- Continuar assistindo ---------- */
  // Conta: salva no servidor. Visitante: cookie pm_cw ("conteudo-episodio-segundos-duracao", separados por _)
  var CW = (function () {
    var KEY = 'pm_cw', MAX = 20, lastSend = 0, pending = null, timer = null;
    var read = function () {
      var m = document.cookie.match(/(?:^|; )pm_cw=([^;]*)/);
      if (!m) return [];
      return m[1].split('_').map(function (s) {
        var p = s.split('-');
        return p.length === 4 ? { c: +p[0], e: p[1] ? +p[1] : 0, t: +p[2] || 0, d: +p[3] || 0 } : null;
      }).filter(function (x) { return x && x.c > 0; });
    };
    var write = function (list) {
      var v = list.slice(0, MAX).map(function (x) { return x.c + '-' + (x.e || '') + '-' + Math.floor(x.t || 0) + '-' + Math.floor(x.d || 0); }).join('_');
      document.cookie = KEY + '=' + v + '; path=' + (PM.base || '') + '/; max-age=' + (v ? 15552000 : 0) + '; samesite=lax';
    };
    var send = function (it, now) {
      pending = it;
      var go = function () {
        if (!pending) return;
        var x = pending; pending = null; lastSend = Date.now();
        post('cw_save', { c: x.c, e: x.e || 0, t: Math.floor(x.t || 0), d: Math.floor(x.d || 0) }).catch(function () {});
      };
      clearTimeout(timer);
      if (now || Date.now() - lastSend > 20000) go(); else timer = setTimeout(go, 20000);
    };
    // Ao sair da página, manda o último tempo que ficou pendente
    window.addEventListener('pagehide', function () {
      if (!pending || !navigator.sendBeacon) return;
      var fd = new FormData(), x = pending; pending = null;
      fd.append('_csrf', PM.csrf); fd.append('c', x.c); fd.append('e', x.e || 0); fd.append('t', Math.floor(x.t || 0)); fd.append('d', Math.floor(x.d || 0));
      navigator.sendBeacon(api('action=cw_save'), fd);
    });
    return {
      // it = { c, e, t, d }; sem t = só abriu (mantém onde parou se for o mesmo episódio)
      put: function (it, now) {
        if (PM.logged) { send(it, now); return; }
        var list = read(), old = null;
        list = list.filter(function (x) { if (x.c === it.c) { old = x; return false; } return true; });
        if (it.t === undefined) { it.t = old && (old.e || 0) === (it.e || 0) ? old.t : 0; it.d = old && (old.e || 0) === (it.e || 0) ? old.d : 0; }
        list.unshift(it);
        write(list);
      },
      remove: function (c) {
        if (PM.logged) { pending = null; clearTimeout(timer); post('cw_remove', { c: c }).catch(function () {}); return; }
        write(read().filter(function (x) { return x.c !== c; }));
      }
    };
  })();
  var cwCtx = null; // título aberto agora: { c, e, next() }
  document.addEventListener('pm:pos', function (ev) {
    if (!cwCtx) return;
    var t = ev.detail.t, d = ev.detail.d;
    if (!d || t < 10) return;
    if (cwCtx.done === (cwCtx.e || 0)) return; // já marcado como terminado
    if (t > d - 60) { // terminou
      cwCtx.done = cwCtx.e || 0;
      var nx = cwCtx.next ? cwCtx.next() : 0;
      if (nx) CW.put({ c: cwCtx.c, e: nx, t: 0, d: 0 }, true);
      else CW.remove(cwCtx.c);
      return;
    }
    CW.put({ c: cwCtx.c, e: cwCtx.e || 0, t: t, d: d });
  });

  // Fileira da página inicial: botão X tira o título da lista
  $$('[data-cw-del]').forEach(function (b) {
    b.addEventListener('click', function (ev) {
      ev.preventDefault(); ev.stopPropagation();
      var card = b.closest('.cw-card'), row = b.closest('[data-cw]');
      CW.remove(+b.dataset.cwDel);
      card.classList.add('cw-out');
      setTimeout(function () {
        card.remove();
        if (row && !$('.cw-card', row)) row.remove();
      }, 250);
      toast('Removido de Continuar assistindo');
    });
  });

  // Filme
  var watch = $('[data-watch]');
  if (watch) {
    var sv = $('[data-servers]', watch), pbox = $('[data-player-box]', watch);
    if (sv && pbox) {
      bindServers(sv, pbox, false);
      var cover = $('.player-cover', pbox);
      if (cover) cover.addEventListener('click', function () {
        var a = $('.server.active', sv) || $('.server', sv);
        if (a) a.click();
      });
      // Continuar assistindo: entra na lista quando começa a ver
      if (watch.dataset.cid) {
        cwCtx = { c: +watch.dataset.cid, e: 0 };
        sv.addEventListener('click', function (ev) { if (ev.target.closest('.server')) CW.put({ c: cwCtx.c, e: 0 }, true); });
      }
      // Veio do "Continuar assistindo" da página inicial: já abre o player
      if (location.hash === '#continuar' && cover) {
        setTimeout(function () { watch.scrollIntoView({ block: 'center' }); cover.click(); }, 150);
      }
    }
  }

  // Canal ao vivo
  $$('[data-autoplay]').forEach(function (b) { playIn(b, JSON.parse(b.dataset.autoplay)); });

  // Séries e animes
  var seriesEl = $('[data-series]');
  var dataEl = $('#series-data');
  if (seriesEl && dataEl) {
    var D = JSON.parse(dataEl.textContent);
    var sel = $('[data-season-select]', seriesEl);
    var list = $('[data-episodes]', seriesEl);
    var panel = $('[data-episode-panel]', seriesEl);
    var flat = [];
    D.seasons.forEach(function (s) {
      (D.episodes[s.id] || []).forEach(function (e) { flat.push({ ep: e, season: s }); });
    });
    var currentEp = null;
    if (seriesEl.dataset.cid) {
      cwCtx = { c: +seriesEl.dataset.cid, e: 0, next: function () {
        var i = flat.findIndex(function (x) { return x.ep.id === cwCtx.e; });
        var n = i >= 0 ? flat.slice(i + 1).find(function (x) { return x.ep.available; }) : null;
        return n ? n.ep.id : 0;
      } };
    }

    var renderSeason = function (sid) {
      var eps = D.episodes[sid] || [];
      if (!eps.length) { list.innerHTML = '<div class="empty small"><p>Nenhum episódio nesta temporada ainda.</p></div>'; return; }
      list.innerHTML = eps.map(function (e) {
        var thumb = e.still || D.fallback;
        return '<button class="episode' + (currentEp === e.id ? ' playing' : '') + '" data-ep="' + e.id + '">' +
          '<span class="ep-num">' + e.n + '</span>' +
          '<span class="ep-thumb">' + (thumb ? '<img loading="lazy" src="' + esc(thumb) + '" alt="">' : '') + '<span class="play-ico"></span></span>' +
          '<span class="ep-body"><span class="ep-title"><span>' + e.n + '. ' + esc(e.name || ('Episódio ' + e.n)) + '</span><small>' + esc(e.runtime) + '</small></span>' +
          (e.available ? '' : '<span class="ep-soon">Em breve</span>') +
          '<p class="ep-overview">' + esc(e.overview || '') + '</p></span></button>';
      }).join('');
    };

    var openEpisode = function (id, scroll) {
      var idx = flat.findIndex(function (x) { return x.ep.id === id; });
      if (idx < 0) return;
      var item = flat[idx];
      currentEp = id;
      if (+sel.value !== item.season.id) { sel.value = item.season.id; }
      renderSeason(item.season.id);
      panel.hidden = false;
      var label = (item.season.n === 0 ? 'Especial' : 'T' + item.season.n) + ':E' + item.ep.n + ' • ' + (item.ep.name || '');
      $('[data-ep-title]', panel).textContent = label;
      $('[data-ep-prev]', panel).disabled = idx === 0;
      $('[data-ep-next]', panel).disabled = idx === flat.length - 1;
      var rf = $('#report-modal input[name=episode_id]');
      if (rf) rf.value = id;
      var srv = $('[data-servers]', panel), box = $('[data-player-box]', panel);
      srv.innerHTML = '';
      box.innerHTML = '<div class="player-msg"><div class="spinner"></div></div>';
      if (scroll !== false) panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
      history.replaceState(null, '', '#ep-' + id);
      if (cwCtx) { cwCtx.e = id; CW.put({ c: cwCtx.c, e: id }, true); }
      fetch(api('action=episode&id=' + id), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j.error) { box.innerHTML = '<div class="player-msg">' + esc(j.error) + '</div>'; return; }
          if (!j.players.length) { box.innerHTML = '<div class="player-msg">Este episódio ainda não tem player disponível.</div>'; return; }
          srv.innerHTML = j.players.map(function (p, i) {
            return '<button class="server' + (i === 0 ? ' active' : '') + (p.maint ? ' is-maint' : '') + '" data-player="' + esc(JSON.stringify(p)) + '">' +
              '<svg class="ico" viewBox="0 0 24 24"><path d="M7 4v16l13-8z" fill="currentColor"/></svg> <span>' + esc(p.name) + '</span>' +
              (p.audio ? '<em>' + esc(p.audio) + '</em>' : '') + (p.quality ? '<em class="q">' + esc(p.quality) + '</em>' : '') +
              (p.maint && !p.soon ? '<em class="mt">' + (p.maint === 'admin' ? 'Manutenção • só você vê' : 'Manutenção') + '</em>' : '') + '</button>';
          }).join('');
          bindServers(srv, box, true);
        })
        .catch(function () { box.innerHTML = '<div class="player-msg">Erro ao carregar. Tente novamente.</div>'; });
    };

    sel.addEventListener('change', function () { renderSeason(+sel.value); });
    list.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-ep]');
      if (b) openEpisode(+b.dataset.ep);
    });
    $('[data-ep-prev]', panel).addEventListener('click', function () {
      var i = flat.findIndex(function (x) { return x.ep.id === currentEp; });
      if (i > 0) openEpisode(flat[i - 1].ep.id);
    });
    $('[data-ep-next]', panel).addEventListener('click', function () {
      var i = flat.findIndex(function (x) { return x.ep.id === currentEp; });
      if (i < flat.length - 1) openEpisode(flat[i + 1].ep.id);
    });

    var m = location.hash.match(/^#ep-(\d+)$/);
    if (m) { openEpisode(+m[1]); }
    else if (sel.value) { renderSeason(+sel.value); }

    // Botão "Assistir" do topo: abre o primeiro episódio disponível
    $$('[data-scroll]').forEach(function (a) {
      a.addEventListener('click', function (ev) {
        if (currentEp || !flat.length) return;
        ev.preventDefault();
        var first = flat.find(function (x) { return x.ep.available; }) || flat[0];
        openEpisode(first.ep.id);
      });
    });
  }

  /* ---------- Minha Lista ---------- */
  $$('[data-fav]').forEach(function (b) {
    b.addEventListener('click', function () {
      post('favorite', { id: b.dataset.fav }).then(function (j) {
        if (j.login) { location.href = PM.base + '/login.php?voltar=' + encodeURIComponent(location.pathname + location.search); return; }
        if (j.error) { toast(j.error); return; }
        b.classList.toggle('on', j.on);
        toast(j.on ? 'Adicionado à Minha Lista' : 'Removido da Minha Lista');
      });
    });
  });

  /* ---------- Modais ---------- */
  function openModal(m) { m.hidden = false; document.body.style.overflow = 'hidden'; }
  function closeModal(m) {
    m.hidden = true; document.body.style.overflow = '';
    var r = $('.ratio', m); if (r) r.innerHTML = '';
  }
  $$('.modal').forEach(function (m) {
    m.addEventListener('click', function (ev) { if (ev.target === m || ev.target.closest('.modal-close')) closeModal(m); });
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') $$('.modal').forEach(function (m) { if (!m.hidden) closeModal(m); });
  });

  $$('[data-trailer]').forEach(function (b) {
    b.addEventListener('click', function () {
      var m = $('#trailer-modal');
      $('.ratio', m).innerHTML = '<iframe src="https://www.youtube-nocookie.com/embed/' + encodeURIComponent(b.dataset.trailer) + '?autoplay=1&rel=0" allow="autoplay; fullscreen; encrypted-media" allowfullscreen></iframe>';
      openModal(m);
    });
  });

  /* ---------- Compartilhar ---------- */
  var sm = $('#share-modal');
  if (sm) {
    var sd = {}; try { sd = JSON.parse($('#share-data').textContent); } catch (e) {}
    var nat = $('[data-share-native]', sm);
    if (navigator.share && nat) nat.hidden = false;
    $$('[data-share]').forEach(function (b) {
      b.addEventListener('click', function () { openModal(sm); });
    });
    if (nat) nat.addEventListener('click', function () {
      navigator.share({ title: sd.title, text: sd.text, url: sd.url }).then(function () { closeModal(sm); }).catch(function () {});
    });
    var copyBtn = $('[data-share-copy]', sm), inp = $('[data-share-url]', sm);
    inp.addEventListener('focus', function () { inp.select(); });
    copyBtn.addEventListener('click', function () {
      var done = function () { toast('Link copiado!'); };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(inp.value).then(done, function () { inp.select(); document.execCommand('copy'); done(); });
      } else { inp.focus(); inp.select(); try { document.execCommand('copy'); done(); } catch (e) { toast('Segure o link para copiar'); } }
    });
    $$('[data-share-to]', sm).forEach(function (a) {
      a.addEventListener('click', function () { setTimeout(function () { closeModal(sm); }, 300); });
    });
  }

  $$('[data-report]').forEach(function (b) {
    b.addEventListener('click', function () { var m = $('#report-modal'); if (m) openModal(m); });
  });
  var rf = $('[data-report-form]');
  if (rf) rf.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var data = {};
    new FormData(rf).forEach(function (v, k) { data[k] = v; });
    post('report', data).then(function (j) {
      if (j.error) { toast(j.error); return; }
      closeModal($('#report-modal'));
      toast('Obrigado! Vamos verificar.');
    });
  });

  // Rolar até o player
  $$('a[data-scroll]').forEach(function (a) {
    a.addEventListener('click', function (ev) {
      if (ev.defaultPrevented) return;
      var t = $(a.getAttribute('href'));
      if (t) { ev.preventDefault(); t.scrollIntoView({ behavior: 'smooth' }); }
    });
  });
})();
