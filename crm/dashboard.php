<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/settings.php';

crm_require_sales_manager();

$canManageSettings = crm_current_user_is_admin();
$assignableUsers = crm_read_assignable_users(false);
$leads = crm_read_leads();
$kanbanColumns = crm_read_kanban_columns();
$statusLabels = [];

foreach ($kanbanColumns as $column) {
    $statusLabels[(string) ($column['status'] ?? '')] = (string) ($column['label'] ?? '');
}

function dashboard_currency(float $value): string
{
    return 'R$ ' . number_format($value, 2, ',', '.');
}

function dashboard_percent(float $value): string
{
    return number_format($value, 1, ',', '.') . '%';
}

function dashboard_status_label(array $labels, string $status): string
{
    return $labels[$status] ?? ucfirst($status !== '' ? $status : 'sem status');
}

function dashboard_money(array $lead, string $field): float
{
    return (float) ($lead[$field] ?? 0);
}

function dashboard_pipeline_value(array $lead): float
{
    return dashboard_money($lead, 'proposal_value');
}

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

$leads = array_values(array_filter($leads, static function (array $lead) use ($filterSellerId, $filterUnassigned, $filterDateFrom, $filterDateTo): bool {
    $createdDate = substr(trim((string) ($lead['created_at'] ?? '')), 0, 10);

    if ($filterDateFrom !== '' && ($createdDate === '' || $createdDate < $filterDateFrom)) {
        return false;
    }

    if ($filterDateTo !== '' && ($createdDate === '' || $createdDate > $filterDateTo)) {
        return false;
    }

    if ($filterSellerId !== null && (int) ($lead['assigned_user_id'] ?? 0) !== $filterSellerId) {
        return false;
    }

    if ($filterUnassigned && (int) ($lead['assigned_user_id'] ?? 0) !== 0) {
        return false;
    }

    return true;
}));

$totalLeads = count($leads);
$closedLeads = array_values(array_filter($leads, static fn(array $lead): bool => (string) ($lead['status'] ?? '') === 'fechado'));
$lostLeads = array_values(array_filter($leads, static fn(array $lead): bool => (string) ($lead['status'] ?? '') === 'perdido'));
$openProposalLeads = array_values(array_filter($leads, static fn(array $lead): bool => (string) ($lead['status'] ?? '') === 'proposta'));
$activePipelineLeads = array_values(array_filter($leads, static fn(array $lead): bool => !in_array((string) ($lead['status'] ?? ''), ['fechado', 'perdido'], true)));
$unattendedLeads = array_values(array_filter($activePipelineLeads, static fn(array $lead): bool => trim((string) ($lead['first_contact_at'] ?? '')) === ''));
$conversionRate = $totalLeads > 0 ? (count($closedLeads) / $totalLeads) * 100 : 0.0;
$proposalOpenValue = array_sum(array_map(static fn(array $lead): float => dashboard_money($lead, 'proposal_value'), $openProposalLeads));
$pipelineValue = array_sum(array_map(static fn(array $lead): float => dashboard_pipeline_value($lead), $activePipelineLeads));
$currentMonth = date('Y-m');
$closedThisMonth = array_values(array_filter($closedLeads, static function (array $lead) use ($currentMonth): bool {
    $closedAt = trim((string) ($lead['closed_at'] ?? ''));
    $date = $closedAt !== '' ? $closedAt : (string) ($lead['updated_at'] ?? '');

    return str_starts_with($date, $currentMonth);
}));
$closedThisMonthValue = array_sum(array_map(static fn(array $lead): float => dashboard_pipeline_value($lead), $closedThisMonth));
$contactDurations = [];
$contactTimeZone = crm_sla_timezone();

foreach ($leads as $lead) {
    $createdAtValue = trim((string) (($lead['assigned_at'] ?? '') ?: ($lead['created_at'] ?? '')));
    $firstContactValue = trim((string) ($lead['first_contact_at'] ?? ''));

    if ($createdAtValue === '' || $firstContactValue === '') {
        continue;
    }

    try {
        $createdAt = new DateTimeImmutable($createdAtValue, $contactTimeZone);
        $firstContactAt = new DateTimeImmutable($firstContactValue, $contactTimeZone);
    } catch (Throwable $error) {
        continue;
    }

    if ($firstContactAt >= $createdAt) {
        $contactDurations[] = crm_sla_access_window_seconds(
            $createdAt,
            $firstContactAt,
            crm_sla_access_user_from_lead($lead)
        );
    }
}

