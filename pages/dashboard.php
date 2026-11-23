
<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/database/connection.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/auth.php';

exigirLogin();

// =============================================
// DADOS DO USUÁRIO
// =============================================

$usuarioId = (int) $_SESSION['usuario_id'];
$nomeUsuario = $_SESSION['nome'] ?? 'Usuário';
$isAdmin = (($_SESSION['role'] ?? '') === 'admin');

// =============================================
// FUNÇÕES AUXILIARES
// =============================================

function escapar($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

// =============================================
// FILTRO DE VISIBILIDADE DOS PROJETOS
// =============================================

// Inclui projetos criados, gerenciados ou aceitos como participante.
$filtroProjetos = "(
    p.id_usuario_criador = ?
    OR EXISTS (
        SELECT 1
        FROM gestores_projeto gp
        WHERE gp.id_projeto = p.id
        AND gp.id_usuario = ?
    )
    OR EXISTS (
        SELECT 1
        FROM participantes_projeto pp
        WHERE pp.id_projeto = p.id
        AND pp.id_usuario = ?
    )
)";

$parametros = [
    $usuarioId,
    $usuarioId,
    $usuarioId
];

// =============================================
// VARIÁVEIS INICIAIS
// =============================================

$erro = '';

$totalProjetos = 0;
$totalTarefas = 0;
$tarefasConcluidas = 0;
$tarefasPendentes = 0;

$projetosRecentes = [];
$tarefasRecentes = [];

// =============================================
// CONSULTAS DO DASHBOARD
// =============================================

try {

    // =========================================
    // TOTAL DE PROJETOS
    // =========================================

    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM projetos p
         WHERE $filtroProjetos"
    );

    $stmt->execute($parametros);

    $totalProjetos = (int) $stmt->fetchColumn();

    // =========================================
    // ESTATÍSTICAS DAS TAREFAS
    // =========================================

    $sql = "SELECT
                COUNT(*) AS total,

                SUM(
                    CASE
                        WHEN t.completo_em IS NOT NULL THEN 1
                        ELSE 0
                    END
                ) AS concluidas,

                SUM(
                    CASE
                        WHEN t.completo_em IS NULL THEN 1
                        ELSE 0
                    END
                ) AS pendentes

            FROM tarefas t

            INNER JOIN projetos p
                ON p.id = t.id_projeto

            WHERE $filtroProjetos";

    $stmt = $pdo->prepare($sql);

    $stmt->execute($parametros);

    $estatisticas = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $totalTarefas = (int) ($estatisticas['total'] ?? 0);

    $tarefasConcluidas = (int) ($estatisticas['concluidas'] ?? 0);

    $tarefasPendentes = (int) ($estatisticas['pendentes'] ?? 0);

    // =========================================
    // PROJETOS RECENTES
    // =========================================

    $stmt = $pdo->prepare(
        "SELECT
            p.id,
            p.nome

         FROM projetos p

         WHERE $filtroProjetos

         ORDER BY p.id DESC

         LIMIT 5"
    );

    $stmt->execute($parametros);

    $projetosRecentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // =========================================
    // TAREFAS RECENTES
    // =========================================

    $sql = "SELECT
                t.id,
                t.nome,
                t.criado_em,
                t.completo_em,
                p.id AS projeto_id,
                p.nome AS projeto_nome

            FROM tarefas t

            INNER JOIN projetos p
                ON p.id = t.id_projeto

            WHERE $filtroProjetos

            ORDER BY t.criado_em DESC

            LIMIT 5";

    $stmt = $pdo->prepare($sql);

    $stmt->execute($parametros);

    $tarefasRecentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    error_log($e->getMessage());

    $erro = 'Não foi possível carregar os dados do dashboard. Confirme se a tabela participantes_projeto existe.';
}

?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Dashboard | TarefAi</title>

    <link rel="stylesheet" href="../css/dashboard.css">

</head>

