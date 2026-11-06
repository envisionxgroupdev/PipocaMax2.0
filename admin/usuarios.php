<?php
require __DIR__ . '/_init.php';

$me = current_user();

if (is_post()) {
    csrf_check();
    $action = in_str('action');
    $id = in_int('id');
    try {
        if ($action === 'save') {
            $name = mb_substr(in_str('name'), 0, 120);
            $email = mb_strtolower(in_str('email'));
            $role = in_str('role') === 'admin' ? 'admin' : 'user';
            $status = in_str('status') === 'banned' ? 'banned' : 'active';
            $pass = (string)($_POST['password'] ?? '');
            if (mb_strlen($name) < 2) throw new RuntimeException('Informe o nome.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('E-mail inválido.');
            if (q_val('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $id])) throw new RuntimeException('Esse e-mail já está em uso.');
            if ($id === (int)$me['id'] && ($role !== 'admin' || $status !== 'active')) throw new RuntimeException('Você não pode remover seu próprio acesso de administrador.');
            if ($id) {
                q('UPDATE users SET name = ?, email = ?, role = ?, status = ? WHERE id = ?', [$name, $email, $role, $status, $id]);
                if ($pass !== '') {
                    if (strlen($pass) < 8) throw new RuntimeException('A senha precisa ter pelo menos 8 caracteres.');
                    q('UPDATE users SET password = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $id]);
                }
                flash('success', 'Usuário atualizado.');
            } else {
                if (strlen($pass) < 8) throw new RuntimeException('Defina uma senha com pelo menos 8 caracteres.');
                q('INSERT INTO users (name, email, password, role, status) VALUES (?, ?, ?, ?, ?)', [$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role, $status]);
                flash('success', 'Usuário criado.');
            }
            redirect('admin/usuarios.php');
        } elseif ($action === 'delete') {
            if ($id === (int)$me['id']) throw new RuntimeException('Você não pode excluir sua própria conta.');
            q('DELETE FROM users WHERE id = ?', [$id]);
            flash('success', 'Usuário excluído.');
        } elseif ($action === 'ban') {
            if ($id === (int)$me['id']) throw new RuntimeException('Você não pode bloquear sua própria conta.');
            q("UPDATE users SET status = IF(status = 'active', 'banned', 'active') WHERE id = ?", [$id]);
            flash('success', 'Status do usuário alterado.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('admin/usuarios.php?' . ($id ? 'id=' . $id : 'novo=1'));
    }
    redirect(in_str('back') !== '' ? safe_back(in_str('back')) : 'admin/usuarios.php');
}

$edit = in_int('id') ? q_one('SELECT * FROM users WHERE id = ?', [in_int('id')]) : null;
$showForm = $edit || in_str('novo') === '1';

$busca = in_str('q');
$role = in_str('role');
$where = ['1=1'];
$params = [];
if ($busca !== '') { $where[] = '(name LIKE ? OR email LIKE ?)'; array_push($params, "%$busca%", "%$busca%"); }
if (in_array($role, ['admin', 'user'], true)) { $where[] = 'role = ?'; $params[] = $role; }
if ($role === 'banned') { $where[] = "status = 'banned'"; }
$w = implode(' AND ', $where);
$perPage = 30;
$page = max(1, in_int('p', 1));
$total = (int)q_val("SELECT COUNT(*) FROM users WHERE $w", $params);
$users = q_all("SELECT u.*, (SELECT COUNT(*) FROM favorites f WHERE f.user_id = u.id) AS nfav FROM users u WHERE $w ORDER BY created_at DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params);
$qs = http_build_query(array_filter(['q' => $busca, 'role' => $role]));
$self = url('admin/usuarios.php') . ($qs ? '?' . $qs : '');

admin_header('Usuários', 'usuarios');
?>
<div class="toolbar">
  <form class="filterbar" method="get">
    <input type="search" name="q" value="<?= e($busca) ?>" placeholder="Buscar por nome ou e-mail">
    <select name="role"><option value="">Todos</option><option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>Administradores</option><option value="user" <?= $role === 'user' ? 'selected' : '' ?>>Usuários</option><option value="banned" <?= $role === 'banned' ? 'selected' : '' ?>>Bloqueados</option></select>
    <button class="btn">Filtrar</button>
    <span class="muted"><?= $total ?> usuário(s)</span>
  </form>
  <div class="toolbar-right"><a class="btn btn-red" href="<?= e(url('admin/usuarios.php?novo=1')) ?>"><?= icon('plus') ?> Novo usuário</a></div>
</div>

<?php if ($showForm): $u = $edit ?? ['id' => 0, 'name' => '', 'email' => '', 'role' => 'user', 'status' => 'active']; ?>
<section class="panel">
  <div class="panel-head"><h2><?= $edit ? 'Editar usuário' : 'Novo usuário' ?></h2><a href="<?= e(url('admin/usuarios.php')) ?>">Cancelar</a></div>
  <form method="post" class="form-grid">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
    <label class="field"><span>Nome</span><input name="name" value="<?= e($u['name']) ?>" required></label>
    <label class="field"><span>E-mail</span><input type="email" name="email" value="<?= e($u['email']) ?>" required></label>
    <label class="field"><span><?= $edit ? 'Nova senha (deixe vazio para manter)' : 'Senha' ?></span><input type="password" name="password" minlength="8" autocomplete="new-password" <?= $edit ? '' : 'required' ?>></label>
    <label class="field"><span>Permissão</span><select name="role"><option value="user">Usuário</option><option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Administrador</option></select></label>
    <label class="field"><span>Status</span><select name="status"><option value="active">Ativo</option><option value="banned" <?= $u['status'] === 'banned' ? 'selected' : '' ?>>Bloqueado</option></select></label>
    <div class="span-3"><button class="btn btn-red">Salvar</button></div>
  </form>
</section>
<?php endif; ?>

<div class="table-wrap">
<table class="table">
  <thead><tr><th>Usuário</th><th>Permissão</th><th>Status</th><th>Minha Lista</th><th>Cadastro</th><th>Último acesso</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr>
      <td><div class="title-cell"><span class="avatar" style="background:<?= e($u['avatar_color']) ?>"><?= e(mb_strtoupper(mb_substr($u['name'], 0, 1))) ?></span><div><a href="<?= e(url('admin/usuarios.php?id=' . $u['id'])) ?>"><b><?= e($u['name']) ?></b></a><small><?= e($u['email']) ?></small></div></div></td>
      <td><span class="pill <?= $u['role'] === 'admin' ? 'pill-red' : '' ?>"><?= $u['role'] === 'admin' ? 'Admin' : 'Usuário' ?></span></td>
      <td><span class="pill <?= $u['status'] === 'active' ? 'pill-ok' : 'pill-warn' ?>"><?= $u['status'] === 'active' ? 'Ativo' : 'Bloqueado' ?></span></td>
      <td><?= (int)$u['nfav'] ?></td>
      <td class="muted"><?= e(date('d/m/Y', strtotime($u['created_at']))) ?></td>
      <td class="muted"><?= e(time_ago($u['last_login'])) ?></td>
      <td class="actions">
        <a class="btn-ghost" href="<?= e(url('admin/usuarios.php?id=' . $u['id'])) ?>" title="Editar"><?= icon('edit') ?></a>
        <?php if ((int)$u['id'] !== (int)$me['id']): ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="ban"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="back" value="<?= e($self) ?>"><button class="btn-ghost" title="<?= $u['status'] === 'active' ? 'Bloquear' : 'Desbloquear' ?>"><?= icon($u['status'] === 'active' ? 'close' : 'check') ?></button></form>
          <?= delete_button('', ['action' => 'delete', 'id' => $u['id'], 'back' => $self], 'Excluir', 'Excluir o usuário ' . $u['name'] . '?') ?>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?= paginate($total, $perPage, $page, $self) ?>
<?php admin_footer(); ?>
