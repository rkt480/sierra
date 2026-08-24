<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

crm_send_security_headers();

if (crm_is_logged_in()) {
    crm_require_login();
    header('Location: index.php');
    exit;
}

$error = ($_GET['blocked'] ?? '') === '1'
    ? 'Seu acesso está fora do horário permitido. Tente novamente no próximo dia pela manhã.'
    : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim((string) ($_POST['user'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!crm_verify_csrf_token(crm_request_csrf_token())) {
        $error = 'Sessão expirada. Atualize a página e tente novamente.';
    } elseif (crm_login_is_limited($user)) {
        $error = 'Muitas tentativas. Aguarde alguns minutos e tente novamente.';
    } elseif (crm_attempt_login($user, $password)) {
        crm_clear_login_failures($user);
        header('Location: index.php');
        exit;
    } else {
        $blockedMessage = crm_login_blocked_message();

        if ($blockedMessage !== '') {
            $error = $blockedMessage;
        } else {
            crm_record_login_failure($user);
            $error = 'Usuário ou senha inválidos.';
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
    <title>CRM Sierra</title>
    <link rel="manifest" href="./manifest.webmanifest" />
    <link rel="apple-touch-icon" sizes="180x180" href="./assets/icon-180.png?v=20260819-sierra-icon-v1" />
    <link rel="stylesheet" href="./assets/crm.css?v=20260824-form-answers-v1" />
  </head>
  <body class="auth-page">
    <main class="auth-card">
      <div class="auth-brand">
        <img src="./assets/sierra-source.svg?v=20260819-logo-black" alt="SIERRA" />
      </div>
      <p class="auth-description">Acesse sua conta para visualizar e gerenciar os contatos recebidos</p>

      <?php if ($error !== ''): ?>
        <div class="alert"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
        <label>
          Usuário ou e-mail
          <input type="text" name="user" autocomplete="username" required />
        </label>
        <label>
          Senha
          <input type="password" name="password" autocomplete="current-password" required />
        </label>
        <button type="submit">Entrar no painel</button>
      </form>
      <a class="auth-link" href="./forgot-password.php">Esqueci minha senha</a>
    </main>
  </body>
</html>