$averageFirstContactSeconds = count($contactDurations) > 0 ? (int) round(array_sum($contactDurations) / count($contactDurations)) : 0;
$averageFirstContactLabel = $averageFirstContactSeconds > 0
    ? ($averageFirstContactSeconds >= 3600
        ? number_format($averageFirstContactSeconds / 3600, 1, ',', '.') . 'h'
        : max(1, (int) round($averageFirstContactSeconds / 60)) . 'min')
    : 'Sem dados';
$settings = crm_sales_distribution_settings();
$staleCutoff = time() - ((int) $settings['sla_inactivity_minutes'] * 60);
$staleByStatus = [];
$leadsBySeller = [];
$lostReasons = [];

foreach ($leads as $lead) {
    $seller = trim((string) ($lead['assigned_user_name'] ?? '')) !== '' ? (string) $lead['assigned_user_name'] : 'Sem vendedor';

    if (!isset($leadsBySeller[$seller])) {
        $leadsBySeller[$seller] = [
            'total' => 0,
            'open' => 0,
            'closed' => 0,
            'lost' => 0,
            'pipeline_value' => 0.0,
        ];
    }

    $status = (string) ($lead['status'] ?? '');
    $leadsBySeller[$seller]['total']++;

    if ($status === 'fechado') {
        $leadsBySeller[$seller]['closed']++;
    } elseif ($status === 'perdido') {
        $leadsBySeller[$seller]['lost']++;
    } else {
        $leadsBySeller[$seller]['open']++;
        $leadsBySeller[$seller]['pipeline_value'] += dashboard_pipeline_value($lead);
    }

    $activityAt = strtotime((string) (($lead['last_activity_at'] ?? '') ?: ($lead['updated_at'] ?? '') ?: ($lead['created_at'] ?? '')));

    if (!in_array($status, ['fechado', 'perdido'], true) && $activityAt !== false && $activityAt <= $staleCutoff) {
        $staleByStatus[$status] = ($staleByStatus[$status] ?? 0) + 1;
    }

    if ($status === 'perdido') {
        $reason = trim((string) ($lead['lost_reason'] ?? ''));
        $reason = $reason !== '' ? $reason : 'Sem motivo informado';
        $lostReasons[$reason] = ($lostReasons[$reason] ?? 0) + 1;
    }
}

