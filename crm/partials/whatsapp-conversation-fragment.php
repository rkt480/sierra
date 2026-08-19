<section class="wa-thread" aria-label="Conversa selecionada">
  <?php if (!is_array($activeLead)): ?>
    <div class="wa-no-thread">
      <h2>Selecione uma conversa</h2>
      <p>Quando uma mensagem chegar pelo webhook, a conversa aparece nesta tela.</p>
    </div>
  <?php else: ?>
    <header class="wa-thread-header">
      <button class="wa-mobile-thread-back" type="button" data-wa-mobile-back hidden aria-label="Voltar para conversas" title="Voltar para conversas">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" aria-hidden="true">
          <path d="m15 5-7 7 7 7" />
        </svg>
      </button>
      <?= whatsapp_page_avatar_markup($activeLead, 'large') ?>
      <div>
        <h2><?= htmlspecialchars((string) ($activeLead['name'] ?? 'Contato WhatsApp')) ?></h2>
        <p><?= htmlspecialchars(crm_normalize_lead_whatsapp((string) ($activeLead['whatsapp'] ?? ''))) ?></p>
      </div>
    </header>

    <div class="wa-message-surface">
      <div class="wa-day-chip">Histórico do CRM</div>

      <?php if (count($activeMessages) === 0): ?>
        <div class="wa-message wa-message-note">
          <p>Este contato ainda não tem mensagens registradas. Envie uma mensagem para iniciar o histórico.</p>
        </div>
      <?php endif; ?>

      <?php foreach ($activeMessages as $message): ?>
        <?php
          $messageMediaType = is_array($message['media'] ?? null) ? (string) ($message['media']['type'] ?? '') : '';
          $messageMediaType = in_array($messageMediaType, ['image', 'sticker', 'audio', 'video', 'document'], true) ? $messageMediaType : 'file';
        ?>
        <article class="wa-message wa-message-<?= htmlspecialchars((string) $message['direction']) ?>">
          <?php if (is_array($message['media'] ?? null)): ?>
            <div class="wa-message-media wa-message-media-<?= htmlspecialchars($messageMediaType) ?>"><?= whatsapp_page_received_media_markup($message['media']) ?></div>
          <?php endif; ?>
          <?php if (trim((string) $message['text']) !== ''): ?>
            <p><?= nl2br(htmlspecialchars((string) $message['text'])) ?></p>
          <?php endif; ?>
          <footer>
            <span><?= htmlspecialchars((string) $message['label']) ?></span>
            <time><?= htmlspecialchars(whatsapp_page_time_label((string) $message['at'])) ?></time>
          </footer>
        </article>
      <?php endforeach; ?>
    </div>

    <div class="wa-thread-bottom">
      <div class="wa-window-banner <?= $wa24hOpen ? 'is-open' : 'is-closed' ?>">
        <span class="wa-window-icon"><?= $wa24hOpen ? '✓' : '!' ?></span>
        <div><strong><?= $wa24hOpen ? 'Resposta livre liberada' : 'Janela de 24 horas encerrada' ?></strong><span><?= htmlspecialchars($waWindowLabel) ?></span></div>
      </div>

      <?php if (!$wa24hOpen): ?>
        <section class="wa-template-picker" data-wa-template-picker>
          <div class="wa-template-picker-heading"><div><p class="eyebrow"><?= $provider === 'pilot_status' ? 'Pilot Status' : 'API oficial' ?></p><strong>Enviar template aprovado</strong></div><span><?= $provider === 'pilot_status' ? 'Pilot' : 'Meta' ?></span></div>
          <form method="post" action="send-whatsapp-template.php" data-wa-template-form>
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
            <input type="hidden" name="lead_id" value="<?= htmlspecialchars((string) ($activeLead['id'] ?? '')) ?>" />
            <input type="hidden" name="provider_filter" value="<?= htmlspecialchars($providerFilter) ?>" />
            <div class="wa-template-picker-row"><select name="template_id" data-wa-template-select required><option value="">Selecione um template</option><?php foreach ($whatsappTemplates as $template): $isApproved = crm_whatsapp_template_is_sendable($template); ?><option value="<?= (int) $template['id'] ?>" <?= !$isApproved ? 'disabled' : '' ?>><?= htmlspecialchars((string) $template['name']) ?> · <?= htmlspecialchars(whatsapp_template_status_label_for_conversation($template)) ?></option><?php endforeach; ?></select><button type="submit" data-wa-template-send disabled>Enviar template</button></div>
            <div class="wa-template-variable-fields" data-wa-template-fields hidden></div>
            <p class="wa-template-preview" data-wa-template-preview hidden></p>
          </form>
          <?php if (!$pilotStatusConfigured && $provider === 'pilot_status'): ?><small class="wa-template-picker-note">Configure a API key do Pilot Status antes de enviar templates.</small><?php elseif ($provider !== 'meta_cloud' && $provider !== 'pilot_status'): ?><small class="wa-template-picker-note">Selecione um provedor de WhatsApp nas configurações.</small><?php elseif (count($whatsappTemplates) === 0): ?><small class="wa-template-picker-note">Nenhum template cadastrado. <a href="whatsapp-templates.php">Criar template</a></small><?php elseif (!$hasApprovedWhatsAppTemplate): ?><small class="wa-template-picker-note">Seus templates ainda precisam ser aprovados pela Meta ou sincronizados com o Pilot Status.</small><?php endif; ?>
        </section>
      <?php endif; ?>

      <form class="wa-composer <?= $wa24hOpen ? '' : 'is-locked' ?>" method="post" action="send-chat-message.php" enctype="multipart/form-data" data-wa-composer <?= $wa24hOpen ? '' : 'aria-disabled="true"' ?> <?= $wa24hOpen ? '' : 'hidden' ?>>
        <div class="wa-composer-tools">
          <button class="wa-tool-button" type="button" title="Anexar imagem, áudio ou documento" data-wa-attach aria-label="Anexar imagem, áudio ou documento">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M12 5v14M5 12h14" />
            </svg>
          </button>
          <button class="wa-tool-button" type="button" title="Inserir emoji" data-wa-emoji aria-label="Inserir emoji">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <circle cx="12" cy="12" r="8.5" />
              <path d="M8.5 14.5a4.5 4.5 0 0 0 7 0M9 9.5h.01M15 9.5h.01" />
            </svg>
          </button>
          <div class="wa-emoji-menu" data-wa-emoji-menu hidden>
            <button type="button" data-wa-emoji-value="😀">😀</button>
            <button type="button" data-wa-emoji-value="😂">😂</button>
            <button type="button" data-wa-emoji-value="😍">😍</button>
            <button type="button" data-wa-emoji-value="👍">👍</button>
            <button type="button" data-wa-emoji-value="❤️">❤️</button>
            <button type="button" data-wa-emoji-value="🙏">🙏</button>
          </div>
          <input class="wa-media-input" type="file" name="media" accept="image/jpeg,image/png,image/webp,image/gif,audio/*,application/pdf,application/msword,application/rtf,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv,text/plain,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt" data-wa-media hidden />
        </div>
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
        <input type="hidden" name="lead_id" value="<?= htmlspecialchars((string) ($activeLead['id'] ?? '')) ?>" />
        <input type="hidden" name="provider_filter" value="<?= htmlspecialchars($providerFilter) ?>" />
        <div class="wa-composer-main">
          <div class="wa-media-preview" data-wa-preview hidden></div>
          <div class="wa-recording-preview" data-wa-recording-preview hidden aria-live="polite">
            <button class="wa-recording-discard" type="button" title="Descartar gravação" aria-label="Descartar gravação" data-wa-record-discard>
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M7.5 7l.7 12h7.6l.7-12M10 11v4M14 11v4" /></svg>
            </button>
            <span class="wa-recording-dot" aria-hidden="true"></span>
            <time data-wa-recording-time>0:00</time>
            <span class="wa-recording-wave" data-wa-recording-wave aria-hidden="true"></span>
            <span class="wa-recording-label">Gravando</span>
          </div>
          <textarea name="message" rows="1" maxlength="2000" placeholder="Digite uma mensagem" data-wa-message></textarea>
        </div>
        <button class="wa-record-button" type="button" title="Gravar áudio" data-wa-record aria-label="Gravar áudio">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M12 14.5a3.5 3.5 0 0 0 3.5-3.5V6a3.5 3.5 0 0 0-7 0v5a3.5 3.5 0 0 0 3.5 3.5Z" />
            <path d="M19 11a7 7 0 0 1-14 0M12 18v3M8.5 21h7" />
          </svg>
        </button>
        <button class="wa-send-button" type="submit" title="Enviar mensagem" aria-label="Enviar mensagem">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="m4 4 16 8-16 8 3-8-3-8Z" />
            <path d="M7 12h13" />
          </svg>
        </button>
      </form>
    </div>
  <?php endif; ?>
