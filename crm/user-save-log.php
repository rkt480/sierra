<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

crm_require_admin();

$logPath = __DIR__ . '/data/user-save-errors.log';
$entries = [];

if (is_file($logPath) && is_readable($logPath)) {
    $handle = fopen($logPath, 'rb');

    if (is_resource($handle)) {
        while (($line = fgets($handle)) !== false) {
            $entry = json_decode(trim($line), true);

            if (!is_array($entry)) {
                continue;
            }

            $entries[] = $entry;

            if (count($entries) > 100) {
                array_shift($entries);
            }
        }

        fclose($handle);
    }
}

$entries = array_reverse($entries);
?>
<!DOCTYPE html>
<html lang="pt-BR">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Log do cadastro | CRM</title>
    <link rel="stylesheet" href="./assets/crm.css?v=20261008-topbar-original-v1" />
  </head>
  <body class="settings-page commercial-page">
    <div class="app-shell">
      <aside class="sidebar" aria-label="Navegação do CRM">
        <a class="brand" href="index.php" aria-label="Início">
          <span class="brand-mark"><img src="./assets/sierra-sidebar.svg?v=20260819-sidebar-logo-v2" alt="SIERRA" /></span>
        </a>
        <nav class="sidebar-tabs" aria-label="Atalhos do CRM">
          <a href="whatsapp.php" title="Conversas do WhatsApp" aria-label="Conversas do WhatsApp">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7.4 14.8H6.2a4 4 0 0 1-4-4V7.2a4 4 0 0 1 4-4h7.1a4 4 0 0 1 4 4v.6" /><path d="M10.7 8.2h6.2a4 4 0 0 1 4 4v2.7a4 4 0 0 1-4 4h-2.5L11 21v-2.1h-.3a4 4 0 0 1-4-4v-2.7a4 4 0 0 1 4-4Z" /></svg>
          </a>
          <a href="dashboard.php" title="Dashboard do gestor" aria-label="Dashboard do gestor">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19V5M4 19h16M8 16V9M12 16V7M16 16v-5" /></svg>
          </a>
          <a class="active" href="commercial.php" title="Área comercial" aria-label="Área comercial">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 10.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM16 11a2.7 2.7 0 1 0 0-5.4 2.7 2.7 0 0 0 0 5.4ZM3.5 19.5v-1.2A4.5 4.5 0 0 1 8 13.8a4.5 4.5 0 0 1 4.5 4.5v1.2M13.4 14.2c.8-.5 1.7-.8 2.6-.8a4.2 4.2 0 0 1 4.2 4.2v1.9" /></svg>
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
            <p class="eyebrow">Diagnóstico</p>
            <h1>Erros ao salvar usuários</h1>
          </div>
          <a class="secondary-action" href="commercial.php?tab=usuarios">Voltar ao cadastro</a>
        </header>

        <main class="dashboard settings-layout">
          <section class="automation-card integration-card">
            <p class="commercial-help">Esta página registra apenas falhas do cadastro de usuários. Os registros ficam restritos a administradores do CRM.</p>

            <?php if ($entries === []): ?>
              <div class="alert">Ainda não há erros registrados nesta página. Após publicar esta versão, tente salvar o usuário novamente para gerar o diagnóstico.</div>
            <?php else: ?>
              <?php foreach ($entries as $entry): ?>
                <article class="commercial-user-row" style="margin-top: 16px; padding: 16px;">
                  <p><strong>Data:</strong> <?= htmlspecialchars((string) ($entry['created_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></p>
                  <p><strong>SQLSTATE:</strong> <?= htmlspecialchars((string) ($entry['sqlstate'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                    · <strong>Código do banco:</strong> <?= htmlspecialchars((string) ($entry['driver_code'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                    · <strong>Origem:</strong> <?= htmlspecialchars((string) ($entry['source'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></p>
                  <pre style="white-space: pre-wrap; overflow-wrap: anywhere;"><?= htmlspecialchars((string) ($entry['message'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></pre>
                </article>
              <?php endforeach; ?>
            <?php endif; ?>
          </section>
        </main>
      </div>
    </div>
  </body>
</html>
