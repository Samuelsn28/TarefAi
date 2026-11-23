<?php

/**
 * Componente reutilizável do menu lateral do TarefAi.
 *
 * Antes de incluir este arquivo, a página deve:
 * 1. Incluir lib/database/connection.php e lib/auth.php.
 * 2. Executar exigirLogin().
 *
 * O componente utiliza as variáveis de sessão para identificar o usuário
 * e determinar se o link de administração deve ser exibido.
 */

// =============================================
// DADOS DO USUÁRIO
// =============================================

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
