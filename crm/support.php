<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

crm_require_admin();

$supportSections = [
    [
        'kicker' => 'Acesso',
        'title' => 'Usuários comerciais',
        'items' => [
            ['Cadastro de vendedores', 'Cria acessos individuais para cada pessoa do comercial trabalhar no CRM com login próprio.'],
            ['Editar usuário', 'Atualiza nome, usuário, e-mail, perfil, senha, participação na roleta e peso de distribuição.'],
            ['Excluir usuário', 'Remove o acesso e deixa os leads desse usuário sem vendedor para o admin reorganizar.'],
            ['Usuário ativo', 'Define se o acesso pode entrar no CRM. Usuário inativo não recebe leads e não consegue operar.'],
            ['Horário do vendedor', 'Bloqueia o login fora da faixa configurada e define, para o SLA, quando aquele vendedor pode ser cobrado por um lead parado.'],
        ],
    ],
    [
        'kicker' => 'Perfis',
        'title' => 'Permissões e segurança',
        'items' => [
            ['Administrador (master)', 'Tem acesso total ao CRM e pode visualizar e responder todas as conversas, independentemente do vendedor atribuído, além de gerenciar as regras sensíveis.'],
            ['Gestor', 'Acompanha a operação comercial e dashboards, sem mexer nas configurações administrativas.'],
            ['Vendedor', 'Enxerga somente leads e conversas atribuídos a ele, protegendo a carteira dos outros vendedores.'],
            ['Conversas do WhatsApp', 'A aba de conversas respeita a mesma atribuição do lead: vendedor só vê conversa do próprio lead.'],
        ],
    ],
    [
        'kicker' => 'Distribuição',
        'title' => 'Roleta de leads',
        'items' => [
            ['Usar roleta em novos leads', 'Quando ligado, cada lead novo é atribuído automaticamente a um vendedor habilitado.'],
            ['Participa da roleta', 'Controla quais vendedores podem receber leads automaticamente.'],
            ['Peso da roleta', 'Permite dar mais ou menos volume para um vendedor. Peso maior aumenta a chance de receber leads.'],
            ['Atribuição manual', 'O admin ou gestor pode trocar o vendedor de um lead quando for necessário fazer uma exceção.'],
        ],
    ],
    [
        'kicker' => 'SLA',
        'title' => 'Redistribuição por inatividade',
        'items' => [
            ['Redistribuir lead sem atividade', 'Liga a regra que tira o lead parado de um vendedor e envia para outra pessoa ou para revisão.'],
            ['Tempo sem atividade', 'Define quanto tempo o lead pode ficar sem movimentação no kanban antes de acionar a regra.'],
            ['Pausar fora do horário do vendedor', 'Faz o cronômetro do SLA contar somente as horas em que o vendedor pode acessar o CRM, incluindo as permissões individuais de sábado e domingo.'],
            ['Ao vencer', 'Escolhe se o lead volta para a roleta ou se fica para revisão do gestor.'],
            ['Aplicar SLA em', 'Seleciona em quais etapas do kanban a regra vale, evitando redistribuir leads em fases que não precisam de cobrança.'],
            ['Verificar leads parados agora', 'Executa a checagem manual do SLA sem esperar a rotina automática.'],
        ],
    ],
];

