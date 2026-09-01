<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/forms.php';

crm_require_login();

$canManageSales = crm_current_user_can_manage_sales();
$canManageSettings = crm_current_user_is_admin();
$assignableUsers = crm_read_assignable_users(false);
$leads = crm_read_lead_summaries();
$kanbanColumns = crm_read_kanban_columns();
$followupFlows = $canManageSettings ? crm_read_followup_flows(true) : [];
$scheduled = ($_GET['scheduled'] ?? '') === '1';
$calendarError = (string) ($_GET['calendar_error'] ?? '');
$calendarErrorMessages = [
    'not_connected' => 'Conecte o Google Agenda nas configurações antes de criar agendamentos.',
    'lead_not_found' => 'Contato não encontrado para criar agendamento.',
    'invalid_datetime' => 'Informe uma data e horário válidos para o agendamento.',
    'invalid_email' => 'Informe um e-mail de convidado válido.',
    'create_failed' => 'Não foi possível criar o evento no Google Agenda.',
];
$leadError = (string) ($_GET['error'] ?? '');
$leadErrorMessages = [
    'cpf_required' => 'Informe o CPF completo do lead antes de movê-lo para Fechado.',
];
$statusLabels = [];

foreach ($kanbanColumns as $column) {
    $statusLabels[(string) $column['status']] = (string) $column['label'];
}

$availableTags = crm_read_available_tags($leads);
$filterQuery = trim((string) ($_GET['q'] ?? ''));
$filterStatus = trim((string) ($_GET['status'] ?? ''));
$filterTag = trim((string) ($_GET['tag'] ?? ''));
$filterSeller = trim((string) ($_GET['seller'] ?? ''));
$filterSellerId = null;
$filterUnassigned = false;

if ($filterSeller === 'unassigned') {
    $filterUnassigned = true;
} elseif (preg_match('/^\d+$/', $filterSeller) === 1 && (int) $filterSeller > 0) {
    $filterSellerId = (int) $filterSeller;
} else {
    $filterSeller = '';
}

$normalizeFilterDate = static function (string $value): string {
    $value = trim($value);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return '';
    }

    [$year, $month, $day] = array_map('intval', explode('-', $value));

    return checkdate($month, $day, $year) ? $value : '';
};
$filterDateFrom = $normalizeFilterDate((string) ($_GET['date_from'] ?? ''));
$filterDateTo = $normalizeFilterDate((string) ($_GET['date_to'] ?? ''));
$filteredLeads = array_values(array_filter($leads, function (array $lead) use ($filterQuery, $filterStatus, $filterTag, $filterSellerId, $filterUnassigned, $filterDateFrom, $filterDateTo): bool {
    $createdDate = substr(trim((string) ($lead['created_at'] ?? '')), 0, 10);

    if ($filterDateFrom !== '' && ($createdDate === '' || $createdDate < $filterDateFrom)) {
        return false;
    }

    if ($filterDateTo !== '' && ($createdDate === '' || $createdDate > $filterDateTo)) {
        return false;
    }

    if ($filterStatus !== '' && (string) ($lead['status'] ?? '') !== $filterStatus) {
        return false;
    }

    if ($filterSellerId !== null && (int) ($lead['assigned_user_id'] ?? 0) !== $filterSellerId) {
        return false;
    }

    if ($filterUnassigned && (int) ($lead['assigned_user_id'] ?? 0) !== 0) {
        return false;
    }

    if ($filterTag !== '') {
        $tagKeys = array_map(
            static fn(string $tag): string => function_exists('mb_strtolower') ? mb_strtolower($tag, 'UTF-8') : strtolower($tag),
            crm_decode_lead_tags($lead)
        );
        $filterTagKey = function_exists('mb_strtolower') ? mb_strtolower($filterTag, 'UTF-8') : strtolower($filterTag);

        if (!in_array($filterTagKey, $tagKeys, true)) {
            return false;
        }
    }

    if ($filterQuery === '') {
        return true;
    }

    $haystack = implode(' ', [
        $lead['name'] ?? '',
        $lead['whatsapp'] ?? '',
        $lead['cpf'] ?? '',
        $lead['message'] ?? '',
        $lead['notes'] ?? '',
        implode(' ', crm_decode_lead_tags($lead)),
    ]);
    $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystack, 'UTF-8') : strtolower($haystack);
    $needle = function_exists('mb_strtolower') ? mb_strtolower($filterQuery, 'UTF-8') : strtolower($filterQuery);

    return str_contains($haystack, $needle);
}));
$filtersActive = $filterQuery !== '' || $filterStatus !== '' || $filterTag !== '' || $filterSeller !== '' || $filterDateFrom !== '' || $filterDateTo !== '';
$leadsByStatus = array_fill_keys(array_keys($statusLabels), []);

