/* Pipoca Max — painel administrativo */
(function () {
  'use strict';
  var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };

  // Menu lateral no celular
  var side = document.getElementById('side'), ov = document.getElementById('side-overlay'), burger = document.getElementById('burger');
  if (burger) burger.addEventListener('click', function () { side.classList.toggle('open'); ov.classList.toggle('open'); });
  if (ov) ov.addEventListener('click', function () { side.classList.remove('open'); ov.classList.remove('open'); });

  // Confirmações
  $$('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (ev) { if (!confirm(f.dataset.confirm)) ev.preventDefault(); });
  });
  $$('[data-confirm-click]').forEach(function (b) {
    b.addEventListener('click', function (ev) { if (!confirm(b.dataset.confirmClick)) ev.preventDefault(); });
  });

  // Selecionar todos
  $$('[data-check-all]').forEach(function (all) {
    all.addEventListener('change', function () {
      var name = all.dataset.checkAll;
      var sel = name ? 'input[name="' + name + '[]"]' : 'td.chk input[type=checkbox]';
      $$(sel).forEach(function (c) { c.checked = all.checked; });
    });
  });

  // Prévia de imagens
  function imgUrl(v, size) {
    if (!v) return '';
    if (/^https?:\/\//.test(v)) return v;
    if (v.charAt(0) === '/') return 'https://image.tmdb.org/t/p/' + size + v;
    return v;
  }
  $$('[data-preview]').forEach(function (inp) {
    var target = document.getElementById(inp.dataset.preview);
    if (!target) return;
    inp.addEventListener('input', function () {
      var u = imgUrl(inp.value.trim(), target.classList.contains('wide') ? 'w500' : 'w185');
      if (u) target.src = u;
    });
  });

  // Tela de carregamento para ações demoradas (importação)
  $$('[data-loading]').forEach(function (b) {
    b.addEventListener('click', function () {
      var form = b.form;
      if (!form) return;
      form.addEventListener('submit', function handler(ev) {
        form.removeEventListener('submit', handler);
        if (ev.defaultPrevented) return;
        var o = document.createElement('div');
        o.className = 'loading-overlay';
        o.innerHTML = '<div><div class="spin"></div>' + b.dataset.loading + '</div>';
        document.body.appendChild(o);
      });
    });
  });

  // Clicar no pôster marca o checkbox (importação)
  $$('.tmdb-poster').forEach(function (l) {
    var c = l.querySelector('.tmdb-check');
    if (c) l.closest('.tmdb-item').classList.toggle('sel', c.checked);
  });

  /* ===== Fila de importação (TMDB) ===== */
  var PMA = window.PM || {};
  function ajax(action, data, method) {
    var u = PMA.base + '/admin/ajax.php?action=' + action;
    if (method === 'GET') {
      return fetch(u + '&' + new URLSearchParams(data), { credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }
    var fd = new FormData();
    fd.append('_csrf', PMA.csrf);
    var qs = Object.keys(data).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]); }).join('&');
    fd.append('_p', btoa(unescape(encodeURIComponent(qs))).split('').reverse().join(''));
    return fetch(u, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function escH(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  var Q = document.getElementById('queue');
  function runQueue(items, opts) {
    if (!Q || !items.length) return;
    var el = function (id) { return document.getElementById(id); };
    var total = items.length, done = 0, ok = 0, skip = 0, err = 0, stopped = false, idx = 0;
    Q.hidden = false;
    el('q-log').innerHTML = '';
    el('q-done').hidden = true; el('q-close').hidden = true; el('q-stop').hidden = false;
    el('q-title').textContent = 'Importando ' + total + ' título(s)…';
    var update = function () {
      el('q-ok').textContent = ok; el('q-skip').textContent = skip; el('q-err').textContent = err;
      el('q-bar').style.width = Math.round(done / total * 100) + '%';
      el('q-status').textContent = done + ' de ' + total;
    };
    var log = function (cls, html) {
      var li = document.createElement('li'); li.className = cls; li.innerHTML = html;
      el('q-log').insertBefore(li, el('q-log').firstChild);
    };
    var finish = function () {
      el('q-title').textContent = stopped ? 'Importação interrompida' : 'Importação concluída!';
      el('q-stop').hidden = true; el('q-done').hidden = false; el('q-close').hidden = false;
    };
    el('q-stop').onclick = function () { stopped = true; el('q-stop').disabled = true; };
    el('q-close').onclick = function () { location.reload(); };
    update();
    var worker = function () {
      if (stopped || idx >= items.length) return Promise.resolve();
      var it = items[idx++];
      var data = { item: it.item, type: it.type, skip_existing: opts.update ? '0' : '1',
        is_release: opts.release ? '1' : '0', featured: opts.featured ? '1' : '0', draft: opts.draft ? '1' : '0' };
      return ajax('import_one', data).then(function (j) {
        if (j.ok && j.skipped) { skip++; log('skip', '↷ <b>' + escH(j.title) + '</b> <small>já estava no site</small>'); }
        else if (j.ok) { ok++; log('ok', (j.poster ? '<img src="' + escH(j.poster) + '" alt="">' : '') + '✓ <b>' + escH(j.title) + '</b> <small>' + (j.year || '') + (j.episodes ? ' • ' + j.episodes + ' episódios' : '') + (j.updated ? ' • atualizado' : '') + '</small>'); }
        else { err++; log('err', '✕ <b>' + escH(it.label || it.item) + '</b> <small>' + escH(j.error || 'erro') + '</small>'); }
      }).catch(function () { err++; log('err', '✕ <b>' + escH(it.label || it.item) + '</b> <small>falha de conexão</small>'); })
        .then(function () { done++; update(); return worker(); });
    };
    Promise.all([worker(), worker()]).then(finish);
  }

  // Aba "Em massa"
  var bi = document.getElementById('bulk-items');
  if (bi) {
    var lines = function () { return bi.value.split(/\r?\n/).map(function (l) { return l.trim(); }).filter(Boolean); };
    bi.addEventListener('input', function () { document.getElementById('bulk-count').textContent = lines().length + ' título(s)'; });
    document.getElementById('bulk-start').addEventListener('click', function () {
      var type = document.getElementById('bulk-type').value;
      var uniq = lines().filter(function (v, i, a) { return a.indexOf(v) === i; });
      if (!uniq.length) { alert('Cole pelo menos um título.'); return; }
      runQueue(uniq.map(function (l) { return { item: l, type: type }; }), {
        release: document.getElementById('bulk-release').checked, featured: document.getElementById('bulk-featured').checked,
        draft: document.getElementById('bulk-draft').checked, update: document.getElementById('bulk-update').checked
      });
    });
  }

  // ===== Domínio =====
  var domTest = document.querySelector('[data-domain-test]');
  if (domTest) {
    domTest.querySelector('button').addEventListener('click', function () {
      var out = domTest.querySelector('[data-out]'), done = false;
      out.textContent = 'Testando…'; out.style.color = '';
      window.__pmDomainPing = function (tok, https) {
        done = true;
        if (tok === domTest.dataset.token) { out.textContent = '✓ O domínio novo já abre este site' + (https ? ' com HTTPS.' : '.') + ' Pode ativar.'; out.style.color = '#46d369'; }
        else { out.textContent = '✕ O domínio abre, mas é OUTRO site. Confira para onde ele aponta na Hostinger.'; out.style.color = '#ff6b6b'; }
      };
      var sc = document.createElement('script');
      sc.src = domTest.dataset.url + '&t=' + Date.now();
      sc.onerror = function () { if (!done) { out.textContent = '✕ Ainda não abriu. Confira se o domínio aponta para a Hostinger e se o SSL está ativo (pode levar algumas horas).'; out.style.color = '#ff6b6b'; } };
      document.head.appendChild(sc);
      setTimeout(function () { if (!done && out.textContent === 'Testando…') { out.textContent = '✕ Sem resposta. Tente de novo em alguns minutos.'; out.style.color = '#ff6b6b'; } }, 12000);
    });
  }
  var domList = document.querySelector('[data-dom-list]');
  if (domList) {
    var getL = function () { try { return JSON.parse(localStorage.getItem('pm_dom_list') || '{}'); } catch (e) { return {}; } };
    var st = getL();
    $$('input[data-k]', domList).forEach(function (c) {
      c.checked = !!st[c.dataset.k];
      c.addEventListener('change', function () { var s = getL(); s[c.dataset.k] = c.checked ? 1 : 0; try { localStorage.setItem('pm_dom_list', JSON.stringify(s)); } catch (e) {} });
    });
    document.querySelector('[data-dom-reset]').addEventListener('click', function () { try { localStorage.removeItem('pm_dom_list'); } catch (e) {} $$('input[data-k]', domList).forEach(function (c) { c.checked = false; }); });
  }
  $$('[data-confirm-click]').forEach(function (b) { b.addEventListener('click', function (e) { if (!confirm(b.dataset.confirmClick)) e.preventDefault(); }); });

  // ===== SEO =====
  var seoFill = function (tpl, v) {
    var out = String(tpl || '').replace(/\{(\w+)\}/g, function (m, k) { return v[k] != null ? v[k] : ''; });
    out = out.replace(/\(\s*\)/g, '').replace(/\s+([,.)])/g, '$1').replace(/\(\s+/g, '(').replace(/\s{2,}/g, ' ').replace(/^\s*[-–|•]\s*|\s*[-–|•]\s*$/g, '');
    return out.trim();
  };
  var cutDesc = function (s) { s = s.replace(/\s+/g, ' ').trim(); return s.length > 160 ? s.slice(0, 157).replace(/\s+\S*$/, '') + '…' : s; };
  var lenHint = function (field) {
    var em = field.closest('.field') && field.closest('.field').querySelector('[data-len]');
    if (!em) return;
    var n = field.value.length, max = field.dataset.kind === 'desc' ? 160 : 60;
    em.textContent = '(' + n + ' letras' + (n > max ? ' — o Google corta depois de ~' + max : '') + ')';
    em.style.color = n > max ? '#f5b301' : '';
  };
  var seoTabs = $$('[data-seo-tab]');
  if (seoTabs.length) {
    var siteName = JSON.parse(document.getElementById('seo-site').textContent).site;
    var showTab = function (t) {
      if (!document.getElementById('pane-' + t)) t = 'visao';
      seoTabs.forEach(function (a) { a.classList.toggle('active', a.dataset.seoTab === t); });
      $$('.seo-pane').forEach(function (p) { p.hidden = p.id !== 'pane-' + t; });
    };
    seoTabs.forEach(function (a) { a.addEventListener('click', function (e) { e.preventDefault(); history.replaceState(null, '', '#' + a.dataset.seoTab); showTab(a.dataset.seoTab); }); });
    $$('[data-seo-go]').forEach(function (a) { a.addEventListener('click', function (e) { e.preventDefault(); history.replaceState(null, '', '#' + a.dataset.seoGo); showTab(a.dataset.seoGo); }); });
    showTab(location.hash.replace('#', '') || 'visao');
    var lastField = null;
    $$('[data-seo-field]').forEach(function (f) { f.addEventListener('focus', function () { lastField = f; }); });
    $$('.seo-var').forEach(function (b) {
      b.addEventListener('click', function () {
        var f = lastField || document.querySelector('[data-seo-field]');
        var s = f.selectionStart != null ? f.selectionStart : f.value.length, en = f.selectionEnd != null ? f.selectionEnd : s;
        f.value = f.value.slice(0, s) + b.dataset.var + f.value.slice(en); f.focus();
        f.selectionStart = f.selectionEnd = s + b.dataset.var.length;
        f.dispatchEvent(new Event('input', { bubbles: true }));
      });
    });
    var renderTpl = function (box) {
      var fs = box.querySelectorAll('[data-seo-field]');
      var t = box.dataset.exTitulo, y = box.dataset.exAno;
      var v = { titulo: t, ano: y, titulo_original: t, genero: 'Animação', temporadas: '2 temporadas', canal: t, categoria: 'Abertos', lista: t, pagina: '', site: siteName,
        sinopse: 'Uma jovem corajosa parte numa jornada pelo oceano para salvar o seu povo e descobre o seu destino.' };
      box.querySelector('[data-gp-title]').textContent = seoFill(fs[0].value, v);
      box.querySelector('[data-gp-desc]').textContent = cutDesc(seoFill(fs[1].value, v));
    };
    $$('.seo-tpl').forEach(function (box) {
      $$('[data-seo-field]', box).forEach(function (f) { f.addEventListener('input', function () { renderTpl(box); lenHint(f); }); });
      box.querySelector('[data-seo-reset]').addEventListener('click', function () {
        $$('[data-seo-field]', box).forEach(function (f) { f.value = f.dataset.default; lenHint(f); }); renderTpl(box);
      });
      renderTpl(box);
    });
    $$('[data-seo-field]').forEach(function (f) { lenHint(f); f.addEventListener('input', function () { lenHint(f); }); });
    $$('[data-copy]').forEach(function (b) { b.addEventListener('click', function () { navigator.clipboard && navigator.clipboard.writeText(b.dataset.copy); b.textContent = 'Copiado!'; }); });
  }
  // SEO deste título (Conteúdos)
  var seoBox = document.querySelector('.seo-box');
  if (seoBox) {
    var tpls = JSON.parse(seoBox.dataset.seoTpls), fm = seoBox.closest('form');
    var val = function (n) { var el = fm.querySelector('[name="' + n + '"]'); return el ? el.value.trim() : ''; };
    var upd2 = function () {
      var t = tpls[val('type')] || tpls.movie, g = val('genres').split(',')[0] || '';
      var v = { titulo: val('title') || 'Nome do título', ano: val('year'), titulo_original: val('original_title'), genero: g.trim(), sinopse: val('overview'), temporadas: '', site: seoBox.dataset.site };
      seoBox.querySelector('[data-gp-title]').textContent = seoFill(val('seo_title') || t[0], v);
      seoBox.querySelector('[data-gp-desc]').textContent = cutDesc(seoFill(val('seo_desc') || t[1], v));
      ['seo_title', 'seo_desc'].forEach(function (n) { var c = seoBox.querySelector('[data-count="' + n + '"]'); if (c) c.textContent = val(n) ? '(' + val(n).length + ' letras)' : ''; });
    };
    fm.addEventListener('input', upd2); fm.addEventListener('change', upd2); upd2();
  }

  // Segurança: mostra as chaves só quando há CAPTCHA
  var secCap = document.getElementById('sec-captcha');
  if (secCap) {
    var updCap = function () { $$('[data-captcha-keys]').forEach(function (el) { el.hidden = secCap.value === 'none'; }); };
    secCap.addEventListener('change', updCap); updCap();
  }

  // Player próprio: prévia com as opções do formulário
  var vjsBtn = document.getElementById('vjs-test');
  if (vjsBtn) {
    var vjsForm = document.querySelector('.vjs-cols form');
    var readCfg = function () {
      var c = Object.assign({}, window.PM.vjs || {}), fd = new FormData(vjsForm);
      ['rates', 'resume', 'next', 'doubletap', 'pip', 'autoplay', 'poster'].forEach(function (k) { c[k] = fd.get(k) ? 1 : 0; });
      ['color', 'skip', 'next_seconds', 'wm_text', 'wm_img', 'wm_pos', 'wm_opacity', 'error_msg'].forEach(function (k) { c[k] = fd.get(k); });
      return c;
    };
    var op = vjsForm.querySelector('[name=wm_opacity]');
    op.addEventListener('input', function () { document.querySelector('[data-op]').textContent = op.value; });
    var runTest = function () {
      var u = document.getElementById('vjs-test-url').value.trim(), box = document.getElementById('vjs-box');
      if (!/^https?:\/\//i.test(u)) { box.innerHTML = '<div class="player-msg">Cole um link começando com http.</div>'; return; }
      if (!window.PMPlayer) { box.innerHTML = '<div class="player-msg">Carregando…</div>'; setTimeout(runTest, 400); return; }
      if (box._vjs) { try { box._vjs.dispose(); } catch (e) {} box._vjs = null; }
      window.PMPlayer.play(box, { url: u }, readCfg()).catch(function () { box.innerHTML = '<div class="player-msg">Não foi possível carregar o Video.js.</div>'; });
    };
    vjsBtn.addEventListener('click', runTest);
    document.getElementById('vjs-test-api').addEventListener('click', function () {
      var cid = document.getElementById('vjs-test-title').value, msg = document.getElementById('vjs-api-msg');
      if (!cid) { msg.textContent = 'Escolha um título.'; return; }
      msg.textContent = 'Consultando a API…';
      ajax('vjs_test', { c: cid }, 'GET').then(function (j) {
        if (j.error) { msg.textContent = (j.api ? 'API: ' + j.api + ' — ' : '') + j.error; return; }
        msg.textContent = (j.ep ? j.ep + ' · ' : '') + 'Vídeo: ' + j.url;
        document.getElementById('vjs-test-url').value = j.url; runTest();
      }).catch(function () { msg.textContent = 'Erro ao testar.'; });
    });
    document.getElementById('vjs-test-url').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); runTest(); } });
  }

  // Notificações: título do site, modelos e prévia
  var ntf = document.getElementById('notif-form');
  if (ntf) {
    var g = function (id) { return document.getElementById(id); };
    var prev = function () {
      g('np-title').textContent = g('nt-title').value || 'Título da notificação';
      g('np-msg').textContent = g('nt-msg').value;
      var im = g('nt-img').value.trim();
      g('np-img').hidden = !im; g('np-ico').hidden = !!im; if (im) g('np-img').src = im;
    };
    ['nt-title', 'nt-msg', 'nt-img'].forEach(function (id) { g(id).addEventListener('input', prev); });
    g('nt-pick').addEventListener('change', function () {
      var o = this.options[this.selectedIndex];
      g('nt-cid').value = this.value;
      if (!this.value) return;
      g('nt-title').value = 'Novo ' + (o.dataset.type === 'Série' ? 'série' : (o.dataset.type || 'título').toLowerCase()) + ': ' + o.dataset.title;
      if (o.dataset.type === 'Série') g('nt-title').value = 'Nova série: ' + o.dataset.title;
      if (!g('nt-msg').value) g('nt-msg').value = 'Já disponível no site. Aproveite!';
      g('nt-link').value = o.dataset.link; g('nt-img').value = o.dataset.img;
      prev();
    });
    var tpls = {
      novo: function () { g('nt-pick').focus(); g('nt-pick-f').scrollIntoView({ block: 'center', behavior: 'smooth' }); },
      manut: function () { g('nt-pick').value = ''; g('nt-cid').value = ''; g('nt-title').value = 'Manutenção programada'; g('nt-msg').value = 'O site pode ficar fora do ar por alguns minutos para melhorias. Obrigado pela paciência!'; g('nt-link').value = ''; g('nt-img').value = ''; g('nt-days').value = '1'; prev(); },
      aviso: function () { g('nt-pick').value = ''; g('nt-cid').value = ''; g('nt-title').value = ''; g('nt-msg').value = ''; g('nt-link').value = ''; g('nt-img').value = ''; prev(); g('nt-title').focus(); }
    };
    $$('[data-tpl]').forEach(function (b) { b.addEventListener('click', function () { tpls[b.dataset.tpl](); }); });
    prev();
  }

  // Aba "Importar novidades": um botão só, lembra as escolhas
  var nfBtn = document.getElementById('nf-start');
  if (nfBtn && document.querySelector('[data-imp-type]')) {
    var LS = function (k, v) { try { if (v === undefined) return localStorage.getItem('pm_imp_' + k); localStorage.setItem('pm_imp_' + k, v); } catch (e) {} };
    var typeBoxes = $$('[data-imp-type]'), nfSrc = document.getElementById('nf-src'), nfStatus = document.getElementById('nf-status');
    var nfProg = document.getElementById('nf-progress'), nfYearF = document.getElementById('nf-year-f');
    var names = { movie: 'Filmes', series: 'Séries', anime: 'Animes' };
    var srcMap = {
      popular: { movie: 'popular_movies', series: 'popular_tv', anime: 'anime' },
      top_rated: { movie: 'top_rated_movies', series: 'top_rated_tv', anime: 'anime' },
      trending: { movie: 'trending_movies', series: 'trending_tv', anime: 'anime' },
      now: { movie: 'now_playing', series: 'on_air', anime: 'anime' }
    };
    // escolhas salvas
    var savedTypes = LS('types');
    if (savedTypes) typeBoxes.forEach(function (c) { c.checked = savedTypes.split(',').indexOf(c.value) >= 0; });
    var savedQty = LS('qty');
    if (savedQty) $$('[name=imp-qty]').forEach(function (r) { r.checked = r.value === savedQty; });
    var last = LS('last');
    if (last) document.getElementById('nf-last').textContent = 'Última importação: ' + last + '.';
    nfSrc.addEventListener('change', function () { nfYearF.hidden = nfSrc.value !== 'one_year'; });

    var setRow = function (t, txt, cls) {
      var r = nfProg.querySelector('[data-row="' + t + '"]');
      if (!r) { r = document.createElement('div'); r.dataset.row = t; nfProg.appendChild(r); }
      r.className = 'imp-row ' + (cls || '');
      r.innerHTML = '<b>' + names[t] + '</b><span>' + escH(txt) + '</span>';
    };

    var findFor = function (type, want, all) {
      var gsrc = nfSrc.value, src = gsrc === 'all_years' || gsrc === 'one_year' ? gsrc : srcMap[gsrc][type];
      var oneYear = gsrc === 'one_year';
      var pos = { year: oneYear ? +document.getElementById('nf-year').value : 0, page: 1 };
      var resume = !document.getElementById('nf-restart').checked && !oneYear;
      var got = 0;
      var step = function (first) {
        setRow(type, 'procurando… ' + got + ' de ' + want + (pos.year && pos.year > 1900 ? ' (' + pos.year + ')' : ''), 'busy');
        return ajax('find_new', { type: type, src: src, want: want - got, year: pos.year, page: pos.page, resume: first && resume ? '1' : '0' }).then(function (j) {
          if (j.error) throw new Error(j.error);
          (j.items || []).forEach(function (r) { got++; all.push({ item: String(r.tmdb_id), type: type, label: r.title }); });
          pos = { year: j.year, page: j.page };
          if (got < want && !j.done) return step(false);
          setRow(type, got ? got + ' novo(s) encontrado(s)' + (j.done ? ' — chegou ao fim' : '') : (j.done ? 'nada novo — você já tem tudo daqui' : 'nada novo'), got ? 'ok' : 'none');
          var w = document.querySelector('[data-imp-where="' + type + '"]');
          if (w && src === 'all_years') w.textContent = j.done ? 'Já importou todos os anos' : 'Próximos: ' + j.year + ' › página ' + j.page;
        });
      };
      return step(true).catch(function (e) { setRow(type, 'erro: ' + e.message, 'err'); });
    };

    nfBtn.addEventListener('click', function () {
      var types = typeBoxes.filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
      if (!types.length) { nfStatus.textContent = 'Marque pelo menos um: Filmes, Séries ou Animes.'; return; }
      var qty = +(document.querySelector('[name=imp-qty]:checked') || {}).value || 50;
      LS('types', types.join(',')); LS('qty', String(qty));
      var each = Math.floor(qty / types.length), extra = qty - each * types.length, all = [];
      nfBtn.disabled = true; nfProg.hidden = false; nfProg.innerHTML = ''; nfStatus.textContent = '';
      types.forEach(function (t) { setRow(t, 'na fila', ''); });
      // um tipo por vez (não sobrecarrega a hospedagem)
      types.reduce(function (p, t, i) { return p.then(function () { return findFor(t, each + (i < extra ? 1 : 0), all); }); }, Promise.resolve())
        .then(function () {
          nfBtn.disabled = false;
          if (!all.length) { nfStatus.textContent = 'Nenhum título novo encontrado com essas opções.'; return; }
          var d = new Date();
          LS('last', d.toLocaleDateString('pt-BR') + ' às ' + d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) + ' — ' + all.length + ' título(s)');
          runQueue(all, { release: document.getElementById('nf-release').checked });
        });
    });
  }

  // Aba "Listas inteiras"
  var lsel = document.getElementById('list-name');
  if (lsel) {
    var upd = function () { var y = document.querySelector('.list-year'); if (y) y.hidden = !lsel.options[lsel.selectedIndex].hasAttribute('data-year'); };
    lsel.addEventListener('change', upd); upd();
  }
  var ls = document.getElementById('list-start');
  if (ls) ls.addEventListener('click', function () {
    var sel = document.getElementById('list-name');
    var type = sel.options[sel.selectedIndex].dataset.type;
    var from = Math.max(1, +document.getElementById('list-from').value || 1);
    var to = Math.max(from, +document.getElementById('list-to').value || from);
    if (to - from > 24) { alert('Faça no máximo 25 páginas (500 títulos) por vez.'); return; }
    ls.disabled = true; ls.textContent = 'Lendo a lista…';
    var pages = [], items = [];
    for (var p = from; p <= to; p++) pages.push(p);
    pages.reduce(function (pr, pg) {
      return pr.then(function () {
        var q = { list: sel.value, page: pg };
        if (sel.options[sel.selectedIndex].hasAttribute('data-year')) q.year = document.getElementById('list-year').value;
        return ajax('list', q, 'GET').then(function (j) {
          (j.results || []).forEach(function (r) { items.push({ item: String(r.tmdb_id), type: type, label: r.title }); });
        });
      });
    }, Promise.resolve()).then(function () {
      ls.disabled = false; ls.textContent = 'Importar lista';
      runQueue(items, { release: document.getElementById('list-release').checked });
    });
  });

  // Aba "Buscar": selecionados e botão individual
  var collect = function (els) {
    return els.map(function (item) {
      return { item: item.dataset.tmdb, type: item.querySelector('[data-type]').value, label: item.querySelector('b').textContent };
    });
  };
  var flags = function () {
    var r = document.getElementById('sel-release'), f = document.getElementById('sel-featured');
    return { release: r && r.checked, featured: f && f.checked };
  };
  var ss = document.getElementById('sel-start');
  if (ss) ss.addEventListener('click', function () {
    var items = $$('.tmdb-check:checked').map(function (c) { return c.closest('.tmdb-item'); });
    if (!items.length) { alert('Marque os títulos que quer importar.'); return; }
    runQueue(collect(items), flags());
  });
  $$('[data-import-one]').forEach(function (b) {
    b.addEventListener('click', function () { runQueue(collect([b.closest('.tmdb-item')]), flags()); });
  });

  /* ===== "Puxar dados do TMDB" no cadastro ===== */
  var ts = document.querySelector('[data-tmdb-search]');
  if (ts) {
    var input = ts.querySelector('[data-q]'), box = document.querySelector('[data-results]'), form = document.querySelector('[data-fetch-form]');
    var go = function () {
      var q = input.value.trim();
      if (!q) return;
      box.innerHTML = '<p class="muted">Buscando…</p>';
      ajax('search', { q: q, kind: ts.dataset.kind }, 'GET').then(function (j) {
        if (j.error) { box.innerHTML = '<div class="alert alert-error">' + escH(j.error) + '</div>'; return; }
        if (!j.results.length) { box.innerHTML = '<p class="muted">Nada encontrado.</p>'; return; }
        box.innerHTML = j.results.map(function (r) {
          return '<div class="tmdb-item' + (r.local_id ? ' has' : '') + '"><div class="tmdb-poster"><img src="' + escH(r.poster_url) + '" alt="">' +
            (r.rating ? '<span class="tmdb-rating">★ ' + r.rating + '</span>' : '') + (r.local_id ? '<span class="tmdb-has">No site</span>' : '') + '</div>' +
            '<div class="tmdb-info"><b>' + escH(r.title) + '</b><small>' + escH(r.year || '—') + '</small>' +
            (r.local_id ? '<a class="btn btn-sm" href="' + PMA.base + '/admin/conteudo.php?id=' + r.local_id + '">Editar</a>'
              : '<button type="button" class="btn btn-sm btn-red" data-pick="' + r.tmdb_id + '">Puxar dados</button>') + '</div></div>';
        }).join('');
      });
    };
    ts.querySelector('[data-go]').addEventListener('click', go);
    input.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); go(); } });
    box.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-pick]');
      if (!b) return;
      form.querySelector('[name=tmdb_id]').value = b.dataset.pick;
      $$('[data-flag]').forEach(function (c) {
        if (c.checked) { var h = document.createElement('input'); h.type = 'hidden'; h.name = c.dataset.flag; h.value = '1'; form.appendChild(h); }
      });
      var o = document.createElement('div');
      o.className = 'loading-overlay';
      o.innerHTML = '<div><div class="spin"></div>Puxando dados do TMDB…</div>';
      document.body.appendChild(o);
      form.submit();
    });
  }
})();

