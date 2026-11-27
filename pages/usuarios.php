<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/database/connection.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/admin.php';

exigirAdmin();

$adminId = (int) $_SESSION['usuario_id'];
$tema = (($_SESSION['tema'] ?? 'claro') === 'escuro') ? 'escuro' : 'claro';
$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCSRF();

    $acao = (string) ($_POST['acao'] ?? '');
    $idAlvo = filter_var($_POST['id_usuario'] ?? null, FILTER_VALIDATE_INT);

    if (!$idAlvo || $idAlvo <= 0) {
        $erro = 'Usuário inválido.';
    } elseif ($idAlvo === $adminId) {
        $erro = 'Não é permitido alterar sua própria conta por esta tela. Utilize Configurações.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmtAlvo = $pdo->prepare("SELECT id, nome, email, role, status FROM usuarios WHERE id = ? FOR UPDATE");
            $stmtAlvo->execute([$idAlvo]);
            $alvo = $stmtAlvo->fetch(PDO::FETCH_ASSOC);

            if (!$alvo) {
                throw new RuntimeException('Usuário não encontrado.');
            }

            if ($acao === 'alterar_role') {
                $novoRole = (string) ($_POST['role'] ?? '');
                if (!in_array($novoRole, ['admin', 'default'], true)) {
                    throw new RuntimeException('Nível de acesso inválido.');
                }

                if ($alvo['role'] === 'admin' && $novoRole === 'default' && $alvo['status'] === 'ativo') {
                    $stmtAdminsAtivos = $pdo->query("
                        SELECT id FROM usuarios
                        WHERE role = 'admin' AND status = 'ativo'
                        FOR UPDATE
                    ");
                    $totalAdminsAtivos = count($stmtAdminsAtivos->fetchAll(PDO::FETCH_COLUMN));

                    if ($totalAdminsAtivos <= 1) {
                        throw new RuntimeException('A plataforma precisa manter pelo menos um administrador ativo.');
                    }
                }

                $stmt = $pdo->prepare("UPDATE usuarios SET role = ? WHERE id = ?");
                $stmt->execute([$novoRole, $idAlvo]);

                adminRegistrarAcao(
                    $pdo, $adminId, 'alterar_permissao', 'usuario', $idAlvo,
                    'Permissão de ' . $alvo['nome'] . ' alterada de ' . $alvo['role'] . ' para ' . $novoRole . '.'
                );
                $sucesso = 'Permissão atualizada com sucesso.';

            } elseif ($acao === 'alternar_status') {
                $novoStatus = $alvo['status'] === 'ativo' ? 'bloqueado' : 'ativo';

                if ($alvo['role'] === 'admin' && $alvo['status'] === 'ativo' && $novoStatus === 'bloqueado') {
                    $stmtAdminsAtivos = $pdo->query("
                        SELECT id FROM usuarios
                        WHERE role = 'admin' AND status = 'ativo'
                        FOR UPDATE
                    ");
                    $totalAdminsAtivos = count($stmtAdminsAtivos->fetchAll(PDO::FETCH_COLUMN));

                    if ($totalAdminsAtivos <= 1) {
                        throw new RuntimeException('Não é possível bloquear o último administrador ativo.');
                    }
                }

                $stmt = $pdo->prepare("UPDATE usuarios SET status = ? WHERE id = ?");
                $stmt->execute([$novoStatus, $idAlvo]);

                adminRegistrarAcao(
                    $pdo, $adminId, $novoStatus === 'bloqueado' ? 'bloquear_usuario' : 'desbloquear_usuario',
                    'usuario', $idAlvo,
                    'Conta de ' . $alvo['nome'] . ' (' . $alvo['email'] . ') ' . $novoStatus . '.'
                );
                $sucesso = $novoStatus === 'bloqueado'
                    ? 'Usuário bloqueado. As sessões existentes serão encerradas ao acessar uma nova página.'
                    : 'Usuário desbloqueado com sucesso.';

            } elseif ($acao === 'excluir_usuario') {
                $senhaAdmin = (string) ($_POST['senha_admin'] ?? '');
                $stmtSenhaAdmin = $pdo->prepare("SELECT senha FROM usuarios WHERE id = ? LIMIT 1");
                $stmtSenhaAdmin->execute([$adminId]);
                $hashAdmin = (string) $stmtSenhaAdmin->fetchColumn();

                if ($senhaAdmin === '' || !password_verify($senhaAdmin, $hashAdmin)) {
                    throw new RuntimeException('Confirme a exclusão informando sua senha de administrador correta.');
                }

                $foto = adminExcluirUsuario($pdo, $idAlvo, $adminId);
                $pdo->commit();
                adminRemoverFoto($foto);
                header('Location: usuarios.php?sucesso=excluido');
                exit;
            } else {
                throw new RuntimeException('Ação não reconhecida.');
            }

            $pdo->commit();
            header('Location: usuarios.php?sucesso=atualizado');
            exit;

        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $erro = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($e->getMessage());
            $erro = 'Não foi possível concluir a operação.';
        }
    }
}