foreach ($filteredLeads as $lead) {
    $status = (string) ($lead['status'] ?? 'novo');

    if (!isset($leadsByStatus[$status])) {
        $status = 'novo';
    }

    $leadsByStatus[$status][] = $lead;
}

?>
<!doctype html>
<html lang="pt-BR">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="<?= htmlspecialchars(crm_csrf_token()) ?>" />
    <meta name="theme-color" content="#0b1018" />
    <meta name="apple-mobile-web-app-title" content="CRM Sierra" />
    <title>CRM Sierra</title>
    <link rel="manifest" href="./manifest.webmanifest" />
    <link rel="apple-touch-icon" sizes="180x180" href="./assets/icon-180.png?v=20260819-sierra-icon-v1" />
    <link rel="stylesheet" href="./assets/crm.css?v=20260901-mobile-composer-v1" />
  </head>
  <body class="leads-page">
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
          <?php if ($canManageSettings): ?>
            <a href="whatsapp-templates.php" title="Templates WhatsApp" aria-label="Templates WhatsApp">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M5 4.5h14v15H5z" />
                <path d="M8 8h8M8 12h8M8 16h5" />
              </svg>
            </a>
          <?php endif; ?>
          <?php if ($canManageSales): ?>
            <a href="dashboard.php" title="Dashboard do gestor" aria-label="Dashboard do gestor">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M4 19V5" />
                <path d="M4 19h16" />
                <path d="M8 16V9" />
                <path d="M12 16V7" />
                <path d="M16 16v-5" />
              </svg>
            </a>
          <?php endif; ?>
          <?php if ($canManageSettings): ?>
            <a href="commercial.php" title="Área comercial" aria-label="Área comercial">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M8 10.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" />
                <path d="M16 11a2.7 2.7 0 1 0 0-5.4 2.7 2.7 0 0 0 0 5.4Z" />
                <path d="M3.5 19.5v-1.2A4.5 4.5 0 0 1 8 13.8a4.5 4.5 0 0 1 4.5 4.5v1.2" />
                <path d="M13.4 14.2c.8-.5 1.7-.8 2.6-.8a4.2 4.2 0 0 1 4.2 4.2v1.9" />
              </svg>
            </a>
          <?php endif; ?>
        </nav>
        <a class="sidebar-exit" href="logout.php" title="Sair">Sair</a>
      </aside>

      <div class="workspace">
        <header class="topbar">
          <nav class="topbar-nav" aria-label="Áreas do CRM">
            <a class="active" href="index.php">Contatos</a>
            <?php if ($canManageSales): ?>
              <a href="followups.php">Follow-up</a>
              <a href="dashboard.php">Dashboard</a>
            <?php endif; ?>
            <?php if ($canManageSettings): ?>
              <a href="commercial.php">Comercial</a>
              <a href="whatsapp-templates.php">Templates</a>
              <a href="settings.php">Configurações</a>
            <?php endif; ?>
          </nav>
        </header>

        <header class="app-header">
          <div>
            <p class="eyebrow">CRM privado</p>
            <h1>Contatos</h1>
          </div>
          <nav>
            <?php if ($canManageSales): ?>
              <a href="followups.php">Criar fluxo</a>
            <?php endif; ?>
            <button type="button" data-open-dialog="contact">Criar contato</button>
            <button type="button" class="<?= $filtersActive ? 'is-active' : '' ?>" data-open-dialog="filters">Filtrar</button>
            <?php if ($canManageSettings): ?>
              <button type="button" data-open-dialog="kanban">Editar kanban</button>
              <a href="export.php">Exportar CSV</a>
            <?php endif; ?>
          </nav>
        </header>

    <main class="dashboard">
      <section class="push-onboarding" data-push-onboarding hidden>
        <div>
          <p class="eyebrow">Configuração rápida</p>
          <strong>Receba um aviso quando chegar um novo lead.</strong>
          <span class="push-status" data-push-status role="status" aria-live="polite"></span>
        </div>
        <button type="button" class="push-control" data-push-enable hidden>Ativar notificações</button>
      </section>
      <?php if ($scheduled): ?>
        <div class="alert success">Agendamento criado no Google Agenda.</div>
      <?php endif; ?>

      <?php if ($calendarError !== ''): ?>
        <div class="alert"><?= htmlspecialchars($calendarErrorMessages[$calendarError] ?? 'Erro ao criar agendamento.') ?></div>
      <?php endif; ?>

      <?php if ($leadError !== ''): ?>
        <div class="alert"><?= htmlspecialchars($leadErrorMessages[$leadError] ?? 'Não foi possível atualizar o lead.') ?></div>
      <?php endif; ?>

      <div class="utility-dialog" data-dialog="contact" hidden>
        <div class="utility-dialog-card">
          <header class="utility-dialog-header">
            <div>
              <p class="eyebrow">Novo contato</p>
              <h2>Criar contato</h2>
            </div>
            <button class="modal-close" type="button" data-close-dialog>×</button>
          </header>
          <form class="utility-form" method="post" action="create-lead.php">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
            <label>
              Nome
              <input type="text" name="name" autocomplete="name" required />
            </label>
            <label>
              WhatsApp
              <input type="tel" name="whatsapp" autocomplete="tel" placeholder="55DDDNUMERO" />
            </label>
            <label>
              CPF do cliente
              <input type="text" name="cpf" inputmode="numeric" autocomplete="off" placeholder="000.000.000-00" maxlength="14" data-cpf-input />
            </label>
            <label>
              Coluna
              <select name="status">
                <?php foreach ($statusLabels as $status => $label): ?>
                  <option value="<?= htmlspecialchars($status) ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <?php if ($canManageSales): ?>
              <label>
                Vendedor responsável
                <select name="assigned_user_id">
                  <option value="">Usar roleta ou deixar sem vendedor</option>
                  <?php foreach ($assignableUsers as $user): ?>
                    <option value="<?= (int) $user['id'] ?>"><?= htmlspecialchars(crm_user_label($user)) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            <?php endif; ?>
            <div class="tag-field">
              <label>
                Tags
                <input type="text" name="tags" placeholder="proposta, retorno, indicação" data-tags-input />
              </label>
              <span class="tag-preview" data-tags-preview hidden></span>
              <?php if (count($availableTags) > 0): ?>
                <div class="tag-suggestions" aria-label="Tags salvas">
                  <span>Tags salvas</span>
                  <div>
                    <?php foreach ($availableTags as $tag): ?>
                      <button type="button" class="tag-option" data-tag-option="<?= htmlspecialchars($tag) ?>"><?= htmlspecialchars($tag) ?></button>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endif; ?>
            </div>
            <label class="field-wide">
              Observação inicial
              <textarea name="message" rows="3"></textarea>
            </label>
            <button type="submit">Criar contato</button>
          </form>
        </div>
      </div>

      <div class="utility-dialog" data-dialog="filters" hidden>
        <div class="utility-dialog-card">
          <header class="utility-dialog-header">
            <div>
              <p class="eyebrow">Busca</p>
              <h2>Filtrar contatos</h2>
            </div>
            <button class="modal-close" type="button" data-close-dialog>×</button>
          </header>
          <form class="utility-form" method="get" action="index.php">
            <label class="field-wide">
              Buscar
              <input type="search" name="q" value="<?= htmlspecialchars($filterQuery) ?>" placeholder="Nome, CPF, telefone, tag ou observação" />
            </label>
            <label>
              Data inicial
              <input type="date" name="date_from" value="<?= htmlspecialchars($filterDateFrom) ?>" <?= $filterDateTo !== '' ? 'max="' . htmlspecialchars($filterDateTo) . '"' : '' ?> />
            </label>
            <label>
              Data final
              <input type="date" name="date_to" value="<?= htmlspecialchars($filterDateTo) ?>" <?= $filterDateFrom !== '' ? 'min="' . htmlspecialchars($filterDateFrom) . '"' : '' ?> />
            </label>
            <label>
              Coluna
              <select name="status">
                <option value="">Todas</option>
                <?php foreach ($statusLabels as $status => $label): ?>
                  <option value="<?= htmlspecialchars($status) ?>" <?= $filterStatus === $status ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>
              Tag
              <select name="tag">
                <option value="">Todas</option>
                <?php foreach ($availableTags as $tag): ?>
                  <option value="<?= htmlspecialchars($tag) ?>" <?= $filterTag === $tag ? 'selected' : '' ?>><?= htmlspecialchars($tag) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <?php if ($canManageSales): ?>
              <label>
                Vendedor
                <select name="seller">
                  <option value="">Todos</option>
                  <option value="unassigned" <?= $filterUnassigned ? 'selected' : '' ?>>Sem vendedor</option>
                  <?php foreach ($assignableUsers as $user): ?>
                    <option value="<?= (int) $user['id'] ?>" <?= $filterSellerId === (int) $user['id'] ? 'selected' : '' ?>><?= htmlspecialchars(crm_user_label($user)) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            <?php endif; ?>
            <div class="form-actions field-wide">
              <button type="submit">Aplicar filtro</button>
              <a class="secondary-action" href="index.php">Limpar</a>
            </div>
          </form>
        </div>
      </div>

      <?php if ($canManageSettings): ?>
      <div class="utility-dialog" data-dialog="kanban" hidden>
        <div class="utility-dialog-card utility-dialog-wide">
          <header class="utility-dialog-header">
            <div>
              <p class="eyebrow">Funil comercial</p>
              <h2>Editar kanban</h2>
            </div>
            <button class="modal-close" type="button" data-close-dialog>×</button>
          </header>
          <form class="kanban-form" method="post" action="save-kanban.php">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
            <div class="kanban-edit-list">
              <?php foreach ($kanbanColumns as $index => $column): ?>
                <div class="kanban-edit-row">
                  <input type="hidden" name="columns[<?= (int) $index ?>][status]" value="<?= htmlspecialchars((string) $column['status']) ?>" />
                  <label>
                    Nome da coluna
                    <input type="text" name="columns[<?= (int) $index ?>][label]" value="<?= htmlspecialchars((string) $column['label']) ?>" required />
                  </label>
                  <span><?= htmlspecialchars((string) $column['status']) ?></span>
                  <?php if ((string) ($column['status'] ?? '') === 'followup'): ?>
                    <label class="field-wide">
                      Fluxo automático ao entrar nesta coluna
                      <select name="columns[<?= (int) $index ?>][auto_followup_flow_id]">
                        <option value="0">Não iniciar automaticamente</option>
                        <?php foreach ($followupFlows as $flow): ?>
                          <option value="<?= (int) $flow['id'] ?>" <?= (int) ($column['auto_followup_flow_id'] ?? 0) === (int) $flow['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $flow['name']) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <small class="commercial-help">Ao mover um lead para Follow-up, esse fluxo será agendado automaticamente.</small>
                    </label>
                  <?php endif; ?>
                  <?php if ((int) ($column['is_system'] ?? 0) === 0): ?>
                    <label class="checkbox-field remove-column">
                      <input type="checkbox" name="remove_status[]" value="<?= htmlspecialchars((string) $column['status']) ?>" />
                      Remover
                    </label>
                  <?php else: ?>
                    <span class="system-column">Padrão</span>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
            <label>
              Nova coluna
              <input type="text" name="new_column_label" placeholder="Ex: Negociação" />
            </label>
            <button type="submit">Salvar kanban</button>
          </form>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($filtersActive): ?>
        <div class="filter-summary">
          Mostrando <?= count($filteredLeads) ?> de <?= count($leads) ?> contatos
          <a href="index.php">limpar filtro</a>
        </div>
      <?php endif; ?>

      <section class="metrics">
        <article>
          <span>Total</span>
          <strong data-dashboard-metric="total"><?= count($leads) ?></strong>
        </article>
        <article>
          <span>Novos</span>
          <strong data-dashboard-metric="new"><?= count(array_filter($leads, fn(array $lead): bool => ($lead['status'] ?? '') === 'novo')) ?></strong>
        </article>
        <article>
          <span>Em contato</span>
          <strong data-dashboard-metric="contact"><?= count(array_filter($leads, fn(array $lead): bool => in_array(($lead['status'] ?? ''), ['contatado', 'proposta'], true))) ?></strong>
        </article>
        <article>
          <span>Fechados</span>
          <strong data-dashboard-metric="closed"><?= count(array_filter($leads, fn(array $lead): bool => ($lead['status'] ?? '') === 'fechado')) ?></strong>
        </article>
      </section>

      <?php if (count($leads) === 0): ?>
        <section class="empty-state">
          <h2>Nenhum contato recebido ainda</h2>
          <p>Quando um novo cadastro chegar, ele aparecerá aqui.</p>
        </section>
      <?php elseif (count($filteredLeads) === 0): ?>
        <section class="empty-state">
          <h2>Nenhum contato encontrado</h2>
          <p>Ajuste os filtros para visualizar outros contatos do CRM.</p>
        </section>
      <?php else: ?>
        <p class="mobile-kanban-hint">Toque e segure um lead para arrastá-lo para outra etapa.</p>
        <section class="kanban-board" aria-label="Funil comercial em Kanban" style="grid-template-columns: repeat(<?= max(1, count($statusLabels)) ?>, minmax(285px, 1fr));">
          <?php foreach ($statusLabels as $status => $label): ?>
            <section class="kanban-column" data-status="<?= htmlspecialchars($status) ?>">
              <header class="kanban-column-header">
                <div>
                  <span class="status status-<?= htmlspecialchars($status) ?>"><?= htmlspecialchars($label) ?></span>
                  <strong><?= count($leadsByStatus[$status]) ?></strong>
                </div>
              </header>

              <div class="kanban-dropzone" data-status="<?= htmlspecialchars($status) ?>">
                <?php foreach ($leadsByStatus[$status] as $lead): ?>
                  <article
                    class="lead-card kanban-card"
                    draggable="true"
                    data-lead-id="<?= htmlspecialchars((string) ($lead['id'] ?? '')) ?>"
                    data-lead-has-cpf="<?= crm_lead_has_cpf($lead) ? 'true' : 'false' ?>"
                  >
                    <div class="lead-main">
                      <div>
                        <h2><?= htmlspecialchars((string) ($lead['name'] ?? 'Sem nome')) ?></h2>
                        <p>Vendedor: <?= htmlspecialchars(trim((string) ($lead['assigned_user_name'] ?? '')) !== '' ? (string) $lead['assigned_user_name'] : 'Sem vendedor') ?></p>
                      </div>
                    </div>

                    <div class="lead-actions">
                      <a
                        class="lead-chat-action"
                        href="whatsapp.php?lead=<?= urlencode((string) ($lead['id'] ?? '')) ?>"
                        title="Abrir conversa"
                        aria-label="Abrir conversa do contato"
                      >
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                          <path d="M7.4 14.8H6.2a4 4 0 0 1-4-4V7.2a4 4 0 0 1 4-4h7.1a4 4 0 0 1 4 4v.6" />
                          <path d="M10.7 8.2h6.2a4 4 0 0 1 4 4v2.7a4 4 0 0 1-4 4h-2.5L11 21v-2.1h-.3a4 4 0 0 1-4-4v-2.7a4 4 0 0 1 4-4Z" />
                        </svg>
                      </a>
                      <button class="details-toggle" type="button" data-toggle-details>Detalhes</button>
                      <label class="mobile-status-move">
                        Mover para
                        <select data-mobile-status aria-label="Mover lead para outra etapa">
                          <?php foreach ($statusLabels as $moveStatus => $moveLabel): ?>
                            <option value="<?= htmlspecialchars($moveStatus) ?>" <?= $moveStatus === $status ? 'selected' : '' ?>><?= htmlspecialchars($moveLabel) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </label>
                      <?php if ($canManageSales): ?>
                        <form method="post" action="delete.php" onsubmit="return confirm('Excluir este contato?');">
                          <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
                          <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($lead['id'] ?? '')) ?>" />
                          <button type="submit" class="danger">Excluir</button>
                        </form>
                      <?php endif; ?>
                    </div>

                    <div
                      class="lead-details-panel"
                      hidden
                      role="dialog"
                      aria-modal="true"
                      aria-label="Detalhes do contato"
                      data-modal-lead-id="<?= htmlspecialchars((string) ($lead['id'] ?? '')) ?>"
                    >
                      <div class="lead-modal-card lead-modal-card-loading" data-lead-details-container>
                        <p class="lead-details-loading" role="status">Carregando detalhes…</p>
                      </div>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
            </section>
          <?php endforeach; ?>
        </section>
      <?php endif; ?>
    </main>
      </div>
    </div>
    <script src="./assets/crm.js?v=20260901-mobile-composer-v1"></script>
    <script src="./assets/crm-navigation.js?v=20260812-fast-navigation-v3"></script>
  </body>
</html>
