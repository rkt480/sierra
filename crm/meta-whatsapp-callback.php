<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/meta-whatsapp.php';

crm_send_security_headers();

$sensitiveKeys = ['code', 'access_token', 'token', 'auth_code'];
$received = [];

foreach ($_GET as $key => $value) {
    $key = (string) $key;
    $rawValue = is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);
    $rawValue = $rawValue === false ? '' : $rawValue;
    $normalizedValue = substr($rawValue, 0, 800);

    $received[$key] = in_array(strtolower($key), $sensitiveKeys, true)
        ? '[recebido, ocultado por seguranca]'
        : $normalizedValue;
}

if ($received !== []) {
    meta_whatsapp_log('Callback do Cadastro Incorporado recebido.', [
        'query' => $received,
    ]);
}
?>
<!doctype html>
<html lang="pt-BR">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="robots" content="noindex,nofollow" />
    <title>Cadastro WhatsApp | Sierra</title>
    <link rel="stylesheet" href="./assets/crm.css?v=20260901-mobile-responsive-v2" />
  </head>
  <body>
    <main class="workspace" style="min-height: 100vh; padding: 32px;">
      <section class="automation-card" style="max-width: 760px; margin: 0 auto;">
        <p class="eyebrow">Meta Cloud API</p>
        <h1>Retorno do Cadastro Incorporado</h1>

        <?php if ($received === []): ?>
          <div class="alert">
            Nenhum parâmetro foi retornado pela Meta nesta abertura.
          </div>
        <?php else: ?>
          <div class="alert success">
            Cadastro retornou para a Sierra. Os dados também foram registrados no log da Meta WhatsApp.
          </div>

          <div class="settings-grid">
            <?php foreach ($received as $key => $value): ?>
              <label>
                <?= htmlspecialchars((string) $key) ?>
                <input type="text" value="<?= htmlspecialchars((string) $value) ?>" readonly />
              </label>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div class="form-actions">
          <a class="button secondary" href="settings.php">Voltar para configurações</a>
        </div>
      </section>
    </main>
  </body>
</html>
