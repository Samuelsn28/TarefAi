<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/database/connection.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/auth.php';

exigirLogin();

// Dados do usuário autenticado.
$usuarioId = (int) $_SESSION['usuario_id'];
$nomeUsuario = $_SESSION['nome'];
$role = $_SESSION['role'];

$isAdmin = ($role === 'admin');

// Função para proteger a saída de dados HTML.
function escapar($valor): string {
    return htmlspecialchars(
        (string) $valor,
        ENT_QUOTES,
        'UTF-8'
    );
}

// Todos os usuários, inclusive administradores,
// visualizam somente os projetos aos quais pertencem.
$filtroProjetos = "
    (
        p.id_usuario_criador = ?
        OR EXISTS (
            SELECT 1
            FROM gestores_projeto gp
            WHERE gp.id_projeto = p.id
            AND gp.id_usuario = ?
        )
    )
";

$parametros = [$usuarioId, $usuarioId];

$erro = '';

$totalProjetos = 0;
$totalTarefas = 0;
$tarefasConcluidas = 0;
$tarefasPendentes = 0;

$projetosRecentes = [];
$tarefasRecentes = [];

try {

    // =====================================
    // TOTAL DE PROJETOS
    // =====================================

    $sql = "
        SELECT COUNT(*)
        FROM projetos p
        WHERE $filtroProjetos
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($parametros);

    $totalProjetos = (int) $stmt->fetchColumn();


    // =====================================
    // ESTATÍSTICAS DE TAREFAS
    // =====================================

    $sql = "
        SELECT
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

        WHERE $filtroProjetos
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($parametros);

    $estatisticas = $stmt->fetch();

    $totalTarefas = (int) $estatisticas['total'];
    $tarefasConcluidas = (int) $estatisticas['concluidas'];
    $tarefasPendentes = (int) $estatisticas['pendentes'];


    // =====================================
    // PROJETOS RECENTES
    // =====================================

    $sql = "
        SELECT
            p.id,
            p.nome

        FROM projetos p

        WHERE $filtroProjetos

        ORDER BY p.id DESC

        LIMIT 5
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($parametros);

    $projetosRecentes = $stmt->fetchAll();


    // =====================================
    // TAREFAS RECENTES
    // =====================================

    $sql = "
        SELECT
            t.id,
            t.nome,
            t.criado_em,
            t.completo_em,
            p.nome AS projeto_nome

        FROM tarefas t

        INNER JOIN projetos p
            ON p.id = t.id_projeto

        WHERE $filtroProjetos

        ORDER BY t.criado_em DESC

        LIMIT 5
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($parametros);

    $tarefasRecentes = $stmt->fetchAll();


} catch (PDOException $e) {

    error_log($e->getMessage());

    $erro = 'Não foi possível carregar os dados do dashboard.';

}

?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Dashboard | TarefAi</title>

    <link rel="stylesheet"
          href="../css/dashboard.css">

</head>

<body>

