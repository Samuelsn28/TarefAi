<?php
require_once __DIR__ . '/lib/auth.php';

// Usuários autenticados seguem diretamente para o painel.
if (!empty($_SESSION['usuario_id'])) {
    header('Location: pages/dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="TarefAi: organize projetos, acompanhe tarefas e mantenha sua equipe em sintonia.">
    <title>TarefAi | Organize ideias. Faça acontecer.</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/landing.css">
</head>
<body>
<header class="site-header">
    <nav class="nav container" aria-label="Navegação principal">
        <a class="brand" href="index.php" aria-label="TarefAi - início">
            <span class="brand-mark">T</span><span>Taref<span class="brand-ai">Ai</span></span>
        </a>
        <section class="nav-links">
            <a href="#recursos">Recursos</a>
            <a href="#como-funciona">Como funciona</a>
        </section>
        <section class="nav-actions">
            <a class="login-link" href="pages/login.php">Entrar</a>
            <a class="button button-small" href="pages/cadastro.php">Criar conta <span aria-hidden="true">↗</span></a>
        </section>
    </nav>
</header>

<main>
    <section class="hero container">
        <section class="hero-copy">
            <section class="eyebrow"><span class="eyebrow-dot"></span> MENOS CONFUSÃO, MAIS REALIZAÇÃO</section>
            <h1>Suas ideias merecem sair do <span>papel.</span></h1>
            <p class="hero-description">Organize projetos, acompanhe tarefas e trabalhe em equipe sem perder o fio da meada. Tudo o que você precisa para transformar planos em progresso.</p>
            <section class="hero-actions">
                <a class="button" href="pages/cadastro.php">Começar agora <span aria-hidden="true">→</span></a>
                <a class="secondary-link" href="#recursos">Conhecer a plataforma <span aria-hidden="true">↓</span></a>
            </section>
            <section class="hero-note"><span class="note-check">✓</span> Um espaço simples para manter tudo em movimento.</section>
        </section>

        <section class="hero-visual" aria-label="Prévia ilustrativa do painel TarefAi">
            <section class="visual-glow"></section>
            <section class="dashboard-window">
                <section class="window-top">
                    <section class="window-dots"><i></i><i></i><i></i></section>
                    <span>tarefai / painel</span>
                    <span class="window-avatar">A</span>
                </section>
                <section class="preview-content">
                    <section class="preview-sidebar">
                        <section class="mini-logo">T</section>
                        <section class="side-line active"></section><section class="side-line"></section><section class="side-line"></section><section class="side-line short"></section>
                        <section class="side-bottom"></section>
                    </section>
                    <section class="preview-main">
                        <section class="preview-greeting">SEU ESPAÇO DE TRABALHO</section>
                        <section class="preview-title">Visão geral</section>
                        <section class="preview-stats">
                            <section class="preview-stat"><span>Projetos</span><strong>08</strong><b class="stat-purple"></b></section>
                            <section class="preview-stat"><span>Tarefas</span><strong>24</strong><b class="stat-blue"></b></section>
                            <section class="preview-stat"><span>Concluídas</span><strong>16</strong><b class="stat-green"></b></section>
                        </section>
                        <section class="preview-lower">
                            <section class="preview-panel">
                                <section class="panel-heading">Projetos recentes <span>•••</span></section>
                                <section class="project-row"><i class="project-icon purple-bg">✦</i><section><b>Projeto Aurora</b><small>Equipe de criação</small></section><em>Ativo</em></section>
                                <section class="project-row"><i class="project-icon blue-bg">◈</i><section><b>Site institucional</b><small>Desenvolvimento</small></section><em>Ativo</em></section>
                                <section class="project-row"><i class="project-icon peach-bg">⌁</i><section><b>Planejamento</b><small>Organização</small></section><em>Em dia</em></section>
                            </section>
                            <section class="preview-panel task-panel">
                                <section class="panel-heading">Suas tarefas <span>＋</span></section>
                                <section class="task-row"><i class="checked">✓</i><span class="done">Definir objetivos</span></section>
                                <section class="task-row"><i></i><span>Revisar apresentação</span></section>
                                <section class="task-row"><i></i><span>Alinhar próximos passos</span></section>
                                <section class="progress-label"><span>Progresso</span><b>68%</b></section>
                                <section class="progress-track"><span></span></section>
                            </section>
                        </section>
                    </section>
                </section>
            </section>
            <section class="floating-card floating-check"><span>✓</span><section><b>Mais uma tarefa concluída</b><small>Bom trabalho!</small></section></section>
            <section class="floating-card floating-team"><section class="avatar-stack"><i>A</i><i>M</i><i>+</i></section><section><b>Trabalho em equipe</b><small>Todo mundo na mesma página</small></section></section>
        </section>
        <section class="hero-orb orb-one"></section><section class="hero-orb orb-two"></section>
    </section>

    <section class="trust-strip">
        <section class="container trust-inner"><span>UM LUGAR PARA CADA PARTE DO SEU PROJETO</span><section><b>Planeje</b><i>✳</i><b>Organize</b><i>✳</i><b>Colabore</b><i>✳</i><b>Conclua</b></section></section>
    </section>

    <section class="features section container" id="recursos">
        <section class="section-heading">
            <section class="eyebrow">TUDO EM UM SÓ LUGAR</section>
            <h2>Clareza para trabalhar.<br><span>Liberdade para criar.</span></h2>
            <p>Chega de informações espalhadas. Tenha uma visão clara do que precisa ser feito e do que já foi conquistado.</p>
        </section>
        <section class="feature-grid">
            <article class="feature-card">
                <section class="feature-icon icon-purple">▦</section>
                <h3>Projetos organizados</h3>
                <p>Reúna as informações de cada iniciativa e acompanhe seus projetos em um só espaço.</p>
                <span class="feature-number">01</span>
            </article>
            <article class="feature-card featured-card">
                <section class="feature-icon icon-blue">✓</section>
                <h3>Tarefas sob controle</h3>
                <p>Registre atividades, acompanhe o andamento e marque cada etapa concluída.</p>
                <span class="feature-number">02</span>
            </article>
            <article class="feature-card">
                <section class="feature-icon icon-orange">◈</section>
                <h3>Equipe em sintonia</h3>
                <p>Compartilhe projetos com gestores e mantenha cada pessoa conectada ao que importa.</p>
                <span class="feature-number">03</span>
            </article>
        </section>
    </section>

    <section class="how-section" id="como-funciona">
        <section class="container how-inner">
            <section class="how-copy">
                <section class="eyebrow">COMECE SEM COMPLICAÇÃO</section>
                <h2>Do primeiro passo<br>ao <span>feito.</span></h2>
                <p>O TarefAi foi pensado para deixar a organização mais leve. Crie seu espaço e comece a dar forma aos seus planos.</p>
                <a class="button" href="pages/cadastro.php">Criar minha conta <span aria-hidden="true">→</span></a>
            </section>
            <section class="steps">
                <section class="step"><span>01</span><section><h3>Crie sua conta</h3><p>Entre na plataforma e configure seu espaço de trabalho.</p></section></section>
                <section class="step"><span>02</span><section><h3>Monte seus projetos</h3><p>Cadastre projetos e organize as tarefas de cada um.</p></section></section>
                <section class="step"><span>03</span><section><h3>Acompanhe o progresso</h3><p>Veja o que está em andamento e celebre o que foi concluído.</p></section></section>
            </section>
        </section>
    </section>

    <section class="final-cta container">
        <section><section class="eyebrow">SEU PRÓXIMO PASSO COMEÇA AQUI</section><h2>Vamos tirar esse projeto do papel?</h2><p>Organize suas ideias e faça acontecer com o TarefAi.</p></section>
        <a class="button button-light" href="pages/cadastro.php">Começar agora <span aria-hidden="true">↗</span></a>
        <section class="cta-decoration">T</section>
    </section>
</main>

<footer class="site-footer">
    <section class="container footer-inner">
        <a class="brand footer-brand" href="index.php"><span class="brand-mark">T</span><span>Taref<span class="brand-ai">Ai</span></span></a>
        <span>Organize ideias. Faça acontecer.</span>
        <span>© <?= date('Y') ?> TarefAi</span>
    </section>
</footer>
</body>
</html>
