<?php
/**
 * Recursos administrativos do TarefAí.
 * O papel "admin" controla a plataforma; a gestão de projetos continua
 * sendo determinada exclusivamente por projetos.id_usuario_criador
 * e gestores_projeto.
 */

function adminEscapar($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function adminRegistrarAcao(
    PDO $pdo,
    int $adminId,
    string $acao,
    string $entidade,
    ?int $entidadeId = null,
    string $detalhes = ''
): void {
    $stmt = $pdo->prepare("
        INSERT INTO admin_logs
            (admin_id, acao, entidade, entidade_id, detalhes, criado_em)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$adminId, $acao, $entidade, $entidadeId, $detalhes]);
}

function adminExcluirProjeto(PDO $pdo, int $projetoId): void
{
    $stmt = $pdo->prepare("
        DELETE FROM notificacoes
        WHERE id_projeto = ?
           OR id_tarefa IN (SELECT id FROM tarefas WHERE id_projeto = ?)
           OR id_convite IN (SELECT id FROM convites_projeto WHERE id_projeto = ?)
    ");
    $stmt->execute([$projetoId, $projetoId, $projetoId]);

    $stmt = $pdo->prepare("DELETE FROM projetos WHERE id = ?");
    $stmt->execute([$projetoId]);
}

/**
 * Exclui um usuário preservando projetos com outro gestor.
 * Se não houver outro gestor, o projeto é excluído conforme a política
 * definida para a exclusão de contas no TarefAí.
 *
 * Deve ser chamada dentro de uma transação.
 */
function adminExcluirUsuario(PDO $pdo, int $usuarioId, int $adminId): ?string
{
    if ($usuarioId === $adminId) {
        throw new RuntimeException('Não é permitido excluir a própria conta por esta tela. Utilize Configurações.');
    }

    $stmtUsuario = $pdo->prepare("
        SELECT id, nome, email, role, status, foto_perfil
        FROM usuarios
        WHERE id = ?
        FOR UPDATE
    ");
    $stmtUsuario->execute([$usuarioId]);
    $usuario = $stmtUsuario->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        throw new RuntimeException('Usuário não encontrado.');
    }

    if ($usuario['role'] === 'admin' && $usuario['status'] === 'ativo') {
        $stmtAdmins = $pdo->query("
            SELECT id
            FROM usuarios
            WHERE role = 'admin' AND status = 'ativo'
            FOR UPDATE
        ");
        if (count($stmtAdmins->fetchAll(PDO::FETCH_COLUMN)) <= 1) {
            throw new RuntimeException('Não é possível excluir o último administrador ativo.');
        }
    }

    $stmtProjetos = $pdo->prepare("
        SELECT id
        FROM projetos
        WHERE id_usuario_criador = ?
        ORDER BY id ASC
        FOR UPDATE
    ");
    $stmtProjetos->execute([$usuarioId]);
    $projetosCriados = $stmtProjetos->fetchAll(PDO::FETCH_COLUMN);

    foreach ($projetosCriados as $projetoId) {
        $projetoId = (int) $projetoId;

        $stmtGestor = $pdo->prepare("
            SELECT gp.id_usuario
            FROM gestores_projeto gp
            INNER JOIN usuarios u ON u.id = gp.id_usuario
            WHERE gp.id_projeto = ?
              AND gp.id_usuario <> ?
              AND u.status = 'ativo'
            ORDER BY gp.id_usuario ASC
            LIMIT 1
            FOR UPDATE
        ");
        $stmtGestor->execute([$projetoId, $usuarioId]);
        $novoCriadorId = $stmtGestor->fetchColumn();

        if ($novoCriadorId !== false) {
            $novoCriadorId = (int) $novoCriadorId;

            $stmtTransferir = $pdo->prepare("
                UPDATE projetos
                SET id_usuario_criador = ?
                WHERE id = ?
            ");
            $stmtTransferir->execute([$novoCriadorId, $projetoId]);

            $stmtParticipante = $pdo->prepare("
                INSERT IGNORE INTO participantes_projeto (id_usuario, id_projeto)
                VALUES (?, ?)
            ");
            $stmtParticipante->execute([$novoCriadorId, $projetoId]);

            // O novo criador já possui poder de gestão pelo próprio vínculo de criador.
            // O vínculo antigo será removido pela exclusão do usuário.
        } else {
            adminExcluirProjeto($pdo, $projetoId);
        }
    }

    // Remove referências a convites criados pelo usuário antes da cascata.
    $stmt = $pdo->prepare("
        DELETE FROM notificacoes
        WHERE id_convite IN (
            SELECT id
            FROM convites_projeto
            WHERE id_usuario_convidou = ?
        )
    ");
    $stmt->execute([$usuarioId]);

    // Revoga convites pendentes destinados ao e-mail da conta.
    $stmt = $pdo->prepare("
        UPDATE convites_projeto
        SET status = 'revogado'
        WHERE LOWER(email) = LOWER(?)
          AND status = 'pendente'
    ");
    $stmt->execute([$usuario['email']]);

    // A tabela de notificações não possui FK para usuarios.
    $stmt = $pdo->prepare("DELETE FROM notificacoes WHERE id_usuario = ?");
    $stmt->execute([$usuarioId]);

    adminRegistrarAcao(
        $pdo,
        $adminId,
        'excluir_usuario',
        'usuario',
        $usuarioId,
        'Conta excluída: ' . $usuario['nome'] . ' (' . $usuario['email'] . ').'
    );

    $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
    $stmt->execute([$usuarioId]);

    return $usuario['foto_perfil'] ?: null;
}

function adminRemoverFoto(?string $caminhoRelativo): void
{
    if (!$caminhoRelativo || !str_starts_with($caminhoRelativo, 'uploads/perfis/')) {
        return;
    }

    $nomeArquivo = basename($caminhoRelativo);
    if ($nomeArquivo === '' || $nomeArquivo === '.' || $nomeArquivo === '..') {
        return;
    }

    $caminho = $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/uploads/perfis/' . $nomeArquivo;
    if (is_file($caminho)) {
        @unlink($caminho);
    }
}

function adminObterConfiguracao(PDO $pdo, string $chave, string $padrao = ''): string
{
    $stmt = $pdo->prepare("SELECT valor FROM configuracoes_plataforma WHERE chave = ? LIMIT 1");
    $stmt->execute([$chave]);
    $valor = $stmt->fetchColumn();

    return $valor === false ? $padrao : (string) $valor;
}

function adminSalvarConfiguracao(PDO $pdo, int $adminId, string $chave, string $valor): void
{
    $stmt = $pdo->prepare("
        INSERT INTO configuracoes_plataforma (chave, valor, atualizado_por, atualizado_em)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            valor = VALUES(valor),
            atualizado_por = VALUES(atualizado_por),
            atualizado_em = NOW()
    ");
    $stmt->execute([$chave, $valor, $adminId]);
}
