<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/database/connection.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/notificacoes.php';

exigirLogin();

$usuarioId = (int) $_SESSION['usuario_id'];

/*
|--------------------------------------------------------------------------
| Funções auxiliares
|--------------------------------------------------------------------------
*/

function tarefasEscapar($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function tarefasRedirecionar($projetoId, $mensagem = null, $tipo = 'sucesso'): void
{
    $url = 'projeto.php?id=' . (int) $projetoId;

    if ($mensagem !== null) {
        $url .= '&mensagem=' . urlencode($mensagem);
        $url .= '&tipo=' . urlencode($tipo);
    }

    header('Location: ' . $url);
    exit;
}

/*
|--------------------------------------------------------------------------
| Validação e permissões do projeto
|--------------------------------------------------------------------------
*/

$idProjeto = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$idProjeto || $idProjeto <= 0) {
    http_response_code(400);
    exit('Projeto inválido.');
}

$stmtProjeto = $pdo->prepare("
    SELECT p.id, p.nome, p.id_usuario_criador
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
          OR EXISTS (
              SELECT 1
              FROM participantes_projeto pp
              WHERE pp.id_projeto = p.id
                AND pp.id_usuario = ?
          )
      )
    LIMIT 1
");
$stmtProjeto->execute([$idProjeto, $usuarioId, $usuarioId, $usuarioId]);
$projeto = $stmtProjeto->fetch(PDO::FETCH_ASSOC);

if (!$projeto) {
    http_response_code(403);
    exit('Você não tem acesso a este projeto.');
}

$ehCriador = $usuarioId === (int) $projeto['id_usuario_criador'];

