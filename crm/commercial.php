<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/settings.php';

crm_require_admin();

$activeTab = (string) ($_GET['tab'] ?? 'usuarios');

if (!in_array($activeTab, ['usuarios', 'distribuicao'], true)) {
    $activeTab = 'usuarios';
}

$saved = ($_GET['saved'] ?? '') === '1';
$error = '';
$settings = crm_read_settings();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    crm_require_valid_csrf();
    $action = (string) ($_POST['commercial_action'] ?? '');

    if ($action === 'save_user') {
        $result = crm_save_user([
            'id' => $_POST['user_id'] ?? 0,
            'name' => $_POST['name'] ?? '',
            'username' => $_POST['username'] ?? '',
            'email' => $_POST['email'] ?? '',
            'password' => $_POST['password'] ?? '',
            'role' => $_POST['role'] ?? 'vendedor',
            'active' => ($_POST['active'] ?? '') === '1',
            'receive_lead_notifications' => ($_POST['receive_lead_notifications'] ?? '') === '1',
            'participates_in_rotation' => ($_POST['participates_in_rotation'] ?? '') === '1',
            'rotation_weight' => $_POST['rotation_weight'] ?? 1,
            'access_schedule_enabled' => ($_POST['access_schedule_enabled'] ?? '') === '1',
            'access_start_time' => $_POST['access_start_time'] ?? '09:00',
            'access_end_time' => $_POST['access_end_time'] ?? '18:00',
            'access_saturday_enabled' => ($_POST['access_saturday_enabled'] ?? '') === '1',
            'access_sunday_enabled' => ($_POST['access_sunday_enabled'] ?? '') === '1',
        ]);

        if (($result['ok'] ?? false) === true) {
            header('Location: commercial.php?tab=usuarios&saved=1');
            exit;
        }

        $activeTab = 'usuarios';
        $error = (string) ($result['error'] ?? 'Não foi possível salvar o usuário.');
    }

    if ($action === 'delete_user') {
        $result = crm_delete_user((int) ($_POST['user_id'] ?? 0));

        if (($result['ok'] ?? false) === true) {
            header('Location: commercial.php?tab=usuarios&saved=1');
            exit;
        }

        $activeTab = 'usuarios';
        $error = (string) ($result['error'] ?? 'Não foi possível excluir o usuário.');
    }

    if ($action === 'save_distribution') {
        $settings['sales_rotation_enabled'] = (($_POST['sales_rotation_enabled'] ?? '') === '1');
        $settings['sales_sla_enabled'] = (($_POST['sales_sla_enabled'] ?? '') === '1');
        $settings['sales_sla_inactivity_minutes'] = max(15, min(10080, (int) ($_POST['sales_sla_inactivity_minutes'] ?? 240)));
        $settings['sales_sla_warning_minutes'] = 0;
        $settings['sales_sla_action'] = in_array((string) ($_POST['sales_sla_action'] ?? 'rotation'), ['rotation', 'manager_review'], true)
            ? (string) $_POST['sales_sla_action']
            : 'rotation';
        $settings['sales_sla_respect_access_schedule'] = (($_POST['sales_sla_respect_access_schedule'] ?? '') === '1');
        $settings['sales_sla_statuses'] = crm_normalize_sales_sla_statuses($_POST['sales_sla_statuses'] ?? []);
        crm_write_settings($settings);

        header('Location: commercial.php?tab=distribuicao&saved=1');
        exit;
    }
}

