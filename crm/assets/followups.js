const stepsContainer = document.querySelector("#flowSteps");
const addStepButton = document.querySelector("#addFlowStep");
const template = document.querySelector("#flowStepTemplate");
const flowForm = document.querySelector("#flowForm");
const flowId = document.querySelector("#flowId");
const stepsJson = document.querySelector("#stepsJson");
const flowName = document.querySelector("#flowName");
const flowDescription = document.querySelector("#flowDescription");
const saveFlowButton = document.querySelector("#saveFlowButton");
const cancelEditButton = document.querySelector("#cancelEditFlow");
const csrfToken = document.querySelector("meta[name='csrf-token']")?.content || "";
const templateCatalog = Array.isArray(window.followupTemplateCatalog) ? window.followupTemplateCatalog : [];
const emojis = [
  ["😀", "feliz sorriso rosto"], ["😃", "feliz sorriso"], ["😄", "alegre sorriso"], ["😁", "sorrindo"], ["😊", "sorriso fofo"], ["🙂", "leve sorriso"],
  ["😉", "piscada"], ["😍", "apaixonado amor"], ["🥰", "carinho amor"], ["😘", "beijo"], ["😎", "confiante legal"], ["🤩", "uau estrela"],
  ["🥳", "festa parabens"], ["😇", "anjo"], ["🤔", "pensando duvida"], ["🤝", "acordo parceria"], ["🙏", "obrigado gratidao"], ["👏", "aplausos"],
  ["🙌", "comemoracao"], ["👍", "positivo ok"], ["👎", "negativo"], ["👊", "forca"], ["💪", "forte força"], ["👋", "oi aceno"],
  ["❤️", "coracao amor"], ["💙", "coracao azul"], ["💚", "coracao verde"], ["💛", "coracao amarelo"], ["🧡", "coracao laranja"], ["💜", "coracao roxo"],
  ["🔥", "fogo destaque quente"], ["✨", "brilho novidade"], ["⭐", "estrela"], ["💡", "ideia"], ["🎯", "alvo objetivo"], ["🚀", "foguete crescimento"],
  ["✅", "confirmado certo"], ["☑️", "check marcado"], ["✔️", "check certo"], ["❌", "erro nao"], ["⚠️", "alerta atencao"], ["📌", "pin importante"],
  ["💬", "conversa mensagem"], ["📲", "celular whatsapp"], ["📞", "telefone ligacao"], ["📩", "email mensagem"], ["📢", "anuncio aviso"], ["🔔", "notificacao"],
  ["📅", "calendario data"], ["⏰", "alarme hora"], ["⏳", "tempo espera"], ["💰", "dinheiro valor"], ["💳", "cartao pagamento"], ["🧾", "recibo documento"],
  ["📄", "documento pdf"], ["📎", "anexo arquivo"], ["🖼️", "imagem foto"], ["🎥", "video"], ["🎧", "audio fone"], ["🎁", "presente bonus"],
  ["🏆", "trofeu conquista"], ["📈", "grafico crescimento"], ["📊", "grafico dados"], ["🛠️", "ferramenta ajuste"], ["🔒", "seguro"], ["🌟", "especial"],
];

function splitMinutes(minutes) {
  if (minutes >= 1440 && minutes % 1440 === 0) {
    return { value: minutes / 1440, unit: "days" };
  }

  if (minutes >= 60 && minutes % 60 === 0) {
    return { value: minutes / 60, unit: "hours" };
  }

  return { value: minutes, unit: "minutes" };
}

function createStep(stepData = { delay_minutes: 0, message: "" }) {
  const clone = template.content.firstElementChild.cloneNode(true);
  const delay = splitMinutes(Number(stepData.delay_minutes || 0));

  clone.querySelector("[data-name='delay_value']").value = delay.value;
  clone.querySelector("[data-name='delay_unit']").value = delay.unit;
  clone.querySelector("[data-name='message']").value = stepData.message || "";
  clone.querySelector("[data-name='message_type']").value = stepData.message_type || "template";
  populateTemplateSelect(clone, Number(stepData.template_id || 0));
  renderTemplateVariables(clone, stepData.variable_mapping || {});
  updateStepType(clone);
  bindRemoveButton(clone);
  renderEmojiPickers(clone);

  return clone;
}

function defaultVariableField(variable, index) {
  const normalized = String(variable || "").trim().toLowerCase();
  const namedFields = {
    name: "name",
    nome: "name",
    company: "company",
    empresa: "company",
    segment: "segment",
    segmento: "segment",
    whatsapp: "whatsapp",
    phone: "whatsapp",
    telefone: "whatsapp",
    message: "message",
    mensagem: "message",
    seller: "seller",
    vendedor: "seller",
    atendente: "seller",
  };

  return namedFields[normalized] || ["name", "company", "segment", "message"][index] || "name";
}