ksort($leadsBySeller);
arsort($lostReasons);
?>
<!doctype html>
<html lang="pt-BR">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Dashboard | CRM</title>
    <link rel="stylesheet" href="./assets/crm.css?v=20260901-mobile-composer-v1" />
  </head>
  <body class="settings-page dashboard-page">
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
          <a class="active" href="dashboard.php" title="Dashboard do gestor" aria-label="Dashboard do gestor">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M4 19V5" />
              <path d="M4 19h16" />
              <path d="M8 16V9" />
              <path d="M12 16V7" />
              <path d="M16 16v-5" />
            </svg>
          </a>
          <?php if ($canManageSettings): ?>
            <a href="whatsapp-templates.php" title="Templates WhatsApp" aria-label="Templates WhatsApp">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4.5h14v15H5z" /><path d="M8 8h8M8 12h8M8 16h5" /></svg>
            </a>
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
            <a href="index.php">Contatos</a>
            <a href="followups.php">Follow-up</a>
            <a class="active" href="dashboard.php">Dashboard</a>
            <?php if ($canManageSettings): ?>
              <a href="commercial.php">Comercial</a>
              <a href="whatsapp-templates.php">Templates</a>
              <a href="settings.php">Configurações</a>
            <?php endif; ?>
          </nav>
        </header>

        <header class="app-header">
          <div>
            <p class="eyebrow">Gestão comercial</p>
            <h1>Dashboard do gestor</h1>
          </div>
          <form class="dashboard-filter-form" method="get" action="dashboard.php">
            <label for="dashboard-seller-filter">
              Vendedor
              <select id="dashboard-seller-filter" name="seller">
                <option value="">Todos os vendedores</option>
                <option value="unassigned" <?= $filterUnassigned ? 'selected' : '' ?>>Sem vendedor</option>
                <?php foreach ($assignableUsers as $user): ?>
                  <option value="<?= (int) $user['id'] ?>" <?= $filterSellerId === (int) $user['id'] ? 'selected' : '' ?>><?= htmlspecialchars(crm_user_label($user)) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label for="dashboard-date-from">
              Data inicial
              <input id="dashboard-date-from" type="date" name="date_from" value="<?= htmlspecialchars($filterDateFrom) ?>" />
            </label>
            <label for="dashboard-date-to">
              Data final
              <input id="dashboard-date-to" type="date" name="date_to" value="<?= htmlspecialchars($filterDateTo) ?>" />
            </label>
            <button type="submit">Filtrar</button>
            <?php if ($filterSeller !== '' || $filterDateFrom !== '' || $filterDateTo !== ''): ?>
              <a class="secondary-action" href="dashboard.php">Limpar</a>
            <?php endif; ?>
          </form>
        </header>

        <main class="dashboard settings-layout manager-dashboard">
          <section class="metrics dashboard-metrics">
            <article>
              <span>Leads sem atendimento</span>
              <strong><?= count($unattendedLeads) ?></strong>
            </article>
            <article>
              <span>Taxa de conversão</span>
              <strong><?= htmlspecialchars(dashboard_percent($conversionRate)) ?></strong>
            </article>
            <article>
              <span>Propostas abertas</span>
              <strong><?= count($openProposalLeads) ?></strong>
              <small><?= htmlspecialchars(dashboard_currency($proposalOpenValue)) ?></small>
            </article>
            <article>
              <span>Pipeline potencial</span>
              <strong><?= htmlspecialchars(dashboard_currency($pipelineValue)) ?></strong>
            </article>
            <article>
              <span>Fechados no mês</span>
              <strong><?= count($closedThisMonth) ?></strong>
              <small><?= htmlspecialchars(dashboard_currency($closedThisMonthValue)) ?></small>
            </article>
            <article>
              <span>Tempo 1º contato útil</span>
              <strong><?= htmlspecialchars($averageFirstContactLabel) ?></strong>
            </article>
          </section>

          <section class="dashboard-grid">
            <article class="automation-card dashboard-panel dashboard-team-panel">
              <header class="settings-group-header">
                <div>
                  <p class="eyebrow">Equipe</p>
                  <h2>Leads por vendedor</h2>
                </div>
              </header>
              <div class="dashboard-table">
                <div class="dashboard-table-row dashboard-table-head">
                  <span>Vendedor</span>
                  <span>Total</span>
                  <span>Abertos</span>
                  <span>Fechados</span>
                  <span>Pipeline</span>
                </div>
                <?php foreach ($leadsBySeller as $seller => $data): ?>
                  <div class="dashboard-table-row">
                    <span><?= htmlspecialchars($seller) ?></span>
                    <span><?= (int) $data['total'] ?></span>
                    <span><?= (int) $data['open'] ?></span>
                    <span><?= (int) $data['closed'] ?></span>
                    <span><?= htmlspecialchars(dashboard_currency((float) $data['pipeline_value'])) ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
            </article>

            <article class="automation-card dashboard-panel">
              <header class="settings-group-header">
                <div>
                  <p class="eyebrow">SLA</p>
                  <h2>Leads parados por etapa</h2>
                </div>
              </header>
              <div class="dashboard-table compact">
                <?php if (count($staleByStatus) === 0): ?>
                  <p class="dashboard-empty">Nenhum lead parado pelo prazo configurado.</p>
                <?php endif; ?>
                <?php foreach ($staleByStatus as $status => $total): ?>
                  <div class="dashboard-table-row">
                    <span><?= htmlspecialchars(dashboard_status_label($statusLabels, (string) $status)) ?></span>
                    <strong><?= (int) $total ?></strong>
                  </div>
                <?php endforeach; ?>
              </div>
            </article>

            <article class="automation-card dashboard-panel">
              <header class="settings-group-header">
                <div>
                  <p class="eyebrow">Perdas</p>
                  <h2>Motivo de perda</h2>
                </div>
              </header>
              <div class="dashboard-table compact">
                <?php if (count($lostReasons) === 0): ?>
                  <p class="dashboard-empty">Ainda não há motivos de perda registrados.</p>
                <?php endif; ?>
                <?php foreach ($lostReasons as $reason => $total): ?>
                  <div class="dashboard-table-row">
                    <span><?= htmlspecialchars((string) $reason) ?></span>
                    <strong><?= (int) $total ?></strong>
                  </div>
                <?php endforeach; ?>
              </div>
            </article>

            <article class="automation-card dashboard-panel">
              <header class="settings-group-header">
                <div>
                  <p class="eyebrow">Financeiro</p>
                  <h2>Resumo comercial</h2>
                </div>
              </header>
              <dl class="dashboard-kpis">
                <div>
                  <dt>Total de leads</dt>
                  <dd><?= (int) $totalLeads ?></dd>
                </div>
                <div>
                  <dt>Fechados</dt>
                  <dd><?= count($closedLeads) ?></dd>
                </div>
                <div>
                  <dt>Perdidos</dt>
                  <dd><?= count($lostLeads) ?></dd>
                </div>
                <div>
                  <dt>Limite de inatividade</dt>
                  <dd><?= (int) $settings['sla_inactivity_minutes'] ?> min</dd>
                </div>
              </dl>
            </article>
          </section>
        </main>
      </div>
    </div>
    <script src="./assets/crm-navigation.js?v=20260812-fast-navigation-v3"></script>
  </body>
</html>
