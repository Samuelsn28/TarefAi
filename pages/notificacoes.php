<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/database/connection.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/notificacoes.php';

exigirLogin();

$usuarioId = (int) $_SESSION['usuario_id'];

function notificacoesEscapar($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCSRF();

    $acao = (string) ($_POST['acao'] ?? '');

    if ($acao === 'marcar_lida') {
        $idNotificacao = filter_var($_POST['id_notificacao'] ?? null, FILTER_VALIDATE_INT);

        if ($idNotificacao && $idNotificacao > 0) {
            $stmt = $pdo->prepare("
                UPDATE notificacoes
                SET lida_em = NOW()
                WHERE id = ?
                  AND id_usuario = ?
                  AND lida_em IS NULL
            ");
            $stmt->execute([$idNotificacao, $usuarioId]);
        }
    } elseif ($acao === 'marcar_todas_lidas') {
        $stmt = $pdo->prepare("
            UPDATE notificacoes
            SET lida_em = NOW()
            WHERE id_usuario = ?
              AND lida_em IS NULL
        ");
        $stmt->execute([$usuarioId]);
    }

    header('Location: notificacoes.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, tipo, titulo, mensagem, id_projeto, id_tarefa, id_convite, lida_em, criado_em
    FROM notificacoes
    WHERE id_usuario = ?
    ORDER BY criado_em DESC, id DESC
    LIMIT 100
");
$stmt->execute([$usuarioId]);
$notificacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM notificacoes
    WHERE id_usuario = ?
      AND lida_em IS NULL
");
$stmt->execute([$usuarioId]);
$totalNaoLidas = (int) $stmt->fetchColumn();

$csrfToken = $_SESSION['csrf_token'] ?? '';
$nomeUsuario = $_SESSION['nome'] ?? 'Usuário';
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= (($_SESSION['tema'] ?? 'claro') === 'escuro') ? 'escuro' : 'claro' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notificações | TarefAí</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <style>
        .notifications-content { padding: 30px; }
        .notifications-header { display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom:24px; }
        .notifications-header h1 { font-size:26px; margin-bottom:6px; color:var(--text); }
        .notifications-header p { color:var(--text-light); font-size:13px; }
        .notification-card { background:var(--white); border:1px solid var(--border); border-radius:13px; padding:18px 20px; margin-bottom:12px; display:flex; justify-content:space-between; align-items:flex-start; gap:18px; }
        .notification-card.unread { border-left:4px solid var(--primary); background:var(--surface-primary); }
        .notification-main { min-width:0; flex:1; }
        .notification-main h2 { font-size:15px; color:var(--text); margin-bottom:7px; }
        .notification-main p { color:var(--text-secondary); font-size:13px; line-height:1.5; overflow-wrap:anywhere; }
        .notification-date { display:block; color:var(--text-muted); font-size:11px; margin-top:9px; }
        .notification-actions { display:flex; flex-wrap:wrap; align-items:center; gap:8px; }
        .notification-link, .notification-button { border:0; border-radius:7px; padding:8px 11px; font-size:12px; font-weight:700; cursor:pointer; text-decoration:none; white-space:nowrap; }
        .notification-link { background:var(--surface-primary); color:var(--primary-dark); }
        .notification-button { background:var(--surface-soft); color:var(--text-secondary); }
        .notification-empty { background:var(--white); border:1px solid var(--border); border-radius:13px; padding:55px 20px; text-align:center; color:var(--text-light); }
        .notification-empty strong { display:block; color:var(--text); margin-bottom:8px; font-size:16px; }
        .btn-mark-all { border:0; border-radius:8px; padding:10px 14px; background:var(--surface-primary); color:var(--primary-dark); font-weight:700; cursor:pointer; }
        @media(max-width:650px) {
            .notifications-content { padding:18px; }
            .notifications-header { align-items:flex-start; flex-direction:column; }
            .notification-card { flex-direction:column; }
        }
    </style>
</head>
<body>
<section class="dashboard-container">
    <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/components/sidebar.php'; ?>

    <main class="main-content">
        <header class="topbar">
            <section>
                <span class="topbar-label">CENTRAL DE AVISOS</span>
                <h1>Notificações</h1>
            </section>
            <section class="topbar-user">
                <div class="topbar-avatar">
                    <?= notificacoesEscapar(function_exists('mb_substr') ? mb_substr($nomeUsuario, 0, 1, 'UTF-8') : substr($nomeUsuario, 0, 1)) ?>
                </div>
                <div>
                    <strong><?= notificacoesEscapar($nomeUsuario) ?></strong>
                    <span>Usuário</span>
                </div>
            </section>
        </header>

        <section class="notifications-content">
            <section class="notifications-header">
                <div>
                    <h1>Suas notificações</h1>
                    <p><?= $totalNaoLidas ?> não lida(s)</p>
                </div>

                <?php if ($totalNaoLidas > 0): ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= notificacoesEscapar($csrfToken) ?>">
                        <input type="hidden" name="acao" value="marcar_todas_lidas">
                        <button type="submit" class="btn-mark-all">Marcar todas como lidas</button>
                    </form>
                <?php endif; ?>
            </section>

            <?php if (empty($notificacoes)): ?>
                <section class="notification-empty">
                    <strong>Nenhuma notificação por enquanto</strong>
                    Quando você receber um convite ou uma tarefa for concluída em um projeto que gerencia, o aviso aparecerá aqui.
                </section>
            <?php else: ?>
                <?php foreach ($notificacoes as $notificacao): ?>
                    <?php
                        $urlDestino = 'notificacoes.php';

                        if ($notificacao['tipo'] === 'convite_projeto' && !empty($notificacao['id_convite'])) {
                            $urlDestino = '/TarefAi/aceitar_convite.php?id_convite=' . (int) $notificacao['id_convite'];
                        } elseif (!empty($notificacao['id_projeto'])) {
                            $urlDestino = 'projeto.php?id=' . (int) $notificacao['id_projeto'];
                        }
                    ?>
                    <article class="notification-card <?= $notificacao['lida_em'] === null ? 'unread' : '' ?>">
                        <section class="notification-main">
                            <h2><?= notificacoesEscapar($notificacao['titulo']) ?></h2>
                            <p><?= notificacoesEscapar($notificacao['mensagem']) ?></p>
                            <span class="notification-date">
                                <?= !empty($notificacao['criado_em']) ? date('d/m/Y H:i', strtotime($notificacao['criado_em'])) : '' ?>
                                <?= $notificacao['lida_em'] === null ? ' · Não lida' : ' · Lida' ?>
                            </span>
                        </section>

                        <section class="notification-actions">
                            <a class="notification-link" href="<?= notificacoesEscapar($urlDestino) ?>">
                                <?= $notificacao['tipo'] === 'convite_projeto' ? 'Ver convite' : 'Abrir projeto' ?>
                            </a>

                            <?php if ($notificacao['lida_em'] === null): ?>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= notificacoesEscapar($csrfToken) ?>">
                                    <input type="hidden" name="acao" value="marcar_lida">
                                    <input type="hidden" name="id_notificacao" value="<?= (int) $notificacao['id'] ?>">
                                    <button type="submit" class="notification-button">Marcar como lida</button>
                                </form>
                            <?php endif; ?>
                        </section>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>
</section>
</body>
</html>
