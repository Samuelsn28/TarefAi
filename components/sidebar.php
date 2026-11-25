
<?php

$sidebarNomeUsuario = $_SESSION['nome'] ?? 'Usuário';
$sidebarIsAdmin = (($_SESSION['role'] ?? '') === 'admin');

// =============================================
// IDENTIFICAÇÃO DA PÁGINA ATUAL
// =============================================

$sidebarArquivoAtual = basename($_SERVER['SCRIPT_NAME'] ?? '');

$sidebarPaginaAtiva = match ($sidebarArquivoAtual) {
    'dashboard.php' => 'dashboard',
    'projetos.php', 'projeto.php' => 'projetos',
    'tarefas.php' => 'tarefas',
    'categorias.php' => 'categorias',
    'usuarios.php' => 'usuarios',
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
        height: 100vh;
        max-height: 100vh;
        overflow: hidden;
        background: #ffffff;
        border-right: 1px solid #eceef4;
        padding: 28px 18px;
        display: flex;
        flex-direction: column;
        flex-shrink: 0;
        box-sizing: border-box;
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
        color: #20243a;
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
        overflow: hidden;
    }

    .nav-label {
        font-size: 10px;
        font-weight: 800;
        color: #a1a4b5;
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
        color: #777b91;
        text-decoration: none;
        transition: 0.2s;
        flex-shrink: 0;
    }

    .nav-item:hover {
        background: #f3f2ff;
        color: #635bdb;
    }

    .nav-item.active {
        background: #efedff;
        color: #635bdb;
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
        border-top: 1px solid #eceef4;
        flex-shrink: 0;
    }

    .sidebar-user {
        display: flex;
        align-items: center;
        gap: 11px;
        margin-bottom: 20px;
        padding: 0 5px;
        min-width: 0;
    }

    .user-avatar {
        width: 38px;
        height: 38px;
        min-width: 38px;
        border-radius: 50%;
        background: #eeecff;
        color: #635bdb;
        display: flex;
        justify-content: center;
        align-items: center;
        font-weight: 800;
    }

    .user-info {
        display: flex;
        flex-direction: column;
        gap: 4px;
        min-width: 0;
    }

    .user-info strong {
        font-size: 13px;
        color: #20243a;
        overflow-wrap: anywhere;
    }

    .user-info span {
        font-size: 11px;
        color: #85899e;
    }

    .logout-link {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px;
        color: #e05d65;
        font-size: 13px;
        font-weight: 600;
        border-radius: 8px;
        text-decoration: none;
    }

    .logout-link:hover {
        background: #fff1f1;
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

        <span>TarefAi</span>

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

        <?php if ($sidebarIsAdmin): ?>

            <span class="nav-label">ADMINISTRAÇÃO</span>

            <a
                href="usuarios.php"
                class="nav-item <?= $sidebarPaginaAtiva === 'usuarios' ? 'active' : '' ?>"
                <?= $sidebarPaginaAtiva === 'usuarios' ? 'aria-current="page"' : '' ?>
            >
                <span class="nav-icon">♙</span>
                Usuários
            </a>

        <?php endif; ?>

    </nav>

    <!-- USUÁRIO E LOGOUT -->

    <section class="sidebar-bottom">

        <section class="sidebar-user">

            <section class="user-avatar">
                <?= sidebarEscapar($sidebarPrimeiraLetra) ?>
            </section>

            <section class="user-info">

                <strong>
                    <?= sidebarEscapar($sidebarNomeUsuario) ?>
                </strong>

                <span>
                    <?= $sidebarIsAdmin ? 'Administrador' : 'Usuário' ?>
                </span>

            </section>

        </section>

        <a href="logout.php" class="logout-link">
            <span>↪</span>
            Sair da conta
        </a>

    </section>

</aside>
