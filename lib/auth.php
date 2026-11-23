<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function verificarCSRF(): void {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        http_response_code(403);
        exit('Requisição inválida. Atualize a página.');
    }
}

function exigirLogin(): void {
    if (empty($_SESSION['usuario_id'])) {
        header('Location: login.php');
        exit;
    }
}

function exigirAdmin(): void {
    exigirLogin();

    if ($_SESSION['role'] !== 'admin') {
        http_response_code(403);
        exit('Você não tem permissão para acessar esta página.');
    }
}