if (isset($_GET['sucesso'])) {
    $sucesso = $_GET['sucesso'] === 'excluido'
        ? 'Usuário excluído. Os projetos foram transferidos quando havia outro gestor; os demais foram removidos.'
        : 'Operação concluída com sucesso.';
}

$busca = trim((string) ($_GET['busca'] ?? ''));
$filtro = (string) ($_GET['status'] ?? 'todos');
if (!in_array($filtro, ['todos', 'ativo', 'bloqueado'], true)) {
    $filtro = 'todos';
}
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$limite = 15;
$condicoes = [];
$parametros = [];

if ($busca !== '') {
    $condicoes[] = '(u.nome LIKE ? OR u.email LIKE ?)';
    $parametros[] = '%' . $busca . '%';
    $parametros[] = '%' . $busca . '%';
}
if ($filtro !== 'todos') {
    $condicoes[] = 'u.status = ?';
    $parametros[] = $filtro;
}
$where = $condicoes ? 'WHERE ' . implode(' AND ', $condicoes) : '';

$stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios u $where");
$stmt->execute($parametros);
$total = (int) $stmt->fetchColumn();
$totalPaginas = max(1, (int) ceil($total / $limite));
$pagina = min($pagina, $totalPaginas);
$offset = ($pagina - 1) * $limite;

