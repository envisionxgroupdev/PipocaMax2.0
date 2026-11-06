<?php
require __DIR__ . '/_init.php';
admin_header('Diagnóstico', 'config');
$base = BASE_URL;
?>
<section class="panel">
  <div class="panel-head"><h2>Diagnóstico de envio</h2></div>
  <p class="muted">Esta página testa vários tipos de envio para descobrir o que a hospedagem está bloqueando. Toque em <b>Rodar testes</b>, espere terminar e mande um print do resultado.</p>
  <button class="btn btn-red btn-lg" id="diag-run">Rodar testes</button>
  <table class="table compact" style="margin-top:16px;min-width:0">
    <thead><tr><th>#</th><th>Teste</th><th>Resultado</th></tr></thead>
    <tbody id="diag-out"><tr><td colspan="3" class="empty-cell">Ainda não rodou.</td></tr></tbody>
  </table>
  <p class="muted small" id="diag-info"></p>
</section>
<script>
(function () {
  var B = <?= json_encode($base) ?>, CSRF = <?= json_encode(csrf_token()) ?>;
  var pack = function (qs) { return btoa(unescape(encodeURIComponent(qs))).split('').reverse().join(''); };
  var enc = function (o) { return Object.keys(o).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(o[k]); }).join('&'); };
  var tests = [
    ['Abrir página (GET)', 'GET', B + '/admin/ajax.php?action=ping', null],
    ['Envio na raiz do site (api.php)', 'POST', B + '/api.php?action=ping', 'x=1'],
    ['Envio pequeno no painel', 'POST', B + '/admin/ajax.php?action=ping', enc({ _csrf: CSRF })],
    ['Envio com texto comum', 'POST', B + '/admin/ajax.php?action=ping', enc({ _csrf: CSRF, nome: 'Pipoca Max teste' })],
    ['Envio com link', 'POST', B + '/admin/ajax.php?action=ping', enc({ _csrf: CSRF, url: 'https://exemplo.com/filme/550' })],
    ['Envio com { } e |', 'POST', B + '/admin/ajax.php?action=ping', enc({ _csrf: CSRF, t: 'Dublado|https://exemplo.com/filme/{tmdb_id}|Dublado' })],
    ['Envio embrulhado', 'POST', B + '/admin/ajax.php?action=ping', enc({ _csrf: CSRF, _p: pack('t=Dublado%7Chttps%3A%2F%2Fexemplo.com') })],
    ['Envio em configuracoes.php', 'POST', B + '/admin/configuracoes.php', enc({ _csrf: CSRF, _p: pack('section=nada') })],
    ['Envio em automatico.php', 'POST', B + '/admin/automatico.php', enc({ _csrf: CSRF, _p: pack('action=nada') })],
    ['Envio em players.php', 'POST', B + '/admin/players.php', enc({ _csrf: CSRF, _p: pack('action=nada&back=/admin/') })]
  ];
  var out = document.getElementById('diag-out');
  document.getElementById('diag-run').addEventListener('click', function () {
    out.innerHTML = '';
    var i = 0;
    var next = function () {
      if (i >= tests.length) { document.getElementById('diag-info').textContent = 'Concluído em ' + new Date().toLocaleString('pt-BR') + ' • ' + navigator.userAgent; return; }
      var t = tests[i++], tr = document.createElement('tr');
      tr.innerHTML = '<td>' + i + '</td><td>' + t[0] + '</td><td class="muted">testando…</td>';
      out.appendChild(tr);
      var opt = { method: t[1], credentials: 'same-origin', redirect: 'manual', cache: 'no-store' };
      if (t[3] !== null) { opt.body = t[3]; opt.headers = { 'Content-Type': 'application/x-www-form-urlencoded' }; }
      var t0 = Date.now();
      fetch(t[2], opt).then(function (r) {
        var ok = r.type === 'opaqueredirect' || (r.status >= 200 && r.status < 400);
        tr.lastChild.innerHTML = (ok ? '<span class="pill pill-ok">✓ passou</span>' : '<span class="pill pill-warn">✗ erro ' + r.status + '</span>') + ' <small class="muted">' + (Date.now() - t0) + ' ms</small>';
      }).catch(function (e) {
        tr.lastChild.innerHTML = '<span class="pill pill-red">✗ conexão cortada</span> <small class="muted">' + (Date.now() - t0) + ' ms</small>';
      }).then(function () { setTimeout(next, 300); });
    };
    next();
  });
})();
</script>
<?php admin_footer(); ?>
