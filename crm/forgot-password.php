<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/password-reset.php';

crm_send_security_headers();

if (crm_is_logged_in()) {
    crm_require_login();
    header('Location: index.php');
    exit;
}

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));

    if (!crm_verify_csrf_token(crm_request_csrf_token())) {
        $error = 'Sessão expirada. Atualize a página e tente novamente.';
    } elseif (crm_throttle_is_limited('password-reset', strtolower($email), 5, 900)) {
        $success = true;
    } else {
        crm_throttle_record('password-reset', strtolower($email), 900);

        try {
            crm_request_password_reset($email);
            $success = true;
        } catch (Throwable $resetError) {
            error_log('CRM: erro ao solicitar recuperação de senha: ' . $resetError->getMessage());
            $error = 'Não foi possível processar a solicitação agora. Tente novamente mais tarde.';
        }
    }
}
?>
<!doctype html>
<html lang="pt-BR">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="theme-color" content="#0b1018" />
    <meta name="apple-mobile-web-app-title" content="CRM Sierra" />
    <title>Recuperar senha | CRM Sierra</title>
    <link rel="manifest" href="./manifest.webmanifest" />
    <link rel="apple-touch-icon" sizes="180x180" href="./assets/icon-180.png?v=20260819-sierra-icon-v1" />
    <link rel="stylesheet" href="./assets/crm.css?v=20260901-mobile-composer-v1" />
  </head>
  <body class="auth-page">
    <main class="auth-card">
      <div class="auth-brand">
        <img src="./assets/sierra-source.svg?v=20260819-logo-black" alt="SIERRA" />
      </div>
      <h1>Recuperar senha</h1>
      <p class="auth-description">Informe o e-mail cadastrado e enviaremos um link para criar uma nova senha.</p>

      <?php if ($error !== ''): ?>
        <div class="alert"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <?php if ($success): ?>
        <div class="alert success">Se esse e-mail estiver cadastrado, você receberá o link de recuperação em instantes. Verifique também a caixa de spam.</div>
      <?php else: ?>
        <form method="post">
          <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
          <label>
            E-mail
            <input type="email" name="email" autocomplete="email" required autofocus />
          </label>
          <button type="submit">Enviar link de recuperação</button>
        </form>
      <?php endif; ?>

      <a class="auth-link" href="./login.php">Voltar para o login</a>
    </main>
  </body>
</html>