$stmtEhGestor = $pdo->prepare("
    SELECT 1
    FROM gestores_projeto
    WHERE id_projeto = ?
      AND id_usuario = ?
    LIMIT 1
");
$stmtEhGestor->execute([$idProjeto, $usuarioId]);
$ehGestor = (bool) $stmtEhGestor->fetchColumn();

/*
 * Somente o criador e os gestores podem criar, editar e excluir tarefas,
 * além de convidar novos participantes.
 */
$podeGerenciarTarefas = $ehCriador || $ehGestor;
$podeConvidar = $podeGerenciarTarefas;

/*
|--------------------------------------------------------------------------
| Dados auxiliares
|--------------------------------------------------------------------------
*/

$stmtCategorias = $pdo->prepare("
    SELECT id, nome, cor
    FROM categorias
    WHERE id_projeto = ?
    ORDER BY nome ASC
");
$stmtCategorias->execute([$idProjeto]);
$categorias = $stmtCategorias->fetchAll(PDO::FETCH_ASSOC);

/*
 * A equipe inclui o criador, gestores e participantes.
 * Participantes podem ser atribuídos como responsáveis por tarefas.
 */
$stmtEquipe = $pdo->prepare("
    SELECT DISTINCT u.id, u.nome, u.email
    FROM usuarios u
    WHERE u.id = ?
       OR u.id IN (
            SELECT gp.id_usuario
            FROM gestores_projeto gp
            WHERE gp.id_projeto = ?
            UNION
            SELECT pp.id_usuario
            FROM participantes_projeto pp
            WHERE pp.id_projeto = ?
       )
    ORDER BY u.nome ASC
");
$stmtEquipe->execute([
    (int) $projeto['id_usuario_criador'],
    $idProjeto,
    $idProjeto
]);
$equipe = $stmtEquipe->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Processamento das ações
|--------------------------------------------------------------------------
*/

$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCSRF();

    $acao = (string) ($_POST['acao'] ?? '');

    // Confirma novamente o vínculo do usuário com o projeto.
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
              OR EXISTS (
                  SELECT 1
                  FROM participantes_projeto pp
                  WHERE pp.id_projeto = projetos.id
                    AND pp.id_usuario = ?
              )
          )
        LIMIT 1
    ");
    $stmtPermissao->execute([$idProjeto, $usuarioId, $usuarioId, $usuarioId]);

    if (!$stmtPermissao->fetchColumn()) {
        http_response_code(403);
        exit('Acesso negado.');
    }

    /*
     * Bloqueio no servidor: participantes não podem executar ações
     * de criação, edição ou exclusão, mesmo enviando POST manualmente.
     */
    $acoesDeGerenciamento = ['criar', 'editar', 'excluir', 'convidar_participante'];

    if (in_array($acao, $acoesDeGerenciamento, true) && !$podeGerenciarTarefas) {
        http_response_code(403);
        exit('Somente o criador ou um gestor pode realizar esta ação.');
    }

    try {
        /*
        |--------------------------------------------------------------------------
        | Criar convite de participante
        |--------------------------------------------------------------------------
        */

        if ($acao === 'convidar_participante') {
            $emailConvite = trim((string) ($_POST['email_participante'] ?? ''));
            $emailConvite = function_exists('mb_strtolower')
                ? mb_strtolower($emailConvite, 'UTF-8')
                : strtolower($emailConvite);

            if (
                $emailConvite === ''
                || !filter_var($emailConvite, FILTER_VALIDATE_EMAIL)
                || strlen($emailConvite) > 190
            ) {
                throw new Exception('Informe um endereço de e-mail válido (até 190 caracteres).');
            }

            $stmtJaMembro = $pdo->prepare("
                SELECT 1
                FROM usuarios u
                WHERE LOWER(u.email) = LOWER(?)
                  AND (
                      u.id = ?
                      OR EXISTS (
                          SELECT 1
                          FROM gestores_projeto gp
                          WHERE gp.id_usuario = u.id
                            AND gp.id_projeto = ?
                      )
                      OR EXISTS (
                          SELECT 1
                          FROM participantes_projeto pp
                          WHERE pp.id_usuario = u.id
                            AND pp.id_projeto = ?
                      )
                  )
                LIMIT 1
            ");
            $stmtJaMembro->execute([
                $emailConvite,
                (int) $projeto['id_usuario_criador'],
                $idProjeto,
                $idProjeto
            ]);

            if ($stmtJaMembro->fetchColumn()) {
                throw new Exception('Essa pessoa já participa do projeto.');
            }

            $stmtConvitePendente = $pdo->prepare("
                SELECT id
                FROM convites_projeto
                WHERE id_projeto = ?
                  AND LOWER(email) = LOWER(?)
                  AND status = 'pendente'
                  AND expira_em > NOW()
                LIMIT 1
            ");
            $stmtConvitePendente->execute([$idProjeto, $emailConvite]);

            if ($stmtConvitePendente->fetchColumn()) {
                throw new Exception('Já existe um convite pendente para esse e-mail.');
            }

            $tokenConvite = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $tokenConvite);

            $stmtCriarConvite = $pdo->prepare("
                INSERT INTO convites_projeto
                    (id_projeto, email, token_hash, id_usuario_convidou, status, expira_em)
                VALUES (?, ?, ?, ?, 'pendente', DATE_ADD(NOW(), INTERVAL 7 DAY))
            ");
            $stmtCriarConvite->execute([
                $idProjeto,
                $emailConvite,
                $tokenHash,
                $usuarioId
            ]);

            $idConviteCriado = (int) $pdo->lastInsertId();

            // A notificação interna só pode ser enviada se o e-mail já
            // pertencer a uma conta cadastrada no TarefAi.
            $stmtUsuarioConvidado = $pdo->prepare("
                SELECT id
                FROM usuarios
                WHERE LOWER(email) = LOWER(?)
                LIMIT 1
            ");
            $stmtUsuarioConvidado->execute([$emailConvite]);
            $idUsuarioConvidado = (int) $stmtUsuarioConvidado->fetchColumn();

            if ($idUsuarioConvidado > 0) {
                criarNotificacao(
                    $pdo,
                    $idUsuarioConvidado,
                    'convite_projeto',
                    'Convite para um projeto',
                    'Você foi convidado para participar do projeto ' . $projeto['nome'] . '.',
                    $idProjeto,
                    null,
                    $idConviteCriado
                );
            }

            $hostAtual = $_SERVER['HTTP_HOST'] ?? '';

            if (!preg_match('/^[a-zA-Z0-9.-]+(?::[0-9]{1,5})?$/', $hostAtual)) {
                throw new Exception('Não foi possível montar o link público. Verifique a configuração do domínio.');
            }

            $protocolo = (
                !empty($_SERVER['HTTPS'])
                && $_SERVER['HTTPS'] !== 'off'
            ) ? 'https' : 'http';

            $_SESSION['convite_link_gerado'] =
                $protocolo . '://' . $hostAtual
                . '/TarefAi/aceitar_convite.php?token=' . $tokenConvite;

            $_SESSION['convite_email_gerado'] = $emailConvite;

            tarefasRedirecionar(
                $idProjeto,
                'Convite criado. Compartilhe o link com a pessoa convidada.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Criar tarefa
        |--------------------------------------------------------------------------
        */

        elseif ($acao === 'criar') {
            $nome = trim((string) ($_POST['nome'] ?? ''));
            $responsaveis = $_POST['responsaveis'] ?? [];
            $categoriasSelecionadas = $_POST['categorias'] ?? [];

            if ($nome === '') {
                throw new Exception('Informe o nome da tarefa.');
            }

            if (function_exists('mb_strlen') ? mb_strlen($nome, 'UTF-8') > 255 : strlen($nome) > 255) {
                throw new Exception('O nome da tarefa deve ter no máximo 255 caracteres.');
            }

            if (!is_array($responsaveis) || !is_array($categoriasSelecionadas)) {
                throw new Exception('Dados inválidos.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO tarefas (id_projeto, nome)
                VALUES (?, ?)
            ");
            $stmt->execute([$idProjeto, $nome]);

            $idTarefa = (int) $pdo->lastInsertId();

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
                      OR EXISTS (
                          SELECT 1
                          FROM participantes_projeto pp
                          WHERE pp.id_projeto = ?
                            AND pp.id_usuario = u.id
                      )
                  )
            ");

            foreach (array_unique($responsaveis) as $idResponsavel) {
                $idResponsavel = filter_var($idResponsavel, FILTER_VALIDATE_INT);

                if (!$idResponsavel || $idResponsavel <= 0) {
                    continue;
                }

                $stmtResponsavel->execute([
                    $idTarefa,
                    $idResponsavel,
                    (int) $projeto['id_usuario_criador'],
                    $idProjeto,
                    $idProjeto
                ]);
            }

            $stmtCategoria = $pdo->prepare("
                INSERT INTO categorias_aplicadas (id_tarefa, id_categoria)
                SELECT ?, id
                FROM categorias
                WHERE id = ?
                  AND id_projeto = ?
            ");

            foreach (array_unique($categoriasSelecionadas) as $idCategoria) {
                $idCategoria = filter_var($idCategoria, FILTER_VALIDATE_INT);

                if (!$idCategoria || $idCategoria <= 0) {
                    continue;
                }

                $stmtCategoria->execute([$idTarefa, $idCategoria, $idProjeto]);
            }

            $pdo->commit();

            tarefasRedirecionar($idProjeto, 'Tarefa cadastrada com sucesso!');
        }

        /*
        |--------------------------------------------------------------------------
        | Editar tarefa
        |--------------------------------------------------------------------------
        */

        elseif ($acao === 'editar') {
            $idTarefa = filter_var($_POST['id_tarefa'] ?? null, FILTER_VALIDATE_INT);
            $nome = trim((string) ($_POST['nome'] ?? ''));
            $responsaveis = $_POST['responsaveis'] ?? [];
            $categoriasSelecionadas = $_POST['categorias'] ?? [];

            if (!$idTarefa || $idTarefa <= 0) {
                throw new Exception('Tarefa inválida.');
            }

            if ($nome === '') {
                throw new Exception('Informe o nome da tarefa.');
            }

            if (function_exists('mb_strlen') ? mb_strlen($nome, 'UTF-8') > 255 : strlen($nome) > 255) {
                throw new Exception('O nome da tarefa deve ter no máximo 255 caracteres.');
            }

            if (!is_array($responsaveis) || !is_array($categoriasSelecionadas)) {
                throw new Exception('Dados inválidos.');
            }

            $stmtExiste = $pdo->prepare("
                SELECT id
                FROM tarefas
                WHERE id = ?
                  AND id_projeto = ?
                LIMIT 1
            ");
            $stmtExiste->execute([$idTarefa, $idProjeto]);

            if (!$stmtExiste->fetchColumn()) {
                throw new Exception('Tarefa não encontrada.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE tarefas
                SET nome = ?
                WHERE id = ?
                  AND id_projeto = ?
            ");
            $stmt->execute([$nome, $idTarefa, $idProjeto]);

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
                      OR EXISTS (
                          SELECT 1
                          FROM participantes_projeto pp
                          WHERE pp.id_projeto = ?
                            AND pp.id_usuario = u.id
                      )
                  )
            ");

            foreach (array_unique($responsaveis) as $idResponsavel) {
                $idResponsavel = filter_var($idResponsavel, FILTER_VALIDATE_INT);

                if (!$idResponsavel || $idResponsavel <= 0) {
                    continue;
                }

                $stmtResponsavel->execute([
                    $idTarefa,
                    $idResponsavel,
                    (int) $projeto['id_usuario_criador'],
                    $idProjeto,
                    $idProjeto
                ]);
            }

            $stmt = $pdo->prepare("
                DELETE ca
                FROM categorias_aplicadas ca
                INNER JOIN categorias c ON c.id = ca.id_categoria
                WHERE ca.id_tarefa = ?
                  AND c.id_projeto = ?
            ");
            $stmt->execute([$idTarefa, $idProjeto]);

            $stmtCategoria = $pdo->prepare("
                INSERT INTO categorias_aplicadas (id_tarefa, id_categoria)
                SELECT ?, id
                FROM categorias
                WHERE id = ?
                  AND id_projeto = ?
            ");

            foreach (array_unique($categoriasSelecionadas) as $idCategoria) {
                $idCategoria = filter_var($idCategoria, FILTER_VALIDATE_INT);

                if (!$idCategoria || $idCategoria <= 0) {
                    continue;
                }

                $stmtCategoria->execute([$idTarefa, $idCategoria, $idProjeto]);
            }

            $pdo->commit();

            tarefasRedirecionar($idProjeto, 'Tarefa atualizada com sucesso!');
        }

        /*
        |--------------------------------------------------------------------------
        | Excluir tarefa
        |--------------------------------------------------------------------------
        */

        elseif ($acao === 'excluir') {
            $idTarefa = filter_var($_POST['id_tarefa'] ?? null, FILTER_VALIDATE_INT);

            if (!$idTarefa || $idTarefa <= 0) {
                throw new Exception('Tarefa inválida.');
            }

            $stmt = $pdo->prepare("
                DELETE FROM tarefas
                WHERE id = ?
                  AND id_projeto = ?
            ");
            $stmt->execute([$idTarefa, $idProjeto]);

            if ($stmt->rowCount() === 0) {
                throw new Exception('Tarefa não encontrada.');
            }

            tarefasRedirecionar($idProjeto, 'Tarefa excluída com sucesso!');
        }

        /*
        |--------------------------------------------------------------------------
        | Alternar conclusão da tarefa
        |--------------------------------------------------------------------------
        |
        | Esta ação é permitida somente se o usuário atual estiver cadastrado
        | como responsável pela tarefa. A regra também é aplicada no SQL,
        | impedindo que um POST manual altere tarefas de terceiros.
        */

        elseif ($acao === 'alternar_status') {
            $idTarefa = filter_var($_POST['id_tarefa'] ?? null, FILTER_VALIDATE_INT);

            if (!$idTarefa || $idTarefa <= 0) {
                throw new Exception('Tarefa inválida.');
            }

            // Recupera o estado anterior para notificar somente quando
            // a tarefa passa de pendente para concluída.
            $stmtEstadoTarefa = $pdo->prepare("
                SELECT nome, completo_em
                FROM tarefas
                WHERE id = ?
                  AND id_projeto = ?
                LIMIT 1
            ");
            $stmtEstadoTarefa->execute([$idTarefa, $idProjeto]);
            $estadoAnterior = $stmtEstadoTarefa->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("
                UPDATE tarefas t
                SET t.completo_em = CASE
                    WHEN t.completo_em IS NULL THEN CURRENT_TIMESTAMP
                    ELSE NULL
                END
                WHERE t.id = ?
                  AND t.id_projeto = ?
                  AND EXISTS (
                      SELECT 1
                      FROM responsaveis r
                      WHERE r.id_tarefa = t.id
                        AND r.id_usuario = ?
                  )
            ");
            $stmt->execute([$idTarefa, $idProjeto, $usuarioId]);

            if ($stmt->rowCount() > 0 && $estadoAnterior && $estadoAnterior['completo_em'] === null) {
                // O criador e todos os gestores recebem a notificação.
                // UNION evita notificações duplicadas caso alguém apareça
                // em mais de um dos vínculos de gerenciamento.
                $stmtGestoresNotificacao = $pdo->prepare("
                    SELECT id_usuario_criador AS id_usuario
                    FROM projetos
                    WHERE id = ?

                    UNION

                    SELECT id_usuario
                    FROM gestores_projeto
                    WHERE id_projeto = ?
                ");
                $stmtGestoresNotificacao->execute([$idProjeto, $idProjeto]);

                foreach ($stmtGestoresNotificacao->fetchAll(PDO::FETCH_COLUMN) as $idGestorNotificacao) {
                    criarNotificacao(
                        $pdo,
                        (int) $idGestorNotificacao,
                        'tarefa_concluida',
                        'Tarefa concluída',
                        'A tarefa "' . $estadoAnterior['nome'] . '" foi concluída no projeto ' . $projeto['nome'] . '.',
                        $idProjeto,
                        $idTarefa,
                        null
                    );
                }
            }

            if ($stmt->rowCount() === 0) {
                $stmtVerificar = $pdo->prepare("
                    SELECT
                        t.id,
                        EXISTS (
                            SELECT 1
                            FROM responsaveis r
                            WHERE r.id_tarefa = t.id
                              AND r.id_usuario = ?
                        ) AS eh_responsavel
                    FROM tarefas t
                    WHERE t.id = ?
                      AND t.id_projeto = ?
                    LIMIT 1
                ");
                $stmtVerificar->execute([$usuarioId, $idTarefa, $idProjeto]);
                $verificacao = $stmtVerificar->fetch(PDO::FETCH_ASSOC);

                if (!$verificacao) {
                    throw new Exception('Tarefa não encontrada.');
                }

                if (!(int) $verificacao['eh_responsavel']) {
                    throw new Exception('Você só pode concluir ou reabrir tarefas atribuídas a você.');
                }
            }

            tarefasRedirecionar($idProjeto, 'Status da tarefa atualizado!');
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

$idEditar = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT);

/*
 * Participantes não recebem o formulário de edição. A validação abaixo
 * também impede acesso direto a projeto.php?id=X&editar=Y.
 */
if ($idEditar && $idEditar > 0) {
    if (!$podeGerenciarTarefas) {
        http_response_code(403);
        exit('Somente o criador ou um gestor pode editar tarefas.');
    }

    $stmt = $pdo->prepare("
        SELECT id, nome, completo_em
        FROM tarefas
        WHERE id = ?
          AND id_projeto = ?
        LIMIT 1
    ");
    $stmt->execute([$idEditar, $idProjeto]);
    $tarefaEdicao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tarefaEdicao) {
        tarefasRedirecionar($idProjeto, 'Tarefa não encontrada.', 'erro');
    }

    $stmt = $pdo->prepare("
        SELECT id_usuario
        FROM responsaveis
        WHERE id_tarefa = ?
    ");
    $stmt->execute([$idEditar]);
    $responsaveisEdicao = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id_usuario'));

    $stmt = $pdo->prepare("
        SELECT ca.id_categoria
        FROM categorias_aplicadas ca
        INNER JOIN categorias c ON c.id = ca.id_categoria
        WHERE ca.id_tarefa = ?
          AND c.id_projeto = ?
    ");
    $stmt->execute([$idEditar, $idProjeto]);
    $categoriasEdicao = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id_categoria'));
}

/*
|--------------------------------------------------------------------------
| Pesquisa, filtros e paginação
|--------------------------------------------------------------------------
*/

$busca = trim((string) ($_GET['busca'] ?? ''));
$filtroStatus = (string) ($_GET['status'] ?? 'todas');

$filtroCategoria = filter_input(INPUT_GET, 'categoria', FILTER_VALIDATE_INT);

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
    $stmt->execute([$filtroCategoria, $idProjeto]);

    if (!$stmt->fetchColumn()) {
        $filtroCategoria = null;
    }
} else {
    $filtroCategoria = null;
}

$pagina = filter_input(INPUT_GET, 'pagina', FILTER_VALIDATE_INT);
$pagina = ($pagina && $pagina > 0) ? $pagina : 1;

$porPagina = 10;
$offset = ($pagina - 1) * $porPagina;

$where = ['t.id_projeto = ?'];
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

$stmtTotal = $pdo->prepare("
    SELECT COUNT(*)
    FROM tarefas t
    WHERE $whereSql
");
$stmtTotal->execute($params);
$totalTarefas = (int) $stmtTotal->fetchColumn();

$totalPaginas = max(1, (int) ceil($totalTarefas / $porPagina));

if ($pagina > $totalPaginas) {
    $pagina = $totalPaginas;
    $offset = ($pagina - 1) * $porPagina;
}

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
            INNER JOIN usuarios u ON u.id = r.id_usuario
            WHERE r.id_tarefa = t.id
        ) AS nomes_responsaveis,
        EXISTS (
            SELECT 1
            FROM responsaveis r_usuario
            WHERE r_usuario.id_tarefa = t.id
              AND r_usuario.id_usuario = ?
        ) AS usuario_eh_responsavel
    FROM tarefas t
    WHERE $whereSql
    ORDER BY
        CASE WHEN t.completo_em IS NULL THEN 0 ELSE 1 END,
        t.criado_em DESC,
        t.id DESC
    LIMIT $porPagina OFFSET $offset
";

$stmtTarefas = $pdo->prepare($sqlTarefas);
$stmtTarefas->execute(array_merge([$usuarioId], $params));
$tarefas = $stmtTarefas->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Categorias de cada tarefa
|--------------------------------------------------------------------------
*/

$categoriasPorTarefa = [];

if (!empty($tarefas)) {
    $idsTarefas = array_map('intval', array_column($tarefas, 'id'));
    $placeholders = implode(',', array_fill(0, count($idsTarefas), '?'));

    $stmt = $pdo->prepare("
        SELECT ca.id_tarefa, c.id, c.nome, c.cor
        FROM categorias_aplicadas ca
        INNER JOIN categorias c ON c.id = ca.id_categoria
        WHERE ca.id_tarefa IN ($placeholders)
          AND c.id_projeto = ?
        ORDER BY c.nome ASC
    ");
    $stmt->execute(array_merge($idsTarefas, [$idProjeto]));

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $categoria) {
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
$estatisticas = $stmt->fetch(PDO::FETCH_ASSOC);

$totalProjeto = (int) ($estatisticas['total'] ?? 0);
$concluidasProjeto = (int) ($estatisticas['concluidas'] ?? 0);
$pendentesProjeto = (int) ($estatisticas['pendentes'] ?? 0);

/*
|--------------------------------------------------------------------------
| Mensagens e dados da interface
|--------------------------------------------------------------------------
*/

$mensagem = (string) ($_GET['mensagem'] ?? '');
$tipoMensagem = (string) ($_GET['tipo'] ?? 'sucesso');

$csrfToken = $_SESSION['csrf_token'] ?? '';

$linkConviteGerado = $_SESSION['convite_link_gerado'] ?? null;
$emailConviteGerado = $_SESSION['convite_email_gerado'] ?? null;

unset($_SESSION['convite_link_gerado'], $_SESSION['convite_email_gerado']);

?>

<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= (($_SESSION['tema'] ?? 'claro') === 'escuro') ? 'escuro' : 'claro' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tarefas - TarefAí</title>
    <link rel="stylesheet" href="../css/dashboard.css">

    <style>
    .page-content {
        padding: 30px;
    }

    .breadcrumb {
        color: var(--text-secondary);
        font-size: 14px;
        margin-bottom: 12px;
    }

    .breadcrumb a {
        color: var(--text-secondary);
        text-decoration: none;
    }

    .breadcrumb a:hover {
        color: var(--primary);
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
        color: var(--text);
        font-size: 26px;
    }

    .project-heading p {
        margin: 0;
        color: var(--text-secondary);
        font-size: 14px;
    }

    .stats-grid {
        margin-bottom: 25px;
    }

    .invite-card,
    .task-form-card,
    .task-list-card {
        background: var(--white);
        border-radius: 14px;
        padding: 24px;
        box-shadow: 0 2px 12px rgba(15, 23, 42, .05);
        margin-bottom: 25px;
    }

    .invite-card p {
        color: var(--text-secondary);
        font-size: 13px;
        line-height: 1.5;
        margin: -8px 0 18px;
    }

    .invite-form {
        display: flex;
        align-items: flex-end;
        gap: 12px;
    }

    .invite-form .form-group {
        flex: 1;
        margin: 0;
    }

    .team-list {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 18px;
    }

    .team-member {
        background: var(--surface-soft);
        color: var(--text-secondary);
        border-radius: 20px;
        padding: 6px 10px;
        font-size: 12px;
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
        color: var(--text);
        font-size: 18px;
    }

    .form-group {
        margin-bottom: 18px;
    }

    .form-group label {
        display: block;
        font-size: 14px;
        font-weight: 600;
        color: var(--text);
        margin-bottom: 8px;
    }

    .form-control {
        width: 100%;
        box-sizing: border-box;
        border: 1px solid var(--border-strong);
        border-radius: 8px;
        padding: 11px 13px;
        font-size: 14px;
        color: var(--text);
        background: var(--white);
        outline: none;
    }

    .form-control:focus {
        border-color: var(--primary);
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
        background: var(--surface-muted);
        border: 1px solid var(--border);
        padding: 9px 12px;
        border-radius: 8px;
        font-size: 13px;
        color: var(--text-secondary);
        cursor: pointer;
    }

    .selection-item input {
        accent-color: var(--primary);
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
        background: var(--primary);
        color: #fff;
    }

    .btn-primary:hover {
        background: var(--primary-dark);
    }

    .btn-secondary {
        background: var(--surface-soft);
        color: var(--text);
    }

    .btn-secondary:hover {
        background: var(--border-strong);
    }

    .btn-danger {
        background: var(--danger-bg);
        color: var(--danger-text);
    }

    .btn-success {
        background: var(--success-bg);
        color: var(--success-text);
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
        border-bottom: 1px solid var(--border);
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
        accent-color: var(--green);
        cursor: pointer;
        flex-shrink: 0;
    }

    .task-checkbox:disabled {
        cursor: not-allowed;
        opacity: .55;
    }

    .task-info {
        min-width: 0;
    }

    .task-name {
        font-size: 15px;
        font-weight: 600;
        color: var(--text);
        margin: 0 0 8px;
        overflow-wrap: anywhere;
    }

    .task-name.completed {
        text-decoration: line-through;
        color: var(--text-muted);
    }

    .task-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 8px 15px;
        font-size: 12px;
        color: var(--text-secondary);
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
        background: var(--surface-soft);
        color: var(--text-secondary);
    }

    .task-tag-color {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        flex-shrink: 0;
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
        background: var(--info-bg);
        color: var(--primary-dark);
    }

    .action-delete {
        background: var(--danger-bg);
        color: var(--danger-text);
    }

    .status-badge {
        display: inline-block;
        font-size: 11px;
        font-weight: 600;
        padding: 5px 9px;
        border-radius: 20px;
    }

    .status-pending {
        background: var(--warning-bg);
        color: var(--warning-text);
    }

    .status-completed {
        background: var(--success-bg);
        color: var(--success-text);
    }

    .alert {
        padding: 13px 16px;
        border-radius: 9px;
        margin-bottom: 20px;
        font-size: 14px;
    }

    .alert-success {
        background: var(--success-bg);
        color: var(--success-text);
    }

    .alert-error {
        background: var(--danger-bg);
        color: var(--danger-text);
    }

    .empty-state {
        text-align: center;
        padding: 45px 15px;
        color: var(--text-secondary);
    }

    .empty-state h3 {
        color: var(--text);
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
        background: var(--surface-soft);
        color: var(--text-secondary);
    }

    .pagination .active {
        background: var(--primary);
        color: #fff;
    }

    .pagination .disabled {
        opacity: .45;
        pointer-events: none;
    }

    .task-count {
        font-size: 13px;
        color: var(--text-secondary);
    }

    .completion-hint {
        color: var(--text-muted);
        font-size: 11px;
        margin-top: 6px;
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

        .invite-form {
            align-items: stretch;
            flex-direction: column;
        }

        .task-item {
            flex-direction: column;
        }

        .task-actions {
            padding-left: 32px;
        }

        .task-form-card,
        .task-list-card,
        .invite-card {
            padding: 17px;
        }
    }
    </style>
</head>

<body>
<section class="dashboard-container">

    <?php
        require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/components/sidebar.php';
    ?>

    <main class="main-content">
        <header class="topbar">
            <section><span>Gestão de tarefas</span></section>
            <section class="user-info">
                <span><?= tarefasEscapar($_SESSION['nome'] ?? '') ?></span>
            </section>
        </header>

        <section class="page-content">
            <section class="breadcrumb">
                <a href="dashboard.php">Dashboard</a> /
                <a href="projetos.php">Projetos</a> /
                Tarefas
            </section>

            <section class="project-heading">
                <section>
                    <h1><?= tarefasEscapar($projeto['nome']) ?></h1>
                    <p>Gerencie as tarefas deste projeto.</p>
                </section>
                <a href="projetos.php" class="btn btn-secondary">Voltar aos projetos</a>
            </section>

            <?php if ($mensagem !== ''): ?>
                <section class="alert <?= $tipoMensagem === 'erro' ? 'alert-error' : 'alert-success' ?>">
                    <?= tarefasEscapar($mensagem) ?>
                </section>
            <?php endif; ?>

            <?php if ($erro !== null): ?>
                <section class="alert alert-error"><?= tarefasEscapar($erro) ?></section>
            <?php endif; ?>

            <?php if ($podeConvidar): ?>
                <section class="invite-card">
                    <section class="card-heading"><h2>Convidar participantes</h2></section>
                    <p>Informe o e-mail da pessoa. Será criado um convite válido por 7 dias. Ela só terá acesso ao projeto depois de entrar na conta correspondente e aceitar o convite; entrará como participante, não como gestora.</p>

                    <?php if ($linkConviteGerado): ?>
                        <section class="alert alert-success">
                            Convite para <?= tarefasEscapar($emailConviteGerado) ?> criado. Compartilhe este link:
                            <section style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
                                <input type="text" class="form-control" readonly value="<?= tarefasEscapar($linkConviteGerado) ?>" id="linkConviteGerado" style="flex:1;min-width:220px;">
                                <button type="button" class="btn btn-secondary" onclick="navigator.clipboard.writeText(document.getElementById('linkConviteGerado').value)">Copiar link</button>
                            </section>
                        </section>
                    <?php endif; ?>

                    <form method="POST" action="projeto.php?id=<?= (int) $idProjeto ?>" class="invite-form">
                        <input type="hidden" name="csrf_token" value="<?= tarefasEscapar($csrfToken) ?>">
                        <input type="hidden" name="acao" value="convidar_participante">
                        <section class="form-group">
                            <label for="email_participante">E-mail do participante</label>
                            <input type="email" id="email_participante" name="email_participante" class="form-control" placeholder="nome@exemplo.com" maxlength="190" required>
                        </section>
                        <button type="submit" class="btn btn-primary">Gerar convite</button>
                    </form>

                    <section class="team-list">
                        <?php foreach ($equipe as $membro): ?>
                            <span class="team-member"><?= tarefasEscapar($membro['nome']) ?> · <?= tarefasEscapar($membro['email']) ?></span>
                        <?php endforeach; ?>
                    </section>
                </section>
            <?php endif; ?>

            <section class="stats-grid">
                <section class="stat-card">
                    <section class="stat-info">
                        <h3>Total de tarefas</h3>
                        <p><?= $totalProjeto ?></p>
                    </section>
                </section>

                <section class="stat-card">
                    <section class="stat-info">
                        <h3>Concluídas</h3>
                        <p><?= $concluidasProjeto ?></p>
                    </section>
                </section>

                <section class="stat-card">
                    <section class="stat-info">
                        <h3>Pendentes</h3>
                        <p><?= $pendentesProjeto ?></p>
                    </section>
                </section>
            </section>

            <?php if ($podeGerenciarTarefas): ?>
                <section class="task-form-card">
                    <section class="card-heading">
                        <h2><?= $tarefaEdicao ? 'Editar tarefa' : 'Nova tarefa' ?></h2>
                    </section>

                    <form method="POST" action="projeto.php?id=<?= (int) $idProjeto ?>">
                        <input type="hidden" name="csrf_token" value="<?= tarefasEscapar($csrfToken) ?>">
                        <input type="hidden" name="acao" value="<?= $tarefaEdicao ? 'editar' : 'criar' ?>">

                        <?php if ($tarefaEdicao): ?>
                            <input type="hidden" name="id_tarefa" value="<?= (int) $tarefaEdicao['id'] ?>">
                        <?php endif; ?>

                        <section class="form-group">
                            <label for="nome">Nome da tarefa</label>
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
                        </section>

                        <section class="form-group">
                            <label>Responsáveis</label>
                            <?php if (empty($equipe)): ?>
                                <p>Nenhum participante disponível.</p>
                            <?php else: ?>
                                <section class="selection-grid">
                                    <?php foreach ($equipe as $membro): ?>
                                        <label class="selection-item">
                                            <input
                                                type="checkbox"
                                                name="responsaveis[]"
                                                value="<?= (int) $membro['id'] ?>"
                                                <?= in_array((int) $membro['id'], $responsaveisEdicao, true) ? 'checked' : '' ?>
                                            >
                                            <?= tarefasEscapar($membro['nome']) ?>
                                        </label>
                                    <?php endforeach; ?>
                                </section>
                            <?php endif; ?>
                        </section>

                        <section class="form-group">
                            <label>Categorias</label>
                            <?php if (empty($categorias)): ?>
                                <p>Nenhuma categoria cadastrada neste projeto. Você pode cadastrar categorias na página de categorias.</p>
                            <?php else: ?>
                                <section class="selection-grid">
                                    <?php foreach ($categorias as $categoria): ?>
                                        <label class="selection-item">
                                            <input
                                                type="checkbox"
                                                name="categorias[]"
                                                value="<?= (int) $categoria['id'] ?>"
                                                <?= in_array((int) $categoria['id'], $categoriasEdicao, true) ? 'checked' : '' ?>
                                            >
                                            <span class="task-tag-color" style="background: <?= tarefasEscapar($categoria['cor']) ?>"></span>
                                            <?= tarefasEscapar($categoria['nome']) ?>
                                        </label>
                                    <?php endforeach; ?>
                                </section>
                            <?php endif; ?>
                        </section>

                        <section class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <?= $tarefaEdicao ? 'Salvar alterações' : 'Cadastrar tarefa' ?>
                            </button>
                            <?php if ($tarefaEdicao): ?>
                                <a href="projeto.php?id=<?= (int) $idProjeto ?>" class="btn btn-secondary">Cancelar edição</a>
                            <?php endif; ?>
                        </section>
                    </form>
                </section>
            <?php else: ?>
                <section class="alert alert-success">
                    Você participa deste projeto. Pode visualizar as tarefas e concluir ou reabrir somente aquelas atribuídas a você.
                </section>
            <?php endif; ?>

            <section class="task-list-card">
                <section class="card-heading">
                    <h2>Lista de tarefas</h2>
                    <span class="task-count">
                        <?= $totalTarefas ?>
                        <?= $totalTarefas === 1 ? 'resultado' : 'resultados' ?>
                    </span>
                </section>

                <form method="GET" action="projeto.php" class="filter-form">
                    <input type="hidden" name="id" value="<?= (int) $idProjeto ?>">

                    <input
                        type="text"
                        name="busca"
                        class="form-control"
                        placeholder="Pesquisar tarefa..."
                        value="<?= tarefasEscapar($busca) ?>"
                    >

                    <select name="status" class="form-control">
                        <option value="todas" <?= $filtroStatus === 'todas' ? 'selected' : '' ?>>Todos os status</option>
                        <option value="pendentes" <?= $filtroStatus === 'pendentes' ? 'selected' : '' ?>>Pendentes</option>
                        <option value="concluidas" <?= $filtroStatus === 'concluidas' ? 'selected' : '' ?>>Concluídas</option>
                    </select>

                    <select name="categoria" class="form-control">
                        <option value="">Todas as categorias</option>
                        <?php foreach ($categorias as $categoria): ?>
                            <option
                                value="<?= (int) $categoria['id'] ?>"
                                <?= $filtroCategoria === (int) $categoria['id'] ? 'selected' : '' ?>
                            >
                                <?= tarefasEscapar($categoria['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" class="btn btn-primary">Filtrar</button>
                </form>

                <?php if (empty($tarefas)): ?>
                    <section class="empty-state">
                        <h3>Nenhuma tarefa encontrada</h3>
                        <p>Cadastre uma tarefa ou altere os filtros da pesquisa.</p>
                    </section>
                <?php else: ?>
                    <?php foreach ($tarefas as $tarefa): ?>
                        <?php
                            $concluida = $tarefa['completo_em'] !== null;
                            $usuarioResponsavel = (bool) $tarefa['usuario_eh_responsavel'];
                        ?>

                        <article class="task-item">
                            <section class="task-main">
                                <?php if ($usuarioResponsavel): ?>
                                    <form method="POST" action="projeto.php?id=<?= (int) $idProjeto ?>">
                                        <input type="hidden" name="csrf_token" value="<?= tarefasEscapar($csrfToken) ?>">
                                        <input type="hidden" name="acao" value="alternar_status">
                                        <input type="hidden" name="id_tarefa" value="<?= (int) $tarefa['id'] ?>">
                                        <input
                                            type="checkbox"
                                            class="task-checkbox"
                                            <?= $concluida ? 'checked' : '' ?>
                                            onchange="this.form.submit()"
                                            aria-label="Concluir ou reabrir tarefa"
                                            title="Concluir ou reabrir tarefa"
                                        >
                                    </form>
                                <?php else: ?>
                                    <input
                                        type="checkbox"
                                        class="task-checkbox"
                                        <?= $concluida ? 'checked' : '' ?>
                                        disabled
                                        aria-label="Você não é responsável por esta tarefa"
                                        title="Somente o responsável pode alterar o status"
                                    >
                                <?php endif; ?>

                                <section class="task-info">
                                    <h3 class="task-name <?= $concluida ? 'completed' : '' ?>">
                                        <?= tarefasEscapar($tarefa['nome']) ?>
                                    </h3>

                                    <section class="task-meta">
                                        <span>
                                            Criada em:
                                            <?= !empty($tarefa['criado_em']) ? date('d/m/Y H:i', strtotime($tarefa['criado_em'])) : '—' ?>
                                        </span>
                                        <span>
                                            Responsáveis:
                                            <?= tarefasEscapar($tarefa['nomes_responsaveis'] ?: 'Não definidos') ?>
                                        </span>
                                    </section>

                                    <?php if (!$usuarioResponsavel): ?>
                                        <p class="completion-hint">Somente o responsável pode alterar o status desta tarefa.</p>
                                    <?php endif; ?>

                                    <section class="task-tags">
                                        <span class="status-badge <?= $concluida ? 'status-completed' : 'status-pending' ?>">
                                            <?= $concluida ? 'Concluída' : 'Pendente' ?>
                                        </span>

                                        <?php $tags = $categoriasPorTarefa[$tarefa['id']] ?? []; ?>
                                        <?php foreach ($tags as $tag): ?>
                                            <span class="task-tag">
                                                <span class="task-tag-color" style="background: <?= tarefasEscapar($tag['cor']) ?>"></span>
                                                <?= tarefasEscapar($tag['nome']) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </section>
                                </section>
                            </section>

                            <?php if ($podeGerenciarTarefas): ?>
                                <section class="task-actions">
                                    <a
                                        href="projeto.php?id=<?= (int) $idProjeto ?>&editar=<?= (int) $tarefa['id'] ?>"
                                        class="action-link action-edit"
                                    >
                                        Editar
                                    </a>

                                    <form
                                        method="POST"
                                        action="projeto.php?id=<?= (int) $idProjeto ?>"
                                        onsubmit="return confirm('Tem certeza de que deseja excluir esta tarefa?');"
                                    >
                                        <input type="hidden" name="csrf_token" value="<?= tarefasEscapar($csrfToken) ?>">
                                        <input type="hidden" name="acao" value="excluir">
                                        <input type="hidden" name="id_tarefa" value="<?= (int) $tarefa['id'] ?>">
                                        <button type="submit" class="action-link action-delete">Excluir</button>
                                    </form>
                                </section>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>

                    <?php if ($totalPaginas > 1): ?>
                        <section class="pagination">
                            <?php
                                $parametrosPaginacao = [
                                    'id' => $idProjeto,
                                    'busca' => $busca,
                                    'status' => $filtroStatus,
                                    'categoria' => $filtroCategoria
                                ];

                                $urlPagina = function ($numero) use ($parametrosPaginacao) {
                                    $dados = $parametrosPaginacao;
                                    $dados['pagina'] = $numero;
                                    return 'projeto.php?' . http_build_query($dados);
                                };

                                $inicio = max(1, $pagina - 2);
                                $fim = min($totalPaginas, $pagina + 2);
                            ?>

                            <a
                                href="<?= tarefasEscapar($urlPagina(max(1, $pagina - 1))) ?>"
                                class="<?= $pagina <= 1 ? 'disabled' : '' ?>"
                            >Anterior</a>

                            <?php for ($i = $inicio; $i <= $fim; $i++): ?>
                                <a
                                    href="<?= tarefasEscapar($urlPagina($i)) ?>"
                                    class="<?= $i === $pagina ? 'active' : '' ?>"
                                ><?= $i ?></a>
                            <?php endfor; ?>

                            <a
                                href="<?= tarefasEscapar($urlPagina(min($totalPaginas, $pagina + 1))) ?>"
                                class="<?= $pagina >= $totalPaginas ? 'disabled' : '' ?>"
                            >Próxima</a>
                        </section>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </section>
    </main>
</section>
</body>
</html>