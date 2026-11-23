
<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/database/connection.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/auth.php';

exigirLogin();

$usuarioId = (int) $_SESSION['usuario_id'];

/*
|--------------------------------------------------------------------------
| Funções auxiliares
|--------------------------------------------------------------------------
*/

function tarefasEscapar($valor)
{
    return htmlspecialchars(
        (string) $valor,
        ENT_QUOTES,
        'UTF-8'
    );
}

function tarefasRedirecionar($projetoId, $mensagem = null, $tipo = 'sucesso')
{
    $url = 'tarefas.php?projeto=' . (int) $projetoId;

    if ($mensagem !== null) {
        $url .= '&mensagem=' . urlencode($mensagem);
        $url .= '&tipo=' . urlencode($tipo);
    }

    header('Location: ' . $url);
    exit;
}

/*
|--------------------------------------------------------------------------
| Validação do projeto
|--------------------------------------------------------------------------
*/

$idProjeto = filter_input(
    INPUT_GET,
    'projeto',
    FILTER_VALIDATE_INT
);

if (!$idProjeto || $idProjeto <= 0) {
    http_response_code(400);
    exit('Projeto inválido.');
}

/*
 * O usuário só pode acessar o projeto se for seu criador
 * ou estiver cadastrado como gestor.
 */
$sqlProjeto = "
    SELECT
        p.id,
        p.nome,
        p.id_usuario_criador
    FROM projetos p
    WHERE p.id = ?
      AND (
          p.id_usuario_criador = ?
          OR EXISTS (
              SELECT 1
              FROM gestores_projeto gp
              WHERE gp.id_projeto = p.id
                AND gp.id_usuario = ?
          )
      )
    LIMIT 1
";

$stmtProjeto = $pdo->prepare($sqlProjeto);
$stmtProjeto->execute([
    $idProjeto,
    $usuarioId,
    $usuarioId
]);

$projeto = $stmtProjeto->fetch();

if (!$projeto) {
    http_response_code(403);
    exit('Você não tem acesso a este projeto.');
}

/*
|--------------------------------------------------------------------------
| Dados auxiliares
|--------------------------------------------------------------------------
*/

