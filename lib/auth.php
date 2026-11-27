<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function verificarCSRF(): void
{
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'] ?? '', $token)
    ) {
        http_response_code(403);
        exit('Requisição inválida. Atualize a página.');
    }
}

function authEncerrarSessao(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function exigirLogin(): void
{
    if (empty($_SESSION['usuario_id'])) {
        header('Location: /TarefAi/pages/login.php');
        exit;
    }

    global $pdo;

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        http_response_code(503);
        exit('Não foi possível validar a sessão. Tente novamente.');
    }

    try {
        // Revalida o papel e o status em cada requisição protegida.
        // Assim, bloqueios e remoções de privilégios passam a valer
        // também para sessões que já estavam abertas.
        $stmt = $pdo->prepare("
            SELECT role, status
            FROM usuarios
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([(int) $_SESSION['usuario_id']]);
        $usuarioAtual = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log($e->getMessage());
        http_response_code(503);
        exit('Não foi possível validar sua conta. Tente novamente.');
    }

    if (!$usuarioAtual || $usuarioAtual['status'] !== 'ativo') {
        authEncerrarSessao();
        header('Location: /TarefAi/pages/login.php?bloqueada=1');
        exit;
    }

    $_SESSION['role'] = $usuarioAtual['role'];
}

function exigirAdmin(): void
{
    exigirLogin();

    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Você não tem permissão para acessar esta área administrativa.');
    }
}
