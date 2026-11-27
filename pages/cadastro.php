<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/database/connection.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/auth.php';

if (!empty($_SESSION['usuario_id'])) {
    header('Location: dashboard.php');
    exit;
}

$erro = '';
$cadastrosAbertos = true;

try {
    $stmtCadastroConfig = $pdo->prepare(
        "SELECT valor FROM configuracoes_plataforma WHERE chave = 'cadastros_abertos' LIMIT 1"
    );
    $stmtCadastroConfig->execute();
    $valorCadastroConfig = $stmtCadastroConfig->fetchColumn();
    if ($valorCadastroConfig !== false) {
        $cadastrosAbertos = ((string) $valorCadastroConfig === '1');
    }
} catch (Throwable $e) {
    // Antes da migração administrativa, o comportamento permanece aberto.
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCSRF();

    if (!$cadastrosAbertos) {
        $erro = 'Os cadastros estão temporariamente fechados.';
    } else {

    $nome = trim($_POST['nome'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $senha = $_POST['senha'] ?? '';
    $confirmacao = $_POST['confirmacao'] ?? '';

    if (
        $nome === '' ||
        strlen($nome) > 120 ||
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        strlen($email) > 150
    ) {
        $erro = 'Preencha corretamente todos os campos.';
    } elseif (strlen($senha) < 8) {
        $erro = 'A senha precisa ter pelo menos 8 caracteres.';
    } elseif ($senha !== $confirmacao) {
        $erro = 'As senhas não coincidem.';
    } else {
        try {
            // Impede duas primeiras contas simultâneas.
            $lock = $pdo->query(
			    "SELECT GET_LOCK('tarefai_primeiro_admin', 10)"
			)->fetchColumn();

			if ((int)$lock !== 1) {
			    throw new Exception(
			        'Não foi possível iniciar o cadastro. Tente novamente.'
			    );
			}

            $pdo->beginTransaction();

            $consulta = $pdo->prepare(
                "SELECT id FROM usuarios WHERE email = ?"
            );
            $consulta->execute([$email]);

            if ($consulta->fetch()) {
                throw new Exception('Este e-mail já está cadastrado.');
            }

            $total = (int) $pdo->query(
                "SELECT COUNT(*) FROM usuarios"
            )->fetchColumn();

            $role = ($total === 0) ? 'admin' : 'default';

            $hash = password_hash(
                $senha,
                PASSWORD_DEFAULT
            );

            $stmt = $pdo->prepare(
                "INSERT INTO usuarios
                (nome, email, senha, role)
                VALUES (?, ?, ?, ?)"
            );

            $stmt->execute([
                $nome,
                $email,
                $hash,
                $role
            ]);

            $pdo->commit();

            header('Location: login.php?cadastro=sucesso');
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof PDOException &&
                $e->getCode() === '23000') {
                $erro = 'Este e-mail já está cadastrado.';
            } else {
                $erro = $e->getMessage();
            }

        } finally {
            $pdo->query(
                "SELECT RELEASE_LOCK('tarefai_primeiro_admin')"
            );
        }
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

    <title>Criar conta | TarefAí</title>

    <link rel="stylesheet" href="../css/geral.css">
    <script src="../scripts/js/auth.js" defer></script>
</head>

<body class="auth-page">

<main class="auth-container">

    <section class="auth-brand">
        <section class="brand-logo">T</section>

        <h1>TarefAí</h1>

        <p>
            Organize seus projetos,
            conecte sua equipe e transforme
            tarefas em resultados.
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
            <span class="eyebrow">COMECE AGORA</span>

            <h2>Criar sua conta</h2>

            <p>
                Preencha seus dados para entrar
                no TarefAí.
            </p>
        </section>

        <?php if (!$cadastrosAbertos): ?>
            <section class="alert alert-error">
                Os cadastros estão temporariamente fechados pela administração.
            </section>
        <?php endif; ?>

        <?php if ($erro): ?>
            <section class="alert alert-error">
                <?= htmlspecialchars($erro) ?>
            </section>
        <?php endif; ?>

        <?php if ($cadastrosAbertos): ?>
        <form method="POST" id="cadastroForm">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
            >

            <section class="form-group">
                <label for="nome">Nome completo</label>

                <input
                    type="text"
                    id="nome"
                    name="nome"
                    placeholder="Seu nome"
                    maxlength="120"
                    autocomplete="name"
                    required
                    value="<?= htmlspecialchars($_POST['nome'] ?? '') ?>"
                >
            </section>

            <section class="form-group">
                <label for="email">E-mail</label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    placeholder="seu@email.com"
                    maxlength="150"
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
                        placeholder="Mínimo de 8 caracteres"
                        minlength="8"
                        autocomplete="new-password"
                        required
                    >
                </section>
            </section>

            <section class="form-group">
                <label for="confirmacao">
                    Confirmar senha
                </label>

                <section class="password-field">
                    <input
                        type="password"
                        id="confirmacao"
                        name="confirmacao"
                        placeholder="Digite novamente"
                        minlength="8"
                        autocomplete="new-password"
                        required
                    >
                </section>
            </section>

            <button type="submit" class="btn-primary">
                Criar minha conta
            </button>

        </form>
        <?php endif; ?>

        <p class="auth-footer">
            Já possui uma conta?
            <a href="login.php">Entrar</a>
        </p>

    </section>

</main>

</body>
</html>