$users = crm_read_users(true);
$activeUsers = array_values(array_filter($users, static fn(array $user): bool => (int) ($user['active'] ?? 0) === 1));
$rotationUsers = array_values(array_filter($users, static fn(array $user): bool => (int) ($user['active'] ?? 0) === 1 && (int) ($user['participates_in_rotation'] ?? 0) === 1));
$userRoles = crm_user_roles();
$kanbanColumns = crm_read_kanban_columns();
$salesDistributionSettings = crm_sales_distribution_settings();
$slaStatusKeys = array_flip($salesDistributionSettings['sla_statuses']);
$overdueLeads = crm_read_sla_overdue_leads(20);
?>
<!doctype html>
<html lang="pt-BR">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Comercial | CRM</title>
    <link rel="stylesheet" href="./assets/crm.css?v=20260901-mobile-responsive-v2" />
  </head>
  <body class="settings-page commercial-page">
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
          <a href="dashboard.php" title="Dashboard do gestor" aria-label="Dashboard do gestor">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M4 19V5" />
              <path d="M4 19h16" />
              <path d="M8 16V9" />
              <path d="M12 16V7" />
              <path d="M16 16v-5" />
            </svg>
          </a>
          <a class="active" href="commercial.php" title="Área comercial" aria-label="Área comercial">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M8 10.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" />
              <path d="M16 11a2.7 2.7 0 1 0 0-5.4 2.7 2.7 0 0 0 0 5.4Z" />
              <path d="M3.5 19.5v-1.2A4.5 4.5 0 0 1 8 13.8a4.5 4.5 0 0 1 4.5 4.5v1.2" />
              <path d="M13.4 14.2c.8-.5 1.7-.8 2.6-.8a4.2 4.2 0 0 1 4.2 4.2v1.9" />
            </svg>
          </a>
          <a href="whatsapp-templates.php" title="Templates WhatsApp" aria-label="Templates WhatsApp">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4.5h14v15H5z" /><path d="M8 8h8M8 12h8M8 16h5" /></svg>
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
            <a class="active" href="commercial.php">Comercial</a>
            <a href="whatsapp-templates.php">Templates</a>
            <a href="settings.php">Configurações</a>
          </nav>
        </header>

        <header class="app-header">
          <div>
            <p class="eyebrow">Gestão comercial</p>
            <h1>Equipe, roleta e SLA</h1>
          </div>
        </header>

        <?php if ($saved): ?>
          <div class="alert success">Área comercial salva.</div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
          <div class="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <main class="dashboard settings-layout commercial-layout">
          <section class="commercial-summary metrics">
            <article>
              <span>Usuários ativos</span>
              <strong><?= count($activeUsers) ?></strong>
            </article>
            <article>
              <span>Na roleta</span>
              <strong><?= count($rotationUsers) ?></strong>
            </article>
            <article>
              <span>SLA</span>
              <strong><?= $salesDistributionSettings['sla_enabled'] ? 'Ativo' : 'Off' ?></strong>
            </article>
            <article>
              <span>Leads parados</span>
              <strong><?= count($overdueLeads) ?></strong>
            </article>
          </section>

          <nav class="commercial-tabs" aria-label="Seções comerciais">
            <a class="<?= $activeTab === 'usuarios' ? 'active' : '' ?>" href="commercial.php?tab=usuarios">Usuários</a>
            <a class="<?= $activeTab === 'distribuicao' ? 'active' : '' ?>" href="commercial.php?tab=distribuicao">Distribuição</a>
          </nav>

          <?php if ($activeTab === 'usuarios'): ?>
            <section class="commercial-tab-panel">
              <section class="automation-card integration-card commercial-user-create">
                <header class="integration-card-header">
                  <span class="integration-icon sales-icon" aria-hidden="true">
                    <svg viewBox="0 0 48 48" focusable="false">
                      <circle cx="18" cy="17" r="6" fill="#0ea5e9" />
                      <circle cx="31" cy="19" r="5" fill="#22c55e" />
                      <path fill="#0369a1" d="M8 38c.8-7.1 4.4-11 10-11s9.2 3.9 10 11H8Z" />
                      <path fill="#15803d" d="M25 38c.6-5.4 3.4-8.5 7.7-8.5 4.4 0 7.1 3.1 7.8 8.5H25Z" />
                    </svg>
                  </span>
                  <div>
                    <p class="integration-kicker">Novo acesso</p>
                    <h2>Cadastrar usuário</h2>
                  </div>
                  <span class="integration-status">Novo</span>
                </header>
                <form class="flow-form commercial-form" method="post">
                  <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
                  <input type="hidden" name="commercial_action" value="save_user" />
                  <input type="hidden" name="user_id" value="0" />
                  <label>
                    Nome
                    <input type="text" name="name" autocomplete="name" required />
                  </label>
                  <label>
                    Usuário
                    <input type="text" name="username" autocomplete="username" required />
                  </label>
                  <label>
                    E-mail
                    <input type="email" name="email" autocomplete="email" />
                  </label>
                  <label>
                    Perfil
                    <select name="role">
                      <?php foreach ($userRoles as $role => $label): ?>
                        <option value="<?= htmlspecialchars($role) ?>" <?= $role === 'vendedor' ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label>
                    Senha inicial
                    <input type="password" name="password" autocomplete="new-password" required />
                  </label>
                  <label>
                    Peso na roleta
                    <input type="number" name="rotation_weight" value="1" min="1" max="10" />
                  </label>
                  <label class="checkbox-field">
                    <input type="checkbox" name="active" value="1" checked />
                    <span>Usuário ativo</span>
                  </label>
                  <label class="checkbox-field">
                    <input type="checkbox" name="receive_lead_notifications" value="1" checked />
                    <span>Receber notificações de leads</span>
                  </label>
                  <label class="checkbox-field">
                    <input type="checkbox" name="participates_in_rotation" value="1" checked />
                    <span>Participa da roleta</span>
                  </label>
                  <div class="settings-subgroup field-wide access-schedule-settings">
                    <div>
                      <p class="integration-kicker">Controle de acesso</p>
                      <h3>Horário do vendedor</h3>
                    </div>
                    <label class="checkbox-field">
                      <input type="checkbox" name="access_schedule_enabled" value="1" checked />
                      <span>Bloquear acesso fora do horário definido</span>
                    </label>
                    <div class="access-time-fields">
                      <label>
                        Início do acesso
                        <input type="time" name="access_start_time" value="09:00" required />
                      </label>
                      <label>
                        Fim do acesso
                        <input type="time" name="access_end_time" value="18:00" required />
                      </label>
                    </div>
                    <div class="access-weekend-fields" aria-label="Acesso no fim de semana">
                      <label class="checkbox-field">
                        <input type="checkbox" name="access_saturday_enabled" value="1" checked />
                        <span>Permitir acesso no sábado</span>
                      </label>
                      <label class="checkbox-field">
                        <input type="checkbox" name="access_sunday_enabled" value="1" checked />
                        <span>Permitir acesso no domingo</span>
                      </label>
                    </div>
                    <p class="commercial-help">Aplicado somente ao perfil Vendedor. Nos dias permitidos, o acesso segue o horário definido acima. Com a pausa do SLA ativa, esse mesmo calendário controla quando a inatividade pode ser cobrada.</p>
                  </div>
                  <button class="integration-save" type="submit">
                    <span aria-hidden="true">✓</span>
                    Criar usuário
                  </button>
                </form>
              </section>

              <section class="automation-card commercial-users-card">
                <header class="settings-group-header">
                  <div>
                    <p class="eyebrow">Equipe</p>
                    <h2>Usuários cadastrados</h2>
                  </div>
                </header>

                <div class="commercial-user-list">
                  <?php foreach ($users as $crmUser): ?>
                    <?php $crmUserId = (int) ($crmUser['id'] ?? 0); ?>
                    <details class="commercial-user-row">
                      <summary>
                        <span class="wa-avatar"><?= htmlspecialchars(strtoupper(substr(trim((string) ($crmUser['name'] ?? 'U')) ?: 'U', 0, 1))) ?></span>
                        <span>
                          <strong><?= htmlspecialchars(crm_user_label($crmUser)) ?></strong>
                          <small><?= htmlspecialchars((string) ($crmUser['username'] ?? '')) ?> · <?= htmlspecialchars($userRoles[(string) ($crmUser['role'] ?? '')] ?? 'Usuário') ?></small>
                        </span>
                        <em class="<?= (int) ($crmUser['active'] ?? 0) === 1 ? 'is-active' : '' ?>">
                          <?= (int) ($crmUser['active'] ?? 0) === 1 ? 'Ativo' : 'Inativo' ?>
                        </em>
                        <b><?= (int) ($crmUser['participates_in_rotation'] ?? 0) === 1 ? 'Roleta' : 'Fora da roleta' ?></b>
                      </summary>

                      <div class="commercial-user-editor">
                        <form class="flow-form commercial-form" method="post">
                          <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
                          <input type="hidden" name="commercial_action" value="save_user" />
                          <input type="hidden" name="user_id" value="<?= $crmUserId ?>" />
                          <label>
                            Nome
                            <input type="text" name="name" value="<?= htmlspecialchars((string) ($crmUser['name'] ?? '')) ?>" required />
                          </label>
                          <label>
                            Usuário
                            <input type="text" name="username" value="<?= htmlspecialchars((string) ($crmUser['username'] ?? '')) ?>" required />
                          </label>
                          <label>
                            E-mail
                            <input type="email" name="email" value="<?= htmlspecialchars((string) ($crmUser['email'] ?? '')) ?>" />
                          </label>
                          <label>
                            Perfil
                            <select name="role">
                              <?php foreach ($userRoles as $role => $label): ?>
                                <option value="<?= htmlspecialchars($role) ?>" <?= (string) ($crmUser['role'] ?? '') === $role ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                              <?php endforeach; ?>
                            </select>
                          </label>
                          <label>
                            Nova senha
                            <input type="password" name="password" autocomplete="new-password" placeholder="Preencha só para trocar" />
                          </label>
                          <label>
                            Peso na roleta
                            <input type="number" name="rotation_weight" value="<?= max(1, (int) ($crmUser['rotation_weight'] ?? 1)) ?>" min="1" max="10" />
                          </label>
                          <label class="checkbox-field">
                            <input type="checkbox" name="active" value="1" <?= (int) ($crmUser['active'] ?? 0) === 1 ? 'checked' : '' ?> />
                            <span>Usuário ativo</span>
                          </label>
                          <label class="checkbox-field">
                            <input type="checkbox" name="receive_lead_notifications" value="1" <?= (int) ($crmUser['receive_lead_notifications'] ?? 1) === 1 ? 'checked' : '' ?> />
                            <span>Receber notificações de leads</span>
                          </label>
                          <label class="checkbox-field">
                            <input type="checkbox" name="participates_in_rotation" value="1" <?= (int) ($crmUser['participates_in_rotation'] ?? 0) === 1 ? 'checked' : '' ?> />
                            <span>Participa da roleta</span>
                          </label>
                          <div class="settings-subgroup field-wide access-schedule-settings">
                            <div>
                              <p class="integration-kicker">Controle de acesso</p>
                              <h3>Horário do vendedor</h3>
                            </div>
                            <label class="checkbox-field">
                              <input type="checkbox" name="access_schedule_enabled" value="1" <?= crm_user_access_schedule_enabled($crmUser) ? 'checked' : '' ?> />
                              <span>Bloquear acesso fora do horário definido</span>
                            </label>
                            <div class="access-time-fields">
                              <label>
                                Início do acesso
                                <input type="time" name="access_start_time" value="<?= htmlspecialchars(substr(crm_normalize_user_access_time((string) ($crmUser['access_start_time'] ?? ''), '09:00:00'), 0, 5)) ?>" required />
                              </label>
                              <label>
                                Fim do acesso
                                <input type="time" name="access_end_time" value="<?= htmlspecialchars(substr(crm_normalize_user_access_time((string) ($crmUser['access_end_time'] ?? ''), '18:00:00'), 0, 5)) ?>" required />
                              </label>
                            </div>
                            <div class="access-weekend-fields" aria-label="Acesso no fim de semana">
                              <label class="checkbox-field">
                                <input type="checkbox" name="access_saturday_enabled" value="1" <?= crm_user_access_day_enabled($crmUser, 6) ? 'checked' : '' ?> />
                                <span>Permitir acesso no sábado</span>
                              </label>
                              <label class="checkbox-field">
                                <input type="checkbox" name="access_sunday_enabled" value="1" <?= crm_user_access_day_enabled($crmUser, 7) ? 'checked' : '' ?> />
                                <span>Permitir acesso no domingo</span>
                              </label>
                            </div>
                            <p class="commercial-help">Aplicado somente ao perfil Vendedor. Nos dias permitidos, o acesso segue o horário definido acima. Com a pausa do SLA ativa, esse mesmo calendário controla quando a inatividade pode ser cobrada.</p>
                          </div>
                          <button class="integration-save" type="submit">
                            <span aria-hidden="true">✓</span>
                            Salvar usuário
                          </button>
                        </form>

                        <form class="commercial-delete-form" method="post" onsubmit="return confirm('Excluir este usuário? Leads atribuídos a ele ficarão sem vendedor.');">
                          <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
                          <input type="hidden" name="commercial_action" value="delete_user" />
                          <input type="hidden" name="user_id" value="<?= $crmUserId ?>" />
                          <button type="submit" class="danger">Excluir usuário</button>
                        </form>
                      </div>
                    </details>
                  <?php endforeach; ?>
                </div>
              </section>
            </section>
          <?php endif; ?>

          <?php if ($activeTab === 'distribuicao'): ?>
            <section class="commercial-tab-panel">
              <section class="automation-card integration-card">
                <header class="integration-card-header">
                  <span class="integration-icon sales-icon" aria-hidden="true">
                    <svg viewBox="0 0 48 48" focusable="false">
                      <circle cx="18" cy="17" r="6" fill="#0ea5e9" />
                      <circle cx="31" cy="19" r="5" fill="#22c55e" />
                      <path fill="#0369a1" d="M8 38c.8-7.1 4.4-11 10-11s9.2 3.9 10 11H8Z" />
                      <path fill="#15803d" d="M25 38c.6-5.4 3.4-8.5 7.7-8.5 4.4 0 7.1 3.1 7.8 8.5H25Z" />
                    </svg>
                  </span>
                  <div>
                    <p class="integration-kicker">Roleta e SLA</p>
                    <h2>Distribuição comercial</h2>
                  </div>
                  <span class="integration-status <?= $salesDistributionSettings['rotation_enabled'] ? 'is-active' : '' ?>">
                    <?= $salesDistributionSettings['rotation_enabled'] ? 'Roleta ativa' : 'Manual' ?>
                  </span>
                </header>

                <form class="flow-form commercial-form" method="post">
                  <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
                  <input type="hidden" name="commercial_action" value="save_distribution" />
                  <label class="checkbox-field">
                    <input type="checkbox" name="sales_rotation_enabled" value="1" <?= $salesDistributionSettings['rotation_enabled'] ? 'checked' : '' ?> />
                    <span>Usar roleta em novos leads</span>
                  </label>
                  <p class="commercial-help">Quando ativa, todo lead novo sem vendedor é entregue automaticamente ao próximo vendedor ativo que participa da roleta.</p>
                  <?php if ($salesDistributionSettings['rotation_enabled'] && count($rotationUsers) === 0): ?>
                    <p class="commercial-help is-warning">Cadastre ou marque pelo menos um vendedor ativo como “Participa da roleta” para a distribuição funcionar.</p>
                  <?php endif; ?>
                  <label class="checkbox-field">
                    <input type="checkbox" name="sales_sla_enabled" value="1" <?= $salesDistributionSettings['sla_enabled'] ? 'checked' : '' ?> />
                    <span>Redistribuir lead sem atividade</span>
                  </label>
                  <label>
                    Tempo sem atividade
                    <input type="number" name="sales_sla_inactivity_minutes" value="<?= (int) $salesDistributionSettings['sla_inactivity_minutes'] ?>" min="15" max="10080" step="15" />
                  </label>
                  <label class="checkbox-field field-wide">
                    <input type="checkbox" name="sales_sla_respect_access_schedule" value="1" <?= $salesDistributionSettings['sla_respect_access_schedule'] ? 'checked' : '' ?> />
                    <span>Pausar redistribuição fora do horário do vendedor</span>
                  </label>
                  <p class="commercial-help field-wide">Quando ativado, o tempo sem atividade só conta durante o horário em que o vendedor pode acessar o CRM. De segunda a sexta, considera a faixa definida no usuário; sábado e domingo só contam se estiverem liberados para ele.</p>
                  <label>
                    Ao vencer
                    <select name="sales_sla_action">
                      <option value="rotation" <?= $salesDistributionSettings['sla_action'] === 'rotation' ? 'selected' : '' ?>>Voltar para roleta</option>
                      <option value="manager_review" <?= $salesDistributionSettings['sla_action'] === 'manager_review' ? 'selected' : '' ?>>Enviar para revisão do gestor</option>
                    </select>
                  </label>
                  <div class="settings-subgroup commercial-status-list">
                    <div>
                      <p class="integration-kicker">Etapas</p>
                      <h3>Aplicar SLA em</h3>
                    </div>
                    <?php foreach ($kanbanColumns as $column): ?>
                      <?php $columnStatus = (string) ($column['status'] ?? ''); ?>
                      <label class="checkbox-field">
                        <input type="checkbox" name="sales_sla_statuses[]" value="<?= htmlspecialchars($columnStatus) ?>" <?= isset($slaStatusKeys[$columnStatus]) ? 'checked' : '' ?> />
                        <span><?= htmlspecialchars((string) ($column['label'] ?? $columnStatus)) ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                  <button class="integration-save" type="submit">
                    <span aria-hidden="true">✓</span>
                    Salvar distribuição
                  </button>
                </form>

                <form class="integration-test-form" method="post" action="run-sla.php">
                  <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
                  <button class="integration-test" type="submit">Verificar leads parados agora</button>
                </form>
              </section>
            </section>
          <?php endif; ?>
        </main>
      </div>
    </div>
    <script src="./assets/crm-navigation.js?v=20260812-fast-navigation-v3"></script>
  </body>
</html>
