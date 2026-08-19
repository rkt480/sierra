<?php

declare(strict_types=1);

$leadTags = $leadTags ?? crm_decode_lead_tags($lead);
$visibleLeadTags = $visibleLeadTags ?? lead_visible_tags($lead, $leadTags);
?>
<div class="lead-modal-card">
  <header class="lead-modal-header">
    <div>
      <p class="eyebrow">Detalhes do contato</p>
      <h2><?= htmlspecialchars((string) ($lead['name'] ?? 'Sem nome')) ?></h2>
      <?php if (count($visibleLeadTags) > 0): ?>
        <div class="lead-tags lead-modal-tags">
          <?php foreach ($visibleLeadTags as $tag): ?>
            <span><?= htmlspecialchars($tag) ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <button class="modal-close details-toggle" type="button" data-toggle-details>×</button>
  </header>

  <div class="lead-modal-body">
    <aside class="lead-modal-tabs" aria-label="Seções do contato">
      <button class="active" type="button" data-lead-tab="dados">Dados do contato</button>
      <?php if ($canManageSettings): ?>
        <button type="button" data-lead-tab="origem">Origem e UTM</button>
      <?php endif; ?>
      <button type="button" data-lead-tab="comercial">Comercial</button>
    </aside>

    <section class="lead-modal-content">
      <div class="lead-tab-panel active" data-lead-panel="dados">
        <h3>Contato</h3>
        <form class="update-form lead-contact-edit" method="post" action="update.php">
          <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
          <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($lead['id'] ?? '')) ?>" />
          <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>" />
          <label>
            Nome do contato
            <input type="text" name="name" value="<?= htmlspecialchars((string) ($lead['name'] ?? '')) ?>" autocomplete="name" required />
          </label>
          <label>
            WhatsApp
            <input type="tel" name="whatsapp" value="<?= htmlspecialchars((string) ($lead['whatsapp'] ?? '')) ?>" autocomplete="tel" placeholder="55DDDNUMERO" />
          </label>
          <label>
            CPF do cliente
            <input type="text" name="cpf" value="<?= htmlspecialchars(crm_format_cpf((string) ($lead['cpf'] ?? ''))) ?>" inputmode="numeric" autocomplete="off" placeholder="000.000.000-00" maxlength="14" data-cpf-input />
          </label>
          <button type="submit">Salvar contato</button>
        </form>
        <dl class="lead-details">
          <div>
            <dt>CPF</dt>
            <dd><?= htmlspecialchars(crm_format_cpf((string) ($lead['cpf'] ?? '')) ?: 'Não informado') ?></dd>
          </div>
          <div>
            <dt>Recebido em</dt>
            <dd><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) ($lead['created_at'] ?? 'now')))) ?></dd>
          </div>
          <div>
            <dt>Status WhatsApp</dt>
            <dd>
              <?= htmlspecialchars(lead_whatsapp_status_label($lead)) ?>
              <?php if (!empty($lead['whatsapp_sent_at'])): ?>
                em <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $lead['whatsapp_sent_at']))) ?>
              <?php endif; ?>
            </dd>
          </div>
        </dl>
      </div>

      <?php if ($canManageSettings): ?>
      <div class="lead-tab-panel" data-lead-panel="origem" hidden>
        <h3>Origem e UTM</h3>
        <dl class="lead-details">
          <div>
            <dt>Origem do contato</dt>
            <dd><?= htmlspecialchars(lead_origin_summary($lead)) ?></dd>
          </div>
          <div>
            <dt>UTM source</dt>
            <dd><?= htmlspecialchars(trim((string) ($lead['utm_source'] ?? '')) !== '' ? (string) $lead['utm_source'] : 'Sem UTM') ?></dd>
          </div>
          <div>
            <dt>UTM medium</dt>
            <dd><?= htmlspecialchars(trim((string) ($lead['utm_medium'] ?? '')) !== '' ? (string) $lead['utm_medium'] : 'Sem UTM') ?></dd>
          </div>
          <div>
            <dt>UTM campaign</dt>
            <dd><?= htmlspecialchars(trim((string) ($lead['utm_campaign'] ?? '')) !== '' ? (string) $lead['utm_campaign'] : 'Sem UTM') ?></dd>
          </div>
          <div>
            <dt>UTM content / term</dt>
            <dd>
              <?= htmlspecialchars((string) ($lead['utm_content'] ?? '')) ?>
              <?= trim((string) ($lead['utm_term'] ?? '')) !== '' ? ' / ' . htmlspecialchars((string) $lead['utm_term']) : '' ?>
              <?= trim((string) ($lead['utm_content'] ?? '')) === '' && trim((string) ($lead['utm_term'] ?? '')) === '' ? 'Sem UTM' : '' ?>
            </dd>
          </div>
          <div class="field-wide">
            <dt>Página/referrer</dt>
            <dd>
              <?= htmlspecialchars(trim((string) ($lead['landing_path'] ?? '')) !== '' ? (string) $lead['landing_path'] : (string) ($lead['page'] ?? '')) ?>
              <?= trim((string) ($lead['referrer'] ?? '')) !== '' ? ' | Ref: ' . htmlspecialchars((string) $lead['referrer']) : '' ?>
            </dd>
          </div>
        </dl>
      </div>
      <?php endif; ?>

      <div class="lead-tab-panel" data-lead-panel="comercial" hidden>
        <div class="commercial-grid">
          <?php if (!empty($lead['whatsapp_error'])): ?>
            <p class="message error-message field-wide"><?= htmlspecialchars((string) $lead['whatsapp_error']) ?></p>
          <?php endif; ?>

          <?php if ($canManageSales): ?>
            <section class="commercial-block">
              <h3>Responsável</h3>
              <form class="update-form" method="post" action="update.php">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
                <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($lead['id'] ?? '')) ?>" />
                <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>" />
                <label>
                  Vendedor
                  <select name="assigned_user_id">
                    <option value="">Sem vendedor</option>
                    <?php foreach ($assignableUsers as $user): ?>
                      <option value="<?= (int) $user['id'] ?>" <?= (int) ($lead['assigned_user_id'] ?? 0) === (int) $user['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars(crm_user_label($user)) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <button type="submit">Salvar responsável</button>
              </form>
            </section>
          <?php endif; ?>

          <section class="commercial-block field-wide">
            <h3>Valores e previsão</h3>
            <form class="update-form lead-contact-edit" method="post" action="update.php">
              <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
              <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($lead['id'] ?? '')) ?>" />
              <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>" />
              <label>
                Valor da proposta
                <input type="text" name="proposal_value" value="<?= htmlspecialchars(lead_money_input($lead, 'proposal_value')) ?>" inputmode="numeric" autocomplete="off" placeholder="R$ 0,00" data-currency-input />
              </label>
              <label>
                Previsão de fechamento
                <input type="date" name="expected_close_date" value="<?= htmlspecialchars((string) ($lead['expected_close_date'] ?? '')) ?>" />
              </label>
              <label class="field-wide">
                Motivo de perda
                <input type="text" name="lost_reason" value="<?= htmlspecialchars((string) ($lead['lost_reason'] ?? '')) ?>" placeholder="Ex: prazo, orçamento, concorrente, sem retorno" />
              </label>
              <button type="submit">Salvar proposta</button>
            </form>
          </section>

          <section class="commercial-block field-wide">
            <h3>Observações do vendedor</h3>
            <form class="update-form" method="post" action="update.php">
              <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
              <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($lead['id'] ?? '')) ?>" />
              <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>" />
              <label>
                Tags do contato
                <input type="text" name="tags" value="<?= htmlspecialchars(implode(', ', $leadTags)) ?>" placeholder="proposta, retorno, indicação" data-tags-input />
                <span class="tag-preview" data-tags-preview hidden></span>
              </label>
              <label>
                Observações do vendedor
                <textarea name="commercial_notes" rows="3" placeholder="Ex: pediu orçamento, retornar amanhã, perfil bom..."><?= htmlspecialchars((string) ($lead['commercial_notes'] ?? '')) ?></textarea>
              </label>
              <button type="submit">Salvar detalhes</button>
            </form>
          </section>

          <section class="commercial-block">
            <h3>Follow-up</h3>
            <form class="update-form" method="post" action="assign-followup.php">
              <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
              <input type="hidden" name="lead_id" value="<?= htmlspecialchars((string) ($lead['id'] ?? '')) ?>" />
              <label>
                Fluxo de follow-up
                <select name="flow_id" required>
                  <option value="">Selecione um fluxo</option>
                  <?php foreach ($followupFlows as $flow): ?>
                    <option value="<?= (int) $flow['id'] ?>" <?= ((int) ($lead['followup_flow_id'] ?? 0) === (int) $flow['id']) ? 'selected' : '' ?>>
                      <?= htmlspecialchars((string) $flow['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <button type="submit">Aplicar follow-up</button>
            </form>
          </section>

          <section class="commercial-block">
            <h3>Agendamento</h3>
            <?php if (!$googleCalendarConnected): ?>
              <p class="message error-message">Google Agenda ainda não conectado. Vá em Configurações para conectar antes de criar agendamentos.</p>
            <?php else: ?>
              <form class="update-form schedule-form" method="post" action="schedule-lead.php">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
                <input type="hidden" name="lead_id" value="<?= htmlspecialchars((string) ($lead['id'] ?? '')) ?>" />
                <label class="field-wide">
                  Título
                  <input type="text" name="title" value="<?= htmlspecialchars('Reunião com ' . (string) ($lead['name'] ?? 'contato')) ?>" />
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
                    <option value="120">2 horas</option>
                  </select>
                </label>
                <label>
                  E-mail do convidado
                  <input type="email" name="attendee_email" placeholder="cliente@email.com" autocomplete="email" />
                </label>
                <label class="field-wide">
                  Observações
                  <textarea name="notes" rows="3" placeholder="Assunto da reunião, proposta, próximos passos..."></textarea>
                </label>
                <label class="checkbox-field field-wide">
                  <input type="checkbox" name="send_updates" value="1" checked />
                  Enviar convite/notificação pelo Google Agenda
                </label>
                <button type="submit">Criar agendamento</button>
              </form>
            <?php endif; ?>
          </section>
        </div>
      </div>
    </section>
  </div>
</div>
