
<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/database/connection.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/auth.php';

exigirLogin();

// =============================================
// DADOS DO USUÁRIO
// =============================================

$usuarioId = (int) $_SESSION['usuario_id'];
$nomeUsuario = $_SESSION['nome'];
$role = $_SESSION['role'];

$isAdmin = ($role === 'admin');

// =============================================
// FUNÇÕES AUXILIARES
// =============================================

function escapar($valor): string {
    return htmlspecialchars(
        (string) $valor,
        ENT_QUOTES,
        'UTF-8'
    );
}

function tamanhoTexto(string $texto): int {
    return function_exists('mb_strlen')
        ? mb_strlen($texto, 'UTF-8')
        : strlen($texto);
}

// Usuário pode acessar projetos que criou
// ou nos quais foi cadastrado como gestor.

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

$parametrosAcesso = [$usuarioId, $usuarioId];

// =============================================
// VARIÁVEIS
// =============================================

$erro = '';
$sucesso = '';

$projetoEdicao = null;

$nomeFormulario = '';

$busca = trim($_GET['busca'] ?? '');

$pagina = filter_input(
    INPUT_GET,
    'pagina',
    FILTER_VALIDATE_INT
);

$pagina = max(1, (int) $pagina);

$limite = 10;
$totalProjetos = 0;
$totalPaginas = 1;
$projetos = [];

// =============================================
// MENSAGENS
// =============================================

if (isset($_GET['sucesso'])) {

    switch ($_GET['sucesso']) {

        case 'criado':
            $sucesso = 'Projeto cadastrado com sucesso!';
            break;

        case 'editado':
            $sucesso = 'Projeto atualizado com sucesso!';
            break;

        case 'excluido':
            $sucesso = 'Projeto excluído com sucesso!';
            break;
    }
}

