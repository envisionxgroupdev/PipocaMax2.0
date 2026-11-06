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
  function onScroll() { if (topbar) topbar.classList.toggle('scrolled', window.scrollY > 30); }
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

  /* ---------- Fileiras ---------- */
  $$('.row-wrap').forEach(function (w) {
    var track = $('.row-track', w);
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
    var go = function (i) {
      slides[cur].classList.remove('active'); if (dots[cur]) dots[cur].classList.remove('active');
      cur = (i + slides.length) % slides.length;
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

  function playIn(box, p) {
    if (box._hls) { box._hls.destroy(); box._hls = null; }
    box.innerHTML = '<div class="player-msg"><div class="spinner"></div></div>';
    if (!p || !/^https?:\/\//i.test(p.url || '')) { box.innerHTML = '<div class="player-msg">Player indisponível.</div>'; return; }
    if (p.kind === 'embed') {
      var f = document.createElement('iframe');
      f.src = p.url;
      f.setAttribute('allowfullscreen', '');
      f.setAttribute('allow', 'autoplay; fullscreen; encrypted-media; picture-in-picture');
      f.setAttribute('frameborder', '0');
      f.setAttribute('scrolling', 'no');
      box.innerHTML = '';
      box.appendChild(f);
      return;
    }
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
    if (autoplay && btns[0]) playIn(box, JSON.parse(btns[0].dataset.player));
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
      fetch(api('action=episode&id=' + id), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j.error) { box.innerHTML = '<div class="player-msg">' + esc(j.error) + '</div>'; return; }
          if (!j.players.length) { box.innerHTML = '<div class="player-msg">Este episódio ainda não tem player disponível.</div>'; return; }
          srv.innerHTML = j.players.map(function (p, i) {
            return '<button class="server' + (i === 0 ? ' active' : '') + '" data-player="' + esc(JSON.stringify(p)) + '">' +
              '<svg class="ico" viewBox="0 0 24 24"><path d="M7 4v16l13-8z" fill="currentColor"/></svg> <span>' + esc(p.name) + '</span>' +
              (p.audio ? '<em>' + esc(p.audio) + '</em>' : '') + (p.quality ? '<em class="q">' + esc(p.quality) + '</em>' : '') + '</button>';
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
