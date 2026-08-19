<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/meta-whatsapp.php';
require_once __DIR__ . '/lib/pilot-status.php';
require_once __DIR__ . '/lib/whatsapp-templates.php';

crm_require_whatsapp_template_manager();

$templates = crm_read_whatsapp_templates();
$requestedId = (int) ($_GET['id'] ?? 0);
$currentTemplate = $requestedId > 0 ? crm_find_whatsapp_template($requestedId) : null;
$isNew = $currentTemplate === null;
$saved = ($_GET['saved'] ?? '') === '1';
$synced = ($_GET['synced'] ?? '') === '1';
$deleted = ($_GET['deleted'] ?? '') === '1';
$remoteWarning = trim((string) ($_GET['remote_warning'] ?? ''));
$error = trim((string) ($_GET['error'] ?? ''));
$provider = crm_whatsapp_provider();
$metaWhatsAppConfigured = crm_meta_whatsapp_is_configured();
$wabaConfigured = trim((string) crm_meta_whatsapp_settings()['business_account_id']) !== '';
$pilotStatusConfigured = pilot_status_is_configured();
$providerConfigured = $provider === 'pilot_status' ? $pilotStatusConfigured : ($metaWhatsAppConfigured && $wabaConfigured);

$statusLabels = [
    'draft' => 'Rascunho local',
    'pending' => 'Em análise na Meta',
    'approved' => 'Aprovado',
    'rejected' => 'Rejeitado',
];

function whatsapp_template_status_label(array $template, array $labels): string
{
    $metaStatus = strtoupper(trim((string) ($template['meta_status'] ?? '')));

    if ($metaStatus !== '') {
        return match ($metaStatus) {
            'APPROVED' => 'Aprovado na Meta',
            'PENDING' => 'Em análise na Meta',
            'REJECTED' => 'Rejeitado pela Meta',
            default => $metaStatus,
        };
    }

    return $labels[(string) ($template['status'] ?? 'draft')] ?? 'Rascunho local';
}

function whatsapp_template_status_class(array $template): string
{
    $metaStatus = strtoupper(trim((string) ($template['meta_status'] ?? '')));

    if ($metaStatus === '' && strtolower(trim((string) ($template['status'] ?? ''))) === 'approved') {
        return 'is-approved';
    }

    return match ($metaStatus) {
        'APPROVED' => 'is-approved',
        'PENDING' => 'is-pending',
        'REJECTED' => 'is-rejected',
        default => 'is-draft',
    };
}

function whatsapp_template_short_text(string $text): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

    return function_exists('mb_strimwidth') ? mb_strimwidth($text, 0, 78, '...', 'UTF-8') : (strlen($text) > 78 ? substr($text, 0, 75) . '...' : $text);
}

