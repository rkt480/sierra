const builder = document.querySelector("#formBuilder");
const questionsBuilder = document.querySelector("#questionsBuilder");
const configJson = document.querySelector("#configJson");
const initialConfig = JSON.parse(document.querySelector("#initialFormConfig")?.textContent || "{}");
let questions = Array.isArray(initialConfig.questions) ? structuredClone(initialConfig.questions) : [];

function escapeHtml(value) {
  return String(value ?? "").replace(/[&<>"]/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" })[char]);
}

function slugify(value) {
  return String(value || "pergunta").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().replace(/[^a-z0-9]+/g, "_").replace(/^_+|_+$/g, "").slice(0, 80) || "pergunta";
}

function temperatureFromQualification(value) {
  const qualification = Number(value ?? 0);
  if (qualification <= 39) return "cold";
  if (qualification <= 69) return "warm";
  return "hot";
}

function temperatureOptions(value) {
  const temperature = temperatureFromQualification(value);
  return `
    <option value="20" ${temperature === "cold" ? "selected" : ""}>❄️ Frio</option>
    <option value="60" ${temperature === "warm" ? "selected" : ""}>⚡ Morno</option>
    <option value="100" ${temperature === "hot" ? "selected" : ""}>🔥 Quente</option>`;
}

function questionTypeLabel(type) {
  return ({
    text: "Texto curto",
    tel: "Telefone",
    email: "E-mail",
    number: "Número",
    textarea: "Texto longo",
    single_choice: "Múltipla escolha",
  })[type] || "Pergunta";
}

function normalizedQuestion(question) {
  const type = question.type || "single_choice";
  return {
    ...question,
    type,
    required: question.required ?? true,
    placeholder: question.placeholder || "",
    score_enabled: type === "single_choice" ? true : Boolean(question.score_enabled),
    weight: Number(question.weight || 3),
  };
}

function questionTemplate(question, index) {
  question = normalizedQuestion(question);
  const isSystem = Boolean(question.system);
  const isChoice = question.type === "single_choice";
  return `
    <article class="question-editor" data-question-index="${index}">
      <header class="question-editor-header">
        <div class="question-stage"><span class="question-chevron">⌄</span><strong>ETAPA ${index + 1} · ${questionTypeLabel(question.type)}</strong>${isSystem ? '<em>Campo do sistema</em>' : ""}</div>
        <div class="question-order-actions">
          <button type="button" data-move="up" title="Mover para cima" aria-label="Mover para cima">↑</button>
          <button type="button" data-move="down" title="Mover para baixo" aria-label="Mover para baixo">↓</button>
          ${isSystem ? "" : '<button type="button" data-duplicate title="Duplicar pergunta" aria-label="Duplicar pergunta">⧉</button><button type="button" class="danger" data-remove title="Excluir pergunta" aria-label="Excluir pergunta">×</button>'}
        </div>
      </header>
      <div class="question-main">
        <label>Pergunta<input class="question-title-input" type="text" data-question-field="label" value="${escapeHtml(question.label)}" required /></label>
      </div>
      ${isChoice ? scoreAndOptionsTemplate(question, index) : ""}
    </article>`;
}

function scoreAndOptionsTemplate(question, questionIndex) {
  const options = Array.isArray(question.options) ? question.options : [];
  return `
    <div class="score-config">
      <div class="options-editor">
        <div class="option-heading"><strong>Respostas e classificação</strong><span>Escolha como cada resposta qualifica o lead</span></div>
        ${options.map((option, optionIndex) => `
          <div class="option-row" data-option-index="${optionIndex}">
            <span class="option-drag" aria-hidden="true">⠿</span>
            <input type="text" data-option-field="label" value="${escapeHtml(option.label)}" placeholder="Resposta" required />
            <select class="temperature-select" data-temperature="${temperatureFromQualification(option.qualification)}" data-option-field="qualification" aria-label="Classificação da resposta">${temperatureOptions(option.qualification)}</select>
            <button type="button" class="danger" data-remove-option>×</button>
          </div>`).join("")}
        <button type="button" class="secondary-action compact-action" data-add-option="${questionIndex}">Adicionar alternativa</button>
      </div>
    </div>`;
}

function renderQuestions() {
  questionsBuilder.innerHTML = questions.map(questionTemplate).join("");
}