/* ===== Montador da API de players (Configurações) ===== */
(function () {
  'use strict';
  var forms = document.querySelectorAll('[data-api-form]');
  if (!forms.length) return;
  var LABEL = { tmdb_id: 'ID TMDB', imdb_id: 'ID IMDb', temporada: 'Temporada', episodio: 'Episódio', temporada2: 'Temporada (01)', episodio2: 'Episódio (01)' };
  var SAMPLE = { tmdb_id: '550', imdb_id: 'tt0137523', temporada: '1', episodio: '3', temporada2: '01', episodio2: '03' };
  var SAMPLE_EP = { tmdb_id: '1399', imdb_id: 'tt0944947' };
  var esc = function (s) { return String(s || '').replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };

  function parse(text) {
    return text.split(/\r?\n/).map(function (l) { return l.trim(); }).filter(function (l) { return l && l[0] !== '#'; }).map(function (l) {
      var p = l.split('|').map(function (x) { return x.trim(); });
      if (p.length === 1) p = ['', p[0]];
      return { name: p[0] || '', url: p[1] || '', audio: p[2] || '', quality: p[3] || '' };
    });
  }

  document.querySelectorAll('[data-builder]').forEach(function (box) {
    var kind = box.dataset.builder, vars = box.dataset.vars.split(',');
    var ta = box.nextElementSibling;
    var rows = parse(ta.value);
    if (!rows.length) rows.push({ name: 'Player 1', url: '', audio: 'Dublado', quality: '' });

    function sample(url) {
      var s = {}; Object.keys(SAMPLE).forEach(function (k) { s[k] = (kind === 'episode' && SAMPLE_EP[k]) || SAMPLE[k]; });
      return url.replace(/\{(\w+)\}/g, function (m, k) { return s[k] !== undefined ? s[k] : m; });
    }
    function sync() {
      ta.value = rows.filter(function (r) { return r.url.trim(); }).map(function (r) {
        return [r.name.trim() || 'Player', r.url.trim(), r.audio.trim(), r.quality.trim()].join('|').replace(/\|+$/, '');
      }).join('\n');
    }
    function render() {
      box.innerHTML = rows.map(function (r, i) {
        var bad = r.url && !/^https?:\/\//i.test(r.url);
        var noVar = r.url && !/\{(tmdb_id|imdb_id)\}/.test(r.url);
        var noEp = kind === 'episode' && r.url && !noVar && !(/\{(temporada2?)\}/.test(r.url) && /\{(episodio2?)\}/.test(r.url));
        return '<div class="ab-row" data-i="' + i + '">' +
          '<div class="ab-top"><input class="ab-name" placeholder="Nome (ex: Dublado)" value="' + esc(r.name) + '">' +
          '<input class="ab-audio" placeholder="Áudio (opcional)" list="ab-audios" value="' + esc(r.audio) + '">' +
          '<button type="button" class="btn-ghost danger ab-del" title="Remover">✕</button></div>' +
          '<input class="ab-url" placeholder="Cole aqui o link do player…" value="' + esc(r.url) + '">' +
          '<div class="ab-chips"><span class="muted small">Inserir no link:</span>' + vars.map(function (v) {
            return '<button type="button" class="chip-btn" data-var="' + v + '">' + LABEL[v] + '</button>';
          }).join('') + '</div>' +
          (r.url ? '<div class="ab-preview ' + (bad || noVar || noEp ? 'warn' : 'ok') + '">' +
            (bad ? '⚠ O link precisa começar com http:// ou https://' :
             noVar ? '⚠ Falta o ID: apague o número do ' + (kind === 'movie' ? 'filme' : 'título') + ' no link e toque em <b>ID TMDB</b> (ou <b>ID IMDb</b>)' :
             noEp ? '⚠ Falta a temporada e o episódio no link: sem eles, todos os episódios abrem o mesmo vídeo. Toque em <b>Temporada</b> e <b>Episódio</b> nos lugares certos do link.' :
             'Exemplo de como fica: <a href="' + esc(sample(r.url)) + '" target="_blank" rel="noopener">' + esc(sample(r.url)) + '</a>') + '</div>' : '') +
          '</div>';
      }).join('') + '<button type="button" class="btn btn-sm ab-add">+ Adicionar outro player</button>' +
        '<datalist id="ab-audios"><option>Dublado</option><option>Legendado</option><option>Dual Áudio</option></datalist>';
      sync();
    }
    var lastUrl = null;
    box.addEventListener('focusin', function (ev) { if (ev.target.classList.contains('ab-url')) lastUrl = ev.target; });
    box.addEventListener('input', function (ev) {
      var row = ev.target.closest('.ab-row'); if (!row) return;
      var r = rows[+row.dataset.i];
      if (ev.target.classList.contains('ab-name')) r.name = ev.target.value;
      if (ev.target.classList.contains('ab-audio')) r.audio = ev.target.value;
      if (ev.target.classList.contains('ab-url')) r.url = ev.target.value;
      sync();
    });
    box.addEventListener('change', function (ev) { if (ev.target.classList.contains('ab-url')) { var pos = ev.target.selectionStart; render(); } });
    box.addEventListener('mousedown', function (ev) { if (ev.target.closest('.chip-btn')) ev.preventDefault(); });
    box.addEventListener('click', function (ev) {
      var row = ev.target.closest('.ab-row');
      if (ev.target.closest('.ab-add')) { rows.push({ name: 'Player ' + (rows.length + 1), url: '', audio: '', quality: '' }); render(); return; }
      if (!row) return;
      var i = +row.dataset.i;
      if (ev.target.closest('.ab-del')) { rows.splice(i, 1); if (!rows.length) rows.push({ name: 'Player 1', url: '', audio: '', quality: '' }); render(); return; }
      var chip = ev.target.closest('.chip-btn');
      if (chip) {
        var inp = row.querySelector('.ab-url'), token = '{' + chip.dataset.var + '}';
        var s = inp.selectionStart != null && lastUrl === inp ? inp.selectionStart : inp.value.length;
        var e2 = inp.selectionEnd != null && lastUrl === inp ? inp.selectionEnd : s;
        rows[i].url = inp.value.slice(0, s) + token + inp.value.slice(e2);
        render();
        var ni = box.querySelector('.ab-row[data-i="' + i + '"] .ab-url');
        ni.focus(); ni.setSelectionRange(s + token.length, s + token.length); lastUrl = ni;
      }
    });
    render();
  });
})();

