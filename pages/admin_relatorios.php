<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/database/connection.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/admin.php';

exigirAdmin();

$tema = (($_SESSION['tema'] ?? 'claro') === 'escuro') ? 'escuro' : 'claro';

$totais = [
    'usuarios' => (int) $pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn(),
    'ativos' => (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE status = 'ativo'")->fetchColumn(),
    'bloqueados' => (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE status = 'bloqueado'")->fetchColumn(),
    'projetos' => (int) $pdo->query("SELECT COUNT(*) FROM projetos")->fetchColumn(),
    'tarefas' => (int) $pdo->query("SELECT COUNT(*) FROM tarefas")->fetchColumn(),
    'concluidas' => (int) $pdo->query("SELECT COUNT(*) FROM tarefas WHERE completo_em IS NOT NULL")->fetchColumn(),
    'abertas' => (int) $pdo->query("SELECT COUNT(*) FROM tarefas WHERE completo_em IS NULL")->fetchColumn(),
    'convites' => (int) $pdo->query("SELECT COUNT(*) FROM convites_projeto WHERE status = 'pendente' AND expira_em > NOW()")->fetchColumn(),
];

$taxaConclusao = $totais['tarefas'] > 0 ? round($totais['concluidas'] / $totais['tarefas'] * 100, 1) : 0;

$projetos = $pdo->query("
    SELECT p.id, p.nome, u.nome AS criador,
           COUNT(t.id) AS total_tarefas,
           SUM(CASE WHEN t.completo_em IS NOT NULL THEN 1 ELSE 0 END) AS concluidas,
           (SELECT COUNT(*) FROM participantes_projeto pp WHERE pp.id_projeto = p.id) AS participantes
    FROM projetos p
    INNER JOIN usuarios u ON u.id = p.id_usuario_criador
    LEFT JOIN tarefas t ON t.id_projeto = p.id
    GROUP BY p.id, p.nome, u.nome
    ORDER BY total_tarefas DESC, p.id DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

$atividadeTarefas = $pdo->query("
    SELECT DATE_FORMAT(criado_em, '%Y-%m') AS mes, COUNT(*) AS total
    FROM tarefas
    WHERE criado_em >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
    GROUP BY DATE_FORMAT(criado_em, '%Y-%m')
    ORDER BY mes ASC
")->fetchAll(PDO::FETCH_ASSOC);

$maxTarefasMes = 1;
foreach ($atividadeTarefas as $linha) {
    $maxTarefasMes = max($maxTarefasMes, (int) $linha['total']);
}
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= adminEscapar($tema) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatórios | Administração TarefAí</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/admin.css">
</head>
<body>
<div class="dashboard-container">
    <?php require $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/components/sidebar.php'; ?>
    <main class="main-content admin-main">
        <header class="topbar">
            <section><span class="topbar-label">ADMINISTRAÇÃO</span><h1>Relatórios</h1></section>
            <span class="admin-badge admin">Indicadores gerais</span>
        </header>
        <p class="admin-intro">Indicadores agregados da plataforma. Os relatórios não alteram as permissões de acesso aos projetos.</p>

        <section class="admin-stats">
            <article class="admin-stat"><span class="admin-stat-label">Usuários</span><strong><?= $totais['usuarios'] ?></strong><small><?= $totais['ativos'] ?> ativos · <?= $totais['bloqueados'] ?> bloqueados</small></article>
            <article class="admin-stat"><span class="admin-stat-label">Projetos</span><strong><?= $totais['projetos'] ?></strong><small>Em toda a plataforma</small></article>
            <article class="admin-stat"><span class="admin-stat-label">Tarefas</span><strong><?= $totais['tarefas'] ?></strong><small><?= $totais['abertas'] ?> em aberto</small></article>
            <article class="admin-stat"><span class="admin-stat-label">Conclusão geral</span><strong><?= $taxaConclusao ?>%</strong><small><?= $totais['concluidas'] ?> concluídas</small></article>
        </section>

        <section class="admin-grid">
            <article class="admin-card">
                <h2>Criação de tarefas</h2>
                <p>Tarefas criadas nos últimos seis meses, agrupadas por mês.</p>
                <?php if (!$atividadeTarefas): ?>
                    <div class="admin-empty">Não há dados de criação de tarefas nesse período.</div>
                <?php else: ?>
                    <div class="admin-bars">
                        <?php foreach ($atividadeTarefas as $linha): ?>
                            <?php
                                $mesTimestamp = strtotime($linha['mes'] . '-01');
                                $rotuloMes = $mesTimestamp ? date('m/Y', $mesTimestamp) : $linha['mes'];
                                $largura = (int) round(((int) $linha['total'] / $maxTarefasMes) * 100);
                            ?>
                            <div class="admin-bar-row">
                                <span><?= adminEscapar($rotuloMes) ?></span>
                                <div class="admin-bar-track"><div class="admin-bar-fill" style="width:<?= $largura ?>%"></div></div>
                                <strong><?= (int) $linha['total'] ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>

            <article class="admin-card">
                <h2>Convites</h2>
                <p>Convites que ainda aguardam resposta e não expiraram.</p>
                <strong style="font-size:36px;color:var(--text)"><?= $totais['convites'] ?></strong>
                <p style="margin-top:8px">Convites pendentes no momento.</p>
                <div class="admin-note">Convites expirados ou revogados não são contabilizados como pendentes.</div>
            </article>
        </section>

        <section class="admin-card" style="margin-top:18px">
            <h2>Projetos com maior volume de tarefas</h2>
            <p>Os dez projetos com mais tarefas cadastradas.</p>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Projeto</th><th>Criador</th><th>Participantes</th><th>Tarefas</th><th>Concluídas</th><th>Conclusão</th></tr></thead>
                    <tbody>
                    <?php foreach ($projetos as $p): ?>
                        <?php $totalTarefas = (int) $p['total_tarefas']; $concluidas = (int) $p['concluidas']; $percentual = $totalTarefas > 0 ? round($concluidas / $totalTarefas * 100) : 0; ?>
                        <tr>
                            <td><strong><?= adminEscapar($p['nome']) ?></strong><small>ID <?= (int) $p['id'] ?></small></td>
                            <td><?= adminEscapar($p['criador']) ?></td>
                            <td><?= (int) $p['participantes'] ?></td>
                            <td><?= $totalTarefas ?></td>
                            <td><?= $concluidas ?></td>
                            <td><?= $percentual ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$projetos): ?><tr><td colspan="6" class="admin-empty">Nenhum projeto cadastrado.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
</body>
</html>
