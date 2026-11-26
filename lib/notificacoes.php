<?php
/**
 * Funções para registrar notificações internas do TarefAi.
 */

if (!function_exists('criarNotificacao')) {
    function criarNotificacao(
        PDO $pdo,
        int $idUsuario,
        string $tipo,
        string $titulo,
        string $mensagem,
        ?int $idProjeto = null,
        ?int $idTarefa = null,
        ?int $idConvite = null
    ): void {
        if ($idUsuario <= 0) {
            return;
        }

        $stmt = $pdo->prepare("
            INSERT INTO notificacoes
                (id_usuario, tipo, titulo, mensagem, id_projeto, id_tarefa, id_convite, criado_em)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            $idUsuario,
            $tipo,
            $titulo,
            $mensagem,
            $idProjeto,
            $idTarefa,
            $idConvite
        ]);
    }
}
