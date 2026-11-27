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
    $idProjeto = filter_var($_POST['id_projeto'] ?? null, FILTER_VALIDATE_INT);

    if (!$idProjeto || $idProjeto <= 0) {
        $erro = 'Projeto inválido.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmtProjeto = $pdo->prepare("
                SELECT p.id, p.nome, p.id_usuario_criador, u.nome AS criador
                FROM projetos p
                INNER JOIN usuarios u ON u.id = p.id_usuario_criador
                WHERE p.id = ?
                FOR UPDATE
            ");
            $stmtProjeto->execute([$idProjeto]);
            $projeto = $stmtProjeto->fetch(PDO::FETCH_ASSOC);

            if (!$projeto) {
                throw new RuntimeException('Projeto não encontrado.');
            }

            if ($acao === 'transferir') {
                $novoCriadorId = filter_var($_POST['novo_criador'] ?? null, FILTER_VALIDATE_INT);

                if (!$novoCriadorId || $novoCriadorId <= 0 || $novoCriadorId === (int) $projeto['id_usuario_criador']) {
                    throw new RuntimeException('Selecione outro usuário para receber a gestão.');
                }

                $stmtNovo = $pdo->prepare("SELECT id, nome FROM usuarios WHERE id = ? AND status = 'ativo' LIMIT 1");
                $stmtNovo->execute([$novoCriadorId]);
                $novoUsuario = $stmtNovo->fetch(PDO::FETCH_ASSOC);

                if (!$novoUsuario) {
                    throw new RuntimeException('O usuário selecionado não existe ou está bloqueado.');
                }

                $stmtAtualizar = $pdo->prepare("UPDATE projetos SET id_usuario_criador = ? WHERE id = ?");
                $stmtAtualizar->execute([$novoCriadorId, $idProjeto]);

                $stmtParticipante = $pdo->prepare("INSERT IGNORE INTO participantes_projeto (id_usuario, id_projeto) VALUES (?, ?)");
                $stmtParticipante->execute([$novoCriadorId, $idProjeto]);

                // Ao transferir a titularidade, o antigo criador deixa de ser gestor
                // por esse vínculo específico. Outros gestores permanecem inalterados.
                $stmtRemoverGestorAntigo = $pdo->prepare("DELETE FROM gestores_projeto WHERE id_projeto = ? AND id_usuario = ?");
                $stmtRemoverGestorAntigo->execute([$idProjeto, (int) $projeto['id_usuario_criador']]);

                adminRegistrarAcao(
                    $pdo, $adminId, 'transferir_projeto', 'projeto', $idProjeto,
                    'Projeto "' . $projeto['nome'] . '" transferido de ' . $projeto['criador'] . ' para ' . $novoUsuario['nome'] . '.'
                );
                $sucesso = 'Gestão do projeto transferida com sucesso.';

            } elseif ($acao === 'excluir') {
                $senhaAdmin = (string) ($_POST['senha_admin'] ?? '');
                $stmtSenhaAdmin = $pdo->prepare("SELECT senha FROM usuarios WHERE id = ? LIMIT 1");
                $stmtSenhaAdmin->execute([$adminId]);
                $hashAdmin = (string) $stmtSenhaAdmin->fetchColumn();

                if ($senhaAdmin === '' || !password_verify($senhaAdmin, $hashAdmin)) {
                    throw new RuntimeException('Confirme a exclusão informando sua senha de administrador correta.');
                }

                adminExcluirProjeto($pdo, $idProjeto);
                adminRegistrarAcao(
                    $pdo, $adminId, 'excluir_projeto', 'projeto', $idProjeto,
                    'Projeto "' . $projeto['nome'] . '" excluído pela administração.'
                );
                $sucesso = 'Projeto excluído com sucesso.';
            } else {
                throw new RuntimeException('Ação não reconhecida.');
            }

            $pdo->commit();
            header('Location: admin_projetos.php?sucesso=' . ($acao === 'excluir' ? 'excluido' : 'transferido'));
            exit;

        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $erro = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log($e->getMessage());
            $erro = 'Não foi possível concluir a operação.';
        }
    }
}

if (isset($_GET['sucesso'])) {
    $sucesso = $_GET['sucesso'] === 'excluido' ? 'Projeto excluído.' : 'Titularidade transferida.';
}

