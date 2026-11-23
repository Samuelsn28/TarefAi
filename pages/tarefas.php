<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/database/connection.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/auth.php';

exigirLogin();

$usuarioId = (int) $_SESSION['usuario_id'];
$nomeUsuario = $_SESSION['nome'] ?? 'Usuário';
$isAdmin = (($_SESSION['role'] ?? '') === 'admin');

function tarefasEscapar($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function tarefasUrl(array $parametros = []): string
{
    $query = array_merge($_GET, $parametros);

    foreach ($query as $chave => $valor) {
        if ($valor === null || $valor === '') {
            unset($query[$chave]);
        }
    }

    return 'tarefas.php?' . http_build_query($query);
}

function tarefasData(?string $data): string
{
    if (!$data) {
        return '—';
    }

    try {
        return (new DateTime($data))->format('d/m/Y');
    } catch (Throwable $e) {
        return '—';
    }
}

$busca = trim((string) ($_GET['busca'] ?? ''));
$status = (string) ($_GET['status'] ?? 'todas');
$projetoFiltro = filter_input(INPUT_GET, 'projeto', FILTER_VALIDATE_INT);
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$limite = 10;

if (!in_array($status, ['todas', 'pendentes', 'concluidas'], true)) {
    $status = 'todas';
}

if (!$projetoFiltro || $projetoFiltro < 1) {
    $projetoFiltro = null;
}

/*
 * Um projeto é visível quando o usuário é seu criador, gestor ou participante.
 * O perfil admin não concede acesso automático a projetos.
 */
$sqlProjetos = "
    SELECT DISTINCT p.id, p.nome
    FROM projetos p
    WHERE
        p.id_usuario_criador = :usuario_criador
        OR EXISTS (
            SELECT 1
            FROM gestores_projeto gp
            WHERE gp.id_projeto = p.id
              AND gp.id_usuario = :usuario_gestor
        )
        OR EXISTS (
            SELECT 1
            FROM participantes_projeto pp
            WHERE pp.id_projeto = p.id
              AND pp.id_usuario = :usuario_participante
        )
    ORDER BY p.nome ASC
";

$stmtProjetos = $pdo->prepare($sqlProjetos);
$stmtProjetos->execute([
    ':usuario_criador' => $usuarioId,
    ':usuario_gestor' => $usuarioId,
    ':usuario_participante' => $usuarioId,
]);
$projetos = $stmtProjetos->fetchAll();

$idsProjetos = array_map(
    static fn($projeto) => (int) $projeto['id'],
    $projetos
);

if ($projetoFiltro !== null && !in_array($projetoFiltro, $idsProjetos, true)) {
    $projetoFiltro = null;
}

$condicoes = [
    "(
        p.id_usuario_criador = :acesso_criador
        OR EXISTS (
            SELECT 1
            FROM gestores_projeto gp
            WHERE gp.id_projeto = p.id
              AND gp.id_usuario = :acesso_gestor
        )
        OR EXISTS (
            SELECT 1
            FROM participantes_projeto pp
            WHERE pp.id_projeto = p.id
              AND pp.id_usuario = :acesso_participante
        )
    )"
];

$parametros = [
    ':acesso_criador' => $usuarioId,
    ':acesso_gestor' => $usuarioId,
    ':acesso_participante' => $usuarioId,
];

if ($busca !== '') {
    $condicoes[] = '(t.nome LIKE :busca_tarefa OR p.nome LIKE :busca_projeto)';
    $parametros[':busca_tarefa'] = '%' . $busca . '%';
    $parametros[':busca_projeto'] = '%' . $busca . '%';
}

if ($status === 'pendentes') {
    $condicoes[] = 't.completo_em IS NULL';
} elseif ($status === 'concluidas') {
    $condicoes[] = 't.completo_em IS NOT NULL';
}

if ($projetoFiltro !== null) {
    $condicoes[] = 'p.id = :projeto_filtro';
    $parametros[':projeto_filtro'] = $projetoFiltro;
}

$whereSql = implode(' AND ', $condicoes);

/* Indicadores gerais, considerando todos os projetos visíveis. */
$sqlIndicadores = "
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN t.completo_em IS NOT NULL THEN 1 ELSE 0 END) AS concluidas,
        SUM(CASE WHEN t.completo_em IS NULL THEN 1 ELSE 0 END) AS pendentes
    FROM tarefas t
    INNER JOIN projetos p ON p.id = t.id_projeto
    WHERE
        p.id_usuario_criador = :ind_criador
        OR EXISTS (
            SELECT 1 FROM gestores_projeto gp
            WHERE gp.id_projeto = p.id AND gp.id_usuario = :ind_gestor
        )
        OR EXISTS (
            SELECT 1 FROM participantes_projeto pp
            WHERE pp.id_projeto = p.id AND pp.id_usuario = :ind_participante
        )
