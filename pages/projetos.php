
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

function tamanhoTexto(string $texto): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($texto, 'UTF-8')
        : strlen($texto);
}

// =============================================
// FILTROS DE ACESSO E PERMISSÕES
// =============================================

// Visibilidade: criador, gestor ou participante.
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

$parametrosAcesso = [
    $usuarioId,
    $usuarioId,
    $usuarioId
];

// Permissões de gerenciamento: somente criador ou gestor.
$filtroGerenciamento = "(
    p.id_usuario_criador = ?
    OR EXISTS (
        SELECT 1
        FROM gestores_projeto gp
        WHERE gp.id_projeto = p.id
          AND gp.id_usuario = ?
    )
)";

$parametrosGerenciamento = [
    $usuarioId,
    $usuarioId
];

// =============================================
// VARIÁVEIS INICIAIS
// =============================================

$erro = '';
$sucesso = '';
$projetoEdicao = null;
$nomeFormulario = '';

$busca = trim((string) ($_GET['busca'] ?? ''));

$pagina = filter_input(INPUT_GET, 'pagina', FILTER_VALIDATE_INT);
$pagina = max(1, (int) $pagina);

$limite = 10;
$totalProjetos = 0;
$totalPaginas = 1;
$offset = 0;
$projetos = [];

// =============================================
// MENSAGENS DE SUCESSO
// =============================================

if (isset($_GET['sucesso'])) {
    $mensagens = [
        'criado' => 'Projeto cadastrado com sucesso!',
        'editado' => 'Projeto atualizado com sucesso!',
        'excluido' => 'Projeto excluído com sucesso!'
    ];

    $sucesso = $mensagens[$_GET['sucesso']] ?? '';
}

// =============================================
// PROCESSAMENTO DE FORMULÁRIOS
// =============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCSRF();

    $acao = $_POST['acao'] ?? '';

    // =========================================
    // CRIAR PROJETO
    // =========================================

    if ($acao === 'criar') {
        $nome = trim((string) ($_POST['nome'] ?? ''));

        if ($nome === '' || tamanhoTexto($nome) > 150) {
            $erro = 'Informe um nome válido de até 150 caracteres.';
            $nomeFormulario = $nome;
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    'INSERT INTO projetos (nome, id_usuario_criador)
                     VALUES (?, ?)'
                );

                $stmt->execute([
                    $nome,
                    $usuarioId
                ]);

                $idNovoProjeto = (int) $pdo->lastInsertId();

                // O criador também é registrado como participante do projeto.
                $stmtParticipante = $pdo->prepare(
                    'INSERT INTO participantes_projeto (id_usuario, id_projeto)
                     VALUES (?, ?)'
                );

                $stmtParticipante->execute([
                    $usuarioId,
                    $idNovoProjeto
                ]);

                $pdo->commit();

                header('Location: projetos.php?sucesso=criado');
                exit;

            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log($e->getMessage());

                $erro = 'Não foi possível cadastrar o projeto. Verifique se a tabela participantes_projeto foi criada.';
                $nomeFormulario = $nome;
            }
        }

    // =========================================
    // EDITAR OU EXCLUIR PROJETO
    // =========================================

    } elseif ($acao === 'editar' || $acao === 'excluir') {
        $idProjeto = filter_var(
            $_POST['id_projeto'] ?? null,
            FILTER_VALIDATE_INT
        );

        if (!$idProjeto || $idProjeto <= 0) {
            $erro = 'Projeto inválido.';
        } else {
            try {
                // Verifica se o usuário pode gerenciar o projeto.
                $stmt = $pdo->prepare(
                    "SELECT p.id
                     FROM projetos p
                     WHERE p.id = ?
                       AND $filtroGerenciamento
                     LIMIT 1"
                );

                $stmt->execute(
                    array_merge(
                        [$idProjeto],
                        $parametrosGerenciamento
                    )
                );

                if (!$stmt->fetchColumn()) {
                    $erro = 'Somente o criador ou um gestor pode '
                        . ($acao === 'editar' ? 'editar' : 'excluir')
                        . ' este projeto.';

                // =================================
                // EDITAR PROJETO
                // =================================

                } elseif ($acao === 'editar') {
                    $nome = trim((string) ($_POST['nome'] ?? ''));

                    if ($nome === '' || tamanhoTexto($nome) > 150) {
                        $erro = 'Informe um nome válido de até 150 caracteres.';

                        $projetoEdicao = [
                            'id' => $idProjeto,
                            'nome' => $nome
                        ];

                    } else {
                        $stmt = $pdo->prepare(
                            'UPDATE projetos
                             SET nome = ?
                             WHERE id = ?'
                        );

                        $stmt->execute([
                            $nome,
                            $idProjeto
                        ]);

                        header('Location: projetos.php?sucesso=editado');
                        exit;
                    }

                // =================================
                // EXCLUIR PROJETO
                // =================================

                } else {
                    $stmt = $pdo->prepare(
                        'DELETE FROM projetos
                         WHERE id = ?'
                    );

                    $stmt->execute([$idProjeto]);

                    header('Location: projetos.php?sucesso=excluido');
                    exit;
                }

            } catch (PDOException $e) {
                error_log($e->getMessage());

                $erro = 'Não foi possível concluir a operação.';
            }
        }
    }
}