/* ===== Envia o formulário inteiro "embrulhado" (o firewall da hospedagem não consegue ler e barrar) ===== */
document.addEventListener('submit', function (ev) {
  var f = ev.target;
  if (ev.defaultPrevented || !f || (f.method || '').toLowerCase() !== 'post' || f.dataset.plain !== undefined) return;
  var fd = new FormData(f);
  var sub = ev.submitter;
  if (sub && sub.name && !sub.disabled) fd.append(sub.name, sub.value);
  var parts = [], csrf = '';
  fd.forEach(function (v, k) {
    if (typeof v !== 'string') return; // (sem envio de arquivos no painel)
    if (k === '_csrf') { csrf = v; return; }
    parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
  });
  var packed = btoa(unescape(encodeURIComponent(parts.join('&')))).split('').reverse().join('');
  ev.preventDefault();
  var g = document.createElement('form');
  g.method = 'post';
  g.action = f.getAttribute('action') || location.href;
  g.style.display = 'none';
  [['_csrf', csrf], ['_p', packed]].forEach(function (kv) {
    var i2 = document.createElement('input'); i2.type = 'hidden'; i2.name = kv[0]; i2.value = kv[1]; g.appendChild(i2);
  });
  document.body.appendChild(g);
  g.submit();
});

// Se o navegador voltar para uma página já enviada, recarrega (evita mostrar campos codificados)
window.addEventListener('pageshow', function (e) { if (e.persisted) location.reload(); });
// Botões "Copiar"
document.querySelectorAll('[data-copy]').forEach(function (b) {
  b.addEventListener('click', function () {
    var t = document.getElementById(b.dataset.copy).textContent;
    var done = function () { var o = b.textContent; b.textContent = 'Copiado!'; setTimeout(function () { b.textContent = o; }, 1500); };
    if (navigator.clipboard) navigator.clipboard.writeText(t).then(done, function () { prompt('Copie:', t); });
    else prompt('Copie:', t);
  });
});