</section>

<aside class="wa-lead-panel" aria-label="Informações e recursos do lead">
  <?php if (!is_array($activeLead)): ?>
    <section class="wa-lead-panel-empty">
      <h2>Lead</h2>
      <p>Selecione uma conversa para visualizar os dados comerciais.</p>
    </section>
  <?php else: ?>
    <?php $activeLeadTags = crm_decode_lead_tags($activeLead); ?>
    <?php $activeLeadReturnUrl = 'whatsapp.php?provider=' . rawurlencode($providerFilter) . '&lead=' . rawurlencode((string) ($activeLead['id'] ?? '')); ?>
    <section class="wa-lead-profile">
      <?= whatsapp_page_avatar_markup($activeLead, 'large') ?>
      <div>
        <p class="eyebrow">Lead em atendimento</p>
        <h2><?= htmlspecialchars((string) ($activeLead['name'] ?? 'Contato WhatsApp')) ?></h2>
      </div>
    </section>

    <section class="wa-lead-block">
      <h3>Informações</h3>
      <dl class="wa-lead-details">
        <div>
          <dt>WhatsApp</dt>
          <dd><?= htmlspecialchars(crm_normalize_lead_whatsapp((string) ($activeLead['whatsapp'] ?? ''))) ?></dd>
        </div>
        <div>
          <dt>CPF</dt>
          <dd><?= htmlspecialchars(crm_format_cpf((string) ($activeLead['cpf'] ?? '')) ?: 'Não informado') ?></dd>
        </div>
        <div>
          <dt>Vendedor</dt>
          <dd><?= htmlspecialchars(trim((string) ($activeLead['assigned_user_name'] ?? '')) !== '' ? (string) $activeLead['assigned_user_name'] : 'Sem vendedor') ?></dd>
        </div>
        <?php if ($canManageSettings): ?>
          <div>
            <dt>Origem</dt>
            <dd><?= htmlspecialchars(whatsapp_page_origin_summary($activeLead)) ?></dd>
          </div>
        <?php endif; ?>
        <div>
          <dt>Recebido em</dt>
          <dd><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) ($activeLead['created_at'] ?? 'now')))) ?></dd>
        </div>
        <div>
          <dt>Status WhatsApp</dt>
          <dd><?= htmlspecialchars(whatsapp_page_whatsapp_status($activeLead)) ?></dd>
        </div>
      </dl>
    </section>

    <section class="wa-lead-block">
      <h3>Dados do contato</h3>
      <form class="wa-side-form" method="post" action="update.php">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
        <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($activeLead['id'] ?? '')) ?>" />
        <input type="hidden" name="status" value="<?= htmlspecialchars((string) ($activeLead['status'] ?? 'novo')) ?>" />
        <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($activeLeadReturnUrl) ?>" />
        <label>
          Nome do contato
          <input type="text" name="name" value="<?= htmlspecialchars((string) ($activeLead['name'] ?? '')) ?>" autocomplete="name" required />
        </label>
        <label>
          WhatsApp
          <input type="tel" name="whatsapp" value="<?= htmlspecialchars((string) ($activeLead['whatsapp'] ?? '')) ?>" autocomplete="tel" placeholder="55DDDNUMERO" />
        </label>
        <label>
          CPF do cliente
          <input type="text" name="cpf" value="<?= htmlspecialchars(crm_format_cpf((string) ($activeLead['cpf'] ?? ''))) ?>" inputmode="numeric" autocomplete="off" placeholder="000.000.000-00" maxlength="14" data-cpf-input />
        </label>
        <button type="submit">Salvar contato</button>
      </form>
    </section>

    <section class="wa-lead-block">
      <h3>Valores e previsão</h3>
      <form class="wa-side-form" method="post" action="update.php">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
        <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($activeLead['id'] ?? '')) ?>" />
        <input type="hidden" name="status" value="<?= htmlspecialchars((string) ($activeLead['status'] ?? 'novo')) ?>" />
        <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($activeLeadReturnUrl) ?>" />
        <label>
          Valor da proposta
          <input type="text" name="proposal_value" value="<?= htmlspecialchars(whatsapp_page_money_input($activeLead, 'proposal_value')) ?>" inputmode="numeric" autocomplete="off" placeholder="R$ 0,00" data-currency-input />
        </label>
        <label>
          Previsão de fechamento
          <input type="date" name="expected_close_date" value="<?= htmlspecialchars((string) ($activeLead['expected_close_date'] ?? '')) ?>" />
        </label>
        <button type="submit">Salvar proposta</button>
      </form>
    </section>

    <section class="wa-lead-block">
      <h3>Tags e observações do vendedor</h3>
      <form class="wa-side-form" method="post" action="update.php">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
        <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($activeLead['id'] ?? '')) ?>" />
        <input type="hidden" name="status" value="<?= htmlspecialchars((string) ($activeLead['status'] ?? 'novo')) ?>" />
        <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($activeLeadReturnUrl) ?>" />
        <div class="tag-field">
          <span class="tag-preview" data-tags-preview <?= count($activeLeadTags) === 0 ? 'hidden' : '' ?>>
            <?php foreach ($activeLeadTags as $tag): ?>
              <span>
                <?= htmlspecialchars($tag) ?>
                <button type="submit" name="remove_tag" value="<?= htmlspecialchars($tag) ?>" class="tag-preview-remove" data-wa-tag-remove="<?= htmlspecialchars($tag) ?>" title="Remover tag <?= htmlspecialchars($tag) ?>" aria-label="Remover tag <?= htmlspecialchars($tag) ?>">×</button>
              </span>
            <?php endforeach; ?>
          </span>
          <label>
            Tags
            <input type="text" name="tags" value="<?= htmlspecialchars(implode(', ', $activeLeadTags)) ?>" placeholder="quente, proposta, retorno" data-tags-input />
          </label>
        </div>
        <button type="submit">Salvar tags</button>
      </form>
      <form class="wa-side-form" method="post" action="update.php">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
        <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($activeLead['id'] ?? '')) ?>" />
        <input type="hidden" name="status" value="<?= htmlspecialchars((string) ($activeLead['status'] ?? 'novo')) ?>" />
        <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($activeLeadReturnUrl) ?>" />
        <label>
          Observações do vendedor
          <textarea name="commercial_notes" rows="4" placeholder="Resumo, objeções, próximos passos..."><?= htmlspecialchars((string) ($activeLead['commercial_notes'] ?? '')) ?></textarea>
        </label>
        <button type="submit">Salvar observações</button>
      </form>
    </section>

    <section class="wa-lead-block">
      <h3>Follow-up</h3>
      <form class="wa-side-form" method="post" action="assign-followup.php">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
        <input type="hidden" name="lead_id" value="<?= htmlspecialchars((string) ($activeLead['id'] ?? '')) ?>" />
        <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($activeLeadReturnUrl) ?>" />
        <label>
          Fluxo de follow-up
          <select name="flow_id" required>
            <option value="">Selecione</option>
            <?php foreach ($followupFlows as $flow): ?>
              <option value="<?= (int) $flow['id'] ?>" <?= ((int) ($activeLead['followup_flow_id'] ?? 0) === (int) $flow['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars((string) $flow['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <button type="submit">Aplicar</button>
      </form>
    </section>

    <section class="wa-lead-block">
      <h3>Agendamento</h3>
      <?php if (!$googleCalendarConnected): ?>
        <p class="wa-side-note">Google Agenda ainda não conectado.</p>
        <?php if ($canManageSettings): ?>
          <a class="secondary-action" href="settings.php">Configurar agenda</a>
        <?php endif; ?>
      <?php else: ?>
        <form class="wa-side-form" method="post" action="schedule-lead.php">
          <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
          <input type="hidden" name="lead_id" value="<?= htmlspecialchars((string) ($activeLead['id'] ?? '')) ?>" />
          <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($activeLeadReturnUrl) ?>" />
          <label>
            Título
            <input type="text" name="title" value="<?= htmlspecialchars('Reunião com ' . (string) ($activeLead['name'] ?? 'lead')) ?>" />
          </label>
          <label>
            Data
            <input type="date" name="event_date" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required />
          </label>
          <label>
            Horário
            <input type="time" name="event_time" value="<?= htmlspecialchars(date('H:i', strtotime('+1 hour'))) ?>" required />
          </label>
          <label>
            Duração
            <select name="duration_minutes">
              <option value="30">30 minutos</option>
              <option value="45">45 minutos</option>
              <option value="60" selected>1 hora</option>
              <option value="90">1h30</option>
            </select>
          </label>
          <label>
            E-mail do convidado
            <input type="email" name="attendee_email" placeholder="cliente@email.com" autocomplete="email" />
          </label>
          <label>
            Observações
            <textarea name="notes" rows="3" placeholder="Assunto da reunião, proposta, próximos passos..."></textarea>
          </label>
          <label class="wa-checkbox-field">
            <input type="checkbox" name="send_updates" value="1" checked />
            Enviar convite pelo Google Agenda
          </label>
          <button type="submit">Agendar</button>
        </form>
      <?php endif; ?>
    </section>

    <section class="wa-lead-actions">
      <a class="secondary-action" href="index.php?q=<?= urlencode((string) ($activeLead['whatsapp'] ?? '')) ?>" data-no-navigation-prefetch>Abrir no kanban</a>
      <?php if ($canManageSettings): ?>
        <a class="secondary-action" href="settings.php">Configurar WhatsApp</a>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</aside>
