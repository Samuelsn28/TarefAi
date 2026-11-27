
<?php

$sidebarNomeUsuario = $_SESSION['nome'] ?? 'Usuário';
$sidebarTema = in_array(($_SESSION['tema'] ?? 'claro'), ['claro', 'escuro'], true) ? $_SESSION['tema'] : 'claro';
$sidebarFotoPerfil = $_SESSION['foto_perfil'] ?? null;

// Atualiza preferências de sessões iniciadas antes da instalação das configurações.
if (isset($pdo) && $pdo instanceof PDO && (!array_key_exists('tema', $_SESSION) || !array_key_exists('foto_perfil', $_SESSION))) {
    try {
        $stmtPreferenciasSidebar = $pdo->prepare('SELECT tema, foto_perfil FROM usuarios WHERE id = ? LIMIT 1');
        $stmtPreferenciasSidebar->execute([(int) ($_SESSION['usuario_id'] ?? 0)]);
        $preferenciasSidebar = $stmtPreferenciasSidebar->fetch(PDO::FETCH_ASSOC);

        if ($preferenciasSidebar) {
            $_SESSION['tema'] = $preferenciasSidebar['tema'] ?: 'claro';
            $_SESSION['foto_perfil'] = $preferenciasSidebar['foto_perfil'];
            $sidebarTema = $_SESSION['tema'];
            $sidebarFotoPerfil = $_SESSION['foto_perfil'];
        }
    } catch (Throwable $e) {
        // Mantém a navegação disponível caso a migração ainda não tenha sido executada.
    }
}

$sidebarIsAdmin = (($_SESSION['role'] ?? '') === 'admin');
$sidebarTotalProjetosGerenciados = 0;
$sidebarNomePlataforma = 'TarefAí';

if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $stmtSidebarGestao = $pdo->prepare("
            SELECT COUNT(DISTINCT p.id)
            FROM projetos p
            WHERE p.id_usuario_criador = ?
               OR EXISTS (
                    SELECT 1 FROM gestores_projeto gp
                    WHERE gp.id_projeto = p.id AND gp.id_usuario = ?
               )
        ");
        $stmtSidebarGestao->execute([
            (int) ($_SESSION['usuario_id'] ?? 0),
            (int) ($_SESSION['usuario_id'] ?? 0)
        ]);
        $sidebarTotalProjetosGerenciados = (int) $stmtSidebarGestao->fetchColumn();

        $stmtSidebarNome = $pdo->prepare("
            SELECT valor FROM configuracoes_plataforma
            WHERE chave = 'nome_plataforma' LIMIT 1
        ");
        $stmtSidebarNome->execute();
        $nomePlataformaBanco = $stmtSidebarNome->fetchColumn();
        if ($nomePlataformaBanco !== false && trim((string) $nomePlataformaBanco) !== '') {
            $sidebarNomePlataforma = (string) $nomePlataformaBanco;
        }
    } catch (Throwable $e) {
        // A sidebar permanece disponível se a migração administrativa estiver pendente.
    }
}

$sidebarRotuloPapel = $sidebarIsAdmin
    ? 'Administrador da plataforma'
    : ($sidebarTotalProjetosGerenciados > 0 ? 'Gestor de projetos' : 'Membro de projetos');

$sidebarNotificacoesNaoLidas = 0;

if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $stmtSidebarNotificacoes = $pdo->prepare("
            SELECT COUNT(*)
            FROM notificacoes
            WHERE id_usuario = ?
              AND lida_em IS NULL
        ");

        $stmtSidebarNotificacoes->execute([
            (int) ($_SESSION['usuario_id'] ?? 0)
        ]);

        $sidebarNotificacoesNaoLidas = (int) $stmtSidebarNotificacoes->fetchColumn();

    } catch (Throwable $e) {
        // Mantém o menu funcional caso a tabela ainda não tenha sido criada.
        $sidebarNotificacoesNaoLidas = 0;
    }
}

// =============================================
// IDENTIFICAÇÃO DA PÁGINA ATUAL
// =============================================