";

$stmtIndicadores = $pdo->prepare($sqlIndicadores);
$stmtIndicadores->execute([
    ':ind_criador' => $usuarioId,
    ':ind_gestor' => $usuarioId,
    ':ind_participante' => $usuarioId,
]);
$indicadores = $stmtIndicadores->fetch() ?: [
    'total' => 0,
    'concluidas' => 0,
    'pendentes' => 0,
];

$totalTarefas = (int) ($indicadores['total'] ?? 0);
$totalConcluidas = (int) ($indicadores['concluidas'] ?? 0);
$totalPendentes = (int) ($indicadores['pendentes'] ?? 0);

/* Contagem da listagem filtrada para calcular a paginação. */
$sqlContagem = "
    SELECT COUNT(*)
    FROM tarefas t
    INNER JOIN projetos p ON p.id = t.id_projeto
    WHERE $whereSql
";

$stmtContagem = $pdo->prepare($sqlContagem);
$stmtContagem->execute($parametros);
$totalFiltrado = (int) $stmtContagem->fetchColumn();

$totalPaginas = max(1, (int) ceil($totalFiltrado / $limite));
$pagina = min($pagina, $totalPaginas);
$offset = ($pagina - 1) * $limite;

/* Tarefas dos projetos aos quais o usuário pertence. */
$sqlTarefas = "
    SELECT
        t.id,
        t.nome,
        t.criado_em,
        t.completo_em,
        p.id AS projeto_id,
        p.nome AS projeto_nome,
        (
            SELECT GROUP_CONCAT(DISTINCT u.nome ORDER BY u.nome SEPARATOR ', ')
            FROM responsaveis r
            INNER JOIN usuarios u ON u.id = r.id_usuario
            WHERE r.id_tarefa = t.id
        ) AS responsaveis,
        (
            SELECT GROUP_CONCAT(DISTINCT c.nome ORDER BY c.nome SEPARATOR ', ')
            FROM categorias_aplicadas ca
            INNER JOIN categorias c ON c.id = ca.id_categoria
            WHERE ca.id_tarefa = t.id
              AND c.id_projeto = p.id
        ) AS categorias
    FROM tarefas t
    INNER JOIN projetos p ON p.id = t.id_projeto
    WHERE $whereSql
    ORDER BY
        CASE WHEN t.completo_em IS NULL THEN 0 ELSE 1 END ASC,
        t.criado_em DESC,
        t.id DESC
    LIMIT :limite OFFSET :offset
";

$stmtTarefas = $pdo->prepare($sqlTarefas);

foreach ($parametros as $chave => $valor) {
    $stmtTarefas->bindValue($chave, $valor);
}

$stmtTarefas->bindValue(':limite', $limite, PDO::PARAM_INT);
$stmtTarefas->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmtTarefas->execute();

$tarefas = $stmtTarefas->fetchAll();