// =============================================
// PROCESSAMENTO DOS FORMULÁRIOS
// =============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verificarCSRF();

    $acao = $_POST['acao'] ?? '';

    // =========================================
    // CADASTRAR PROJETO
    // =========================================

    if ($acao === 'criar') {

        $nome = trim($_POST['nome'] ?? '');

        if (
            $nome === '' ||
            tamanhoTexto($nome) > 150
        ) {

            $erro = 'Informe um nome válido de até 150 caracteres.';

            $nomeFormulario = $nome;

        } else {

            try {

                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    INSERT INTO projetos (
                        nome,
                        id_usuario_criador
                    )
                    VALUES (?, ?)
                ");

                $stmt->execute([
                    $nome,
                    $usuarioId
                ]);

                $pdo->commit();

                header('Location: projetos.php?sucesso=criado');
                exit;

            } catch (PDOException $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log($e->getMessage());

                $erro = 'Não foi possível cadastrar o projeto. Tente novamente.';

                $nomeFormulario = $nome;
            }
        }
    }

    // =========================================
    // EDITAR PROJETO
    // =========================================

    elseif ($acao === 'editar') {

        $idProjeto = filter_var(
            $_POST['id_projeto'] ?? null,
            FILTER_VALIDATE_INT
        );

        $nome = trim($_POST['nome'] ?? '');

        if (!$idProjeto || $idProjeto <= 0) {

            $erro = 'Projeto inválido.';

        } elseif (
            $nome === '' ||
            tamanhoTexto($nome) > 150
        ) {

            $erro = 'Informe um nome válido de até 150 caracteres.';

            $projetoEdicao = [
                'id' => $idProjeto,
                'nome' => $nome
            ];

        } else {

            try {

                // Verifica se o usuário participa do projeto.

                $stmt = $pdo->prepare("
                    SELECT p.id
                    FROM projetos p
                    WHERE p.id = ?
                    AND $filtroProjetos
                    LIMIT 1
                ");

                $stmt->execute(array_merge(
                    [$idProjeto],
                    $parametrosAcesso
                ));

                if (!$stmt->fetch()) {

                    $erro = 'Você não tem permissão para editar este projeto.';

                } else {

                    $stmt = $pdo->prepare("
                        UPDATE projetos
                        SET nome = ?
                        WHERE id = ?
                    ");

                    $stmt->execute([
                        $nome,
                        $idProjeto
                    ]);

                    header('Location: projetos.php?sucesso=editado');
                    exit;
                }

            } catch (PDOException $e) {

                error_log($e->getMessage());

                $erro = 'Não foi possível atualizar o projeto.';

                $projetoEdicao = [
                    'id' => $idProjeto,
                    'nome' => $nome
                ];
            }
        }
    }

    // =========================================
    // EXCLUIR PROJETO
    // =========================================

    elseif ($acao === 'excluir') {

        $idProjeto = filter_var(
            $_POST['id_projeto'] ?? null,
            FILTER_VALIDATE_INT
        );

        if (!$idProjeto || $idProjeto <= 0) {

            $erro = 'Projeto inválido.';

        } else {

            try {

                // Verifica a permissão antes de excluir.

                $stmt = $pdo->prepare("
                    SELECT p.id
                    FROM projetos p
                    WHERE p.id = ?
                    AND $filtroProjetos
                    LIMIT 1
                ");

                $stmt->execute(array_merge(
                    [$idProjeto],
                    $parametrosAcesso
                ));

                if (!$stmt->fetch()) {

                    $erro = 'Você não tem permissão para excluir este projeto.';

                } else {

                    $stmt = $pdo->prepare("
                        DELETE FROM projetos
                        WHERE id = ?
                    ");

                    $stmt->execute([$idProjeto]);

                    header('Location: projetos.php?sucesso=excluido');
                    exit;
                }

            } catch (PDOException $e) {

                error_log($e->getMessage());

                $erro = 'Não foi possível excluir o projeto.';
            }
        }
    }
}

// =============================================
// CARREGAR PROJETO PARA EDIÇÃO
// =============================================

if (
    isset($_GET['editar']) &&
    !$projetoEdicao
) {

    $idEditar = filter_var(
        $_GET['editar'],
        FILTER_VALIDATE_INT
    );

    if (!$idEditar || $idEditar <= 0) {

        $erro = 'Projeto inválido.';

    } else {

        try {

            $stmt = $pdo->prepare("
                SELECT
                    p.id,
                    p.nome
                FROM projetos p
                WHERE p.id = ?
                AND $filtroProjetos
                LIMIT 1
            ");

            $stmt->execute(array_merge(
                [$idEditar],
                $parametrosAcesso
            ));

            $projetoEdicao = $stmt->fetch();

            if (!$projetoEdicao) {
                $erro = 'Projeto não encontrado ou sem permissão de acesso.';
            }

        } catch (PDOException $e) {

            error_log($e->getMessage());

            $erro = 'Não foi possível carregar o projeto.';
        }
    }
}

// =============================================
// LISTAR PROJETOS
// =============================================

try {

    // -----------------------------------------
    // FILTRO DE PESQUISA
    // -----------------------------------------

    $filtroBusca = '';

    $parametrosConsulta = $parametrosAcesso;

    if ($busca !== '') {

        $filtroBusca = "
            AND p.nome LIKE ?
        ";

        $parametrosConsulta[] = '%' . $busca . '%';
    }

    // -----------------------------------------
    // TOTAL DE PROJETOS
    // -----------------------------------------

    $sql = "
        SELECT COUNT(*)
        FROM projetos p
        WHERE $filtroProjetos
        $filtroBusca
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute($parametrosConsulta);

    $totalProjetos = (int) $stmt->fetchColumn();

    // -----------------------------------------
    // PAGINAÇÃO
    // -----------------------------------------

    $totalPaginas = max(
        1,
        (int) ceil($totalProjetos / $limite)
    );

    $pagina = min($pagina, $totalPaginas);

    $offset = ($pagina - 1) * $limite;

    // -----------------------------------------
    // BUSCAR PROJETOS
    // -----------------------------------------

    $sql = "
        SELECT
            p.id,
            p.nome,
            p.id_usuario_criador,

            (
                SELECT COUNT(*)
                FROM tarefas t
                WHERE t.id_projeto = p.id
            ) AS total_tarefas,

            (
                SELECT COUNT(*)
                FROM tarefas t
                WHERE t.id_projeto = p.id
                AND t.completo_em IS NOT NULL
            ) AS tarefas_concluidas

        FROM projetos p

        WHERE $filtroProjetos

        $filtroBusca

        ORDER BY p.id DESC

        LIMIT $limite OFFSET $offset
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute($parametrosConsulta);

    $projetos = $stmt->fetchAll();

} catch (PDOException $e) {

    error_log($e->getMessage());

    $erro = 'Não foi possível carregar os projetos.';

    $projetos = [];
}

// =============================================
// TOKEN CSRF
// =============================================

$csrfToken = $_SESSION['csrf_token'];

?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Projetos | TarefAi</title>

    <link rel="stylesheet"
          href="../css/dashboard.css">

    <style>

        /* =====================================
           ÁREA DE PROJETOS
        ===================================== */

        .projects-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin: 30px 0 22px;
            flex-wrap: wrap;
        }

        .projects-toolbar h2 {
            font-size: 21px;
            margin-bottom: 5px;
        }

        .projects-toolbar p {
            color: #64748b;
            font-size: 14px;
        }

        .btn-add-project {
            border: none;
            background: #635bdb;
            color: #fff;
            padding: 12px 19px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: .2s;
        }

        .btn-add-project:hover {
            background: #5048c7;
        }

        /* =====================================
           FORMULÁRIO
        ===================================== */

        .project-form-card {
            background: #fff;
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 25px;
            border: 1px solid #e8edf3;
        }

        .project-form-card h3 {
            font-size: 18px;
            margin-bottom: 20px;
        }

        .project-form {
            display: flex;
            align-items: flex-end;
            gap: 15px;
        }

        .project-form .form-group {
            flex: 1;
        }

        .project-form label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 600;
        }

        .project-form input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #dce2ea;
            border-radius: 9px;
            outline: none;
            font-size: 14px;
        }

        .project-form input:focus {
            border-color: #635bdb;
            box-shadow: 0 0 0 3px rgba(99, 91, 219, .1);
        }

        .form-actions {
            display: flex;
            gap: 10px;
        }

        .btn-submit {
            background: #635bdb;
            color: #fff;
            border: none;
            border-radius: 9px;
            padding: 12px 20px;
            cursor: pointer;
            font-weight: 600;
        }

        .btn-cancel {
            background: #f1f5f9;
            color: #475569;
            padding: 12px 17px;
            border-radius: 9px;
            text-decoration: none;
            font-size: 14px;
        }

        /* =====================================
           PESQUISA
        ===================================== */

        .search-form {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .search-form input {
            flex: 1;
            min-width: 0;
            padding: 12px 15px;
            border: 1px solid #e2e8f0;
            border-radius: 9px;
            outline: none;
            font-size: 14px;
        }

        .search-form input:focus {
            border-color: #635bdb;
        }

        .btn-search {
            background: #334155;
            color: #fff;
            border: none;
            padding: 12px 19px;
            border-radius: 9px;
            cursor: pointer;
        }

        /* =====================================
           TABELA / LISTA
        ===================================== */

        .projects-table-wrapper {
            overflow-x: auto;
        }

        .projects-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 650px;
        }

        .projects-table th {
            background: #f8fafc;
            color: #64748b;
            text-align: left;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .4px;
            padding: 15px;
        }

        .projects-table td {
            padding: 17px 15px;
            border-bottom: 1px solid #edf0f4;
            font-size: 14px;
        }

        .projects-table tr:last-child td {
            border-bottom: none;
        }

        .project-name {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .project-name-icon {
            width: 39px;
            height: 39px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            background: #eeedff;
            color: #635bdb;
            font-size: 18px;
        }

        .project-name strong {
            color: #1e293b;
        }

        .project-name small {
            display: block;
            color: #94a3b8;
            margin-top: 4px;
        }

        .task-count {
            color: #475569;
        }

        .progress-container {
            width: 100px;
        }

        .progress-text {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 6px;
        }

        .progress-bar {
            height: 6px;
            background: #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: #22c55e;
            border-radius: 10px;
        }

        .project-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .action-access {
            background: #ecfdf5;
            color: #059669;
        }

        .action-access:hover {
            background: #d1fae5;
        }

        .action-link {
            display: inline-block;
            padding: 7px 10px;
            border-radius: 7px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
        }

        .action-edit {
            background: #eef2ff;
            color: #4f46e5;
        }

        .action-delete {
            background: #fef2f2;
            color: #dc2626;
            border: none;
            cursor: pointer;
        }

        .delete-form {
            display: inline;
        }

        /* =====================================
           ALERTAS
        ===================================== */

        .alert-success,
        .alert-error {
            padding: 14px 17px;
            border-radius: 9px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert-success {
            color: #166534;
            background: #dcfce7;
        }

        .alert-error {
            color: #991b1b;
            background: #fee2e2;
        }

        /* =====================================
           ESTADO VAZIO
        ===================================== */

        .empty-state {
            text-align: center;
            padding: 45px 20px;
            color: #64748b;
        }

        .empty-state span {
            font-size: 35px;
            display: block;
            margin-bottom: 12px;
        }

        .empty-state p {
            font-size: 14px;
        }

        /* =====================================
           PAGINAÇÃO
        ===================================== */

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            padding: 22px 0 5px;
            flex-wrap: wrap;
        }

        .pagination a,
        .pagination span {
            padding: 9px 13px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 13px;
        }

        .pagination a {
            background: #f1f5f9;
            color: #475569;
        }

        .pagination a:hover {
            background: #e2e8f0;
        }

        .pagination .current {
            background: #635bdb;
            color: #fff;
            font-weight: 600;
        }

        .pagination-info {
            color: #64748b;
            text-align: center;
            font-size: 13px;
            margin-top: 15px;
        }

        /* =====================================
           RESPONSIVIDADE
        ===================================== */

        @media (max-width: 768px) {

            .projects-toolbar {
                align-items: flex-start;
            }

            .project-form {
                flex-direction: column;
                align-items: stretch;
            }

            .form-actions {
                width: 100%;
            }

            .form-actions button,
            .form-actions a {
                flex: 1;
                text-align: center;
            }

            .search-form {
                flex-wrap: wrap;
            }

            .search-form input {
                flex-basis: 100%;
            }

            .search-form button {
                flex: 1;
            }

        }

    </style>

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

            <a href="dashboard.php" class="nav-item">

                <span class="nav-icon">▦</span>

                Dashboard

            </a>

            <a href="projetos.php" class="nav-item active">

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
                    GERENCIAMENTO
                </span>

                <h1>Projetos</h1>

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


        <!-- =====================================
             TÍTULO E BOTÃO
        ====================================== -->

        <section class="projects-toolbar">

            <div>

                <h2>Seus projetos</h2>

                <p>
                    Organize e acompanhe os projetos da sua equipe.
                </p>

            </div>

            <a
                href="projetos.php#formulario"
                class="btn-add-project"
                onclick="document.getElementById('nome').focus()"
            >

                + Novo projeto

            </a>

        </section>


        <!-- =====================================
             MENSAGENS
        ====================================== -->

        <?php if ($sucesso): ?>

            <div class="alert-success">
                <?= escapar($sucesso) ?>
            </div>

        <?php endif; ?>


        <?php if ($erro): ?>

            <div class="alert-error">
                <?= escapar($erro) ?>
            </div>

        <?php endif; ?>


        <!-- =====================================
             FORMULÁRIO DE CADASTRO / EDIÇÃO
        ====================================== -->

        <section class="project-form-card" id="formulario">

            <h3>

                <?= $projetoEdicao
                    ? 'Editar projeto'
                    : 'Cadastrar novo projeto' ?>

            </h3>

            <form
                method="POST"
                class="project-form"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= escapar($csrfToken) ?>"
                >

                <?php if ($projetoEdicao): ?>

                    <input
                        type="hidden"
                        name="acao"
                        value="editar"
                    >

                    <input
                        type="hidden"
                        name="id_projeto"
                        value="<?= (int) $projetoEdicao['id'] ?>"
                    >

                <?php else: ?>

                    <input
                        type="hidden"
                        name="acao"
                        value="criar"
                    >

                <?php endif; ?>


                <div class="form-group">

                    <label for="nome">
                        Nome do projeto
                    </label>

                    <input
                        type="text"
                        name="nome"
                        id="nome"
                        maxlength="150"
                        placeholder="Ex.: Desenvolvimento do site"
                        value="<?= escapar(
                            $projetoEdicao['nome']
                            ?? $nomeFormulario
                        ) ?>"
                        required
                    >

                </div>


                <div class="form-actions">

                    <button
                        type="submit"
                        class="btn-submit"
                    >

                        <?= $projetoEdicao
                            ? 'Salvar alterações'
                            : 'Cadastrar projeto' ?>

                    </button>

                    <?php if ($projetoEdicao): ?>

                        <a
                            href="projetos.php"
                            class="btn-cancel"
                        >
                            Cancelar
                        </a>

                    <?php endif; ?>

                </div>

            </form>

        </section>


        <!-- =====================================
             LISTAGEM DOS PROJETOS
        ====================================== -->

        <section class="content-card">

            <div class="card-header">

                <div>

                    <h3>Projetos cadastrados</h3>

                    <p>
                        <?= $totalProjetos ?>
                        projeto(s) encontrado(s).
                    </p>

                </div>

            </div>


            <!-- PESQUISA -->

            <form
                method="GET"
                class="search-form"
            >

                <input
                    type="search"
                    name="busca"
                    placeholder="Pesquisar projeto pelo nome..."
                    value="<?= escapar($busca) ?>"
                >

                <button
                    type="submit"
                    class="btn-search"
                >
                    Pesquisar
                </button>

                <?php if ($busca !== ''): ?>

                    <a
                        href="projetos.php"
                        class="btn-cancel"
                    >
                        Limpar
                    </a>

                <?php endif; ?>

            </form>


            <!-- TABELA -->

            <div class="projects-table-wrapper">

                <table class="projects-table">

                    <thead>

                        <tr>

                            <th>Projeto</th>

                            <th>Tarefas</th>

                            <th>Progresso</th>

                            <th>Ações</th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php if (empty($projetos)): ?>

                            <tr>

                                <td colspan="4">

                                    <div class="empty-state">

                                        <span>▣</span>

                                        <?php if ($busca !== ''): ?>

                                            <p>
                                                Nenhum projeto encontrado para essa pesquisa.
                                            </p>

                                        <?php else: ?>

                                            <p>
                                                Nenhum projeto cadastrado.
                                            </p>

                                            <p>
                                                Cadastre seu primeiro projeto acima.
                                            </p>

                                        <?php endif; ?>

                                    </div>

                                </td>

                            </tr>

                        <?php else: ?>


                            <?php foreach ($projetos as $projeto): ?>

                                <?php

                                    $totalTarefasProjeto = (int)
                                        $projeto['total_tarefas'];

                                    $tarefasConcluidasProjeto = (int)
                                        $projeto['tarefas_concluidas'];

                                    $progresso = $totalTarefasProjeto > 0
                                        ? round(
                                            ($tarefasConcluidasProjeto /
                                            $totalTarefasProjeto) * 100
                                        )
                                        : 0;

                                ?>


                                <tr>

                                    <!-- NOME -->

                                    <td>

                                        <div class="project-name">

                                            <div class="project-name-icon">
                                                ▣
                                            </div>

                                            <div>

                                                <strong>
                                                    <?= escapar($projeto['nome']) ?>
                                                </strong>

                                                <small>
                                                    Projeto #<?= (int) $projeto['id'] ?>
                                                </small>

                                            </div>

                                        </div>

                                    </td>


                                    <!-- TAREFAS -->

                                    <td>

                                        <span class="task-count">

                                            <?= $totalTarefasProjeto ?>

                                            tarefa(s)

                                        </span>

                                    </td>


                                    <!-- PROGRESSO -->

                                    <td>

                                        <div class="progress-container">

                                            <div class="progress-text">

                                                <?= $progresso ?>%

                                            </div>

                                            <div class="progress-bar">

                                                <div
                                                    class="progress-fill"
                                                    style="width: <?= $progresso ?>%"
                                                ></div>

                                            </div>

                                        </div>

                                    </td>


                                    <!-- AÇÕES -->

                                    <td>

                                        <div class="project-actions">

                                            <a
                                                href="tarefas.php?projeto=<?= (int) $projeto['id'] ?>"
                                                class="action-link action-access"
                                            >
                                                Acessar
                                            </a>

                                            <a
                                                href="projetos.php?editar=<?= (int) $projeto['id'] ?>#formulario"
                                                class="action-link action-edit"
                                            >
                                                Editar
                                            </a>

                                            <form
                                                method="POST"
                                                class="delete-form"
                                                onsubmit="return confirm('Tem certeza de que deseja excluir este projeto? Todas as tarefas e categorias relacionadas também serão excluídas.');"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= escapar($csrfToken) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="acao"
                                                    value="excluir"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="id_projeto"
                                                    value="<?= (int) $projeto['id'] ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="action-link action-delete"
                                                >
                                                    Excluir
                                                </button>

                                            </form>

                                        </div>

                                    </td>

                                </tr>


                            <?php endforeach; ?>


                        <?php endif; ?>

                    </tbody>

                </table>

            </div>


            <!-- =================================
                 PAGINAÇÃO
            ================================== -->

            <?php if ($totalPaginas > 1): ?>

                <div class="pagination">

                    <?php if ($pagina > 1): ?>

                        <a href="?pagina=<?= $pagina - 1 ?>&busca=<?= urlencode($busca) ?>">
                            ← Anterior
                        </a>

                    <?php endif; ?>


                    <?php for (
                        $i = 1;
                        $i <= $totalPaginas;
                        $i++
                    ): ?>

                        <?php if (
                            $i === 1 ||
                            $i === $totalPaginas ||
                            abs($i - $pagina) <= 2
                        ): ?>

                            <?php if ($i === $pagina): ?>

                                <span class="current">
                                    <?= $i ?>
                                </span>

                            <?php else: ?>

                                <a href="?pagina=<?= $i ?>&busca=<?= urlencode($busca) ?>">
                                    <?= $i ?>
                                </a>

                            <?php endif; ?>

                        <?php elseif (
                            abs($i - $pagina) === 3
                        ): ?>

                            <span>...</span>

                        <?php endif; ?>

                    <?php endfor; ?>


                    <?php if ($pagina < $totalPaginas): ?>

                        <a href="?pagina=<?= $pagina + 1 ?>&busca=<?= urlencode($busca) ?>">
                            Próxima →
                        </a>

                    <?php endif; ?>

                </div>

            <?php endif; ?>


            <p class="pagination-info">

                <?php if ($totalProjetos > 0): ?>

                    Exibindo

                    <?= min($offset + 1, $totalProjetos) ?>

                    até

                    <?= min($offset + $limite, $totalProjetos) ?>

                    de

                    <?= $totalProjetos ?>

                    projeto(s).

                <?php else: ?>

                    Nenhum resultado para exibir.

                <?php endif; ?>

            </p>


        </section>


        <!-- =====================================
             RODAPÉ
        ====================================== -->

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