<div class="dashboard-container">

    <!-- =====================================
         MENU LATERAL
    ====================================== -->

    <aside class="sidebar">

        <div class="sidebar-brand">

            <div class="brand-logo">T</div>

            <span>TarefAi</span>

        </div>


        <nav class="sidebar-nav">

            <span class="nav-label">
                MENU PRINCIPAL
            </span>


            <a href="dashboard.php" class="nav-item active">

                <span class="nav-icon">▦</span>

                Dashboard

            </a>


            <a href="projetos.php" class="nav-item">

                <span class="nav-icon">▣</span>

                Projetos

            </a>


            <a href="tarefas.php" class="nav-item">

                <span class="nav-icon">☑</span>

                Tarefas

            </a>


            <a href="categorias.php" class="nav-item">

                <span class="nav-icon">◈</span>

                Categorias

            </a>


            <?php if ($isAdmin): ?>

                <span class="nav-label">
                    ADMINISTRAÇÃO
                </span>


                <a href="usuarios.php" class="nav-item">

                    <span class="nav-icon">♙</span>

                    Usuários

                </a>

            <?php endif; ?>

        </nav>


        <!-- USUÁRIO LOGADO -->

        <div class="sidebar-bottom">

            <div class="sidebar-user">

                <div class="user-avatar">

                    <?= escapar(
                        strtoupper(substr($nomeUsuario, 0, 1))
                    ) ?>

                </div>


                <div class="user-info">

                    <strong>
                        <?= escapar($nomeUsuario) ?>
                    </strong>

                    <span>
                        <?= $isAdmin
                            ? 'Administrador'
                            : 'Usuário' ?>
                    </span>

                </div>

            </div>


            <a href="logout.php" class="logout-link">

                <span>↪</span>

                Sair da conta

            </a>

        </div>

    </aside>


    <!-- =====================================
         CONTEÚDO PRINCIPAL
    ====================================== -->

    <main class="main-content">


        <!-- CABEÇALHO -->

        <header class="topbar">

            <div>

                <span class="topbar-label">
                    PAINEL DE CONTROLE
                </span>

                <h1>Dashboard</h1>

            </div>


            <div class="topbar-user">

                <div class="topbar-avatar">

                    <?= escapar(
                        strtoupper(substr($nomeUsuario, 0, 1))
                    ) ?>

                </div>


                <div>

                    <strong>
                        <?= escapar($nomeUsuario) ?>
                    </strong>

                    <span>
                        <?= $isAdmin
                            ? 'Administrador'
                            : 'Usuário comum' ?>
                    </span>

                </div>

            </div>

        </header>


        <!-- BOAS-VINDAS -->

        <section class="welcome-section">

            <div>

                <h2>
                    Olá, <?= escapar($nomeUsuario) ?>! 👋
                </h2>

                <p>
                    Acompanhe seus projetos e organize
                    as atividades da sua equipe.
                </p>

            </div>


            <div class="welcome-decoration">

                <span>✓</span>

            </div>

        </section>


        <?php if ($erro): ?>

            <div class="alert-error">

                <?= escapar($erro) ?>

            </div>

        <?php else: ?>


            <!-- =====================================
                 INDICADORES
            ====================================== -->

            <section class="stats-grid">


                <!-- PROJETOS -->

                <article class="stat-card">

                    <div class="stat-icon purple">
                        ▣
                    </div>

                    <div>

                        <span>Projetos</span>

                        <strong>
                            <?= $totalProjetos ?>
                        </strong>

                        <small>
                            Projetos dos quais você participa
                        </small>

                    </div>

                </article>


                <!-- TOTAL DE TAREFAS -->

                <article class="stat-card">

                    <div class="stat-icon blue">
                        ☑
                    </div>

                    <div>

                        <span>Total de tarefas</span>

                        <strong>
                            <?= $totalTarefas ?>
                        </strong>

                        <small>
                            Tarefas dos seus projetos
                        </small>

                    </div>

                </article>


                <!-- CONCLUÍDAS -->

                <article class="stat-card">

                    <div class="stat-icon green">
                        ✓
                    </div>

                    <div>

                        <span>Concluídas</span>

                        <strong>
                            <?= $tarefasConcluidas ?>
                        </strong>

                        <small>
                            Tarefas finalizadas
                        </small>

                    </div>

                </article>


                <!-- PENDENTES -->

                <article class="stat-card">

                    <div class="stat-icon orange">
                        ◷
                    </div>

                    <div>

                        <span>Pendentes</span>

                        <strong>
                            <?= $tarefasPendentes ?>
                        </strong>

                        <small>
                            Aguardando conclusão
                        </small>

                    </div>

                </article>

            </section>


            <!-- =====================================
                 PROJETOS E TAREFAS
            ====================================== -->

            <section class="dashboard-grid">


                <!-- PROJETOS RECENTES -->

                <article class="content-card">


                    <div class="card-header">

                        <div>

                            <h3>Projetos recentes</h3>

                            <p>
                                Projetos dos quais você participa.
                            </p>

                        </div>


                        <a href="projetos.php" class="view-all">

                            Ver todos →

                        </a>

                    </div>


                    <div class="project-list">


                        <?php if (empty($projetosRecentes)): ?>

                            <div class="empty-state">

                                <span>▣</span>

                                <p>
                                    Nenhum projeto cadastrado.
                                </p>

                            </div>


                        <?php else: ?>


                            <?php foreach ($projetosRecentes as $projeto): ?>


                                <div class="project-item">

                                    <div class="project-icon">
                                        ▣
                                    </div>


                                    <div class="project-info">

                                        <strong>
                                            <?= escapar($projeto['nome']) ?>
                                        </strong>

                                        <span>
                                            Projeto #<?= (int) $projeto['id'] ?>
                                        </span>

                                    </div>


                                    <span class="project-arrow">
                                        →
                                    </span>

                                </div>


                            <?php endforeach; ?>


                        <?php endif; ?>


                    </div>

                </article>



                <!-- TAREFAS RECENTES -->

                <article class="content-card">


                    <div class="card-header">

                        <div>

                            <h3>Tarefas recentes</h3>

                            <p>
                                Confira as últimas atividades.
                            </p>

                        </div>


                        <a href="tarefas.php" class="view-all">

                            Ver todas →

                        </a>

                    </div>



                    <div class="task-list">


                        <?php if (empty($tarefasRecentes)): ?>


                            <div class="empty-state">

                                <span>☑</span>

                                <p>
                                    Nenhuma tarefa cadastrada.
                                </p>

                            </div>


                        <?php else: ?>


                            <?php foreach ($tarefasRecentes as $tarefa): ?>


                                <div class="task-item">


                                    <div class="task-status
                                        <?= $tarefa['completo_em']
                                            ? 'completed'
                                            : 'pending' ?>">

                                        <?= $tarefa['completo_em']
                                            ? '✓'
                                            : '◷' ?>

                                    </div>


                                    <div class="task-info">

                                        <strong>
                                            <?= escapar($tarefa['nome']) ?>
                                        </strong>

                                        <span>
                                            <?= escapar($tarefa['projeto_nome']) ?>
                                        </span>

                                    </div>


                                    <span class="task-badge
                                        <?= $tarefa['completo_em']
                                            ? 'badge-completed'
                                            : 'badge-pending' ?>">

                                        <?= $tarefa['completo_em']
                                            ? 'Concluída'
                                            : 'Pendente' ?>

                                    </span>


                                </div>


                            <?php endforeach; ?>


                        <?php endif; ?>


                    </div>

                </article>


            </section>


        <?php endif; ?>


        <!-- RODAPÉ -->

        <footer class="dashboard-footer">

            <span>

                © <?= date('Y') ?> TarefAi.
                Gestão de tarefas em equipe.

            </span>


            <span>

                Acesso:
                <?= $isAdmin
                    ? 'Administrador'
                    : 'Usuário comum' ?>

            </span>

        </footer>


    </main>

</div>

</body>
</html>