// Categorias pertencentes ao projeto.
$stmtCategorias = $pdo->prepare("
    SELECT id, nome, cor
    FROM categorias
    WHERE id_projeto = ?
    ORDER BY nome ASC
");

$stmtCategorias->execute([$idProjeto]);
$categorias = $stmtCategorias->fetchAll();

// Usuários que participam do projeto:
// criador e gestores cadastrados.
$stmtEquipe = $pdo->prepare("
    SELECT DISTINCT
        u.id,
        u.nome,
        u.email
    FROM usuarios u
    WHERE
        u.id = ?
        OR u.id IN (
            SELECT gp.id_usuario
            FROM gestores_projeto gp
            WHERE gp.id_projeto = ?
        )
    ORDER BY u.nome ASC
");

$stmtEquipe->execute([
    (int) $projeto['id_usuario_criador'],
    $idProjeto
]);

$equipe = $stmtEquipe->fetchAll();

/*
|--------------------------------------------------------------------------
| Processamento das ações
|--------------------------------------------------------------------------
*/

$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCSRF();

    $acao = $_POST['acao'] ?? '';

    // Confirma novamente o acesso ao projeto no POST.
    $stmtPermissao = $pdo->prepare("
        SELECT id
        FROM projetos
        WHERE id = ?
          AND (
              id_usuario_criador = ?
              OR EXISTS (
                  SELECT 1
                  FROM gestores_projeto gp
                  WHERE gp.id_projeto = projetos.id
                    AND gp.id_usuario = ?
              )
          )
        LIMIT 1
    ");

    $stmtPermissao->execute([
        $idProjeto,
        $usuarioId,
        $usuarioId
    ]);

    if (!$stmtPermissao->fetch()) {
        http_response_code(403);
        exit('Acesso negado.');
    }

    try {

        /*
        |--------------------------------------------------------------------------
        | Criar tarefa
        |--------------------------------------------------------------------------
        */

        if ($acao === 'criar') {
            $nome = trim($_POST['nome'] ?? '');

            $responsaveis = $_POST['responsaveis'] ?? [];
            $categoriasSelecionadas = $_POST['categorias'] ?? [];

            if ($nome === '') {
                throw new Exception('Informe o nome da tarefa.');
            }

            if (mb_strlen($nome, 'UTF-8') > 255) {
                throw new Exception(
                    'O nome da tarefa deve ter no máximo 255 caracteres.'
                );
            }

            if (!is_array($responsaveis) || !is_array($categoriasSelecionadas)) {
                throw new Exception('Dados inválidos.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO tarefas (id_projeto, nome)
                VALUES (?, ?)
            ");

            $stmt->execute([
                $idProjeto,
                $nome
            ]);

            $idTarefa = (int) $pdo->lastInsertId();

            /*
             * Inserir responsáveis.
             * Somente usuários da equipe do projeto podem ser atribuídos.
             */
            $stmtResponsavel = $pdo->prepare("
                INSERT INTO responsaveis (id_usuario, id_tarefa)
                SELECT u.id, ?
                FROM usuarios u
                WHERE u.id = ?
                  AND (
                      u.id = ?
                      OR EXISTS (
                          SELECT 1
                          FROM gestores_projeto gp
                          WHERE gp.id_projeto = ?
                            AND gp.id_usuario = u.id
                      )
                  )
            ");

            foreach (array_unique($responsaveis) as $idResponsavel) {
                $idResponsavel = filter_var(
                    $idResponsavel,
                    FILTER_VALIDATE_INT
                );

                if (!$idResponsavel || $idResponsavel <= 0) {
                    continue;
                }

                $stmtResponsavel->execute([
                    $idTarefa,
                    $idResponsavel,
                    (int) $projeto['id_usuario_criador'],
                    $idProjeto
                ]);
            }

            /*
             * Inserir categorias.
             * A categoria precisa pertencer ao projeto atual.
             */
            $stmtCategoria = $pdo->prepare("
                INSERT INTO categorias_aplicadas (
                    id_tarefa,
                    id_categoria
                )
                SELECT ?, id
                FROM categorias
                WHERE id = ?
                  AND id_projeto = ?
            ");

            foreach (array_unique($categoriasSelecionadas) as $idCategoria) {
                $idCategoria = filter_var(
                    $idCategoria,
                    FILTER_VALIDATE_INT
                );

                if (!$idCategoria || $idCategoria <= 0) {
                    continue;
                }

                $stmtCategoria->execute([
                    $idTarefa,
                    $idCategoria,
                    $idProjeto
                ]);
            }

            $pdo->commit();

            tarefasRedirecionar(
                $idProjeto,
                'Tarefa cadastrada com sucesso!'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Editar tarefa
        |--------------------------------------------------------------------------
        */

        elseif ($acao === 'editar') {
            $idTarefa = filter_var(
                $_POST['id_tarefa'] ?? null,
                FILTER_VALIDATE_INT
            );

            $nome = trim($_POST['nome'] ?? '');

            $responsaveis = $_POST['responsaveis'] ?? [];
            $categoriasSelecionadas = $_POST['categorias'] ?? [];

            if (!$idTarefa || $idTarefa <= 0) {
                throw new Exception('Tarefa inválida.');
            }

            if ($nome === '') {
                throw new Exception('Informe o nome da tarefa.');
            }

            if (mb_strlen($nome, 'UTF-8') > 255) {
                throw new Exception(
                    'O nome da tarefa deve ter no máximo 255 caracteres.'
                );
            }

            if (!is_array($responsaveis) || !is_array($categoriasSelecionadas)) {
                throw new Exception('Dados inválidos.');
            }

            // Confirma que a tarefa pertence ao projeto atual.
            $stmtExiste = $pdo->prepare("
                SELECT id
                FROM tarefas
                WHERE id = ?
                  AND id_projeto = ?
                LIMIT 1
            ");

            $stmtExiste->execute([
                $idTarefa,
                $idProjeto
            ]);

            if (!$stmtExiste->fetch()) {
                throw new Exception('Tarefa não encontrada.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE tarefas
                SET nome = ?
                WHERE id = ?
                  AND id_projeto = ?
            ");

            $stmt->execute([
                $nome,
                $idTarefa,
                $idProjeto
            ]);

            // Substitui os responsáveis anteriores.
            $stmt = $pdo->prepare("
                DELETE FROM responsaveis
                WHERE id_tarefa = ?
            ");

            $stmt->execute([$idTarefa]);

            $stmtResponsavel = $pdo->prepare("
                INSERT INTO responsaveis (id_usuario, id_tarefa)
                SELECT u.id, ?
                FROM usuarios u
                WHERE u.id = ?
                  AND (
                      u.id = ?
                      OR EXISTS (
                          SELECT 1
                          FROM gestores_projeto gp
                          WHERE gp.id_projeto = ?
                            AND gp.id_usuario = u.id
                      )
                  )
            ");

            foreach (array_unique($responsaveis) as $idResponsavel) {
                $idResponsavel = filter_var(
                    $idResponsavel,
                    FILTER_VALIDATE_INT
                );

                if (!$idResponsavel || $idResponsavel <= 0) {
                    continue;
                }

                $stmtResponsavel->execute([
                    $idTarefa,
                    $idResponsavel,
                    (int) $projeto['id_usuario_criador'],
                    $idProjeto
                ]);
            }

            // Substitui as categorias anteriores.
            $stmt = $pdo->prepare("
                DELETE ca
                FROM categorias_aplicadas ca
                INNER JOIN categorias c
                    ON c.id = ca.id_categoria
                WHERE ca.id_tarefa = ?
                  AND c.id_projeto = ?
            ");

            $stmt->execute([
                $idTarefa,
                $idProjeto
            ]);

            $stmtCategoria = $pdo->prepare("
                INSERT INTO categorias_aplicadas (
                    id_tarefa,
                    id_categoria
                )
                SELECT ?, id
                FROM categorias
                WHERE id = ?
                  AND id_projeto = ?
            ");

            foreach (array_unique($categoriasSelecionadas) as $idCategoria) {
                $idCategoria = filter_var(
                    $idCategoria,
                    FILTER_VALIDATE_INT
                );

                if (!$idCategoria || $idCategoria <= 0) {
                    continue;
                }

                $stmtCategoria->execute([
                    $idTarefa,
                    $idCategoria,
                    $idProjeto
                ]);
            }

            $pdo->commit();

            tarefasRedirecionar(
                $idProjeto,
                'Tarefa atualizada com sucesso!'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Excluir tarefa
        |--------------------------------------------------------------------------
        */

        elseif ($acao === 'excluir') {
            $idTarefa = filter_var(
                $_POST['id_tarefa'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$idTarefa || $idTarefa <= 0) {
                throw new Exception('Tarefa inválida.');
            }

            $stmt = $pdo->prepare("
                DELETE FROM tarefas
                WHERE id = ?
                  AND id_projeto = ?
            ");

            $stmt->execute([
                $idTarefa,
                $idProjeto
            ]);

            if ($stmt->rowCount() === 0) {
                throw new Exception('Tarefa não encontrada.');
            }

            tarefasRedirecionar(
                $idProjeto,
                'Tarefa excluída com sucesso!'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Alternar conclusão da tarefa
        |--------------------------------------------------------------------------
        */

        elseif ($acao === 'alternar_status') {
            $idTarefa = filter_var(
                $_POST['id_tarefa'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$idTarefa || $idTarefa <= 0) {
                throw new Exception('Tarefa inválida.');
            }

            $stmt = $pdo->prepare("
                UPDATE tarefas
                SET completo_em = CASE
                    WHEN completo_em IS NULL THEN CURRENT_TIMESTAMP
                    ELSE NULL
                END
                WHERE id = ?
                  AND id_projeto = ?
            ");

            $stmt->execute([
                $idTarefa,
                $idProjeto
            ]);

            if ($stmt->rowCount() === 0) {
                // Pode não haver alteração se a tarefa já estiver no estado
                // solicitado ou não existir. Confirmamos sua existência.
                $stmtExiste = $pdo->prepare("
                    SELECT id
                    FROM tarefas
                    WHERE id = ?
                      AND id_projeto = ?
                ");

                $stmtExiste->execute([
                    $idTarefa,
                    $idProjeto
                ]);

                if (!$stmtExiste->fetch()) {
                    throw new Exception('Tarefa não encontrada.');
                }
            }

            tarefasRedirecionar(
                $idProjeto,
                'Status da tarefa atualizado!'
            );
        }

        else {
            throw new Exception('Ação inválida.');
        }

    } catch (Exception $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $erro = $e->getMessage();
    }
}

/*
|--------------------------------------------------------------------------
| Buscar tarefa para edição
|--------------------------------------------------------------------------
*/

$tarefaEdicao = null;
$responsaveisEdicao = [];
$categoriasEdicao = [];

$idEditar = filter_input(
    INPUT_GET,
    'editar',
    FILTER_VALIDATE_INT
);

if ($idEditar && $idEditar > 0) {
    $stmt = $pdo->prepare("
        SELECT id, nome, completo_em
        FROM tarefas
        WHERE id = ?
          AND id_projeto = ?
        LIMIT 1
    ");

    $stmt->execute([
        $idEditar,
        $idProjeto
    ]);

    $tarefaEdicao = $stmt->fetch();

    if (!$tarefaEdicao) {
        tarefasRedirecionar(
            $idProjeto,
            'Tarefa não encontrada.',
            'erro'
        );
    }

    $stmt = $pdo->prepare("
        SELECT id_usuario
        FROM responsaveis
        WHERE id_tarefa = ?
    ");

    $stmt->execute([$idEditar]);

    $responsaveisEdicao = array_map(
        'intval',
        array_column($stmt->fetchAll(), 'id_usuario')
    );

    $stmt = $pdo->prepare("
        SELECT ca.id_categoria
        FROM categorias_aplicadas ca
        INNER JOIN categorias c
            ON c.id = ca.id_categoria
        WHERE ca.id_tarefa = ?
          AND c.id_projeto = ?
    ");

    $stmt->execute([
        $idEditar,
        $idProjeto
    ]);

    $categoriasEdicao = array_map(
        'intval',
        array_column($stmt->fetchAll(), 'id_categoria')
    );
}

/*
|--------------------------------------------------------------------------
| Pesquisa, filtros e paginação
|--------------------------------------------------------------------------
*/

$busca = trim($_GET['busca'] ?? '');

$filtroStatus = $_GET['status'] ?? 'todas';

$filtroCategoria = filter_input(
    INPUT_GET,
    'categoria',
    FILTER_VALIDATE_INT
);

if (!in_array($filtroStatus, ['todas', 'pendentes', 'concluidas'], true)) {
    $filtroStatus = 'todas';
}

if ($filtroCategoria !== null && $filtroCategoria !== false && $filtroCategoria > 0) {
    $stmt = $pdo->prepare("
        SELECT id
        FROM categorias
        WHERE id = ?
          AND id_projeto = ?
    ");

    $stmt->execute([
        $filtroCategoria,
        $idProjeto
    ]);

    if (!$stmt->fetch()) {
        $filtroCategoria = null;
    }
} else {
    $filtroCategoria = null;
}

$pagina = filter_input(
    INPUT_GET,
    'pagina',
    FILTER_VALIDATE_INT
);

$pagina = ($pagina && $pagina > 0) ? $pagina : 1;

$porPagina = 10;
$offset = ($pagina - 1) * $porPagina;

$where = [
    't.id_projeto = ?'
];

$params = [$idProjeto];

if ($busca !== '') {
    $where[] = 't.nome LIKE ?';
    $params[] = '%' . $busca . '%';
}

if ($filtroStatus === 'pendentes') {
    $where[] = 't.completo_em IS NULL';
} elseif ($filtroStatus === 'concluidas') {
    $where[] = 't.completo_em IS NOT NULL';
}

if ($filtroCategoria !== null) {
    $where[] = "
        EXISTS (
            SELECT 1
            FROM categorias_aplicadas ca
            WHERE ca.id_tarefa = t.id
              AND ca.id_categoria = ?
        )
    ";

    $params[] = $filtroCategoria;
}

$whereSql = implode(' AND ', $where);

// Total de tarefas para paginação.
$stmtTotal = $pdo->prepare("
    SELECT COUNT(*)
    FROM tarefas t
    WHERE $whereSql
");

$stmtTotal->execute($params);

$totalTarefas = (int) $stmtTotal->fetchColumn();

$totalPaginas = max(
    1,
    (int) ceil($totalTarefas / $porPagina)
);

if ($pagina > $totalPaginas) {
    $pagina = $totalPaginas;
    $offset = ($pagina - 1) * $porPagina;
}

// Lista de tarefas.
$sqlTarefas = "
    SELECT
        t.id,
        t.nome,
        t.criado_em,
        t.completo_em,

        (
            SELECT GROUP_CONCAT(
                DISTINCT u.nome
                ORDER BY u.nome
                SEPARATOR ', '
            )
            FROM responsaveis r
            INNER JOIN usuarios u
                ON u.id = r.id_usuario
            WHERE r.id_tarefa = t.id
        ) AS nomes_responsaveis

    FROM tarefas t

    WHERE $whereSql

    ORDER BY
        CASE WHEN t.completo_em IS NULL THEN 0 ELSE 1 END,
        t.criado_em DESC,
        t.id DESC

    LIMIT $porPagina OFFSET $offset
";

$stmtTarefas = $pdo->prepare($sqlTarefas);
$stmtTarefas->execute($params);

$tarefas = $stmtTarefas->fetchAll();

/*
|--------------------------------------------------------------------------
| Categorias de cada tarefa
|--------------------------------------------------------------------------
*/

$categoriasPorTarefa = [];

if (!empty($tarefas)) {
    $idsTarefas = array_map(
        'intval',
        array_column($tarefas, 'id')
    );

    $placeholders = implode(
        ',',
        array_fill(0, count($idsTarefas), '?')
    );

    $stmt = $pdo->prepare("
        SELECT
            ca.id_tarefa,
            c.id,
            c.nome,
            c.cor
        FROM categorias_aplicadas ca
        INNER JOIN categorias c
            ON c.id = ca.id_categoria
        WHERE ca.id_tarefa IN ($placeholders)
          AND c.id_projeto = ?
        ORDER BY c.nome ASC
    ");

    $stmt->execute(
        array_merge($idsTarefas, [$idProjeto])
    );

    foreach ($stmt->fetchAll() as $categoria) {
        $categoriasPorTarefa[$categoria['id_tarefa']][] = $categoria;
    }
}

/*
|--------------------------------------------------------------------------
| Indicadores do projeto
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN completo_em IS NOT NULL THEN 1 ELSE 0 END) AS concluidas,
        SUM(CASE WHEN completo_em IS NULL THEN 1 ELSE 0 END) AS pendentes
    FROM tarefas
    WHERE id_projeto = ?
");

$stmt->execute([$idProjeto]);

$estatisticas = $stmt->fetch();

$totalProjeto = (int) ($estatisticas['total'] ?? 0);
$concluidasProjeto = (int) ($estatisticas['concluidas'] ?? 0);
$pendentesProjeto = (int) ($estatisticas['pendentes'] ?? 0);

$progresso = $totalProjeto > 0
    ? round(($concluidasProjeto / $totalProjeto) * 100)
    : 0;

/*
|--------------------------------------------------------------------------
| Mensagens
|--------------------------------------------------------------------------
*/

$mensagem = $_GET['mensagem'] ?? '';
$tipoMensagem = $_GET['tipo'] ?? 'sucesso';

$csrfToken = $_SESSION['csrf_token'] ?? '';

?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Tarefas - TarefAi</title>

    <link rel="stylesheet" href="../css/dashboard.css">

    <style>
        .page-content {
            padding: 30px;
        }

        .breadcrumb {
            color: #64748b;
            font-size: 14px;
            margin-bottom: 12px;
        }

        .breadcrumb a {
            color: #64748b;
            text-decoration: none;
        }

        .breadcrumb a:hover {
            color: #4f46e5;
        }

        .project-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 25px;
        }

        .project-heading h1 {
            margin: 0 0 6px;
            color: #1e293b;
            font-size: 26px;
        }

        .project-heading p {
            margin: 0;
            color: #64748b;
            font-size: 14px;
        }

        .stats-grid {
            margin-bottom: 25px;
        }

        .task-progress {
            background: #e2e8f0;
            height: 8px;
            border-radius: 20px;
            overflow: hidden;
            margin-top: 12px;
        }

        .task-progress-fill {
            background: #4f46e5;
            height: 100%;
            border-radius: 20px;
            transition: width .3s;
        }

        .task-form-card,
        .task-list-card {
            background: #fff;
            border-radius: 14px;
            padding: 24px;
            box-shadow: 0 2px 12px rgba(15, 23, 42, .05);
            margin-bottom: 25px;
        }

        .card-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 20px;
        }

        .card-heading h2 {
            margin: 0;
            color: #1e293b;
            font-size: 18px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 8px;
        }

        .form-control {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 11px 13px;
            font-size: 14px;
            color: #1e293b;
            background: #fff;
            outline: none;
        }

        .form-control:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, .12);
        }

        .selection-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .selection-item {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 9px 12px;
            border-radius: 8px;
            font-size: 13px;
            color: #475569;
            cursor: pointer;
        }

        .selection-item input {
            accent-color: #4f46e5;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 22px;
        }

        .btn {
            border: none;
            border-radius: 8px;
            padding: 10px 16px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .btn-primary {
            background: #4f46e5;
            color: #fff;
        }

        .btn-primary:hover {
            background: #4338ca;
        }

        .btn-secondary {
            background: #e2e8f0;
            color: #334155;
        }

        .btn-secondary:hover {
            background: #cbd5e1;
        }

        .btn-danger {
            background: #fee2e2;
            color: #b91c1c;
        }

        .btn-danger:hover {
            background: #fecaca;
        }

        .btn-success {
            background: #dcfce7;
            color: #15803d;
        }

        .filter-form {
            display: grid;
            grid-template-columns: minmax(180px, 1fr) 180px 180px auto;
            gap: 10px;
            margin-bottom: 22px;
        }

        .filter-form .form-control {
            min-width: 0;
        }

        .task-item {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
            padding: 18px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .task-item:last-child {
            border-bottom: none;
        }

        .task-main {
            display: flex;
            align-items: flex-start;
            gap: 13px;
            flex: 1;
            min-width: 0;
        }

        .task-checkbox {
            width: 19px;
            height: 19px;
            margin-top: 3px;
            accent-color: #16a34a;
            cursor: pointer;
            flex-shrink: 0;
        }

        .task-info {
            min-width: 0;
        }

        .task-name {
            font-size: 15px;
            font-weight: 600;
            color: #1e293b;
            margin: 0 0 8px;
            overflow-wrap: anywhere;
        }

        .task-name.completed {
            text-decoration: line-through;
            color: #94a3b8;
        }

        .task-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 15px;
            font-size: 12px;
            color: #64748b;
        }

        .task-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 10px;
        }

        .task-tag {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11px;
            padding: 5px 9px;
            border-radius: 20px;
            background: #f1f5f9;
            color: #475569;
        }

        .task-tag-color {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        .task-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 7px;
            flex-shrink: 0;
        }

        .action-link {
            border: none;
            text-decoration: none;
            border-radius: 7px;
            padding: 7px 10px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
        }

        .action-edit {
            background: #e0e7ff;
            color: #4338ca;
        }

        .action-delete {
            background: #fee2e2;
            color: #b91c1c;
        }

        .action-complete {
            background: #dcfce7;
            color: #15803d;
        }

        .status-badge {
            display: inline-block;
            font-size: 11px;
            font-weight: 600;
            padding: 5px 9px;
            border-radius: 20px;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-completed {
            background: #dcfce7;
            color: #166534;
        }

        .alert {
            padding: 13px 16px;
            border-radius: 9px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
        }

        .empty-state {
            text-align: center;
            padding: 45px 15px;
            color: #64748b;
        }

        .empty-state h3 {
            color: #334155;
            margin-bottom: 8px;
        }

        .pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            margin-top: 25px;
            flex-wrap: wrap;
        }

        .pagination a,
        .pagination span {
            padding: 8px 12px;
            border-radius: 7px;
            font-size: 13px;
            text-decoration: none;
            background: #f1f5f9;
            color: #475569;
        }

        .pagination .active {
            background: #4f46e5;
            color: #fff;
        }

        .pagination .disabled {
            opacity: .45;
            pointer-events: none;
        }

        .task-count {
            font-size: 13px;
            color: #64748b;
        }

        @media (max-width: 900px) {
            .filter-form {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 650px) {
            .page-content {
                padding: 18px;
            }

            .project-heading {
                align-items: flex-start;
                flex-direction: column;
            }

            .filter-form {
                grid-template-columns: 1fr;
            }

            .task-item {
                flex-direction: column;
            }

            .task-actions {
                padding-left: 32px;
            }

            .task-form-card,
            .task-list-card {
                padding: 17px;
            }
        }
    </style>
</head>

<body>

<div class="dashboard-container">

    <!-- MENU LATERAL -->

    <aside class="sidebar">

        <div class="sidebar-header">
            <h2>TarefAi</h2>
        </div>

        <nav class="sidebar-nav">

            <a href="dashboard.php" class="nav-item">
                Dashboard
            </a>

            <a href="projetos.php" class="nav-item">
                Projetos
            </a>

            <a href="tarefas.php?projeto=<?= $idProjeto ?>" class="nav-item active">
                Tarefas
            </a>

            <a href="categorias.php" class="nav-item">
                Categorias
            </a>

            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                <a href="usuarios.php" class="nav-item">
                    Usuários
                </a>
            <?php endif; ?>

        </nav>

        <div class="sidebar-footer">
            <a href="logout.php" class="nav-item">
                Sair
            </a>
        </div>

    </aside>

    <!-- CONTEÚDO PRINCIPAL -->

    <main class="main-content">

        <!-- TOPBAR -->

        <header class="topbar">

            <div>
                <span>Gestão de tarefas</span>
            </div>

            <div class="user-info">
                <span>
                    <?= tarefasEscapar($_SESSION['nome'] ?? '') ?>
                </span>
            </div>

        </header>

        <div class="page-content">

            <!-- CABEÇALHO -->

            <div class="breadcrumb">
                <a href="dashboard.php">Dashboard</a>
                /
                <a href="projetos.php">Projetos</a>
                /
                Tarefas
            </div>

            <div class="project-heading">

                <div>
                    <h1>
                        <?= tarefasEscapar($projeto['nome']) ?>
                    </h1>

                    <p>
                        Gerencie as tarefas deste projeto.
                    </p>
                </div>

                <a href="projetos.php" class="btn btn-secondary">
                    Voltar aos projetos
                </a>

            </div>

            <!-- MENSAGENS -->

            <?php if ($mensagem !== ''): ?>

                <div class="alert <?= $tipoMensagem === 'erro' ? 'alert-error' : 'alert-success' ?>">
                    <?= tarefasEscapar($mensagem) ?>
                </div>

            <?php endif; ?>

            <?php if ($erro !== null): ?>

                <div class="alert alert-error">
                    <?= tarefasEscapar($erro) ?>
                </div>

            <?php endif; ?>

            <!-- INDICADORES -->

            <section class="stats-grid">

                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Total de tarefas</h3>
                        <p><?= $totalProjeto ?></p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Concluídas</h3>
                        <p><?= $concluidasProjeto ?></p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Pendentes</h3>
                        <p><?= $pendentesProjeto ?></p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Progresso</h3>
                        <p><?= $progresso ?>%</p>

                        <div class="task-progress">
                            <div
                                class="task-progress-fill"
                                style="width: <?= $progresso ?>%"
                            ></div>
                        </div>
                    </div>
                </div>

            </section>

            <!-- FORMULÁRIO DE CADASTRO / EDIÇÃO -->

            <section class="task-form-card">

                <div class="card-heading">

                    <h2>
                        <?= $tarefaEdicao ? 'Editar tarefa' : 'Nova tarefa' ?>
                    </h2>

                </div>

                <form method="POST" action="tarefas.php?projeto=<?= $idProjeto ?>">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= tarefasEscapar($csrfToken) ?>"
                    >

                    <input
                        type="hidden"
                        name="acao"
                        value="<?= $tarefaEdicao ? 'editar' : 'criar' ?>"
                    >

                    <?php if ($tarefaEdicao): ?>

                        <input
                            type="hidden"
                            name="id_tarefa"
                            value="<?= (int) $tarefaEdicao['id'] ?>"
                        >

                    <?php endif; ?>

                    <div class="form-group">

                        <label for="nome">
                            Nome da tarefa
                        </label>

                        <input
                            type="text"
                            id="nome"
                            name="nome"
                            class="form-control"
                            maxlength="255"
                            placeholder="Ex.: Desenvolver tela de login"
                            value="<?= tarefasEscapar($tarefaEdicao['nome'] ?? '') ?>"
                            required
                        >

                    </div>

                    <div class="form-group">

                        <label>
                            Responsáveis
                        </label>

                        <?php if (empty($equipe)): ?>

                            <p>Nenhum participante disponível.</p>

                        <?php else: ?>

                            <div class="selection-grid">

                                <?php foreach ($equipe as $membro): ?>

                                    <label class="selection-item">

                                        <input
                                            type="checkbox"
                                            name="responsaveis[]"
                                            value="<?= (int) $membro['id'] ?>"
                                            <?= in_array(
                                                (int) $membro['id'],
                                                $responsaveisEdicao,
                                                true
                                            ) ? 'checked' : '' ?>
                                        >

                                        <?= tarefasEscapar($membro['nome']) ?>

                                    </label>

                                <?php endforeach; ?>

                            </div>

                        <?php endif; ?>

                    </div>

                    <div class="form-group">

                        <label>
                            Categorias
                        </label>

                        <?php if (empty($categorias)): ?>

                            <p>
                                Nenhuma categoria cadastrada neste projeto.
                                Você pode cadastrar categorias na página de categorias.
                            </p>

                        <?php else: ?>

                            <div class="selection-grid">

                                <?php foreach ($categorias as $categoria): ?>

                                    <label class="selection-item">

                                        <input
                                            type="checkbox"
                                            name="categorias[]"
                                            value="<?= (int) $categoria['id'] ?>"
                                            <?= in_array(
                                                (int) $categoria['id'],
                                                $categoriasEdicao,
                                                true
                                            ) ? 'checked' : '' ?>
                                        >

                                        <span
                                            class="task-tag-color"
                                            style="background: <?= tarefasEscapar($categoria['cor']) ?>"
                                        ></span>

                                        <?= tarefasEscapar($categoria['nome']) ?>

                                    </label>

                                <?php endforeach; ?>

                            </div>

                        <?php endif; ?>

                    </div>

                    <div class="form-actions">

                        <button type="submit" class="btn btn-primary">
                            <?= $tarefaEdicao ? 'Salvar alterações' : 'Cadastrar tarefa' ?>
                        </button>

                        <?php if ($tarefaEdicao): ?>

                            <a
                                href="tarefas.php?projeto=<?= $idProjeto ?>"
                                class="btn btn-secondary"
                            >
                                Cancelar edição
                            </a>

                        <?php endif; ?>

                    </div>

                </form>

            </section>

            <!-- LISTAGEM DE TAREFAS -->

            <section class="task-list-card">

                <div class="card-heading">

                    <h2>Lista de tarefas</h2>

                    <span class="task-count">
                        <?= $totalTarefas ?>
                        <?= $totalTarefas === 1 ? 'resultado' : 'resultados' ?>
                    </span>

                </div>

                <!-- FILTROS -->

                <form
                    method="GET"
                    action="tarefas.php"
                    class="filter-form"
                >

                    <input
                        type="hidden"
                        name="projeto"
                        value="<?= $idProjeto ?>"
                    >

                    <input
                        type="text"
                        name="busca"
                        class="form-control"
                        placeholder="Pesquisar tarefa..."
                        value="<?= tarefasEscapar($busca) ?>"
                    >

                    <select
                        name="status"
                        class="form-control"
                    >

                        <option
                            value="todas"
                            <?= $filtroStatus === 'todas' ? 'selected' : '' ?>
                        >
                            Todos os status
                        </option>

                        <option
                            value="pendentes"
                            <?= $filtroStatus === 'pendentes' ? 'selected' : '' ?>
                        >
                            Pendentes
                        </option>

                        <option
                            value="concluidas"
                            <?= $filtroStatus === 'concluidas' ? 'selected' : '' ?>
                        >
                            Concluídas
                        </option>

                    </select>

                    <select
                        name="categoria"
                        class="form-control"
                    >

                        <option value="">
                            Todas as categorias
                        </option>

                        <?php foreach ($categorias as $categoria): ?>

                            <option
                                value="<?= (int) $categoria['id'] ?>"
                                <?= $filtroCategoria === (int) $categoria['id'] ? 'selected' : '' ?>
                            >
                                <?= tarefasEscapar($categoria['nome']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                    <button type="submit" class="btn btn-primary">
                        Filtrar
                    </button>

                </form>

                <?php if (empty($tarefas)): ?>

                    <div class="empty-state">

                        <h3>Nenhuma tarefa encontrada</h3>

                        <p>
                            Cadastre uma tarefa ou altere os filtros da pesquisa.
                        </p>

                    </div>

                <?php else: ?>

                    <?php foreach ($tarefas as $tarefa): ?>

                        <?php
                            $concluida = $tarefa['completo_em'] !== null;
                        ?>

                        <article class="task-item">

                            <div class="task-main">

                                <!-- CHECKBOX DE CONCLUSÃO -->

                                <form
                                    method="POST"
                                    action="tarefas.php?projeto=<?= $idProjeto ?>"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= tarefasEscapar($csrfToken) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="acao"
                                        value="alternar_status"
                                    >

                                    <input
                                        type="hidden"
                                        name="id_tarefa"
                                        value="<?= (int) $tarefa['id'] ?>"
                                    >

                                    <input
                                        type="checkbox"
                                        class="task-checkbox"
                                        <?= $concluida ? 'checked' : '' ?>
                                        onchange="this.form.submit()"
                                        aria-label="Alterar status da tarefa"
                                    >

                                </form>

                                <div class="task-info">

                                    <h3 class="task-name <?= $concluida ? 'completed' : '' ?>">
                                        <?= tarefasEscapar($tarefa['nome']) ?>
                                    </h3>

                                    <div class="task-meta">

                                        <span>
                                            Criada em:
                                            <?= date(
                                                'd/m/Y H:i',
                                                strtotime($tarefa['criado_em'])
                                            ) ?>
                                        </span>

                                        <span>
                                            Responsáveis:
                                            <?= tarefasEscapar(
                                                $tarefa['nomes_responsaveis'] ?: 'Não definidos'
                                            ) ?>
                                        </span>

                                    </div>

                                    <div class="task-tags">

                                        <span class="status-badge <?= $concluida ? 'status-completed' : 'status-pending' ?>">
                                            <?= $concluida ? 'Concluída' : 'Pendente' ?>
                                        </span>

                                        <?php
                                            $tags = $categoriasPorTarefa[$tarefa['id']] ?? [];
                                        ?>

                                        <?php foreach ($tags as $tag): ?>

                                            <span class="task-tag">

                                                <span
                                                    class="task-tag-color"
                                                    style="background: <?= tarefasEscapar($tag['cor']) ?>"
                                                ></span>

                                                <?= tarefasEscapar($tag['nome']) ?>

                                            </span>

                                        <?php endforeach; ?>

                                    </div>

                                </div>

                            </div>

                            <!-- AÇÕES -->

                            <div class="task-actions">

                                <a
                                    href="tarefas.php?projeto=<?= $idProjeto ?>&editar=<?= (int) $tarefa['id'] ?>"
                                    class="action-link action-edit"
                                >
                                    Editar
                                </a>

                                <form
                                    method="POST"
                                    action="tarefas.php?projeto=<?= $idProjeto ?>"
                                    onsubmit="return confirm('Tem certeza de que deseja excluir esta tarefa?');"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= tarefasEscapar($csrfToken) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="acao"
                                        value="excluir"
                                    >

                                    <input
                                        type="hidden"
                                        name="id_tarefa"
                                        value="<?= (int) $tarefa['id'] ?>"
                                    >

                                    <button
                                        type="submit"
                                        class="action-link action-delete"
                                    >
                                        Excluir
                                    </button>

                                </form>

                            </div>

                        </article>

                    <?php endforeach; ?>

                    <!-- PAGINAÇÃO -->

                    <?php if ($totalPaginas > 1): ?>

                        <div class="pagination">

                            <?php
                                $parametros = [
                                    'projeto' => $idProjeto,
                                    'busca' => $busca,
                                    'status' => $filtroStatus,
                                    'categoria' => $filtroCategoria
                                ];

                                $urlPagina = function ($numero) use ($parametros) {
                                    $dados = $parametros;
                                    $dados['pagina'] = $numero;

                                    return 'tarefas.php?' . http_build_query($dados);
                                };
                            ?>

                            <a
                                href="<?= tarefasEscapar($urlPagina(max(1, $pagina - 1))) ?>"
                                class="<?= $pagina <= 1 ? 'disabled' : '' ?>"
                            >
                                Anterior
                            </a>

                            <?php
                                $inicio = max(1, $pagina - 2);
                                $fim = min($totalPaginas, $pagina + 2);
                            ?>

                            <?php for ($i = $inicio; $i <= $fim; $i++): ?>

                                <a
                                    href="<?= tarefasEscapar($urlPagina($i)) ?>"
                                    class="<?= $i === $pagina ? 'active' : '' ?>"
                                >
                                    <?= $i ?>
                                </a>

                            <?php endfor; ?>

                            <a
                                href="<?= tarefasEscapar($urlPagina(min($totalPaginas, $pagina + 1))) ?>"
                                class="<?= $pagina >= $totalPaginas ? 'disabled' : '' ?>"
                            >
                                Próxima
                            </a>

                        </div>

                    <?php endif; ?>

                <?php endif; ?>

            </section>

        </div>

    </main>

</div>

</body>
</html>