// =============================================
// CARREGAR PROJETO PARA EDIÇÃO
// =============================================

if (isset($_GET['editar']) && !$projetoEdicao) {
    $idEditar = filter_var(
        $_GET['editar'],
        FILTER_VALIDATE_INT
    );

    if (!$idEditar || $idEditar <= 0) {
        $erro = 'Projeto inválido.';
    } else {
        try {
            $stmt = $pdo->prepare(
                "SELECT p.id, p.nome
                 FROM projetos p
                 WHERE p.id = ?
                   AND $filtroGerenciamento
                 LIMIT 1"
            );

            $stmt->execute(
                array_merge(
                    [$idEditar],
                    $parametrosGerenciamento
                )
            );

            $projetoEdicao = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if (!$projetoEdicao) {
                $erro = 'Projeto não encontrado ou você não tem permissão para editá-lo.';
            }

        } catch (PDOException $e) {
            error_log($e->getMessage());

            $erro = 'Não foi possível carregar o projeto para edição.';
        }
    }
}

// =============================================
// BUSCAR E LISTAR PROJETOS
// =============================================

try {
    $filtroBusca = '';
    $parametrosConsulta = $parametrosAcesso;

    if ($busca !== '') {
        $filtroBusca = ' AND p.nome LIKE ?';

        $parametrosConsulta[] = '%' . $busca . '%';
    }

    // =========================================
    // CONTAR PROJETOS
    // =========================================

    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM projetos p
         WHERE $filtroProjetos
         $filtroBusca"
    );

    $stmt->execute($parametrosConsulta);

    $totalProjetos = (int) $stmt->fetchColumn();

    // =========================================
    // PAGINAÇÃO
    // =========================================

    $totalPaginas = max(
        1,
        (int) ceil($totalProjetos / $limite)
    );

    $pagina = min($pagina, $totalPaginas);

    $offset = ($pagina - 1) * $limite;

    // =========================================
    // CONSULTA DOS PROJETOS
    // =========================================

    $sql = "SELECT
                p.id,
                p.nome,
                p.id_usuario_criador,

                (
                    SELECT COUNT(*)
                    FROM tarefas t
                    WHERE t.id_projeto = p.id
                ) AS total_tarefas,

                CASE
                    WHEN p.id_usuario_criador = ?
                    OR EXISTS (
                        SELECT 1
                        FROM gestores_projeto gp
                        WHERE gp.id_projeto = p.id
                          AND gp.id_usuario = ?
                    )
                    THEN 1
                    ELSE 0
                END AS pode_gerenciar

            FROM projetos p

            WHERE $filtroProjetos
            $filtroBusca

            ORDER BY p.id DESC
            LIMIT $limite OFFSET $offset";

    $stmt = $pdo->prepare($sql);

    $stmt->execute(
        array_merge(
            [$usuarioId, $usuarioId],
            $parametrosConsulta
        )
    );

    $projetos = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log($e->getMessage());

    $erro = 'Não foi possível carregar os projetos. Confirme se a tabela participantes_projeto existe.';

    $projetos = [];
}

