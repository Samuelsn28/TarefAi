<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/database/connection.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/auth.php';

exigirLogin();

$usuarioId = (int) $_SESSION['usuario_id'];
$emailUsuario = mb_strtolower(trim((string) ($_SESSION['email'] ?? '')), 'UTF-8');
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');

function conviteEscapar($valor)
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(400);
    exit('Link de convite inválido.');
}

$tokenHash = hash('sha256', $token);
$stmt = $pdo->prepare("
    SELECT c.id, c.id_projeto, c.email, c.status, c.expira_em, p.nome AS nome_projeto
    FROM convites_projeto c
    INNER JOIN projetos p ON p.id = c.id_projeto
    WHERE c.token_hash = ?
    LIMIT 1
");
$stmt->execute([$tokenHash]);
$convite = $stmt->fetch();

if (!$convite) {
    http_response_code(404);
    exit('Convite não encontrado ou link inválido.');
}

if ($convite['status'] !== 'pendente' || strtotime($convite['expira_em']) < time()) {
    if ($convite['status'] === 'pendente') {
        $pdo->prepare("UPDATE convites_projeto SET status = 'expirado' WHERE id = ?")->execute([$convite['id']]);
    }
    http_response_code(410);
    exit('Este convite não está mais válido. Peça ao gestor do projeto um novo link.');
}

if ($emailUsuario === '' || $emailUsuario !== mb_strtolower($convite['email'], 'UTF-8')) {
    http_response_code(403);
    exit('Entre no TarefAi com o e-mail convidado (' . conviteEscapar($convite['email']) . ') para aceitar este convite.');
}

$erro = null;
$sucesso = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCSRF();
    try {
        $pdo->beginTransaction();

        // Bloqueia o convite durante a aceitação para evitar uso simultâneo.
        $stmtLock = $pdo->prepare("SELECT id, id_projeto, email, status, expira_em FROM convites_projeto WHERE id = ? FOR UPDATE");
        $stmtLock->execute([$convite['id']]);
        $conviteAtual = $stmtLock->fetch();

        if (!$conviteAtual || $conviteAtual['status'] !== 'pendente' || strtotime($conviteAtual['expira_em']) < time()) {
            throw new Exception('Este convite já foi utilizado ou expirou.');
        }

        $stmtGestor = $pdo->prepare("SELECT 1 FROM gestores_projeto WHERE id_usuario = ? AND id_projeto = ? LIMIT 1");
        $stmtGestor->execute([$usuarioId, $convite['id_projeto']]);
        $jaGestor = (bool) $stmtGestor->fetchColumn();

        $stmtCriador = $pdo->prepare("SELECT 1 FROM projetos WHERE id = ? AND id_usuario_criador = ? LIMIT 1");
        $stmtCriador->execute([$convite['id_projeto'], $usuarioId]);
        $jaCriador = (bool) $stmtCriador->fetchColumn();

        if (!$jaGestor && !$jaCriador) {
            $stmtParticipante = $pdo->prepare("INSERT IGNORE INTO participantes_projeto (id_usuario, id_projeto) VALUES (?, ?)");
            $stmtParticipante->execute([$usuarioId, $convite['id_projeto']]);
        }

        $pdo->prepare("UPDATE convites_projeto SET status = 'aceito', aceito_em = NOW() WHERE id = ?")->execute([$convite['id']]);
        $pdo->commit();
        $sucesso = true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $erro = $e instanceof PDOException ? 'Não foi possível aceitar o convite. Tente novamente.' : $e->getMessage();
    }
}

$csrfToken = $_SESSION['csrf_token'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aceitar convite - TarefAi</title>
    <link rel="stylesheet" href="/TarefAi/css/geral.css">
    <style>
        body{min-height:100vh;display:grid;place-items:center;background:#f1f5f9;padding:20px}
        .convite-box{width:min(100%,520px);background:#fff;padding:32px;border-radius:16px;box-shadow:0 8px 30px rgba(15,23,42,.08)}
        .convite-box h1{margin:0 0 12px;color:#0f172a}.convite-box p{color:#64748b;line-height:1.6}
        .convite-box .btn{display:inline-block;margin-top:12px}.alert{padding:12px 14px;border-radius:8px;margin:14px 0}.alert-error{background:#fee2e2;color:#991b1b}.alert-success{background:#dcfce7;color:#166534}
    </style>
</head>
<body>
    <main class="convite-box">
        <?php if ($sucesso): ?>
            <h1>Convite aceito!</h1>
            <div class="alert alert-success">Você agora participa do projeto <strong><?= conviteEscapar($convite['nome_projeto']) ?></strong> como participante.</div>
            <p>Você não recebeu permissões de gestor.</p>
            <a class="btn btn-primary" href="/TarefAi/pages/dashboard.php">Ir para o painel</a>
        <?php else: ?>
            <h1>Convite para participar</h1>
            <p>Você foi convidado(a) para participar do projeto <strong><?= conviteEscapar($convite['nome_projeto']) ?></strong> com o e-mail <strong><?= conviteEscapar($convite['email']) ?></strong>.</p>
            <p>Ao aceitar, você entrará como participante, sem permissões de gestor.</p>
            <?php if ($erro): ?><div class="alert alert-error"><?= conviteEscapar($erro) ?></div><?php endif; ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= conviteEscapar($csrfToken) ?>">
                <input type="hidden" name="token" value="<?= conviteEscapar($token) ?>">
                <button type="submit" class="btn btn-primary">Aceitar convite</button>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>