$sidebarArquivoAtual = basename($_SERVER['SCRIPT_NAME'] ?? '');

$sidebarPaginaAtiva = match ($sidebarArquivoAtual) {
    'dashboard.php' => 'dashboard',
    'projetos.php', 'projeto.php' => 'projetos',
    'tarefas.php' => 'tarefas',
    'notificacoes.php' => 'notificacoes',
    'categorias.php' => 'categorias',
    'usuarios.php' => 'usuarios',
    'admin.php' => 'admin',
    'admin_projetos.php' => 'admin_projetos',
    'admin_relatorios.php' => 'admin_relatorios',
    'admin_atividades.php' => 'admin_atividades',
    'admin_configuracoes.php' => 'admin_configuracoes',
    'configuracoes.php' => 'configuracoes',
    default => '',
};

// =============================================
// FUNÇÕES AUXILIARES DO COMPONENTE
// =============================================

if (!function_exists('sidebarEscapar')) {
    function sidebarEscapar($valor): string
    {
        return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
    }
}

$sidebarPrimeiraLetra = function_exists('mb_substr')
    ? mb_substr($sidebarNomeUsuario, 0, 1, 'UTF-8')
    : substr($sidebarNomeUsuario, 0, 1);

$sidebarPrimeiraLetra = function_exists('mb_strtoupper')
    ? mb_strtoupper($sidebarPrimeiraLetra, 'UTF-8')
    : strtoupper($sidebarPrimeiraLetra);

?>