// =============================================
// TOKEN CSRF
// =============================================

$csrfToken = $_SESSION['csrf_token'] ?? '';

?>

<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= (($_SESSION['tema'] ?? 'claro') === 'escuro') ? 'escuro' : 'claro' ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Projetos | TarefAí</title>

    <link rel="stylesheet" href="../css/dashboard.css">

    <style>
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
            color: var(--text-secondary);
            font-size: 14px;
        }

        .project-form-card {
            background: var(--white);
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 25px;
            border: 1px solid var(--border);
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

        .project-form input,
        .search-form input {
            width: 100%;
            background: var(--input-background);
            color: var(--text);
            padding: 12px 14px;
            border: 1px solid var(--border-strong);
            border-radius: 9px;
            outline: none;
            font-size: 14px;
        }

        .project-form input:focus,
        .search-form input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 91, 219, .1);
        }

        .form-actions,
        .project-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-submit {
            background: var(--primary);
            color: #fff;
            border: 0;
            border-radius: 9px;
            padding: 12px 20px;
            cursor: pointer;
            font-weight: 600;
        }

        .btn-cancel {
            background: var(--surface-soft);
            color: var(--text-secondary);
            padding: 12px 17px;
            border-radius: 9px;
            text-decoration: none;
            font-size: 14px;
        }

        .search-form {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .search-form input {
            flex: 1;
            min-width: 0;
        }

        .btn-search {
            background: var(--primary-dark);
            color: #fff;
            border: 0;
            padding: 12px 19px;
            border-radius: 9px;
            cursor: pointer;
        }

        .projects-table-wrapper {
            overflow-x: auto;
        }

        .projects-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 560px;
        }

        .projects-table th {
            background: var(--surface-muted);
            color: var(--text-secondary);
            text-align: left;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .4px;
            padding: 15px;
        }

        .projects-table td {
            padding: 17px 15px;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
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
            background: var(--surface-primary);
            color: var(--primary);
            font-size: 18px;
        }

        .project-name strong {
            color: var(--text);
        }

        .project-name small {
            display: block;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .action-link {
            display: inline-block;
            padding: 7px 10px;
            border-radius: 7px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
        }

        .action-access {
            background: var(--success-bg);
            color: var(--success-text);
        }

        .action-edit {
            background: var(--info-bg);
            color: var(--primary);
        }

        .action-delete {
            background: var(--danger-bg);
            color: var(--danger-text);
            border: 0;
            cursor: pointer;
        }

        .delete-form {
            display: inline;
        }

        .permission-label {
            font-size: 11px;
            color: var(--text-secondary);
            white-space: nowrap;
        }

        .alert-success,
        .alert-error {
            padding: 14px 17px;
            border-radius: 9px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert-success {
            color: var(--success-text);
            background: var(--success-bg);
        }

        .alert-error {
            color: var(--danger-text);
            background: var(--danger-bg);
        }

        .empty-state {
            text-align: center;
            padding: 45px 20px;
            color: var(--text-secondary);
        }

        .empty-state span {
            font-size: 35px;
            display: block;
            margin-bottom: 12px;
        }

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
            background: var(--surface-soft);
            color: var(--text-secondary);
        }

        .pagination .current {
            background: var(--primary);
            color: #fff;
            font-weight: 600;
        }

        .pagination-info {
            color: var(--text-secondary);
            text-align: center;
            font-size: 13px;
            margin-top: 15px;
        }

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

            .form-actions > * {
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
         BARRA LATERAL
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

            <div>
                <span class="topbar-label">GERENCIAMENTO</span>
                <h1>Projetos</h1>
            </div>

        </header>

        <!-- =================================
             TÍTULO DA PÁGINA
        ================================== -->

        <section class="projects-toolbar">

            <div>
                <h2>Seus projetos</h2>

                <p>
                    Projetos que você criou, gerencia ou dos quais participa.
                </p>
            </div>

        </section>

        <!-- =================================
             MENSAGENS
        ================================== -->

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

        <!-- =================================
             FORMULÁRIO DE PROJETO
        ================================== -->

        <section class="project-form-card" id="formulario">

            <h3>
                <?= $projetoEdicao ? 'Editar projeto' : 'Cadastrar novo projeto' ?>
            </h3>

            <form method="POST" class="project-form">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= escapar($csrfToken) ?>"
                >

                <?php if ($projetoEdicao): ?>

                    <input type="hidden" name="acao" value="editar">

                    <input
                        type="hidden"
                        name="id_projeto"
                        value="<?= (int) $projetoEdicao['id'] ?>"
                    >

                <?php else: ?>

                    <input type="hidden" name="acao" value="criar">

                <?php endif; ?>

                <div class="form-group">

                    <label for="nome">Nome do projeto</label>

                    <input
                        type="text"
                        name="nome"
                        id="nome"
                        maxlength="150"
                        placeholder="Ex.: Desenvolvimento do site"
                        value="<?= escapar($projetoEdicao['nome'] ?? $nomeFormulario) ?>"
                        required
                    >

                </div>

                <div class="form-actions">

                    <button type="submit" class="btn-submit">
                        <?= $projetoEdicao ? 'Salvar alterações' : 'Cadastrar projeto' ?>
                    </button>

                    <?php if ($projetoEdicao): ?>

                        <a href="projetos.php" class="btn-cancel">
                            Cancelar
                        </a>

                    <?php endif; ?>

                </div>

            </form>

        </section>

        <!-- =================================
             LISTAGEM DE PROJETOS
        ================================== -->

        <section class="content-card">

            <div class="card-header">

                <div>
                    <h3>Projetos cadastrados</h3>

                    <p>
                        <?= $totalProjetos ?> projeto(s) encontrado(s).
                    </p>
                </div>

            </div>

            <!-- PESQUISA -->

            <form method="GET" class="search-form">

                <input
                    type="search"
                    name="busca"
                    placeholder="Pesquisar projeto pelo nome..."
                    value="<?= escapar($busca) ?>"
                >

                <button type="submit" class="btn-search">
                    Pesquisar
                </button>

                <?php if ($busca !== ''): ?>

                    <a href="projetos.php" class="btn-cancel">
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
                            <th>Ações</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if (!$projetos): ?>

                            <tr>
                                <td colspan="3">

                                    <div class="empty-state">

                                        <span>▣</span>

                                        <p>
                                            <?= $busca !== ''
                                                ? 'Nenhum projeto encontrado para essa pesquisa.'
                                                : 'Nenhum projeto cadastrado.' ?>
                                        </p>

                                    </div>

                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($projetos as $projeto): ?>

                                <?php
                                $totalTarefasProjeto = (int) $projeto['total_tarefas'];
                                ?>

                                <tr>

                                    <!-- NOME DO PROJETO -->

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

                                    <!-- TOTAL DE TAREFAS -->

                                    <td>
                                        <?= $totalTarefasProjeto ?> tarefa(s)
                                    </td>

                                    <!-- AÇÕES -->

                                    <td>

                                        <div class="project-actions">

                                            <a
                                                href="projeto.php?id=<?= (int) $projeto['id'] ?>"
                                                class="action-link action-access"
                                            >
                                                Acessar
                                            </a>

                                            <?php if ((int) $projeto['pode_gerenciar'] === 1): ?>

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

                                            <?php else: ?>

                                                <span class="permission-label">
                                                    Participante
                                                </span>

                                            <?php endif; ?>

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

                    <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>

                        <?php if (
                            $i === 1
                            || $i === $totalPaginas
                            || abs($i - $pagina) <= 2
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

                        <?php elseif (abs($i - $pagina) === 3): ?>

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

            <!-- INFORMAÇÕES DA PAGINAÇÃO -->

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

        <!-- =================================
             RODAPÉ
        ================================== -->

        <footer class="dashboard-footer">

            <span>
                © <?= date('Y') ?> TarefAí. Gestão de tarefas em equipe.
            </span>

            <span>
                Acesso: <?= $isAdmin ? 'Administrador' : 'Usuário comum' ?>
            </span>

        </footer>

    </main>

</div>

</body>
</html>