function selectedTemplate(step) {
  const id = Number(step.querySelector("[data-name='template_id']")?.value || 0);
  return templateCatalog.find((item) => Number(item.id) === id) || null;
}

function populateTemplateSelect(step, selectedId = 0) {
  const select = step.querySelector("[data-name='template_id']");

  if (!select) {
    return;
  }

  select.replaceChildren(new Option("Selecione um template aprovado", ""));
  templateCatalog.forEach((item) => select.appendChild(new Option(item.name, String(item.id))));

  if (selectedId > 0 && !templateCatalog.some((item) => Number(item.id) === selectedId)) {
    select.appendChild(new Option("Template não disponível ou não aprovado", String(selectedId)));
  }

  select.value = selectedId > 0 ? String(selectedId) : "";
}

function renderTemplateVariables(step, mapping = {}) {
  const container = step.querySelector("[data-template-variables]");

  if (!container) {
    return;
  }

  container.replaceChildren();
  const template = selectedTemplate(step);
  const variables = Array.isArray(template?.variables) ? template.variables : [];

  if (variables.length === 0) {
    return;
  }

  const title = document.createElement("strong");
  title.textContent = "Preenchimento das variáveis";
  container.appendChild(title);

  const fields = [
    ["seller", "Vendedor responsável"],
    ["name", "Nome do lead"],
    ["company", "Empresa"],
    ["segment", "Segmento"],
    ["message", "Mensagem original"],
    ["whatsapp", "WhatsApp"],
  ];

  variables.forEach((variable, index) => {
    const label = document.createElement("label");
    label.textContent = `{{${variable}}}`;
    const select = document.createElement("select");
    select.dataset.variableKey = variable;

    fields.forEach(([value, text]) => select.appendChild(new Option(text, value)));
    select.value = String(mapping[variable] || defaultVariableField(variable, index));
    label.appendChild(select);
    container.appendChild(label);
  });
}

function updateStepType(step) {
  const type = step.querySelector("[data-name='message_type']")?.value || "template";
  const templateField = step.querySelector("[data-template-field]");
  const templateVariables = step.querySelector("[data-template-variables]");
  const textField = step.querySelector("[data-text-field]");
  const templateSelect = step.querySelector("[data-name='template_id']");

  if (templateField) templateField.hidden = type !== "template";
  if (templateVariables) templateVariables.hidden = type !== "template";
  if (textField) textField.hidden = type === "template";
  if (templateSelect) templateSelect.required = type === "template";
}

function refreshStepNames() {
  const steps = Array.from(stepsContainer.querySelectorAll("[data-step]"));

  steps.forEach((step, index) => {
    step.querySelector("strong").textContent = `Mensagem ${index + 1}`;
    step.querySelectorAll("[name], [data-name]").forEach((field) => {
      const key = field.dataset.name || field.name.match(/\[(.*?)\]$/)?.[1];

      if (key) {
        field.name = `steps[${index}][${key}]`;
      }
    });

    const removeButton = step.querySelector("[data-remove-step]");
    removeButton.disabled = steps.length === 1;
  });

  syncStepsJson();
}

function bindRemoveButton(step) {
  step.querySelector("[data-remove-step]").addEventListener("click", () => {
    const steps = stepsContainer.querySelectorAll("[data-step]");

    if (steps.length <= 1) {
      return;
    }

    step.remove();
    refreshStepNames();
  });
}

function insertEmoji(textarea, emoji) {
  const start = textarea.selectionStart ?? textarea.value.length;
  const end = textarea.selectionEnd ?? textarea.value.length;
  const before = textarea.value.slice(0, start);
  const after = textarea.value.slice(end);

  textarea.value = `${before}${emoji}${after}`;
  textarea.focus();
  textarea.setSelectionRange(start + emoji.length, start + emoji.length);
  textarea.dispatchEvent(new Event("input", { bubbles: true }));
}

function renderEmojiGrid(grid, query = "") {
  const normalizedQuery = query.trim().toLowerCase();
  const visibleEmojis = emojis.filter(([emoji, keywords]) => {
    return normalizedQuery === "" || emoji.includes(normalizedQuery) || keywords.includes(normalizedQuery);
  });

  grid.innerHTML = "";

  visibleEmojis.forEach(([emoji, keywords]) => {
    const button = document.createElement("button");
    button.type = "button";
    button.dataset.emoji = emoji;
    button.title = keywords;
    button.textContent = emoji;
    grid.appendChild(button);
  });
}

function closeEmojiPanels(except = null) {
  document.querySelectorAll("[data-emoji-panel]").forEach((panel) => {
    if (panel !== except) {
      panel.hidden = true;
    }
  });
}

function renderEmojiPickers(root = document) {
  root.querySelectorAll("[data-emoji-grid]").forEach((grid) => {
    renderEmojiGrid(grid);
  });
}

