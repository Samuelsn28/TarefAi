<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/database/connection.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/lib/auth.php';

exigirLogin();

$usuarioId = (int) $_SESSION['usuario_id'];
$erro = '';
$sucesso = '';

function configuracoesEscapar($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function configuracoesRedirecionar(): void
{
    header('Location: configuracoes.php');
    exit;
}

function configuracoesRemoverFoto(?string $caminhoRelativo): void
{
    if (!$caminhoRelativo || !str_starts_with($caminhoRelativo, 'uploads/perfis/')) {
        return;
    }

    $nomeArquivo = basename($caminhoRelativo);
    if ($nomeArquivo === '' || $nomeArquivo === '.' || $nomeArquivo === '..') {
        return;
    }

    $caminhoAbsoluto = $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/uploads/perfis/' . $nomeArquivo;

    if (is_file($caminhoAbsoluto)) {
        @unlink($caminhoAbsoluto);
    }
}

// Recupera os dados diretamente do banco para evitar depender apenas da sessão.
$stmtUsuario = $pdo->prepare(
    'SELECT id, nome, email, senha, role, tema, foto_perfil
     FROM usuarios
     WHERE id = ?
     LIMIT 1'
);
$stmtUsuario->execute([$usuarioId]);
$usuario = $stmtUsuario->fetch();

if (!$usuario) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

$_SESSION['nome'] = $usuario['nome'];
$_SESSION['tema'] = $usuario['tema'] ?: 'claro';
$_SESSION['foto_perfil'] = $usuario['foto_perfil'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCSRF();

    $acao = $_POST['acao'] ?? '';

    try {
        if ($acao === 'alterar_tema') {
            $tema = $_POST['tema'] ?? '';

            if (!in_array($tema, ['claro', 'escuro'], true)) {
                throw new RuntimeException('Selecione um tema válido.');
            }

            $stmt = $pdo->prepare('UPDATE usuarios SET tema = ? WHERE id = ?');
            $stmt->execute([$tema, $usuarioId]);

            $_SESSION['tema'] = $tema;
            configuracoesRedirecionar();
        }

        if ($acao === 'alterar_nome') {
            $nome = trim($_POST['nome'] ?? '');
            $tamanhoNome = function_exists('mb_strlen')
                ? mb_strlen($nome, 'UTF-8')
                : strlen($nome);

            if ($nome === '') {
                throw new RuntimeException('Informe um nome.');
            }

            if ($tamanhoNome > 120) {
                throw new RuntimeException('O nome deve ter no máximo 120 caracteres.');
            }

            $stmt = $pdo->prepare('UPDATE usuarios SET nome = ? WHERE id = ?');
            $stmt->execute([$nome, $usuarioId]);

            $_SESSION['nome'] = $nome;
            configuracoesRedirecionar();
        }

        if ($acao === 'alterar_foto') {
            if (!isset($_FILES['foto_perfil']) || $_FILES['foto_perfil']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Selecione uma imagem válida para enviar.');
            }

            $arquivo = $_FILES['foto_perfil'];
            $limiteBytes = 5 * 1024 * 1024;

            if ($arquivo['size'] <= 0 || $arquivo['size'] > $limiteBytes) {
                throw new RuntimeException('A imagem deve ter no máximo 5 MB.');
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($arquivo['tmp_name']);

            $extensoesPermitidas = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp'
            ];

            if (!isset($extensoesPermitidas[$mime]) || @getimagesize($arquivo['tmp_name']) === false) {
                throw new RuntimeException('Formato inválido. Utilize JPG, PNG ou WEBP.');
            }

            $diretorioRelativo = 'uploads/perfis/';
            $diretorioAbsoluto = $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/' . $diretorioRelativo;

            if (!is_dir($diretorioAbsoluto) && !mkdir($diretorioAbsoluto, 0755, true) && !is_dir($diretorioAbsoluto)) {
                throw new RuntimeException('Não foi possível preparar o diretório de imagens.');
            }

            if (!is_writable($diretorioAbsoluto)) {
                throw new RuntimeException('O diretório de imagens não possui permissão de escrita.');
            }

            $nomeArquivo = bin2hex(random_bytes(20)) . '.' . $extensoesPermitidas[$mime];
            $destino = $diretorioAbsoluto . $nomeArquivo;

            if (!move_uploaded_file($arquivo['tmp_name'], $destino)) {
                throw new RuntimeException('Não foi possível salvar a imagem enviada.');
            }

            $novoCaminho = $diretorioRelativo . $nomeArquivo;
            $fotoAnterior = $usuario['foto_perfil'];

            try {
                $stmt = $pdo->prepare('UPDATE usuarios SET foto_perfil = ? WHERE id = ?');
                $stmt->execute([$novoCaminho, $usuarioId]);
            } catch (Throwable $e) {
                @unlink($destino);
                throw $e;
            }

            $_SESSION['foto_perfil'] = $novoCaminho;
            configuracoesRemoverFoto($fotoAnterior);
            configuracoesRedirecionar();
        }

        if ($acao === 'excluir_conta') {
            $senhaAtual = $_POST['senha_atual'] ?? '';
            $confirmacao = $_POST['confirmacao_exclusao'] ?? '';

            if (!password_verify($senhaAtual, $usuario['senha'])) {
                throw new RuntimeException('A senha informada está incorreta.');
            }

            if ($confirmacao !== 'EXCLUIR') {
                throw new RuntimeException('Digite EXCLUIR para confirmar a exclusão da conta.');
            }

            $pdo->beginTransaction();

            if ($usuario['role'] === 'admin') {
                $stmtAdminsAtivos = $pdo->query("
                    SELECT id
                    FROM usuarios
                    WHERE role = 'admin' AND status = 'ativo'
                    FOR UPDATE
                ");

                if (count($stmtAdminsAtivos->fetchAll(PDO::FETCH_COLUMN)) <= 1) {
                    throw new RuntimeException(
                        'Você é o último administrador ativo. Promova outro usuário a administrador antes de excluir sua conta.'
                    );
                }
            }

            $fotoParaExcluir = $usuario['foto_perfil'];

            // Bloqueia a conta durante o procedimento de exclusão.
            $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE id = ? FOR UPDATE');
            $stmt->execute([$usuarioId]);

            // Para cada projeto criado pelo usuário, procura outro gestor.
            $stmtProjetos = $pdo->prepare(
                'SELECT id FROM projetos WHERE id_usuario_criador = ? ORDER BY id ASC'
            );
            $stmtProjetos->execute([$usuarioId]);
            $projetosCriados = $stmtProjetos->fetchAll(PDO::FETCH_COLUMN);

            foreach ($projetosCriados as $projetoId) {
                $projetoId = (int) $projetoId;

                $stmtGestor = $pdo->prepare(
                    'SELECT gp.id_usuario
                     FROM gestores_projeto gp
                     INNER JOIN usuarios u ON u.id = gp.id_usuario
                     WHERE gp.id_projeto = ?
                       AND gp.id_usuario <> ?
                       AND u.status = \'ativo\'
                     ORDER BY gp.id_usuario ASC
                     LIMIT 1'
                );
                $stmtGestor->execute([$projetoId, $usuarioId]);
                $novoCriadorId = $stmtGestor->fetchColumn();

                if ($novoCriadorId !== false) {
                    // A titularidade passa para o outro gestor, preservando o projeto.
                    $stmtTransferir = $pdo->prepare(
                        'UPDATE projetos SET id_usuario_criador = ? WHERE id = ?'
                    );
                    $stmtTransferir->execute([(int) $novoCriadorId, $projetoId]);
                } else {
                    // A tabela notificacoes não possui FKs: remove referências antes do projeto.
                    $stmtLimparNotificacoes = $pdo->prepare(
                        'DELETE FROM notificacoes
                         WHERE id_projeto = ?
                            OR id_tarefa IN (SELECT id FROM tarefas WHERE id_projeto = ?)
                            OR id_convite IN (SELECT id FROM convites_projeto WHERE id_projeto = ?)'
                    );
                    $stmtLimparNotificacoes->execute([$projetoId, $projetoId, $projetoId]);

                    // Tarefas, categorias, vínculos e convites são removidos por cascata.
                    $stmtExcluirProjeto = $pdo->prepare('DELETE FROM projetos WHERE id = ?');
                    $stmtExcluirProjeto->execute([$projetoId]);
                }
            }

            // Remove notificações que apontam para convites que serão apagados em cascata.
            $stmt = $pdo->prepare(
                'DELETE FROM notificacoes
                 WHERE id_convite IN (
                     SELECT id FROM convites_projeto WHERE id_usuario_convidou = ?
                 )'
            );
            $stmt->execute([$usuarioId]);

            // Notificações pertencentes ao usuário não possuem FK para usuarios.
            $stmt = $pdo->prepare('DELETE FROM notificacoes WHERE id_usuario = ?');
            $stmt->execute([$usuarioId]);

            // Convites pendentes destinados ao e-mail antigo deixam de ser utilizáveis.
            $stmt = $pdo->prepare(
                "UPDATE convites_projeto
                 SET status = 'revogado'
                 WHERE email = ? AND status = 'pendente'"
            );
            $stmt->execute([$usuario['email']]);

            // As demais relações do usuário possuem ON DELETE CASCADE.
            $stmt = $pdo->prepare('DELETE FROM usuarios WHERE id = ?');
            $stmt->execute([$usuarioId]);

            $pdo->commit();

            configuracoesRemoverFoto($fotoParaExcluir);

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

            session_destroy();
            header('Location: login.php?conta_excluida=1');
            exit;
        }

        throw new RuntimeException('Ação não reconhecida.');

    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $erro = $e->getMessage();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($e->getMessage());
        $erro = 'Não foi possível concluir a operação. Tente novamente.';
    }
}

$temaAtual = $_SESSION['tema'] ?? 'claro';
$fotoPerfil = $_SESSION['foto_perfil'] ?? null;
$primeiraLetra = function_exists('mb_substr')
    ? mb_strtoupper(mb_substr($usuario['nome'], 0, 1, 'UTF-8'), 'UTF-8')
    : strtoupper(substr($usuario['nome'], 0, 1));

?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= configuracoesEscapar($temaAtual) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações | TarefAí</title>
    <link rel="stylesheet" href="../css/dashboard.css">
</head>
<body>
<div class="dashboard-container">
    <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/TarefAi/components/sidebar.php'; ?>

    <main class="main-content settings-main">
        <header class="topbar">
            <section>
                <span class="topbar-label">PREFERÊNCIAS DA CONTA</span>
                <h1>Configurações</h1>
            </section>
        </header>

        <?php if ($erro !== ''): ?>
            <div class="settings-alert settings-alert-error" role="alert">
                <?= configuracoesEscapar($erro) ?>
            </div>
        <?php endif; ?>

        <section class="settings-intro">
            <div class="settings-profile-preview">
                <?php if ($fotoPerfil): ?>
                    <img src="../<?= configuracoesEscapar($fotoPerfil) ?>" alt="Foto de perfil">
                <?php else: ?>
                    <span><?= configuracoesEscapar($primeiraLetra) ?></span>
                <?php endif; ?>
            </div>
            <div>
                <h2><?= configuracoesEscapar($usuario['nome']) ?></h2>
                <p><?= configuracoesEscapar($usuario['email']) ?></p>
            </div>
        </section>

        <section class="settings-grid">
            <article class="settings-card">
                <div class="settings-card-heading">
                    <span class="settings-icon">◐</span>
                    <div>
                        <h2>Aparência</h2>
                        <p>Escolha como o TarefAí será exibido.</p>
                    </div>
                </div>

                <form method="POST" class="settings-form">
                    <input type="hidden" name="csrf_token" value="<?= configuracoesEscapar($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="acao" value="alterar_tema">

                    <div class="theme-options">
                        <label class="theme-option <?= $temaAtual === 'claro' ? 'selected' : '' ?>">
                            <input type="radio" name="tema" value="claro" <?= $temaAtual === 'claro' ? 'checked' : '' ?>>
                            <span class="theme-preview theme-preview-light"><i></i><i></i><i></i></span>
                            <strong>Claro</strong>
                        </label>
                        <label class="theme-option <?= $temaAtual === 'escuro' ? 'selected' : '' ?>">
                            <input type="radio" name="tema" value="escuro" <?= $temaAtual === 'escuro' ? 'checked' : '' ?>>
                            <span class="theme-preview theme-preview-dark"><i></i><i></i><i></i></span>
                            <strong>Escuro</strong>
                        </label>
                    </div>
                    <button type="submit" class="settings-button">Salvar tema</button>
                </form>
            </article>

            <article class="settings-card">
                <div class="settings-card-heading">
                    <span class="settings-icon">✎</span>
                    <div>
                        <h2>Nome de exibição</h2>
                        <p>Altere o nome apresentado na sua conta.</p>
                    </div>
                </div>

                <form method="POST" class="settings-form">
                    <input type="hidden" name="csrf_token" value="<?= configuracoesEscapar($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="acao" value="alterar_nome">
                    <label for="nome">Nome</label>
                    <input class="settings-input" type="text" id="nome" name="nome" maxlength="120" value="<?= configuracoesEscapar($usuario['nome']) ?>" required>
                    <button type="submit" class="settings-button">Salvar nome</button>
                </form>
            </article>

            <article class="settings-card">
                <div class="settings-card-heading">
                    <span class="settings-icon">▧</span>
                    <div>
                        <h2>Foto de perfil</h2>
                        <p>Envie uma imagem JPG, PNG ou WEBP de até 5 MB.</p>
                    </div>
                </div>

                <form method="POST" enctype="multipart/form-data" class="settings-form">
                    <input type="hidden" name="csrf_token" value="<?= configuracoesEscapar($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="acao" value="alterar_foto">
                    <label for="foto_perfil">Selecionar imagem</label>
                    <input class="settings-file" type="file" id="foto_perfil" name="foto_perfil" accept="image/jpeg,image/png,image/webp" required>
                    <button type="submit" class="settings-button">Atualizar foto</button>
                </form>
            </article>

            <article class="settings-card settings-danger-card">
                <div class="settings-card-heading">
                    <span class="settings-icon danger-icon">!</span>
                    <div>
                        <h2>Excluir conta</h2>
                        <p>Esta ação é permanente e não poderá ser desfeita.</p>
                    </div>
                </div>

                <div class="settings-warning">
                    Seus projetos serão transferidos para outro gestor quando houver um. Projetos sem outro gestor serão excluídos, juntamente com suas tarefas e categorias.
                </div>

                <form method="POST" class="settings-form" id="deleteAccountForm">
                    <input type="hidden" name="csrf_token" value="<?= configuracoesEscapar($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="acao" value="excluir_conta">
                    <label for="senha_atual">Senha atual</label>
                    <input class="settings-input" type="password" id="senha_atual" name="senha_atual" autocomplete="current-password" required>
                    <label for="confirmacao_exclusao">Digite EXCLUIR para confirmar</label>
                    <input class="settings-input" type="text" id="confirmacao_exclusao" name="confirmacao_exclusao" autocomplete="off" required>
                    <button type="submit" class="settings-button settings-button-danger">Excluir minha conta</button>
                </form>
            </article>
        </section>
    </main>
</div>

<script>
document.getElementById('deleteAccountForm')?.addEventListener('submit', function (event) {
    if (!confirm('Tem certeza de que deseja excluir sua conta? Esta ação é permanente.')) {
        event.preventDefault();
    }
});

document.querySelectorAll('.theme-option input').forEach(function (input) {
    input.addEventListener('change', function () {
        document.querySelectorAll('.theme-option').forEach(function (option) {
            option.classList.toggle('selected', option.querySelector('input').checked);
        });
    });
});
</script>
</body>
</html>