/* ===== "Rodar agora" da importação automática (um título por vez) ===== */
(function () {
  var btn = document.getElementById('auto-run');
  if (!btn) return;
  var PMA = window.PM || {};
  var post = function (action, data) {
    var fd = new FormData();
    fd.append('_csrf', PMA.csrf);
    var qs = Object.keys(data).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]); }).join('&');
    fd.append('_p', btoa(unescape(encodeURIComponent(qs))).split('').reverse().join(''));
    return fetch(PMA.base + '/admin/ajax.php?action=' + action, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  };
  var el = function (id) { return document.getElementById(id); };
  var esc = function (s) { return String(s || '').replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
  btn.addEventListener('click', function () {
    var target = Math.max(1, +btn.dataset.per || 10), done = 0, upd = 0, err = 0, steps = 0, stop = false, fails = 0, titles = [];
    el('queue').hidden = false; el('q-log').innerHTML = ''; el('q-close').hidden = true; el('q-stop').hidden = false; el('q-stop').disabled = false;
    el('q-title').textContent = 'Rodando a importação…';
    var log = function (cls, html) { var li = document.createElement('li'); li.className = cls; li.innerHTML = html; el('q-log').insertBefore(li, el('q-log').firstChild); };
    var paint = function () {
      el('q-ok').textContent = done; el('q-skip').textContent = upd; el('q-err').textContent = err;
      el('q-bar').style.width = Math.min(100, Math.round(done / target * 100)) + '%';
      el('q-status').textContent = done + ' de ' + target;
    };
    var finish = function (msg) {
      el('q-title').textContent = msg; el('q-stop').hidden = true; el('q-close').hidden = false; el('q-bar').style.width = '100%';
      if (done || upd) post('auto_log', { msg: done + ' título(s) novo(s) (rodado manualmente)' + (titles.length ? ': ' + titles.slice(0, 8).join(', ') + (titles.length > 8 ? '…' : '') : '') + (upd ? ' • ' + upd + ' série(s) com episódios novos' : ''), type: 'ok' });
    };
    el('q-stop').onclick = function () { stop = true; el('q-stop').disabled = true; };
    el('q-close').onclick = function () { location.reload(); };
    var step = function () {
      if (stop) return finish('Importação interrompida');
      if (done >= target) return finish('Importação concluída!');
      steps++;
      // na primeira passada também confere 1 série com episódios novos
      post('auto_step', { series: steps === 1 ? 1 : 0 }).then(function (j) {
        fails = 0;
        if (j.error) { err++; log('err', '✕ ' + esc(j.error)); }
        (j.titles || []).forEach(function (t) { titles.push(t); log('ok', '✓ <b>' + esc(t) + '</b>'); });
        done += j.imported || 0; upd += j.updated || 0; err += j.errors || 0;
        if (j.updated) log('ok', '✓ ' + j.updated + ' série(s) ganharam episódios novos');
        paint();
        if (!j.imported && !j.errors) return finish(done ? 'Importação concluída! Não há mais títulos novos agora.' : 'Nada novo para importar agora.');
        setTimeout(step, 400);
      }).catch(function () {
        fails++; err++; paint();
        log('err', '✕ A hospedagem demorou para responder' + (fails < 3 ? ', tentando de novo…' : '.'));
        if (fails >= 3) return finish('Parou: a hospedagem não respondeu 3 vezes seguidas');
        setTimeout(step, 3000);
      });
    };
    paint(); step();
  });
})();