$primeiroItem = $totalFiltrado > 0 ? $offset + 1 : 0;
$ultimoItem = min($offset + $limite, $totalFiltrado);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tarefas | TarefAi</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <style>
        .tarefas-page {
            width: 100%;
            min-width: 0;
        }

        .tarefas-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 24px;
        }

        .tarefas-header h1 {
            margin: 0 0 6px;
        }

        .tarefas-header p {
            margin: 0;
            color: var(--text-secondary, #64748b);
        }

        .tarefas-indicadores {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .tarefa-indicador {
            padding: 20px;
            border: 1px solid var(--border-color, #e2e8f0);
            border-radius: 12px;
            background: var(--card-bg, #fff);
        }

        .tarefa-indicador span {
            display: block;
            color: var(--text-secondary, #64748b);
            font-size: 13px;
            margin-bottom: 8px;
        }

        .tarefa-indicador strong {
            display: block;
            font-size: 27px;
            line-height: 1.2;
        }

        .tarefas-filtros {
            display: flex;
            flex-wrap: wrap;
            align-items: end;
            gap: 12px;
            padding: 18px;
            margin-bottom: 18px;
            border: 1px solid var(--border-color, #e2e8f0);
            border-radius: 12px;
            background: var(--card-bg, #fff);
        }

        .tarefas-campo {
            display: flex;
            flex: 1 1 180px;
            flex-direction: column;
            gap: 6px;
        }

        .tarefas-campo label {
            font-size: 13px;
            font-weight: 600;
        }

        .tarefas-campo input,
        .tarefas-campo select {
            width: 100%;
            min-height: 40px;
            padding: 8px 11px;
            border: 1px solid var(--border-color, #cbd5e1);
            border-radius: 8px;
            background: var(--input-bg, #fff);
            color: inherit;
        }

        .tarefas-botao {
            min-height: 40px;
            padding: 8px 16px;
            border: 0;
            border-radius: 8px;
            background: var(--primary-color, #4f46e5);
            color: #fff;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .tarefas-botao-secundario {
            background: var(--surface-secondary, #e2e8f0);
            color: var(--text-primary, #334155);
        }

        .tarefas-tabela-container {
            overflow-x: auto;
            border: 1px solid var(--border-color, #e2e8f0);
            border-radius: 12px;
            background: var(--card-bg, #fff);
        }

        .tarefas-tabela {
            width: 100%;
            border-collapse: collapse;
            min-width: 760px;
        }

        .tarefas-tabela th,
        .tarefas-tabela td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid var(--border-color, #e2e8f0);
            vertical-align: middle;
        }

        .tarefas-tabela th {
            color: var(--text-secondary, #64748b);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .tarefas-tabela tbody tr:last-child td {
            border-bottom: 0;
        }

        .tarefa-nome {
            display: block;
            color: var(--text-primary, #0f172a);
            font-weight: 600;
            text-decoration: none;
        }

        .tarefa-projeto {
            display: inline-block;
            margin-top: 5px;
            color: var(--text-secondary, #64748b);
            font-size: 12px;
            text-decoration: none;
        }

        .tarefa-status {
            display: inline-flex;
            align-items: center;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .tarefa-status-pendente {
            background: #fff7ed;
            color: #c2410c;
        }

        .tarefa-status-concluida {
            background: #ecfdf5;
            color: #047857;
        }

        .tarefa-categorias,
        .tarefa-responsaveis {
            color: var(--text-secondary, #64748b);
            font-size: 13px;
            line-height: 1.5;
        }

        .tarefas-vazio {
            padding: 48px 20px;
            text-align: center;
            color: var(--text-secondary, #64748b);
        }

        .tarefas-vazio strong {
            display: block;
            margin-bottom: 8px;
            color: var(--text-primary, #334155);
            font-size: 17px;
        }

        .tarefas-rodape {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            padding: 16px 2px;
            color: var(--text-secondary, #64748b);
            font-size: 13px;
        }

        .tarefas-paginacao {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .tarefas-paginacao a,
        .tarefas-paginacao span {
            display: inline-flex;
            min-width: 34px;
            min-height: 34px;
            align-items: center;
            justify-content: center;
            padding: 5px 9px;
            border: 1px solid var(--border-color, #e2e8f0);
            border-radius: 7px;
            color: inherit;
            text-decoration: none;
        }

        .tarefas-paginacao .atual {
            background: var(--primary-color, #4f46e5);
            border-color: var(--primary-color, #4f46e5);
            color: #fff;
        }

        @media (max-width: 760px) {
            .tarefas-indicadores {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .tarefas-header {
                flex-direction: column;
            }

            .tarefas-filtros {
                align-items: stretch;
            }

            .tarefas-filtros .tarefas-botao {
                width: 100%;
            }
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php
        require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/components/sidebar.php';
    ?>

    <main class="main-content">

        <section class="content tarefas-page">
            <div class="tarefas-header">
                <div>
                    <h1>Tarefas</h1>
                    <p>Visualize as tarefas dos projetos dos quais você participa.</p>
                </div>
            </div>

            <div class="tarefas-indicadores">
                <div class="tarefa-indicador">
                    <span>Total de tarefas</span>
                    <strong><?= $totalTarefas ?></strong>
                </div>
                <div class="tarefa-indicador">
                    <span>Pendentes</span>
                    <strong><?= $totalPendentes ?></strong>
                </div>
                <div class="tarefa-indicador">
                    <span>Concluídas</span>
                    <strong><?= $totalConcluidas ?></strong>
                </div>
            </div>

            <form method="GET" action="tarefas.php" class="tarefas-filtros">
                <div class="tarefas-campo">
                    <label for="busca">Pesquisar</label>
                    <input
                        type="search"
                        id="busca"
                        name="busca"
                        value="<?= tarefasEscapar($busca) ?>"
                        placeholder="Nome da tarefa ou projeto"
                    >
                </div>

                <div class="tarefas-campo">
                    <label for="projeto">Projeto</label>
                    <select id="projeto" name="projeto">
                        <option value="">Todos os projetos</option>
                        <?php foreach ($projetos as $projeto): ?>
                            <option
                                value="<?= (int) $projeto['id'] ?>"
                                <?= $projetoFiltro === (int) $projeto['id'] ? 'selected' : '' ?>
                            >
                                <?= tarefasEscapar($projeto['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="tarefas-campo">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="todas" <?= $status === 'todas' ? 'selected' : '' ?>>Todos</option>
                        <option value="pendentes" <?= $status === 'pendentes' ? 'selected' : '' ?>>Pendentes</option>
                        <option value="concluidas" <?= $status === 'concluidas' ? 'selected' : '' ?>>Concluídas</option>
                    </select>
                </div>

                <button type="submit" class="tarefas-botao">Filtrar</button>
                <a href="tarefas.php" class="tarefas-botao tarefas-botao-secundario">Limpar</a>
            </form>

            <div class="tarefas-tabela-container">
                <?php if (empty($tarefas)): ?>
                    <div class="tarefas-vazio">
                        <strong>Nenhuma tarefa encontrada</strong>
                        <p>
                            <?php if ($busca !== '' || $status !== 'todas' || $projetoFiltro !== null): ?>
                                Tente alterar os filtros para encontrar tarefas.
                            <?php else: ?>
                                Os projetos dos quais você participa ainda não possuem tarefas.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <table class="tarefas-tabela">
                        <thead>
                            <tr>
                                <th>Tarefa</th>
                                <th>Responsáveis</th>
                                <th>Categoria</th>
                                <th>Criação</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tarefas as $tarefa): ?>
                                <?php $concluida = !empty($tarefa['completo_em']); ?>
                                <tr>
                                    <td>
                                        <a
                                            class="tarefa-nome"
                                            href="projeto.php?id=<?= (int) $tarefa['projeto_id'] ?>"
                                        >
                                            <?= tarefasEscapar($tarefa['nome']) ?>
                                        </a>
                                        <a
                                            class="tarefa-projeto"
                                            href="projeto.php?id=<?= (int) $tarefa['projeto_id'] ?>"
                                        >
                                            <?= tarefasEscapar($tarefa['projeto_nome']) ?>
                                        </a>
                                    </td>
                                    <td class="tarefa-responsaveis">
                                        <?= tarefasEscapar($tarefa['responsaveis'] ?: 'Não atribuído') ?>
                                    </td>
                                    <td class="tarefa-categorias">
                                        <?= tarefasEscapar($tarefa['categorias'] ?: '—') ?>
                                    </td>
                                    <td><?= tarefasEscapar(tarefasData($tarefa['criado_em'])) ?></td>
                                    <td>
                                        <span class="tarefa-status <?= $concluida ? 'tarefa-status-concluida' : 'tarefa-status-pendente' ?>">
                                            <?= $concluida ? 'Concluída' : 'Pendente' ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="tarefas-rodape">
                <span>
                    Exibindo <?= $primeiroItem ?>–<?= $ultimoItem ?>
                    de <?= $totalFiltrado ?> tarefa(s)
                </span>

                <?php if ($totalPaginas > 1): ?>
                    <nav class="tarefas-paginacao" aria-label="Paginação">
                        <?php if ($pagina > 1): ?>
                            <a href="<?= tarefasEscapar(tarefasUrl(['pagina' => $pagina - 1])) ?>" aria-label="Página anterior">‹</a>
                        <?php endif; ?>

                        <?php
                        $inicioPagina = max(1, $pagina - 2);
                        $fimPagina = min($totalPaginas, $pagina + 2);
                        ?>

                        <?php for ($numero = $inicioPagina; $numero <= $fimPagina; $numero++): ?>
                            <?php if ($numero === $pagina): ?>
                                <span class="atual" aria-current="page"><?= $numero ?></span>
                            <?php else: ?>
                                <a href="<?= tarefasEscapar(tarefasUrl(['pagina' => $numero])) ?>"><?= $numero ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($pagina < $totalPaginas): ?>
                            <a href="<?= tarefasEscapar(tarefasUrl(['pagina' => $pagina + 1])) ?>" aria-label="Próxima página">›</a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>
</body>
</html>