$stmt = $pdo->prepare("
    SELECT u.id, u.nome, u.email, u.role, u.status, u.ultimo_acesso,
           (SELECT COUNT(*) FROM projetos p WHERE p.id_usuario_criador = u.id
             OR EXISTS (SELECT 1 FROM gestores_projeto gp WHERE gp.id_projeto = p.id AND gp.id_usuario = u.id)
           ) AS projetos_gerenciados
    FROM usuarios u
    $where
    ORDER BY u.id DESC
    LIMIT $limite OFFSET $offset
");
$stmt->execute($parametros);
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

function usuariosData(?string $data): string
{
    return $data ? date('d/m/Y H:i', strtotime($data)) : 'Nunca';
}
function usuariosUrl(int $pagina, string $busca, string $status): string
{
    return '?' . http_build_query(['pagina' => $pagina, 'busca' => $busca, 'status' => $status]);
}
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= adminEscapar($tema) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usuários | Administração TarefAí</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/admin.css">
</head>
<body>
<div class="dashboard-container">
    <?php require $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/components/sidebar.php'; ?>
    <main class="main-content admin-main">
        <header class="topbar">
            <section><span class="topbar-label">ADMINISTRAÇÃO</span><h1>Usuários</h1></section>
            <span class="admin-badge admin">Administrador da plataforma</span>
        </header>

        <p class="admin-intro">Gerencie contas e permissões globais. Ser administrador não transforma um usuário em gestor dos projetos de outras pessoas.</p>

        <?php if ($erro !== ''): ?><div class="admin-alert error"><?= adminEscapar($erro) ?></div><?php endif; ?>
        <?php if ($sucesso !== ''): ?><div class="admin-alert success"><?= adminEscapar($sucesso) ?></div><?php endif; ?>

        <div class="admin-note">
            <strong>Diferença entre permissões:</strong> Administrador controla a plataforma inteira.
            Gestor de projeto é definido individualmente em cada projeto pelo criador ou pelo vínculo em
            <code>gestores_projeto</code>. Um usuário comum pode ser gestor em um projeto e participante em outro.
        </div>

        <section class="admin-card">
            <div class="admin-toolbar">
                <form class="admin-search" method="GET">
                    <input class="admin-input" type="search" name="busca" placeholder="Buscar por nome ou e-mail..." value="<?= adminEscapar($busca) ?>">
                    <select class="admin-select" name="status">
                        <option value="todos" <?= $filtro === 'todos' ? 'selected' : '' ?>>Todos os status</option>
                        <option value="ativo" <?= $filtro === 'ativo' ? 'selected' : '' ?>>Ativos</option>
                        <option value="bloqueado" <?= $filtro === 'bloqueado' ? 'selected' : '' ?>>Bloqueados</option>
                    </select>
                    <button class="admin-btn primary" type="submit">Filtrar</button>
                </form>
                <span class="admin-badge"><?= $total ?> conta(s)</span>
            </div>

            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Usuário</th><th>Permissão da plataforma</th><th>Status</th><th>Projetos gerenciados</th><th>Último acesso</th><th>Ações</th></tr></thead>
                    <tbody>
                    <?php foreach ($usuarios as $u): ?>
                        <tr>
                            <td><strong><?= adminEscapar($u['nome']) ?></strong><small><?= adminEscapar($u['email']) ?> · ID <?= (int) $u['id'] ?></small></td>
                            <td><span class="admin-badge <?= $u['role'] === 'admin' ? 'admin' : '' ?>"><?= $u['role'] === 'admin' ? 'Administrador' : 'Usuário comum' ?></span></td>
                            <td><span class="admin-badge <?= $u['status'] === 'bloqueado' ? 'blocked' : 'manager' ?>"><?= $u['status'] === 'bloqueado' ? 'Bloqueado' : 'Ativo' ?></span></td>
                            <td><?= (int) $u['projetos_gerenciados'] ?></td>
                            <td><?= adminEscapar(usuariosData($u['ultimo_acesso'])) ?></td>
                            <td>
                                <?php if ((int) $u['id'] === $adminId): ?>
                                    <span class="admin-badge">Sua conta</span>
                                <?php else: ?>
                                    <div class="admin-actions">
                                        <form method="POST" class="admin-actions">
                                            <input type="hidden" name="csrf_token" value="<?= adminEscapar($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="id_usuario" value="<?= (int) $u['id'] ?>">
                                            <input type="hidden" name="acao" value="alterar_role">
                                            <select class="admin-select" name="role" aria-label="Permissão">
                                                <option value="default" <?= $u['role'] === 'default' ? 'selected' : '' ?>>Usuário</option>
                                                <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Administrador</option>
                                            </select>
                                            <button class="admin-btn" type="submit">Salvar</button>
                                        </form>
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?= adminEscapar($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="id_usuario" value="<?= (int) $u['id'] ?>">
                                            <input type="hidden" name="acao" value="alternar_status">
                                            <button class="admin-btn <?= $u['status'] === 'ativo' ? 'danger' : 'success' ?>" type="submit"><?= $u['status'] === 'ativo' ? 'Bloquear' : 'Desbloquear' ?></button>
                                        </form>
                                        <form method="POST" onsubmit="return confirm('Excluir permanentemente esta conta? Projetos com outro gestor serão transferidos; projetos sem outro gestor serão excluídos.');">
                                            <input type="hidden" name="csrf_token" value="<?= adminEscapar($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="id_usuario" value="<?= (int) $u['id'] ?>">
                                            <input type="hidden" name="acao" value="excluir_usuario">
                                            <input class="admin-input" type="password" name="senha_admin" placeholder="Sua senha" required autocomplete="current-password" aria-label="Sua senha de administrador">
                                            <button class="admin-btn danger" type="submit">Excluir</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$usuarios): ?><tr><td colspan="6" class="admin-empty">Nenhum usuário encontrado.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPaginas > 1): ?>
                <nav class="admin-pagination">
                    <?php if ($pagina > 1): ?><a href="<?= adminEscapar(usuariosUrl($pagina - 1, $busca, $filtro)) ?>">Anterior</a><?php endif; ?>
                    <span class="current"><?= $pagina ?> / <?= $totalPaginas ?></span>
                    <?php if ($pagina < $totalPaginas): ?><a href="<?= adminEscapar(usuariosUrl($pagina + 1, $busca, $filtro)) ?>">Próxima</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>
    </main>
</div>
</body>
</html>