<body>

    <section class="dashboard-container">

        <!-- =====================================
             MENU LATERAL
        ====================================== -->

        <?php
            require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/components/sidebar.php';
        ?>

        <!-- =====================================
             CONTEÚDO PRINCIPAL
        ====================================== -->

        <main class="main-content">

            <!-- CABEÇALHO -->

            <header class="topbar">

                <section>

                    <span class="topbar-label">
                        PAINEL DE CONTROLE
                    </span>

                    <h1>Dashboard</h1>

                </section>

                <section class="topbar-user">

                    <section class="topbar-avatar">
                        <?= escapar(strtoupper(substr($nomeUsuario, 0, 1))) ?>
                    </section>

                    <section>

                        <strong>
                            <?= escapar($nomeUsuario) ?>
                        </strong>

                        <span>
                            <?= $isAdmin ? 'Administrador' : 'Usuário comum' ?>
                        </span>

                    </section>

                </section>

            </header>

            <!-- =================================
                 BOAS-VINDAS
            ================================== -->

            <section class="welcome-section">

                <section>

                    <h2>
                        Olá, <?= escapar($nomeUsuario) ?>! 👋
                    </h2>

                    <p>
                        Acompanhe seus projetos e organize as atividades da sua equipe.
                    </p>

                </section>

                <section class="welcome-decoration">
                    <span>✓</span>
                </section>

            </section>

            <!-- =================================
                 MENSAGENS DE ERRO
            ================================== -->

            <?php if ($erro): ?>

                <section class="alert-error">
                    <?= escapar($erro) ?>
                </section>

            <?php else: ?>

                <!-- =================================
                     CARDS DE ESTATÍSTICAS
                ================================== -->

                <section class="stats-grid">

                    <!-- TOTAL DE PROJETOS -->

                    <article class="stat-card">

                        <section class="stat-icon purple">
                            ▣
                        </section>

                        <section>

                            <span>Projetos</span>

                            <strong>
                                <?= $totalProjetos ?>
                            </strong>

                            <small>
                                Projetos dos quais você participa
                            </small>

                        </section>

                    </article>

                    <!-- TOTAL DE TAREFAS -->

                    <article class="stat-card">

                        <section class="stat-icon blue">
                            ☑
                        </section>

                        <section>

                            <span>Total de tarefas</span>

                            <strong>
                                <?= $totalTarefas ?>
                            </strong>

                            <small>
                                Tarefas dos seus projetos
                            </small>

                        </section>

                    </article>

                    <!-- TAREFAS CONCLUÍDAS -->

                    <article class="stat-card">

                        <section class="stat-icon green">
                            ✓
                        </section>

                        <section>

                            <span>Concluídas</span>

                            <strong>
                                <?= $tarefasConcluidas ?>
                            </strong>

                            <small>
                                Tarefas finalizadas
                            </small>

                        </section>

                    </article>

                    <!-- TAREFAS PENDENTES -->

                    <article class="stat-card">

                        <section class="stat-icon orange">
                            ◷
                        </section>

                        <section>

                            <span>Pendentes</span>

                            <strong>
                                <?= $tarefasPendentes ?>
                            </strong>

                            <small>
                                Aguardando conclusão
                            </small>

                        </section>

                    </article>

                </section>

                <!-- =================================
                     LISTAGENS DO DASHBOARD
                ================================== -->

                <section class="dashboard-grid">

                    <!-- =================================
                         PROJETOS RECENTES
                    ================================== -->

                    <article class="content-card">

                        <section class="card-header">

                            <section>

                                <h3>Projetos recentes</h3>

                                <p>
                                    Projetos dos quais você participa.
                                </p>

                            </section>

                            <a href="projetos.php" class="view-all">
                                Ver todos →
                            </a>

                        </section>

                        <section class="project-list">

                            <?php if (!$projetosRecentes): ?>

                                <section class="empty-state">

                                    <span>▣</span>

                                    <p>
                                        Nenhum projeto cadastrado.
                                    </p>

                                </section>

                            <?php else: ?>

                                <?php foreach ($projetosRecentes as $projeto): ?>

                                    <a
                                        class="project-item"
                                        href="projeto.php?id=<?= (int) $projeto['id'] ?>"
                                        style="text-decoration: none; color: inherit;"
                                    >

                                        <section class="project-icon">
                                            ▣
                                        </section>

                                        <section class="project-info">

                                            <strong>
                                                <?= escapar($projeto['nome']) ?>
                                            </strong>

                                            <span>
                                                Projeto #<?= (int) $projeto['id'] ?>
                                            </span>

                                        </section>

                                        <span class="project-arrow">
                                            →
                                        </span>

                                    </a>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </section>

                    </article>

                    <!-- =================================
                         TAREFAS RECENTES
                    ================================== -->

                    <article class="content-card">

                        <section class="card-header">

                            <section>

                                <h3>Tarefas recentes</h3>

                                <p>
                                    Confira as últimas atividades.
                                </p>

                            </section>

                            <a href="tarefas.php" class="view-all">
                                Ver todas →
                            </a>

                        </section>

                        <section class="task-list">

                            <?php if (!$tarefasRecentes): ?>

                                <section class="empty-state">

                                    <span>☑</span>

                                    <p>
                                        Nenhuma tarefa cadastrada.
                                    </p>

                                </section>

                            <?php else: ?>

                                <?php foreach ($tarefasRecentes as $tarefa): ?>

                                    <section class="task-item">

                                        <!-- STATUS DA TAREFA -->

                                        <section class="task-status <?= $tarefa['completo_em'] ? 'completed' : 'pending' ?>">

                                            <?= $tarefa['completo_em'] ? '✓' : '◷' ?>

                                        </section>

                                        <!-- INFORMAÇÕES DA TAREFA -->

                                        <section class="task-info">

                                            <strong>
                                                <?= escapar($tarefa['nome']) ?>
                                            </strong>

                                            <span>
                                                <?= escapar($tarefa['projeto_nome']) ?>
                                            </span>

                                        </section>

                                        <!-- SITUAÇÃO -->

                                        <span class="task-badge <?= $tarefa['completo_em'] ? 'badge-completed' : 'badge-pending' ?>">

                                            <?= $tarefa['completo_em'] ? 'Concluída' : 'Pendente' ?>

                                        </span>

                                    </section>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </section>

                    </article>

                </section>

            <?php endif; ?>

            <!-- =====================================
                 RODAPÉ
            ====================================== -->

            <footer class="dashboard-footer">

                <span>
                    © <?= date('Y') ?> TarefAi. Gestão de tarefas em equipe.
                </span>

                <span>
                    Acesso: <?= $isAdmin ? 'Administrador' : 'Usuário comum' ?>
                </span>

            </footer>

        </main>

    </section>

</body>

</html>