function updateQuestionFromField(field) {
  const card = field.closest("[data-question-index]");
  if (!card) return;
  const index = Number(card.dataset.questionIndex);
  const key = field.dataset.questionField;
  if (!key) return;
  questions[index][key] = field.type === "checkbox" ? field.checked : (key === "weight" ? Number(field.value) : field.value);
  if (key === "type") {
    questions[index].options = field.value === "single_choice" && !questions[index].options?.length ? [{ id: "resposta-1", label: "Resposta 1", qualification: 60 }] : (questions[index].options || []);
    questions[index].score_enabled = field.value === "single_choice" ? Boolean(questions[index].score_enabled) : false;
    renderQuestions();
  }
}

function updateOptionFromField(field) {
  const questionIndex = Number(field.closest("[data-question-index]").dataset.questionIndex);
  const optionIndex = Number(field.closest("[data-option-index]").dataset.optionIndex);
  const key = field.dataset.optionField;
  questions[questionIndex].options[optionIndex][key] = key === "qualification" ? Number(field.value) : field.value;
  if (key === "qualification") field.dataset.temperature = temperatureFromQualification(field.value);
}

questionsBuilder.addEventListener("input", (event) => {
  const field = event.target;
  if (field.matches("[data-question-field]")) updateQuestionFromField(field);
  if (field.matches("[data-option-field]")) updateOptionFromField(field);
});
questionsBuilder.addEventListener("change", (event) => {
  if (event.target.matches("[data-question-field]")) updateQuestionFromField(event.target);
  if (event.target.matches("[data-option-field]")) updateOptionFromField(event.target);
});

questionsBuilder.addEventListener("click", (event) => {
  const button = event.target.closest("button");
  if (!button) return;
  const card = button.closest("[data-question-index]");
  const index = card ? Number(card.dataset.questionIndex) : -1;
  if (button.matches("[data-remove]") && index >= 0) questions.splice(index, 1);
  if (button.dataset.move === "up" && index > 0) [questions[index - 1], questions[index]] = [questions[index], questions[index - 1]];
  if (button.dataset.move === "down" && index >= 0 && index < questions.length - 1) [questions[index + 1], questions[index]] = [questions[index], questions[index + 1]];
  if (button.matches("[data-duplicate]") && index >= 0) {
    const duplicate = structuredClone(questions[index]);
    duplicate.id = `${duplicate.id || "pergunta"}_copia`;
    duplicate.system = false;
    questions.splice(index + 1, 0, duplicate);
  }
  if (button.matches("[data-add-option]") && index >= 0) questions[index].options.push({ id: `resposta-${questions[index].options.length + 1}`, label: `Resposta ${questions[index].options.length + 1}`, qualification: 60 });
  if (button.matches("[data-remove-option]") && index >= 0) {
    const optionIndex = Number(button.closest("[data-option-index]").dataset.optionIndex);
    questions[index].options.splice(optionIndex, 1);
  }
  renderQuestions();
});

document.querySelector("#addQuestion").addEventListener("click", () => {
  const number = questions.length + 1;
  questions.push({ id: `pergunta_${number}`, label: "Nova pergunta", type: "single_choice", required: true, system: false, placeholder: "", score_enabled: true, weight: 3, options: [{ id: "resposta-1", label: "Resposta 1", qualification: 60 }] });
  renderQuestions();
  questionsBuilder.lastElementChild?.scrollIntoView({ behavior: "smooth", block: "center" });
});

document.querySelector("#formName")?.addEventListener("input", (event) => {
  const slug = document.querySelector("#formSlug");
  if (slug && (!slug.value || slug.value === "novo-formulario")) slug.value = slugify(event.target.value).replaceAll("_", "-");
});

builder.addEventListener("submit", () => {
  const config = {
    title: document.querySelector('[data-config-field="title"]').value,
    description: document.querySelector('[data-config-field="description"]').value,
    submit_label: document.querySelector('[data-config-field="submit_label"]').value,
    success_message: document.querySelector('[data-config-field="success_message"]').value,
    thresholds: { cold_max: 39, warm_max: 69 },
    questions: questions.map((question) => {
      const normalized = normalizedQuestion(question);
      return {
        ...normalized,
        id: normalized.system ? normalized.id : slugify(normalized.id || normalized.label),
        score_enabled: normalized.type === "single_choice" ? true : Boolean(normalized.score_enabled),
      };
    }),
  };
  configJson.value = JSON.stringify(config);
});

renderQuestions();
