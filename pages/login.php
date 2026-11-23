<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/database/connection.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/auth.php';

if (!empty($_SESSION['usuario_id'])) {
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
            "SELECT id, nome, email, senha, role
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
            session_regenerate_id(true);

            $_SESSION['usuario_id'] = $usuario['id'];
            $_SESSION['nome'] = $usuario['nome'];
            $_SESSION['email'] = $usuario['email'];
            $_SESSION['role'] = $usuario['role'];

            // Atualiza o token após autenticação.
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            header('Location: dashboard.php');
            exit;
        }

        $erro = 'E-mail ou senha incorretos.';
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Entrar | TarefAi</title>

    <link rel="stylesheet" href="../css/geral.css">
    <script src="../scripts/js/auth.js" defer></script>
</head>

<body class="auth-page">

<main class="auth-container">

    <section class="auth-brand">
        <div class="brand-logo">T</div>

        <h1>TarefAi</h1>

        <p>
            Suas tarefas, seus projetos e sua equipe.
            Tudo organizado em um só lugar.
        </p>

        <div class="brand-decoration">
            <span>✓</span>
            <span>Organização</span>
            <span>•</span>
            <span>Produtividade</span>
        </div>
    </section>

    <section class="auth-card">

        <div class="auth-heading">
            <span class="eyebrow">BEM-VINDO DE VOLTA</span>

            <h2>Acesse sua conta</h2>

            <p>
                Entre para continuar gerenciando
                seus projetos.
            </p>
        </div>

        <?php if (isset($_GET['cadastro'])): ?>
            <div class="alert alert-success">
                Cadastro realizado! Faça login para continuar.
            </div>
        <?php endif; ?>

        <?php if ($erro): ?>
            <div class="alert alert-error">
                <?= htmlspecialchars($erro) ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="loginForm">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
            >

            <div class="form-group">
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
            </div>

            <div class="form-group">
                <label for="senha">Senha</label>

                <div class="password-field">
                    <input
                        type="password"
                        id="senha"
                        name="senha"
                        placeholder="Digite sua senha"
                        autocomplete="current-password"
                        required
                    >

                    <button type="button"
                            class="toggle-password"
                            data-target="senha">
                        Mostrar
                    </button>
                </div>
            </div>

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