$bodyVariables = crm_whatsapp_template_variables((string) ($currentTemplate['body_text'] ?? ''));
?>
<!doctype html>
<html lang="pt-BR">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="<?= htmlspecialchars(crm_csrf_token()) ?>" />
    <title>Templates WhatsApp | CRM</title>
    <link rel="stylesheet" href="./assets/crm.css?v=20260819-sidebar-logo-v1" />
  </head>
  <body class="wa-templates-page">
    <div class="app-shell">
      <aside class="sidebar" aria-label="Navegação do CRM">
        <a class="brand" href="index.php" aria-label="Início"><span class="brand-mark"><img src="./assets/sierra-sidebar.svg?v=20260819-sidebar-logo-v2" alt="SIERRA" /></span></a>
        <nav class="sidebar-tabs" aria-label="Atalhos do CRM">
          <a class="sidebar-whatsapp" href="whatsapp.php" title="Conversas do WhatsApp" aria-label="Conversas do WhatsApp"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7.4 14.8H6.2a4 4 0 0 1-4-4V7.2a4 4 0 0 1 4-4h7.1a4 4 0 0 1 4 4v.6" /><path d="M10.7 8.2h6.2a4 4 0 0 1 4 4v2.7a4 4 0 0 1-4 4h-2.5L11 21v-2.1h-.3a4 4 0 0 1-4-4v-2.7a4 4 0 0 1 4-4Z" /></svg></a>
          <a class="active" href="whatsapp-templates.php" title="Templates WhatsApp" aria-label="Templates WhatsApp"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4.5h14v15H5z" /><path d="M8 8h8M8 12h8M8 16h5" /></svg></a>
          <a href="dashboard.php" title="Dashboard do gestor" aria-label="Dashboard do gestor"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19V5M4 19h16M8 16V9M12 16V7M16 16v-5" /></svg></a>
          <a href="settings.php" title="Configurações" aria-label="Configurações"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7Z" /><path d="m19 13 .1-1-.1-1 1.8-1.3-1.9-3.2-2.1.8a8 8 0 0 0-1.7-1L14.8 4h-3.6l-.3 2.3a8 8 0 0 0-1.7 1l-2.1-.8-1.9 3.2L7 11a8 8 0 0 0 0 2l-1.8 1.3 1.9 3.2 2.1-.8a8 8 0 0 0 1.7 1l.3 2.3h3.6l.3-2.3a8 8 0 0 0 1.7-1l2.1.8 1.9-3.2L19 13Z" /></svg></a>
        </nav>
        <a class="sidebar-exit" href="logout.php" title="Sair">Sair</a>
      </aside>

      <div class="workspace">
        <header class="topbar"><nav class="topbar-nav" aria-label="Áreas do CRM"><a href="index.php">Contatos</a><a href="whatsapp.php">Conversas</a><a class="active" href="whatsapp-templates.php">Templates</a><a href="followups.php">Follow-up</a><a href="settings.php">Configurações</a></nav></header>
        <header class="app-header"><div><p class="eyebrow">WhatsApp oficial</p><h1>Templates de mensagem</h1><p class="page-intro">Crie modelos, envie para aprovação da Meta e use-os quando a janela de 24 horas estiver encerrada.</p></div><a class="primary-action" href="whatsapp-templates.php?id=new">Novo template</a></header>

        <main class="wa-templates-layout">
          <section class="wa-template-editor">
            <?php if ($saved && !$synced): ?><div class="alert success">Template salvo com sucesso.</div><?php endif; ?>
            <?php if ($deleted): ?><div class="alert success">Template excluído do CRM.<?= htmlspecialchars($remoteWarning) ?></div><?php endif; ?>
            <?php if ($synced): ?><div class="alert success">Status dos templates sincronizado com a Meta.</div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <header class="section-heading"><div><p class="eyebrow"><?= $isNew ? 'Novo cadastro' : 'Editando template' ?></p><h2><?= htmlspecialchars((string) ($currentTemplate['name'] ?? 'Modelo de mensagem')) ?></h2></div><?php if (!$isNew): ?><span class="wa-template-status <?= whatsapp_template_status_class($currentTemplate) ?>"><?= htmlspecialchars(whatsapp_template_status_label($currentTemplate, $statusLabels)) ?></span><?php endif; ?></header>

            <form class="wa-template-form" method="post" action="save-whatsapp-template.php">
              <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
              <input type="hidden" name="id" value="<?= (int) ($currentTemplate['id'] ?? 0) ?>" />
              <div class="wa-template-form-grid">
                <label>Nome do template <span>somente minúsculas, números e _</span><input type="text" name="name" value="<?= htmlspecialchars((string) ($currentTemplate['name'] ?? '')) ?>" pattern="[a-z0-9_]+" maxlength="512" placeholder="boas_vindas_cliente" required /></label>
                <label>Idioma<select name="language"><option value="pt_BR" <?= (($currentTemplate['language'] ?? 'pt_BR') === 'pt_BR') ? 'selected' : '' ?>>Português (Brasil)</option><option value="en_US" <?= (($currentTemplate['language'] ?? '') === 'en_US') ? 'selected' : '' ?>>English (US)</option><option value="es_ES" <?= (($currentTemplate['language'] ?? '') === 'es_ES') ? 'selected' : '' ?>>Español</option></select></label>
                <label>Categoria<select name="category"><option value="UTILITY" <?= (($currentTemplate['category'] ?? 'UTILITY') === 'UTILITY') ? 'selected' : '' ?>>Utilidade</option><option value="MARKETING" <?= (($currentTemplate['category'] ?? '') === 'MARKETING') ? 'selected' : '' ?>>Marketing</option></select></label>
                <label class="field-wide">Cabeçalho <span>opcional · texto</span><input type="text" name="header_text" value="<?= htmlspecialchars((string) ($currentTemplate['header_text'] ?? '')) ?>" maxlength="60" placeholder="Ex.: Atendimento Publi" /></label>
                <label class="field-wide">Corpo da mensagem <span><?= $provider === 'pilot_status' ? 'use {{nome}}, {{pedido}} para campos variáveis · mantenha pelo menos 3 palavras fixas por variável' : 'use {{1}}, {{2}} para campos variáveis' ?></span><textarea name="body_text" rows="8" maxlength="1024" placeholder="<?= $provider === 'pilot_status' ? 'Olá, {{nome}}! Recebemos seu contato e vamos continuar seu atendimento por aqui.' : 'Olá, {{1}}! Recebemos seu contato e vamos continuar seu atendimento por aqui.' ?>" required><?= htmlspecialchars((string) ($currentTemplate['body_text'] ?? '')) ?></textarea></label>
                <label class="field-wide">Rodapé <span>opcional</span><input type="text" name="footer_text" value="<?= htmlspecialchars((string) ($currentTemplate['footer_text'] ?? '')) ?>" maxlength="60" placeholder="Sierra" /></label>
              </div>
              <div class="wa-template-help"><strong>Como funciona</strong><span><?= $provider === 'pilot_status' ? 'O Pilot Status envia o template para a Meta quando o número é oficial. Depois de aprovado, ele ficará disponível na aba Conversas.' : 'A Meta analisa o conteúdo antes de liberar o envio. Depois de aprovado, ele ficará disponível na aba Conversas.' ?></span></div>
              <div class="builder-actions"><button type="submit" name="action" value="save">Salvar rascunho</button><?php if ($providerConfigured): ?><button class="secondary-action" type="submit" name="action" value="submit_provider">Salvar e enviar para aprovação</button><?php else: ?><span class="wa-template-config-note"><?= $provider === 'pilot_status' ? 'Configure a API key do Pilot Status para enviar o template.' : 'Configure a Meta Cloud API e o WABA ID para enviar à aprovação.' ?></span><?php endif; ?><?php if (!$isNew): ?><button class="danger" type="submit" name="action" value="delete" onclick="return confirm('Excluir este template? Essa ação não pode ser desfeita.');">Excluir template</button><?php endif; ?></div>
            </form>
          </section>

          <aside class="wa-template-list-card"><header class="section-heading"><div><p class="eyebrow">Biblioteca</p><h2>Seus templates</h2></div><span class="wa-template-count"><?= count($templates) ?></span></header>
            <?php if ($providerConfigured): ?><form method="post" action="save-whatsapp-template.php" class="wa-template-sync-form"><input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" /><button type="submit" name="action" value="sync_provider">Sincronizar status com <?= $provider === 'pilot_status' ? 'o Pilot Status' : 'a Meta' ?></button></form><?php endif; ?>
            <?php if ($templates === []): ?><div class="wa-template-empty"><strong>Nenhum template criado</strong><span>Comece pelo modelo de boas-vindas ou retorno.</span></div><?php endif; ?>
            <?php foreach ($templates as $template): ?><a class="wa-template-list-item" href="whatsapp-templates.php?id=<?= (int) $template['id'] ?>"><div><strong><?= htmlspecialchars((string) $template['name']) ?></strong><span><?= htmlspecialchars(whatsapp_template_short_text((string) $template['body_text'])) ?></span></div><em class="wa-template-status <?= whatsapp_template_status_class($template) ?>"><?= htmlspecialchars(whatsapp_template_status_label($template, $statusLabels)) ?></em></a><?php endforeach; ?>
          </aside>
        </main>
      </div>
    </div>
    <script src="./assets/crm-navigation.js?v=20260812-fast-navigation-v3"></script>
  </body>
</html>
