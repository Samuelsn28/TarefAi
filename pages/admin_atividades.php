<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/database/connection.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/admin.php';

exigirAdmin();

$tema = (($_SESSION['tema'] ?? 'claro') === 'escuro') ? 'escuro' : 'claro';
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$limite = 25;
$total = (int) $pdo->query("SELECT COUNT(*) FROM admin_logs")->fetchColumn();
$totalPaginas = max(1, (int) ceil($total / $limite));
$pagina = min($pagina, $totalPaginas);
$offset = ($pagina - 1) * $limite;

$stmt = $pdo->prepare("
    SELECT l.id, l.acao, l.entidade, l.entidade_id, l.detalhes, l.criado_em,
           COALESCE(u.nome, 'Administrador removido') AS administrador,
           COALESCE(u.email, '') AS email
    FROM admin_logs l
    LEFT JOIN usuarios u ON u.id = l.admin_id
    ORDER BY l.criado_em DESC, l.id DESC
    LIMIT $limite OFFSET $offset
");
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= adminEscapar($tema) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Histórico administrativo | TarefAí</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/admin.css">
</head>
<body>
<div class="dashboard-container">
    <?php require $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/components/sidebar.php'; ?>
    <main class="main-content admin-main">
        <header class="topbar">
            <section><span class="topbar-label">ADMINISTRAÇÃO</span><h1>Histórico de atividades</h1></section>
            <span class="admin-badge admin">Auditoria</span>
        </header>
        <p class="admin-intro">Registro das operações realizadas na área administrativa, incluindo alterações de permissões, bloqueios, exclusões e transferências de projetos.</p>

        <section class="admin-card">
            <div class="admin-toolbar">
                <div><h2>Registro de ações</h2><p style="margin:5px 0 0;color:var(--text-secondary);font-size:12px"><?= $total ?> registro(s)</p></div>
            </div>
            <?php if (!$logs): ?>
                <div class="admin-empty">Nenhuma ação administrativa foi registrada até o momento.</div>
            <?php else: ?>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead><tr><th>Administrador</th><th>Ação</th><th>Entidade</th><th>Detalhes</th><th>Data</th></tr></thead>
                        <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><strong><?= adminEscapar($log['administrador']) ?></strong><small><?= adminEscapar($log['email']) ?></small></td>
                                <td><span class="admin-badge admin"><?= adminEscapar(str_replace('_', ' ', $log['acao'])) ?></span></td>
                                <td><?= adminEscapar($log['entidade']) ?><?= $log['entidade_id'] ? ' #' . (int) $log['entidade_id'] : '' ?></td>
                                <td><?= adminEscapar($log['detalhes']) ?></td>
                                <td><?= adminEscapar(date('d/m/Y H:i:s', strtotime($log['criado_em']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($totalPaginas > 1): ?>
                    <nav class="admin-pagination">
                        <?php if ($pagina > 1): ?><a href="?pagina=<?= $pagina - 1 ?>">Anterior</a><?php endif; ?>
                        <span class="current"><?= $pagina ?> / <?= $totalPaginas ?></span>
                        <?php if ($pagina < $totalPaginas): ?><a href="?pagina=<?= $pagina + 1 ?>">Próxima</a><?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>
</div>
</body>
</html>
