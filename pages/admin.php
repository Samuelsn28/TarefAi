<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/database/connection.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/admin.php';

exigirAdmin();

$usuarioId = (int) $_SESSION['usuario_id'];
$tema = (($_SESSION['tema'] ?? 'claro') === 'escuro') ? 'escuro' : 'claro';

$totais = [
    'usuarios' => (int) $pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn(),
    'ativos' => (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE status = 'ativo'")->fetchColumn(),
    'bloqueados' => (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE status = 'bloqueado'")->fetchColumn(),
    'admins' => (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE role = 'admin'")->fetchColumn(),
    'projetos' => (int) $pdo->query("SELECT COUNT(*) FROM projetos")->fetchColumn(),
    'tarefas' => (int) $pdo->query("SELECT COUNT(*) FROM tarefas")->fetchColumn(),
    'concluidas' => (int) $pdo->query("SELECT COUNT(*) FROM tarefas WHERE completo_em IS NOT NULL")->fetchColumn(),
    'convites' => (int) $pdo->query("SELECT COUNT(*) FROM convites_projeto WHERE status = 'pendente' AND expira_em > NOW()")->fetchColumn(),
];

$percentualConclusao = $totais['tarefas'] > 0
    ? round(($totais['concluidas'] / $totais['tarefas']) * 100)
    : 0;

$stmtLogs = $pdo->query("
    SELECT l.acao, l.entidade, l.entidade_id, l.detalhes, l.criado_em,
           COALESCE(u.nome, 'Administrador removido') AS administrador
    FROM admin_logs l
    LEFT JOIN usuarios u ON u.id = l.admin_id
    ORDER BY l.criado_em DESC, l.id DESC
    LIMIT 8
");
$logsRecentes = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);

function adminDataCurta(?string $data): string
{
    return $data ? date('d/m/Y H:i', strtotime($data)) : '';
}
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= adminEscapar($tema) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administração | TarefAí</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/admin.css">
</head>
<body>
<div class="dashboard-container">
    <?php require $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/components/sidebar.php'; ?>

    <main class="main-content admin-main">
        <header class="topbar">
            <section>
                <span class="topbar-label">CENTRAL ADMINISTRATIVA</span>
                <h1>Visão geral</h1>
            </section>
            <span class="admin-badge admin">Administrador da plataforma</span>
        </header>

        <p class="admin-intro">
            Acompanhe a saúde da plataforma e gerencie usuários, projetos e configurações.
            A administração da plataforma não concede automaticamente acesso aos projetos.
        </p>

        <section class="admin-stats">
            <article class="admin-stat">
                <span class="admin-stat-label">Usuários cadastrados</span>
                <strong><?= $totais['usuarios'] ?></strong>
                <small><?= $totais['ativos'] ?> ativos · <?= $totais['bloqueados'] ?> bloqueados</small>
            </article>
            <article class="admin-stat">
                <span class="admin-stat-label">Administradores</span>
                <strong><?= $totais['admins'] ?></strong>
                <small>Contas com controle da plataforma</small>
            </article>
            <article class="admin-stat">
                <span class="admin-stat-label">Projetos</span>
                <strong><?= $totais['projetos'] ?></strong>
                <small>Projetos de todos os usuários</small>
            </article>
            <article class="admin-stat">
                <span class="admin-stat-label">Tarefas</span>
                <strong><?= $totais['tarefas'] ?></strong>
                <small><?= $totais['concluidas'] ?> concluídas · <?= $percentualConclusao ?>%</small>
            </article>
        </section>

        <section class="admin-grid">
            <article class="admin-card">
                <h2>Atalhos administrativos</h2>
                <p>Gerencie a plataforma sem assumir a gestão dos projetos dos usuários.</p>
                <div class="admin-shortcuts">
                    <a class="admin-shortcut" href="usuarios.php"><span class="admin-shortcut-icon">♙</span>Usuários</a>
                    <a class="admin-shortcut" href="admin_projetos.php"><span class="admin-shortcut-icon">▣</span>Projetos</a>
                    <a class="admin-shortcut" href="admin_relatorios.php"><span class="admin-shortcut-icon">▥</span>Relatórios</a>
                    <a class="admin-shortcut" href="admin_atividades.php"><span class="admin-shortcut-icon">◷</span>Histórico</a>
                    <a class="admin-shortcut" href="admin_configuracoes.php"><span class="admin-shortcut-icon">⚙</span>Configurações</a>
                    <a class="admin-shortcut" href="dashboard.php"><span class="admin-shortcut-icon">⌂</span>Meu dashboard</a>
                </div>
            </article>

            <article class="admin-card">
                <h2>Atividade recente</h2>
                <p>Últimas operações realizadas na área administrativa.</p>
                <?php if (!$logsRecentes): ?>
                    <div class="admin-empty">Ainda não há ações administrativas registradas.</div>
                <?php else: ?>
                    <?php foreach ($logsRecentes as $log): ?>
                        <div class="admin-log-item">
                            <div>
                                <strong><?= adminEscapar($log['administrador']) ?> · <?= adminEscapar(str_replace('_', ' ', $log['acao'])) ?></strong>
                                <p><?= adminEscapar($log['detalhes']) ?></p>
                            </div>
                            <time><?= adminEscapar(adminDataCurta($log['criado_em'])) ?></time>
                        </div>
                    <?php endforeach; ?>
                    <a class="admin-btn" href="admin_atividades.php">Ver histórico completo</a>
                <?php endif; ?>
            </article>
        </section>

        <section class="admin-card" style="margin-top:18px">
            <h2>Indicadores operacionais</h2>
            <p>Dados atuais da plataforma.</p>
            <div class="admin-bars">
                <div class="admin-bar-row">
                    <span>Tarefas concluídas</span>
                    <div class="admin-bar-track"><div class="admin-bar-fill" style="width:<?= $percentualConclusao ?>%"></div></div>
                    <strong><?= $percentualConclusao ?>%</strong>
                </div>
                <div class="admin-bar-row">
                    <span>Convites pendentes</span>
                    <div class="admin-bar-track"><div class="admin-bar-fill" style="width:<?= min(100, $totais['convites'] * 10) ?>%"></div></div>
                    <strong><?= $totais['convites'] ?></strong>
                </div>
            </div>
        </section>
    </main>
</div>
</body>
</html>