$implementationSteps = [
    'Cadastrar todos os vendedores e gestores com perfil correto.',
    'Ativar somente quem deve receber lead novo na roleta.',
    'Definir o peso de cada vendedor conforme capacidade ou senioridade.',
    'Configurar o tempo de SLA considerando o padrão de atendimento da Sierra.',
    'Manter ativada a pausa do SLA fora do horário de acesso dos vendedores.',
    'Selecionar as etapas do kanban onde falta de movimento deve gerar redistribuição.',
    'Criar um lead de teste, validar atribuição, conversa e restrição de acesso por vendedor.',
];
?>
<!doctype html>
<html lang="pt-BR">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Suporte Interno | CRM</title>
    <link rel="stylesheet" href="./assets/crm.css?v=20260824-form-answers-v1" />
  </head>
  <body class="settings-page support-page">
    <div class="app-shell">
      <aside class="sidebar" aria-label="Navegação do CRM">
        <a class="brand" href="index.php" aria-label="Início">
          <span class="brand-mark"><img src="./assets/sierra-sidebar.svg?v=20260819-sidebar-logo-v2" alt="SIERRA" /></span>
        </a>
        <nav class="sidebar-tabs" aria-label="Atalhos do CRM">
          <a class="sidebar-whatsapp" href="whatsapp.php" title="Conversas do WhatsApp" aria-label="Conversas do WhatsApp">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M7.4 14.8H6.2a4 4 0 0 1-4-4V7.2a4 4 0 0 1 4-4h7.1a4 4 0 0 1 4 4v.6" />
              <path d="M10.7 8.2h6.2a4 4 0 0 1 4 4v2.7a4 4 0 0 1-4 4h-2.5L11 21v-2.1h-.3a4 4 0 0 1-4-4v-2.7a4 4 0 0 1 4-4Z" />
            </svg>
          </a>
          <a href="whatsapp-templates.php" title="Templates WhatsApp" aria-label="Templates WhatsApp">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4.5h14v15H5z" /><path d="M8 8h8M8 12h8M8 16h5" /></svg>
          </a>
          <a href="dashboard.php" title="Dashboard do gestor" aria-label="Dashboard do gestor">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M4 19V5" />
              <path d="M4 19h16" />
              <path d="M8 16V9" />
              <path d="M12 16V7" />
              <path d="M16 16v-5" />
            </svg>
          </a>
          <a href="commercial.php" title="Área comercial" aria-label="Área comercial">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M8 10.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" />
              <path d="M16 11a2.7 2.7 0 1 0 0-5.4 2.7 2.7 0 0 0 0 5.4Z" />
              <path d="M3.5 19.5v-1.2A4.5 4.5 0 0 1 8 13.8a4.5 4.5 0 0 1 4.5 4.5v1.2" />
              <path d="M13.4 14.2c.8-.5 1.7-.8 2.6-.8a4.2 4.2 0 0 1 4.2 4.2v1.9" />
            </svg>
          </a>
          <a class="active" href="support.php" title="Suporte interno" aria-label="Suporte interno">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <circle cx="12" cy="12" r="8.5" />
              <path d="M9.7 9.6a2.4 2.4 0 0 1 2.4-2 2.5 2.5 0 0 1 2.6 2.5c0 1.7-1.4 2.2-2.2 3-.4.4-.5.8-.5 1.4" />
              <path d="M12 17.2h.01" />
            </svg>
          </a>
        </nav>
        <a class="sidebar-exit" href="logout.php" title="Sair">Sair</a>
      </aside>

      <div class="workspace">
        <header class="topbar">
          <nav class="topbar-nav" aria-label="Áreas do CRM">
            <a href="index.php">Contatos</a>
            <a href="followups.php">Follow-up</a>
            <a href="dashboard.php">Dashboard</a>
            <a href="commercial.php">Comercial</a>
            <a href="whatsapp-templates.php">Templates</a>
            <a class="active" href="support.php">Suporte</a>
            <a href="settings.php">Configurações</a>
          </nav>
        </header>

        <header class="app-header">
          <div>
            <p class="eyebrow">Suporte interno</p>
            <h1>Manual da área comercial</h1>
          </div>
          <nav>
            <a href="commercial.php">Abrir comercial</a>
          </nav>
        </header>

        <main class="dashboard settings-layout support-layout">
          <section class="support-intro automation-card">
            <div>
              <p class="integration-kicker">Objetivo</p>
              <h2>Como operar equipe, roleta e SLA</h2>
            </div>
            <p>
              Esta página documenta os recursos criados para o controle comercial da Sierra:
              vendedores, atribuição de leads, proteção por carteira, conversas vinculadas e
              redistribuição quando um lead fica parado no kanban.
            </p>
          </section>

          <section class="support-grid">
            <?php foreach ($supportSections as $section): ?>
              <article class="automation-card support-card">
                <header>
                  <p class="integration-kicker"><?= htmlspecialchars($section['kicker']) ?></p>
                  <h2><?= htmlspecialchars($section['title']) ?></h2>
                </header>
                <dl class="support-definition-list">
                  <?php foreach ($section['items'] as $item): ?>
                    <div>
                      <dt><?= htmlspecialchars($item[0]) ?></dt>
                      <dd><?= htmlspecialchars($item[1]) ?></dd>
                    </div>
                  <?php endforeach; ?>
                </dl>
              </article>
            <?php endforeach; ?>
          </section>

          <section class="settings-group">
            <header class="settings-group-header">
              <div>
                <p class="eyebrow">Implantação</p>
                <h2>Etapas recomendadas para deixar completo</h2>
              </div>
            </header>
            <article class="automation-card support-steps-card">
              <ol class="support-steps">
                <?php foreach ($implementationSteps as $step): ?>
                  <li><?= htmlspecialchars($step) ?></li>
                <?php endforeach; ?>
              </ol>
            </article>
          </section>
        </main>
      </div>
    </div>
    <script src="./assets/crm-navigation.js?v=20260812-fast-navigation-v3"></script>
  </body>
</html>
