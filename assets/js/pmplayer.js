/* Pipoca Max — player próprio (Video.js). Só é usado nos players do tipo "Player próprio". */
(function () {
  'use strict';
  var VJS_V = '8.24.2', vjsLoading = null;

  function base() { return (window.PM && PM.base) || ''; }
  function loadFile(tag, attrs) {
    return new Promise(function (res, rej) {
      var el = document.createElement(tag);
      for (var k in attrs) el[k] = attrs[k];
      el.onload = res; el.onerror = rej;
      document.head.appendChild(el);
    });
  }
  function loadVjs() {
    if (window.videojs) return Promise.resolve(window.videojs);
    if (!vjsLoading) {
      var b = base() + '/assets/vendor/videojs/';
      vjsLoading = Promise.all([
        loadFile('link', { rel: 'stylesheet', href: b + 'video-js.min.css?v=' + VJS_V }).then(function () {
          return loadFile('link', { rel: 'stylesheet', href: base() + '/assets/css/pmplayer.css?v=' + ((window.PM && PM.vjs && PM.vjs.v) || VJS_V) });
        }),
        loadFile('script', { src: b + 'video.min.js?v=' + VJS_V })
      ]).then(function () {
        return loadFile('script', { src: b + 'pt-BR.js?v=' + VJS_V }).catch(function () {});
      }).then(function () {
        if (!window.videojs) throw new Error('videojs');
        return window.videojs;
      });
      vjsLoading.catch(function () { vjsLoading = null; });
    }
    return vjsLoading;
  }

  var fmtT = function (s) {
    s = Math.floor(s); var h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), x = s % 60;
    return (h ? h + ':' + (m < 10 ? '0' : '') : '') + m + ':' + (x < 10 ? '0' : '') + x;
  };
  var posKey = function (url) { return 'pm_pos:' + url.slice(0, 300); };
  var clearPos = function (url) { try { localStorage.removeItem(posKey(url)); } catch (e) {} };
  var savePos = function (url, t, d) {
    if (!d || !isFinite(d) || !t) return; // trocando/fechando o player: não mexe no que já foi salvo
    try {
      if (t < 30 || t > d - 60) localStorage.removeItem(posKey(url));
      else localStorage.setItem(posKey(url), JSON.stringify({ t: Math.floor(t), at: Date.now() }));
    } catch (e) {}
  };
  var readPos = function (url) {
    try { var o = JSON.parse(localStorage.getItem(posKey(url)) || 'null'); return o && Date.now() - o.at < 60 * 864e5 ? o.t : 0; } catch (e) { return 0; }
  };
  var esc = function (t) { var d = document.createElement('div'); d.textContent = t == null ? '' : t; return d.innerHTML; };

  function play(box, p, cfg) {
    cfg = cfg || {};
    var on = function (k, def) { return cfg[k] === undefined ? def : !!cfg[k]; };
    return loadVjs().then(function (videojs) {
      box.innerHTML = '';
      var el = document.createElement('video-js');
      el.className = 'video-js vjs-pm vjs-big-play-centered';
      el.setAttribute('playsinline', '');
      if (cfg.color && /^#[0-9a-f]{3,8}$/i.test(cfg.color)) el.style.setProperty('--pm-red', cfg.color);
      box.appendChild(el);

      var isHls = /\.m3u8($|\?)/i.test(p.url);
      var live = !!box.closest('[data-channel]');
      var skip = live ? 0 : (+cfg.skip >= 0 ? +cfg.skip : 10);
      var opts = {
        controls: true, autoplay: on('autoplay', true), preload: 'auto', fill: true, language: 'pt-BR',
        poster: on('poster', true) && box.dataset.poster ? box.dataset.poster : undefined,
        playbackRates: !live && on('rates', true) ? [0.5, 0.75, 1, 1.25, 1.5, 2] : undefined,
        inactivityTimeout: 2500,
        controlBar: { pictureInPictureToggle: on('pip', true) },
        userActions: { hotkeys: true, doubleClick: true },
        html5: { vhs: { overrideNative: !videojs.browser.IS_ANY_SAFARI }, nativeAudioTracks: false, nativeVideoTracks: false }
      };
      if (skip > 0) opts.controlBar.skipButtons = { forward: skip, backward: skip };
      var pl = videojs(el, opts);
      box._vjs = pl;
      pl.src({ src: p.url, type: isHls ? 'application/x-mpegURL' : (/\.webm($|\?)/i.test(p.url) ? 'video/webm' : 'video/mp4') });
      if (opts.autoplay) pl.ready(function () { var r = pl.play(); if (r && r.catch) r.catch(function () {}); });

      // Marca d'água (logo ou texto)
      if (cfg.wm_img || cfg.wm_text) {
        var wm = document.createElement('div');
        wm.className = 'pm-wm pm-wm-' + (cfg.wm_pos || 'tr');
        wm.style.opacity = cfg.wm_opacity ? (+cfg.wm_opacity / 100) : 0.6;
        wm.innerHTML = cfg.wm_img ? '<img src="' + esc(cfg.wm_img) + '" alt="">' : esc(cfg.wm_text);
        el.appendChild(wm);
      }

      // Aviso "em teste"
      if (p.test && cfg.test_msg) {
        var tb = document.createElement('div');
        tb.className = 'pm-test';
        tb.innerHTML = '<span class="pm-test-tag">TESTE</span><span>' + esc(cfg.test_msg) + '</span><button type="button" aria-label="Fechar">×</button>';
        tb.querySelector('button').addEventListener('click', function () { tb.remove(); });
        el.appendChild(tb);
        setTimeout(function () { if (tb.parentNode) tb.classList.add('pm-test-min'); }, 9000);
      }

      pl.on('error', function () {
        var em = el.querySelector('.vjs-modal-dialog-content');
        if (em) em.textContent = cfg.error_msg || 'Não foi possível carregar este vídeo. Tente outro player.';
      });

      // Teclado: setas avançam/voltam, cima/baixo mudam o volume
      el.addEventListener('keydown', function (e) {
        if (!live && (e.key === 'ArrowRight' || e.key === 'ArrowLeft')) { e.preventDefault(); pl.currentTime(Math.max(0, pl.currentTime() + (e.key === 'ArrowRight' ? 1 : -1) * (skip || 10))); }
        if (e.key === 'ArrowUp' || e.key === 'ArrowDown') { e.preventDefault(); pl.volume(Math.min(1, Math.max(0, pl.volume() + (e.key === 'ArrowUp' ? 0.1 : -0.1)))); }
      });

      // Celular: dois toques nos lados avançam/voltam
      if (!live && on('doubletap', true)) {
        var lastTap = 0, step = skip || 10;
        el.addEventListener('touchend', function (e) {
          if (e.target.closest('.vjs-control-bar, .vjs-menu, .vjs-big-play-button, .pm-resume, .pm-next')) return;
          var now = Date.now(), t = e.changedTouches[0], r = el.getBoundingClientRect(), x = (t.clientX - r.left) / r.width;
          if (now - lastTap < 300 && (x < 0.35 || x > 0.65)) {
            var d = x > 0.5 ? step : -step;
            pl.currentTime(Math.max(0, pl.currentTime() + d));
            var f = document.createElement('div');
            f.className = 'pm-seek-flash ' + (d > 0 ? 'r' : 'l'); f.textContent = (d > 0 ? '+' : '') + d + 's';
            el.appendChild(f); setTimeout(function () { f.remove(); }, 600);
            lastTap = 0; e.preventDefault(); return;
          }
          lastTap = now;
        }, { passive: false });
      }
      if (live) return pl;

      // Continuar de onde parou
      if (on('resume', true)) {
        var saved = readPos(p.url), lastSave = 0;
        pl.one('loadedmetadata', function () {
          var d = pl.duration();
          if (saved > 30 && d && saved < d - 60) {
            pl.currentTime(saved);
            var rs = document.createElement('div');
            rs.className = 'pm-resume';
            rs.innerHTML = '<span>Continuando de ' + fmtT(saved) + '</span><button type="button">Começar do início</button>';
            rs.querySelector('button').addEventListener('click', function () { pl.currentTime(0); rs.remove(); });
            el.appendChild(rs);
            setTimeout(function () { rs.remove(); }, 7000);
          }
        });
        pl.on('timeupdate', function () {
          var now = Date.now();
          if (now - lastSave > 5000) { lastSave = now; savePos(p.url, pl.currentTime(), pl.duration()); }
        });
        pl.on('pause', function () { if (!pl.isDisposed()) savePos(p.url, pl.currentTime(), pl.duration()); });
      }

      // Fim do episódio: próximo automático
      pl.on('ended', function () {
        clearPos(p.url);
        if (!on('next', true)) return;
        var panel = box.closest('[data-episode-panel]'), next = panel && panel.querySelector('[data-ep-next]');
        if (!next || next.disabled) return;
        var n = +cfg.next_seconds > 0 ? +cfg.next_seconds : 8, ov = document.createElement('div');
        ov.className = 'pm-next';
        ov.innerHTML = '<b>Próximo episódio em <span>' + n + '</span>s</b><div><button type="button" class="btn btn-red btn-sm" data-go>Assistir agora</button><button type="button" class="btn btn-glass btn-sm" data-stop>Cancelar</button></div>';
        el.appendChild(ov);
        var iv = setInterval(function () { n--; ov.querySelector('span').textContent = n; if (n <= 0) { clearInterval(iv); next.click(); } }, 1000);
        ov.querySelector('[data-go]').addEventListener('click', function () { clearInterval(iv); next.click(); });
        ov.querySelector('[data-stop]').addEventListener('click', function () { clearInterval(iv); ov.remove(); });
        pl.one('play', function () { clearInterval(iv); ov.remove(); });
      });
      return pl;
    });
  }

  window.PMPlayer = { play: play };
})();