$busca = trim((string) ($_GET['busca'] ?? ''));
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$limite = 12;
$where = '';
$params = [];
if ($busca !== '') {
    $where = 'WHERE p.nome LIKE ? OR u.nome LIKE ? OR u.email LIKE ?';
    $params = ['%' . $busca . '%', '%' . $busca . '%', '%' . $busca . '%'];
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM projetos p INNER JOIN usuarios u ON u.id = p.id_usuario_criador $where");
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$totalPaginas = max(1, (int) ceil($total / $limite));
$pagina = min($pagina, $totalPaginas);
$offset = ($pagina - 1) * $limite;

$stmt = $pdo->prepare("
    SELECT p.id, p.nome, p.id_usuario_criador, u.nome AS criador, u.email,
           (SELECT COUNT(*) FROM participantes_projeto pp WHERE pp.id_projeto = p.id) AS participantes,
           (SELECT COUNT(*) FROM gestores_projeto gp WHERE gp.id_projeto = p.id) AS gestores,
           (SELECT COUNT(*) FROM tarefas t WHERE t.id_projeto = p.id) AS tarefas,
           (SELECT COUNT(*) FROM tarefas t WHERE t.id_projeto = p.id AND t.completo_em IS NOT NULL) AS concluidas
    FROM projetos p
    INNER JOIN usuarios u ON u.id = p.id_usuario_criador
    $where
    ORDER BY p.id DESC
    LIMIT $limite OFFSET $offset
");
$stmt->execute($params);
$projetos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$usuariosAtivos = $pdo->query("SELECT id, nome, email FROM usuarios WHERE status = 'ativo' ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= adminEscapar($tema) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Projetos | Administração TarefAí</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/admin.css">
</head>
<body>
<div class="dashboard-container">
    <?php require $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/components/sidebar.php'; ?>
    <main class="main-content admin-main">
        <header class="topbar">
            <section><span class="topbar-label">ADMINISTRAÇÃO</span><h1>Projetos</h1></section>
            <span class="admin-badge admin">Visão global</span>
        </header>
        <p class="admin-intro">Consulte todos os projetos da plataforma, transfira sua titularidade ou remova projetos. A consulta administrativa não adiciona você à equipe nem concede permissões operacionais no projeto.</p>

        <?php if ($erro): ?><div class="admin-alert error"><?= adminEscapar($erro) ?></div><?php endif; ?>
        <?php if ($sucesso): ?><div class="admin-alert success"><?= adminEscapar($sucesso) ?></div><?php endif; ?>

        <section class="admin-card">
            <div class="admin-toolbar">
                <form class="admin-search" method="GET">
                    <input class="admin-input" type="search" name="busca" value="<?= adminEscapar($busca) ?>" placeholder="Buscar projeto ou criador...">
                    <button class="admin-btn primary" type="submit">Buscar</button>
                </form>
                <span class="admin-badge"><?= $total ?> projeto(s)</span>
            </div>

            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Projeto</th><th>Criador atual</th><th>Equipe</th><th>Tarefas</th><th>Transferir titularidade</th><th>Ações</th></tr></thead>
                    <tbody>
                    <?php foreach ($projetos as $p): ?>
                        <tr>
                            <td><strong><?= adminEscapar($p['nome']) ?></strong><small>ID <?= (int) $p['id'] ?></small></td>
                            <td><?= adminEscapar($p['criador']) ?><small><?= adminEscapar($p['email']) ?></small></td>
                            <td><?= (int) $p['participantes'] ?> participante(s)<small><?= (int) $p['gestores'] ?> gestor(es) adicional(is)</small></td>
                            <td><?= (int) $p['concluidas'] ?> / <?= (int) $p['tarefas'] ?> concluídas</td>
                            <td>
                                <form method="POST" class="admin-actions">
                                    <input type="hidden" name="csrf_token" value="<?= adminEscapar($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="acao" value="transferir">
                                    <input type="hidden" name="id_projeto" value="<?= (int) $p['id'] ?>">
                                    <select class="admin-select" name="novo_criador" required aria-label="Novo criador">
                                        <option value="">Selecionar usuário</option>
                                        <?php foreach ($usuariosAtivos as $u): ?>
                                            <?php if ((int) $u['id'] !== (int) $p['id_usuario_criador']): ?>
                                                <option value="<?= (int) $u['id'] ?>"><?= adminEscapar($u['nome']) ?></option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="admin-btn primary" type="submit">Transferir</button>
                                </form>
                            </td>
                            <td>
                                <form method="POST" onsubmit="return confirm('Excluir este projeto e todas as suas tarefas, categorias, convites e vínculos?');">
                                    <input type="hidden" name="csrf_token" value="<?= adminEscapar($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="acao" value="excluir">
                                    <input type="hidden" name="id_projeto" value="<?= (int) $p['id'] ?>">
                                    <input class="admin-input" type="password" name="senha_admin" placeholder="Sua senha" required autocomplete="current-password" aria-label="Sua senha de administrador">
                                    <button class="admin-btn danger" type="submit">Excluir</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$projetos): ?><tr><td colspan="6" class="admin-empty">Nenhum projeto encontrado.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPaginas > 1): ?>
                <nav class="admin-pagination">
                    <?php if ($pagina > 1): ?><a href="?<?= http_build_query(['pagina' => $pagina - 1, 'busca' => $busca]) ?>">Anterior</a><?php endif; ?>
                    <span class="current"><?= $pagina ?> / <?= $totalPaginas ?></span>
                    <?php if ($pagina < $totalPaginas): ?><a href="?<?= http_build_query(['pagina' => $pagina + 1, 'busca' => $busca]) ?>">Próxima</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>
    </main>
</div>
</body>
</html>