<style>
    /* =====================================
       MENU LATERAL
    ===================================== */

    .sidebar {
        width: 250px;
        min-width: 250px;

        height: 100vh;
        max-height: 100vh;

        /* Mantém a sidebar visível durante o scroll */
        position: sticky;
        top: 0;
        align-self: flex-start;

        /* Permite rolagem própria se necessário */
        overflow-x: hidden;
        overflow-y: auto;

        background: var(--white);
        border-right: 1px solid var(--border);

        padding: 28px 18px;

        display: flex;
        flex-direction: column;

        flex-shrink: 0;
        box-sizing: border-box;

        z-index: 100;
    }

    /* =====================================
       IDENTIDADE VISUAL
    ===================================== */

    .sidebar-brand {
        display: flex;
        align-items: center;
        gap: 12px;

        padding: 0 12px;
        margin-bottom: 48px;

        font-size: 23px;
        font-weight: 800;
        color: var(--text);

        flex-shrink: 0;
    }

    .brand-logo {
        width: 39px;
        height: 39px;
        min-width: 39px;

        border-radius: 12px;
        background: linear-gradient(135deg, #7669ec, #5148c7);

        display: flex;
        justify-content: center;
        align-items: center;

        color: #ffffff;
        font-size: 22px;
        font-weight: bold;
    }

    /* =====================================
       NAVEGAÇÃO
    ===================================== */

    .sidebar-nav {
        display: flex;
        flex-direction: column;
        gap: 8px;

        flex: 1;
        min-height: 0;

        overflow: visible;
    }

    .nav-label {
        font-size: 10px;
        font-weight: 800;
        color: var(--text-muted);
        letter-spacing: 1.2px;

        margin: 22px 12px 8px;

        flex-shrink: 0;
    }

    .nav-item {
        display: flex;
        align-items: center;
        gap: 13px;

        padding: 13px 14px;

        border-radius: 9px;

        font-size: 14px;
        font-weight: 600;

        color: var(--text-light);
        text-decoration: none;

        transition: 0.2s;

        flex-shrink: 0;
        position: relative;
    }

    .nav-item:hover {
        background: var(--surface-hover);
        color: var(--primary);
    }

    .nav-item.active {
        background: var(--surface-primary);
        color: var(--primary);
    }

    /* =====================================
       CONTADOR DE NOTIFICAÇÕES
    ===================================== */

    .sidebar-notification-count {
        margin-left: auto;

        min-width: 19px;
        height: 19px;

        padding: 0 5px;

        border-radius: 20px;

        display: inline-flex;
        align-items: center;
        justify-content: center;

        background: var(--danger);
        color: #fff;

        font-size: 10px;
        font-weight: 800;
    }

    .nav-icon {
        font-size: 19px;
        width: 22px;
        min-width: 22px;
        text-align: center;
    }

    /* =====================================
       USUÁRIO E LOGOUT
    ===================================== */

    .sidebar-bottom {
        margin-top: auto;
        padding-top: 25px;

        border-top: 1px solid var(--border);

        flex-shrink: 0;
    }

    .sidebar-user-link {
        display: flex;
        color: inherit;
        text-decoration: none;
        border-radius: 10px;
        transition: background 0.2s;
    }

    .sidebar-user-link:hover {
        background: var(--surface-hover);
    }

    .sidebar-user {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 11px;
        padding: 10px 5px;

        min-width: 0;
    }

    .user-avatar {
        width: 38px;
        height: 38px;
        min-width: 38px;

        border-radius: 50%;

        background: var(--surface-primary);
        color: var(--primary);

        display: flex;
        justify-content: center;
        align-items: center;

        font-weight: 800;
        overflow: hidden;
        object-fit: cover;
    }

    .user-info {
        display: flex;
        flex-direction: column;
        gap: 4px;

        min-width: 0;
    }

    .user-info strong {
        font-size: 13px;
        color: var(--text);
        overflow-wrap: anywhere;
    }

    .user-info span {
        font-size: 11px;
        color: var(--text-light);
    }

    .logout-link {
        display: flex;
        align-items: center;
        gap: 10px;

        padding: 12px;

        color: var(--danger);

        font-size: 13px;
        font-weight: 600;

        border-radius: 8px;
        text-decoration: none;
    }

    .logout-link:hover {
        background: var(--danger-bg);
    }

    /* =====================================
       RESPONSIVIDADE
    ===================================== */

    @media (max-width: 768px) {

        .sidebar {
            width: 75px;
            min-width: 75px;

            padding: 22px 10px;
        }

        .sidebar-brand {
            justify-content: center;
            padding: 0;
            margin-bottom: 35px;
        }

        .sidebar-brand > span {
            display: none;
        }

        .nav-label {
            display: none;
        }

        .nav-item {
            justify-content: center;
            padding: 13px 5px;
            font-size: 0;
        }

        .nav-icon {
            font-size: 20px;
        }

        .sidebar-notification-count {
            position: absolute;

            top: 5px;
            right: 5px;

            min-width: 16px;
            height: 16px;

            padding: 0 3px;

            font-size: 9px;
        }

        .sidebar-user {
            justify-content: center;
            padding: 0;
        }

        .user-info,
        .logout-link {
            display: none;
        }

    }
</style>

<!-- =====================================
     MENU LATERAL
====================================== -->

<aside class="sidebar">

    <section class="sidebar-brand">

        <section class="brand-logo">T</section>

        <span><?= sidebarEscapar($sidebarNomePlataforma) ?></span>

    </section>

    <nav class="sidebar-nav">

        <span class="nav-label">MENU PRINCIPAL</span>

        <a
            href="dashboard.php"
            class="nav-item <?= $sidebarPaginaAtiva === 'dashboard' ? 'active' : '' ?>"
            <?= $sidebarPaginaAtiva === 'dashboard' ? 'aria-current="page"' : '' ?>
        >
            <span class="nav-icon">▦</span>
            Dashboard
        </a>

        <a
            href="projetos.php"
            class="nav-item <?= $sidebarPaginaAtiva === 'projetos' ? 'active' : '' ?>"
            <?= $sidebarPaginaAtiva === 'projetos' ? 'aria-current="page"' : '' ?>
        >
            <span class="nav-icon">▣</span>
            Projetos
        </a>

        <a
            href="tarefas.php"
            class="nav-item <?= $sidebarPaginaAtiva === 'tarefas' ? 'active' : '' ?>"
            <?= $sidebarPaginaAtiva === 'tarefas' ? 'aria-current="page"' : '' ?>
        >
            <span class="nav-icon">☑</span>
            Tarefas
        </a>

        <a
            href="notificacoes.php"
            class="nav-item <?= $sidebarPaginaAtiva === 'notificacoes' ? 'active' : '' ?>"
            <?= $sidebarPaginaAtiva === 'notificacoes' ? 'aria-current="page"' : '' ?>
        >
            <span class="nav-icon">🔔</span>
            Notificações

            <?php if ($sidebarNotificacoesNaoLidas > 0): ?>
                <span class="sidebar-notification-count">
                    <?= $sidebarNotificacoesNaoLidas > 99 ? '99+' : $sidebarNotificacoesNaoLidas ?>
                </span>
            <?php endif; ?>
        </a>

        <?php if ($sidebarIsAdmin): ?>

            <span class="nav-label">ADMINISTRAÇÃO</span>

            <a
                href="admin.php"
                class="nav-item <?= $sidebarPaginaAtiva === 'admin' ? 'active' : '' ?>"
                <?= $sidebarPaginaAtiva === 'admin' ? 'aria-current="page"' : '' ?>
            >
                <span class="nav-icon">▦</span>
                Visão geral
            </a>

            <a
                href="usuarios.php"
                class="nav-item <?= $sidebarPaginaAtiva === 'usuarios' ? 'active' : '' ?>"
                <?= $sidebarPaginaAtiva === 'usuarios' ? 'aria-current="page"' : '' ?>
            >
                <span class="nav-icon">♙</span>
                Usuários
            </a>

            <a
                href="admin_projetos.php"
                class="nav-item <?= $sidebarPaginaAtiva === 'admin_projetos' ? 'active' : '' ?>"
                <?= $sidebarPaginaAtiva === 'admin_projetos' ? 'aria-current="page"' : '' ?>
            >
                <span class="nav-icon">▣</span>
                Projetos
            </a>

            <a
                href="admin_relatorios.php"
                class="nav-item <?= $sidebarPaginaAtiva === 'admin_relatorios' ? 'active' : '' ?>"
                <?= $sidebarPaginaAtiva === 'admin_relatorios' ? 'aria-current="page"' : '' ?>
            >
                <span class="nav-icon">▥</span>
                Relatórios
            </a>

            <a
                href="admin_atividades.php"
                class="nav-item <?= $sidebarPaginaAtiva === 'admin_atividades' ? 'active' : '' ?>"
                <?= $sidebarPaginaAtiva === 'admin_atividades' ? 'aria-current="page"' : '' ?>
            >
                <span class="nav-icon">◷</span>
                Histórico
            </a>

            <a
                href="admin_configuracoes.php"
                class="nav-item <?= $sidebarPaginaAtiva === 'admin_configuracoes' ? 'active' : '' ?>"
                <?= $sidebarPaginaAtiva === 'admin_configuracoes' ? 'aria-current="page"' : '' ?>
            >
                <span class="nav-icon">⚙</span>
                Plataforma
            </a>

        <?php endif; ?>

    </nav>

    <!-- USUÁRIO E LOGOUT -->

    <section class="sidebar-bottom">

        <a href="configuracoes.php" class="sidebar-user-link" aria-label="Abrir configurações da conta">
            <section class="sidebar-user">

                <?php if ($sidebarFotoPerfil): ?>
                    <img class="user-avatar" src="../<?= sidebarEscapar($sidebarFotoPerfil) ?>" alt="Foto de perfil">
                <?php else: ?>
                    <section class="user-avatar">
                        <?= sidebarEscapar($sidebarPrimeiraLetra) ?>
                    </section>
                <?php endif; ?>

                <section class="user-info">
                    <strong><?= sidebarEscapar($sidebarNomeUsuario) ?></strong>
                    <span><?= sidebarEscapar($sidebarRotuloPapel) ?></span>
                </section>
            </section>
        </a>

        <section style="margin-bottom: 10px"></section>

        <a href="logout.php" class="logout-link">
            <span>↪</span>
            Sair da conta
        </a>

    </section>

</aside>

<script>
document.documentElement.dataset.theme = <?= json_encode($sidebarTema, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
</script>
