<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/forms.php';

crm_require_admin();

$error = '';
$saved = ($_GET['saved'] ?? '') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    crm_require_valid_csrf();
    $config = json_decode((string) ($_POST['config_json'] ?? '{}'), true);
    if (!is_array($config)) {
        $error = 'Não foi possível ler as perguntas do formulário.';
    } else {
        $publish = true;
        $id = crm_save_form(
            trim((string) ($_POST['id'] ?? '')),
            (string) ($_POST['name'] ?? ''),
            (string) ($_POST['slug'] ?? ''),
            $config,
            $publish
        );
        header('Location: forms.php?id=' . rawurlencode($id) . '&saved=1&published=1');
        exit;
    }
}

$forms = crm_read_forms();
$requestedId = trim((string) ($_GET['id'] ?? ''));
$currentForm = $requestedId === 'new' ? null : crm_find_form($requestedId !== '' ? $requestedId : (string) ($forms[0]['id'] ?? ''));
$currentConfig = $currentForm['draft'] ?? crm_default_form_config();
$isNew = $currentForm === null;
?>
<!doctype html>
<html lang="pt-BR">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="<?= htmlspecialchars(crm_csrf_token()) ?>" />
    <title>Formulários | CRM</title>
    <link rel="stylesheet" href="./assets/crm.css?v=20260824-form-answers-v1" />
  </head>
  <body>
    <div class="app-shell">
      <aside class="sidebar" aria-label="Navegação do CRM">
        <a class="brand" href="index.php" aria-label="Início"><span class="brand-mark"><img src="./assets/sierra-sidebar.svg?v=20260819-sidebar-logo-v2" alt="SIERRA" /></span></a>
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
        </nav>
        <a class="sidebar-exit" href="logout.php" title="Sair">Sair</a>
      </aside>

      <div class="workspace">
        <header class="topbar">
          <nav class="topbar-nav" aria-label="Áreas do CRM">
            <a href="index.php">Leads</a>
            <a class="active" href="forms.php">Formulários</a>
            <a href="followups.php">Follow-up</a>
            <a href="dashboard.php">Dashboard</a>
            <a href="commercial.php">Comercial</a>
            <a href="whatsapp-templates.php">Templates</a>
            <a href="settings.php">Configurações</a>
          </nav>
        </header>

        <header class="app-header">
          <div><p class="eyebrow">Captação</p><h1>Formulários e Lead Score</h1></div>
        </header>

        <main class="dashboard form-builder-layout">
          <?php if ($saved): ?><div class="alert success">Formulário salvo e publicado na landing page.</div><?php endif; ?>
          <?php if ($error !== ''): ?><div class="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>

          <section class="form-builder-card">
            <form method="post" id="formBuilder">
              <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
              <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($currentForm['id'] ?? '')) ?>" />
              <input type="hidden" name="config_json" id="configJson" />

              <header class="builder-header">
                <div>
                  <p class="eyebrow"><?= $isNew ? 'Novo formulário' : 'Editando formulário' ?></p>
                  <h2><?= htmlspecialchars((string) ($currentForm['name'] ?? 'Novo formulário')) ?></h2>
                </div>
                <?php if (!$isNew): ?><span class="integration-status <?= ($currentForm['status'] ?? '') === 'published' ? 'is-active' : '' ?>"><?= ($currentForm['status'] ?? '') === 'published' ? 'Publicado' : 'Rascunho' ?></span><?php endif; ?>
              </header>

              <details class="general-settings"<?= $isNew ? ' open' : '' ?>>
                <summary>
                  <div><strong>Configurações gerais</strong><span>Nome, título, descrição e mensagem de sucesso</span></div>
                  <em>Editar</em>
                </summary>
                <div class="builder-grid">
                  <label>Nome interno<input type="text" name="name" id="formName" value="<?= htmlspecialchars((string) ($currentForm['name'] ?? 'Novo formulário')) ?>" required /></label>
                  <label>Identificador da landing<input type="text" name="slug" id="formSlug" value="<?= htmlspecialchars((string) ($currentForm['slug'] ?? 'novo-formulario')) ?>" pattern="[a-z0-9-]+" required /></label>
                  <label class="field-wide">Título no formulário<input type="text" data-config-field="title" value="<?= htmlspecialchars((string) ($currentConfig['title'] ?? '')) ?>" /></label>
                  <label class="field-wide">Descrição<textarea rows="2" data-config-field="description"><?= htmlspecialchars((string) ($currentConfig['description'] ?? '')) ?></textarea></label>
                  <label>Texto do botão<input type="text" data-config-field="submit_label" value="<?= htmlspecialchars((string) ($currentConfig['submit_label'] ?? 'Enviar')) ?>" /></label>
                  <label>Mensagem de sucesso<input type="text" data-config-field="success_message" value="<?= htmlspecialchars((string) ($currentConfig['success_message'] ?? '')) ?>" /></label>
                </div>
              </details>

              <section class="questions-section">
                <header><div><p class="eyebrow">Perguntas</p><h3>Campos exibidos na landing page</h3></div><button class="secondary-action" type="button" id="addQuestion">Adicionar pergunta</button></header>
                <div id="questionsBuilder" class="questions-builder"></div>
              </section>

              <div class="builder-actions">
                <button type="submit" name="action" value="publish">Publicar na landing page</button>
              </div>
            </form>
          </section>
        </main>
      </div>
    </div>

    <script id="initialFormConfig" type="application/json"><?= json_encode($currentConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <script src="./assets/forms.js?v=20260711-simple-score"></script>
    <script src="./assets/crm-navigation.js?v=20260812-fast-navigation-v3"></script>
  </body>
</html>
