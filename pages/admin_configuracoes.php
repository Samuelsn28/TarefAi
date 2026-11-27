<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/database/connection.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/admin.php';

exigirAdmin();

$adminId = (int) $_SESSION['usuario_id'];
$tema = (($_SESSION['tema'] ?? 'claro') === 'escuro') ? 'escuro' : 'claro';
$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCSRF();

    $nomePlataforma = trim((string) ($_POST['nome_plataforma'] ?? ''));
    $cadastrosAbertos = isset($_POST['cadastros_abertos']) ? '1' : '0';
    $tamanhoNome = function_exists('mb_strlen') ? mb_strlen($nomePlataforma, 'UTF-8') : strlen($nomePlataforma);

    if ($nomePlataforma === '' || $tamanhoNome > 60) {
        $erro = 'Informe um nome de plataforma com até 60 caracteres.';
    } else {
        try {
            $pdo->beginTransaction();

            $nomeAnterior = adminObterConfiguracao($pdo, 'nome_plataforma', 'TarefAí');
            $cadastrosAnteriores = adminObterConfiguracao($pdo, 'cadastros_abertos', '1');

            adminSalvarConfiguracao($pdo, $adminId, 'nome_plataforma', $nomePlataforma);
            adminSalvarConfiguracao($pdo, $adminId, 'cadastros_abertos', $cadastrosAbertos);

            adminRegistrarAcao(
                $pdo,
                $adminId,
                'alterar_configuracoes',
                'plataforma',
                null,
                'Nome: "' . $nomeAnterior . '" → "' . $nomePlataforma . '". Cadastro público: '
                    . ($cadastrosAnteriores === '1' ? 'aberto' : 'fechado') . ' → '
                    . ($cadastrosAbertos === '1' ? 'aberto' : 'fechado') . '.'
            );

            $pdo->commit();
            header('Location: admin_configuracoes.php?salvo=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log($e->getMessage());
            $erro = 'Não foi possível salvar as configurações.';
        }
    }
}

if (isset($_GET['salvo'])) {
    $sucesso = 'Configurações da plataforma atualizadas.';
}

$nomePlataforma = adminObterConfiguracao($pdo, 'nome_plataforma', 'TarefAí');
$cadastrosAbertos = adminObterConfiguracao($pdo, 'cadastros_abertos', '1') === '1';
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= adminEscapar($tema) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações da plataforma | TarefAí</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/admin.css">
</head>
<body>
<div class="dashboard-container">
    <?php require $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/components/sidebar.php'; ?>
    <main class="main-content admin-main">
        <header class="topbar">
            <section><span class="topbar-label">ADMINISTRAÇÃO</span><h1>Configurações da plataforma</h1></section>
            <span class="admin-badge admin">Configurações globais</span>
        </header>
        <p class="admin-intro">Estas opções afetam o funcionamento geral do TarefAí, não as preferências individuais de cada usuário.</p>

        <?php if ($erro): ?><div class="admin-alert error"><?= adminEscapar($erro) ?></div><?php endif; ?>
        <?php if ($sucesso): ?><div class="admin-alert success"><?= adminEscapar($sucesso) ?></div><?php endif; ?>

        <section class="admin-card">
            <h2>Identidade e cadastro</h2>
            <p>Defina o nome exibido na navegação e controle se novos usuários podem criar contas.</p>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= adminEscapar($_SESSION['csrf_token']) ?>">
                <div class="admin-form-grid">
                    <div class="admin-field full">
                        <label for="nome_plataforma">Nome da plataforma</label>
                        <input class="admin-input" style="width:100%" id="nome_plataforma" name="nome_plataforma" maxlength="60" required value="<?= adminEscapar($nomePlataforma) ?>">
                        <small>O nome é exibido na identificação da sidebar.</small>
                    </div>
                    <div class="admin-field full">
                        <label for="cadastros_abertos">Cadastro público</label>
                        <label style="display:flex;align-items:center;gap:10px;font-weight:500">
                            <input type="checkbox" id="cadastros_abertos" name="cadastros_abertos" value="1" <?= $cadastrosAbertos ? 'checked' : '' ?>>
                            Permitir que visitantes criem novas contas
                        </label>
                        <small>Ao desativar, a página de cadastro informa que os registros estão temporariamente fechados. Administradores ainda podem acessar a plataforma normalmente.</small>
                    </div>
                </div>
                <div style="margin-top:20px">
                    <button class="admin-btn primary" type="submit">Salvar configurações</button>
                </div>
            </form>
        </section>

        <div class="admin-note" style="margin-top:18px">
            <strong>Importante sobre permissões:</strong> alterar o papel de um usuário para administrador concede acesso a esta área administrativa.
            Isso não adiciona o usuário como gestor de nenhum projeto. Para conceder gestão de projeto, utilize os controles de equipe dentro do próprio projeto.
        </div>
    </main>
</div>
</body>
</html>
