<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/database/connection.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/auth.php';

if (!empty($_SESSION['usuario_id'])) {
    exigirLogin();
    header('Location: dashboard.php');
    exit;
}

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCSRF();

    $email = strtolower(trim($_POST['email'] ?? ''));
    $senha = $_POST['senha'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $senha === '') {
        $erro = 'Informe um e-mail e uma senha válidos.';
    } else {
        $stmt = $pdo->prepare(
            "SELECT id, nome, email, senha, role, tema, foto_perfil, status
             FROM usuarios
             WHERE email = ?
             LIMIT 1"
        );

        $stmt->execute([$email]);
        $usuario = $stmt->fetch();

        if (
            $usuario &&
            password_verify($senha, $usuario['senha'])
        ) {
            if ($usuario['status'] !== 'ativo') {
                $erro = 'Esta conta está bloqueada. Entre em contato com um administrador.';
            } else {
                $pdo->prepare('UPDATE usuarios SET ultimo_acesso = NOW() WHERE id = ?')
                    ->execute([(int) $usuario['id']]);

                session_regenerate_id(true);

            $_SESSION['usuario_id'] = $usuario['id'];
            $_SESSION['nome'] = $usuario['nome'];
            $_SESSION['email'] = $usuario['email'];
            $_SESSION['role'] = $usuario['role'];
            $_SESSION['tema'] = $usuario['tema'] ?: 'claro';
            $_SESSION['foto_perfil'] = $usuario['foto_perfil'];

            // Atualiza o token após autenticação.
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                header('Location: dashboard.php');
                exit;
            }
        } else {
            $erro = 'E-mail ou senha incorretos.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Entrar | TarefAí</title>

    <link rel="stylesheet" href="../css/geral.css">
    <script src="../scripts/js/auth.js" defer></script>
</head>

<body class="auth-page">

<main class="auth-container">

    <section class="auth-brand">
        <section class="brand-logo">T</section>

        <h1>TarefAí</h1>

        <p>
            Suas tarefas, seus projetos e sua equipe.
            Tudo organizado em um só lugar.
        </p>

        <section class="brand-decoration">
            <span>✓</span>
            <span>Organização</span>
            <span>•</span>
            <span>Produtividade</span>
        </section>
    </section>

    <section class="auth-card">

        <section class="auth-heading">
            <span class="eyebrow">BEM-VINDO DE VOLTA</span>

            <h2>Acesse sua conta</h2>

            <p>
                Entre para continuar gerenciando
                seus projetos.
            </p>
        </section>

        <?php if (isset($_GET['cadastro'])): ?>
            <section class="alert alert-success">
                Cadastro realizado! Faça login para continuar.
            </section>
        <?php endif; ?>

        <?php if (isset($_GET['bloqueada'])): ?>
            <section class="alert alert-error">
                Sua conta está bloqueada ou não está mais disponível.
            </section>
        <?php endif; ?>

        <?php if (isset($_GET['conta_excluida'])): ?>
            <section class="alert alert-success">
                Sua conta foi excluída com sucesso.
            </section>
        <?php endif; ?>

        <?php if ($erro): ?>
            <section class="alert alert-error">
                <?= htmlspecialchars($erro) ?>
            </section>
        <?php endif; ?>

        <form method="POST" id="loginForm">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
            >

            <section class="form-group">
                <label for="email">E-mail</label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    placeholder="seu@email.com"
                    autocomplete="email"
                    required
                    value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                >
            </section>

            <section class="form-group">
                <label for="senha">Senha</label>

                <section class="password-field">
                    <input
                        type="password"
                        id="senha"
                        name="senha"
                        placeholder="Digite sua senha"
                        autocomplete="current-password"
                        required
                    >
                </section>
            </section>

            <button type="submit" class="btn-primary">
                Entrar na plataforma
            </button>

        </form>

        <p class="auth-footer">
            Ainda não possui uma conta?
            <a href="cadastro.php">Cadastre-se</a>
        </p>

    </section>

</main>

</body>
</html>