function syncStepsJson() {
  if (!stepsJson) {
    return;
  }

  const steps = Array.from(stepsContainer.querySelectorAll("[data-step]")).map((step) => {
    const delayValueField = step.querySelector("[data-name='delay_value'], [name$='[delay_value]']");
    const delayUnitField = step.querySelector("[data-name='delay_unit'], [name$='[delay_unit]']");
    const messageField = step.querySelector("[data-name='message'], [name$='[message]']");

    return {
      delay_value: Number(delayValueField?.value || 0),
      delay_unit: String(delayUnitField?.value || "minutes"),
      message: String(messageField?.value || ""),
      message_type: String(step.querySelector("[data-name='message_type']")?.value || "template"),
      template_id: Number(step.querySelector("[data-name='template_id']")?.value || 0),
      variable_mapping: Object.fromEntries(Array.from(step.querySelectorAll("[data-variable-key]")).map((field) => [field.dataset.variableKey, field.value])),
    };
  });

  stepsJson.value = JSON.stringify(steps);
}

stepsContainer.querySelectorAll("[data-step]").forEach(bindRemoveButton);
stepsContainer.querySelectorAll("[data-step]").forEach((step) => {
  populateTemplateSelect(step, Number(step.querySelector("[data-name='template_id']")?.value || 0));
  renderTemplateVariables(step);
  updateStepType(step);
});

addStepButton.addEventListener("click", () => {
  const clone = createStep({ delay_minutes: 1440, message: "", message_type: "template" });
  stepsContainer.appendChild(clone);
  refreshStepNames();
  clone.querySelector("[data-name='template_id']")?.focus();
});

stepsContainer.addEventListener("input", syncStepsJson);
stepsContainer.addEventListener("change", (event) => {
  const step = event.target.closest("[data-step]");

  if (step && event.target.matches("[data-name='message_type']")) {
    updateStepType(step);
  }

  if (step && event.target.matches("[data-name='template_id']")) {
    renderTemplateVariables(step);
  }

  syncStepsJson();
});
stepsContainer.addEventListener("click", (event) => {
  const toggle = event.target.closest("[data-emoji-toggle]");

  if (toggle) {
    const picker = toggle.closest("[data-emoji-picker]");
    const panel = picker?.querySelector("[data-emoji-panel]");
    const search = picker?.querySelector("[data-emoji-search]");

    if (panel) {
      const shouldOpen = panel.hidden;
      closeEmojiPanels(panel);
      panel.hidden = !shouldOpen;

      if (shouldOpen) {
        search?.focus();
      }
    }

    return;
  }

  const button = event.target.closest("[data-emoji]");

  if (!button) {
    return;
  }

  const step = button.closest("[data-step]");
  const textarea = step?.querySelector("[data-name='message'], [name$='[message]']");

  if (textarea) {
    insertEmoji(textarea, button.dataset.emoji || "");
  }
});
stepsContainer.addEventListener("input", (event) => {
  const search = event.target.closest("[data-emoji-search]");

  if (!search) {
    return;
  }

  const picker = search.closest("[data-emoji-picker]");
  const grid = picker?.querySelector("[data-emoji-grid]");

  if (grid) {
    renderEmojiGrid(grid, search.value);
  }
});

document.addEventListener("click", (event) => {
  if (!event.target.closest("[data-emoji-picker]")) {
    closeEmojiPanels();
  }
});

document.querySelectorAll("[data-edit-flow]").forEach((button) => {
  button.addEventListener("click", () => {
    const item = button.closest(".flow-item");
    const flow = JSON.parse(item.dataset.flow || "{}");

    flowId.value = flow.id || "";
    flowName.value = flow.name || "";
    flowDescription.value = flow.description || "";
    stepsContainer.innerHTML = "";

    const steps = Array.isArray(flow.steps) && flow.steps.length > 0
      ? flow.steps
      : [{ delay_minutes: 0, message: "" }];

    steps.forEach((step) => {
      stepsContainer.appendChild(createStep(step));
    });

    saveFlowButton.textContent = "Salvar alterações";
    cancelEditButton.hidden = false;
    refreshStepNames();
    flowForm.scrollIntoView({ behavior: "smooth", block: "start" });
  });
});

cancelEditButton.addEventListener("click", () => {
  flowForm.reset();
  flowId.value = "";
  stepsContainer.innerHTML = "";
  stepsContainer.appendChild(createStep({ delay_minutes: 0, message: "" }));
  saveFlowButton.textContent = "Salvar fluxo";
  cancelEditButton.hidden = true;
  refreshStepNames();
});

flowForm.addEventListener("submit", () => {
  syncStepsJson();
});

refreshStepNames();
syncStepsJson();
renderEmojiPickers();