/* ===== Fila de temporadas: com o painel aberto, termina de baixar séries longas aos poucos ===== */
(function () {
  var bar = document.getElementById('sq-bar');
  if (!bar) return;
  var PMA = window.PM || {}, fails = 0;
  var tick = function () {
    var fd = new FormData(); fd.append('_csrf', PMA.csrf);
    fetch(PMA.base + '/admin/ajax.php?action=season_queue', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        fails = 0;
        if (!j.left) { document.getElementById('sq-text').textContent = 'Episódios baixados ✓'; bar.classList.add('done'); setTimeout(function () { bar.remove(); }, 2500); return; }
        document.getElementById('sq-text').textContent = 'Baixando episódios de séries longas: ' + j.left + ' temporada(s) na fila…';
        setTimeout(tick, 800);
      })
      .catch(function () { if (++fails < 5) setTimeout(tick, 4000); });
  };
  setTimeout(tick, 1200);
})();
// Atalhos de anos
document.querySelectorAll('[data-y]').forEach(function (b) {
  b.addEventListener('click', function () {
    var p = b.dataset.y.split('-');
    document.getElementById('yf').value = p[0];
    document.getElementById('yt').value = p[1];
  });
});

/* ===== Importação de listas de canais em partes ===== */
(function () {
  var box = document.getElementById('tvq');
  if (!box) return;
  var PMA = window.PM || {};
  var post = function (action, data) {
    var fd = new FormData(); fd.append('_csrf', PMA.csrf);
    var qs = Object.keys(data).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]); }).join('&');
    fd.append('_p', btoa(unescape(encodeURIComponent(qs))).split('').reverse().join(''));
    return fetch(PMA.base + '/admin/ajax.php?action=' + action, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  };
  var el = function (id) { return document.getElementById(id); };
  var run = function (sid, name) {
    var add = 0, upd = 0;
    box.hidden = false; el('tvq-close').hidden = true;
    el('tvq-title').textContent = 'Importando ' + (name || 'lista') + '…';
    el('tvq-msg').textContent = 'Baixando a lista…'; el('tvq-bar').style.width = '3%';
    el('tvq-add').textContent = '0'; el('tvq-upd').textContent = '0'; el('tvq-rem').textContent = '0';
    var end = function (title, msg) { el('tvq-title').textContent = title; el('tvq-msg').textContent = msg || ''; el('tvq-close').hidden = false; };
    el('tvq-close').onclick = function () { location.href = PMA.base + '/admin/canais.php#listas'; };
    post('tv_prepare', { sid: sid }).then(function (j) {
      if (!j.ok) return end('Não foi possível importar', j.error);
      var total = j.total;
      var step = function (off, tries) {
        el('tvq-status').textContent = off + ' de ' + total;
        el('tvq-bar').style.width = Math.max(3, Math.round(off / total * 100)) + '%';
        if (off >= total) {
          el('tvq-msg').textContent = 'Finalizando…';
          return post('tv_finish', { sid: sid }).then(function (f) {
            el('tvq-rem').textContent = f.removed || 0;
            end('Lista importada!', total + ' canal(is) na lista.');
          });
        }
        el('tvq-msg').textContent = 'Gravando canais…';
        post('tv_chunk', { sid: sid, offset: off }).then(function (c) {
          if (!c.ok) return end('Não foi possível importar', c.error);
          add += c.added; upd += c.updated;
          el('tvq-add').textContent = add; el('tvq-upd').textContent = upd;
          step(c.next, 0);
        }).catch(function () {
          if (tries < 3) return setTimeout(function () { step(off, tries + 1); }, 3000);
          end('A hospedagem não respondeu', 'Tente "Atualizar agora" de novo: ele continua sem duplicar.');
        });
      };
      step(0, 0);
    }).catch(function () { end('A hospedagem não respondeu', 'Não consegui baixar a lista agora. Tente de novo em alguns minutos.'); });
  };
  document.querySelectorAll('[data-tv-run]').forEach(function (b) {
    b.addEventListener('click', function () { run(b.dataset.tvRun, b.dataset.tvName); });
  });
  if (box.dataset.autostart) {
    var b = document.querySelector('[data-tv-run="' + box.dataset.autostart + '"]');
    run(box.dataset.autostart, b ? b.dataset.tvName : '');
  }
})();

