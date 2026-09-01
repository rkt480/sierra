<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/password-reset.php';

crm_send_security_headers();

$token = trim((string) ($_POST['token'] ?? $_GET['token'] ?? ''));
$tokenValid = preg_match('/^[a-f0-9]{64}$/', $token) === 1;
$error = $tokenValid ? '' : 'Este link é inválido ou expirou.';
$success = false;

if ($tokenValid) {
    try {
        $tokenValid = crm_password_reset_token_is_valid($token);

        if (!$tokenValid) {
            $error = 'Este link é inválido ou expirou.';
        }
    } catch (Throwable $resetLookupError) {
        error_log('CRM: erro ao validar link de recuperação: ' . $resetLookupError->getMessage());
        $tokenValid = false;
        $error = 'Não foi possível validar este link agora. Tente novamente mais tarde.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid) {
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');

    if (!crm_verify_csrf_token(crm_request_csrf_token())) {
        $error = 'Sessão expirada. Atualize a página e tente novamente.';
    } elseif (strlen($password) < 6) {
        $error = 'Use uma senha com pelo menos 6 caracteres.';
    } elseif ($password !== $passwordConfirmation) {
        $error = 'As senhas não coincidem.';
    } else {
        try {
            $result = crm_consume_password_reset_token($token, $password);

            if (($result['ok'] ?? false) === true) {
                $success = true;
            } else {
                $error = (string) ($result['error'] ?? 'Este link é inválido ou expirou.');
                $tokenValid = false;
            }
        } catch (Throwable $resetError) {
            error_log('CRM: erro ao redefinir senha: ' . $resetError->getMessage());
            $error = 'Não foi possível redefinir a senha agora. Tente novamente mais tarde.';
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
    <title>Nova senha | CRM Sierra</title>
    <link rel="manifest" href="./manifest.webmanifest" />
    <link rel="apple-touch-icon" sizes="180x180" href="./assets/icon-180.png?v=20260819-sierra-icon-v1" />
    <link rel="stylesheet" href="./assets/crm.css?v=20260901-mobile-responsive-v2" />
  </head>
  <body class="auth-page">
    <main class="auth-card">
      <div class="auth-brand">
        <img src="./assets/sierra-source.svg?v=20260819-logo-black" alt="SIERRA" />
      </div>
      <h1>Nova senha</h1>
      <p class="auth-description">Crie uma nova senha para acessar o CRM.</p>

      <?php if ($error !== ''): ?>
        <div class="alert"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <?php if ($success): ?>
        <div class="alert success">Senha alterada com sucesso. Agora você já pode entrar no CRM.</div>
      <?php elseif ($tokenValid): ?>
        <form method="post">
          <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
          <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>" />
          <label>
            Nova senha
            <input type="password" name="password" autocomplete="new-password" minlength="6" required autofocus />
          </label>
          <label>
            Confirmar nova senha
            <input type="password" name="password_confirmation" autocomplete="new-password" minlength="6" required />
          </label>
          <button type="submit">Salvar nova senha</button>
        </form>
      <?php endif; ?>

      <a class="auth-link" href="./login.php">Voltar para o login</a>
    </main>
  </body>
</html>
