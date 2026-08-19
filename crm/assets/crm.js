const cards = document.querySelectorAll(".kanban-card");
const dropzones = document.querySelectorAll(".kanban-dropzone");
const kanbanBoard = document.querySelector(".kanban-board");
const mobileStatusControls = document.querySelectorAll("[data-mobile-status]");
const dialogButtons = document.querySelectorAll("[data-open-dialog]");
const csrfToken = document.querySelector("meta[name='csrf-token']")?.content || "";

let draggedCard = null;
let boardScrollFrame = null;
let boardScrollSpeed = 0;
let localKanbanMoveUntil = 0;
const modalOrigins = new WeakMap();

function updateColumnCounts() {
  document.querySelectorAll(".kanban-column").forEach((column) => {
    const count = column.querySelectorAll(".kanban-card").length;
    const counter = column.querySelector(".kanban-column-header strong");

    if (counter) {
      counter.textContent = count;
    }
  });
}

function dashboardMetricBucket(status) {
  if (status === "novo") {
    return "new";
  }

  if (["contatado", "proposta"].includes(status)) {
    return "contact";
  }

  if (status === "fechado") {
    return "closed";
  }

  return null;
}

function updateDashboardMetrics(previousStatus, nextStatus) {
  const previousBucket = dashboardMetricBucket(previousStatus);
  const nextBucket = dashboardMetricBucket(nextStatus);

  if (previousBucket === nextBucket) {
    return;
  }

  if (previousBucket) {
    const previousMetric = document.querySelector(`[data-dashboard-metric="${previousBucket}"]`);

    if (previousMetric) {
      previousMetric.textContent = String(Math.max(0, Number(previousMetric.textContent) - 1));
    }
  }

  if (nextBucket) {
    const nextMetric = document.querySelector(`[data-dashboard-metric="${nextBucket}"]`);

    if (nextMetric) {
      nextMetric.textContent = String(Number(nextMetric.textContent) + 1);
    }
  }
}

async function persistLeadStatus(leadId, status, orders) {
  const response = await fetch("./api/update-status.php", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-CSRF-Token": csrfToken,
    },
    body: JSON.stringify({ id: leadId, status, orders }),
  });

  if (!response.ok) {
    const data = await response.json().catch(() => ({}));
    const error = new Error(data.error || "Não foi possível mover o lead.");
    error.code = data.code || "";
    throw error;
  }

  const data = await response.json();

  if (data.meta && data.meta.ok === false && data.meta.skipped !== true) {
    console.warn("Meta CAPI não confirmou o evento.", data.meta);
  }
}

function leadHasCpf(card) {
  return card?.dataset.leadHasCpf === "true";
}

function guideLeadCpf(card) {
  window.alert("Informe o CPF completo do lead antes de movê-lo para Fechado.");

  const detailsButton = card?.querySelector(".lead-actions [data-toggle-details]");

  if (detailsButton) {
    card.dataset.focusCpfAfterDetails = "true";
    detailsButton.click();
  }
}

function canMoveLeadToStatus(card, status) {
  if (status !== "fechado" || leadHasCpf(card)) {
    return true;
  }

  guideLeadCpf(card);
  return false;
}

function getCardAfterPointer(zone, clientY) {
  const cardsInZone = [...zone.querySelectorAll(".kanban-card:not(.is-dragging)")];

  return cardsInZone.find((card) => {
    const rect = card.getBoundingClientRect();
    return clientY < rect.top + rect.height / 2;
  }) || null;
}

function getLeadOrder(zone) {
  return [...zone.querySelectorAll(".kanban-card")].map((card) => card.dataset.leadId);
}

function markLocalKanbanMove() {
  localKanbanMoveUntil = Date.now() + 12000;
}

function consumeLocalKanbanMove() {
  const isRecent = Date.now() <= localKanbanMoveUntil;
  localKanbanMoveUntil = 0;
  return isRecent;
}

function keepKanbanCardInView(card) {
  if (!kanbanBoard || !card || !window.matchMedia("(max-width: 880px), (pointer: coarse)").matches) {
    return;
  }

  const column = card.closest(".kanban-column");

  if (!column) {
    return;
  }

  const boardRect = kanbanBoard.getBoundingClientRect();
  const columnRect = column.getBoundingClientRect();
  const targetLeft = kanbanBoard.scrollLeft
    + columnRect.left
    - boardRect.left
    - Math.max(0, (boardRect.width - columnRect.width) / 2);

  kanbanBoard.scrollTo({ left: Math.max(0, targetLeft), behavior: "smooth" });
}

function stopBoardAutoScroll() {
  boardScrollSpeed = 0;

  if (boardScrollFrame !== null) {
    cancelAnimationFrame(boardScrollFrame);
    boardScrollFrame = null;
  }
}

function scrollBoardStep() {
  if (!kanbanBoard || boardScrollSpeed === 0) {
    stopBoardAutoScroll();
    return;
  }

  kanbanBoard.scrollLeft += boardScrollSpeed;
  boardScrollFrame = requestAnimationFrame(scrollBoardStep);
}

function updateBoardAutoScroll(clientX) {
  if (!kanbanBoard || !draggedCard) {
    stopBoardAutoScroll();
    return;
  }

  const rect = kanbanBoard.getBoundingClientRect();
  const edgeSize = Math.min(120, rect.width * 0.22);
  const leftDistance = clientX - rect.left;
  const rightDistance = rect.right - clientX;
  const maxSpeed = 18;

  if (leftDistance >= 0 && leftDistance < edgeSize) {
    boardScrollSpeed = -Math.ceil(((edgeSize - leftDistance) / edgeSize) * maxSpeed);
  } else if (rightDistance >= 0 && rightDistance < edgeSize) {
    boardScrollSpeed = Math.ceil(((edgeSize - rightDistance) / edgeSize) * maxSpeed);
  } else {
    boardScrollSpeed = 0;
  }

  if (boardScrollSpeed !== 0 && boardScrollFrame === null) {
    boardScrollFrame = requestAnimationFrame(scrollBoardStep);
  }

  if (boardScrollSpeed === 0) {
    stopBoardAutoScroll();
  }
}

const touchDragState = {
  card: null,
  previousParent: null,
  previousNextSibling: null,
  pointerId: null,
  startX: 0,
  startY: 0,
  lastX: 0,
  lastY: 0,
  dropzone: null,
  holdTimer: null,
  active: false,
};

function isTouchKanbanEvent(event) {
  if (event.type.startsWith("pointer")) {
    return event.pointerType === "touch";
  }

  return !window.PointerEvent && event.type.startsWith("touch");
}

function getGesturePoint(event) {
  return event.touches?.[0] || event.changedTouches?.[0] || event;
}

function getGesturePointerId(event) {
  return event.pointerId ?? "touch";
}

function isKanbanInteractiveTarget(target) {
  return Boolean(target?.closest("a, button, select, input, textarea, form, .mobile-status-move, [data-mobile-status]"));
}

function clearTouchDragTimer() {
  if (touchDragState.holdTimer !== null) {
    window.clearTimeout(touchDragState.holdTimer);
    touchDragState.holdTimer = null;
  }
}

function clearTouchDropzoneState() {
  dropzones.forEach((zone) => zone.classList.remove("is-over"));
}

function resetTouchDragState() {
  clearTouchDragTimer();
  touchDragState.card = null;
  touchDragState.previousParent = null;
  touchDragState.previousNextSibling = null;
  touchDragState.pointerId = null;
  touchDragState.lastX = 0;
  touchDragState.lastY = 0;
  touchDragState.dropzone = null;
  touchDragState.active = false;
  document.body.classList.remove("touch-dragging");
  clearTouchDropzoneState();
  stopBoardAutoScroll();
  draggedCard = null;
}

function restoreTouchCard(card, parent, nextSibling, status) {
  if (parent) {
    parent.insertBefore(card, nextSibling?.parentElement === parent ? nextSibling : null);
  }

  card.querySelectorAll("input[name='status']").forEach((input) => {
    input.value = status;
  });

  const mobileStatus = card.querySelector("[data-mobile-status]");

  if (mobileStatus) {
    mobileStatus.value = status;
  }
}

function activateTouchKanbanDrag(card) {
  if (touchDragState.card !== card || touchDragState.active) {
    return;
  }

  touchDragState.active = true;
  clearTouchDragTimer();
  draggedCard = card;
  card.classList.add("is-dragging", "is-touch-dragging");
  document.body.classList.add("touch-dragging");
}

function getTouchDropzone(clientX, clientY) {
  const target = document.elementFromPoint(clientX, clientY);
  const directZone = target?.closest(".kanban-dropzone");

  if (directZone) {
    return directZone;
  }

  const directColumn = target?.closest(".kanban-column");

  if (directColumn) {
    return directColumn.querySelector(".kanban-dropzone");
  }

  return [...document.querySelectorAll(".kanban-column")].find((column) => {
    const rect = column.getBoundingClientRect();
    return clientX >= rect.left && clientX <= rect.right && clientY >= rect.top && clientY <= rect.bottom;
  })?.querySelector(".kanban-dropzone") || null;
}

function placeTouchCardInDropzone(card, zone, clientY) {
  if (!zone || !document.body.contains(zone)) {
    return;
  }

  const cardAfterPointer = getCardAfterPointer(zone, clientY);
  const alreadyInPosition = card.parentElement === zone
    && (cardAfterPointer === card || card.nextElementSibling === cardAfterPointer);

  if (!alreadyInPosition) {
    zone.insertBefore(card, cardAfterPointer);
    updateColumnCounts();
  }
}

function beginTouchKanbanDrag(event) {
  if (!isTouchKanbanEvent(event) || isKanbanInteractiveTarget(event.target)) {
    return;
  }

  const card = event.currentTarget;
  const point = getGesturePoint(event);

  if (touchDragState.card) {
    return;
  }

  event.preventDefault();

  touchDragState.card = card;
  touchDragState.previousParent = card.parentElement;
  touchDragState.previousNextSibling = card.nextElementSibling;
  touchDragState.pointerId = getGesturePointerId(event);
  touchDragState.startX = point.clientX;
  touchDragState.startY = point.clientY;
  touchDragState.lastX = point.clientX;
  touchDragState.lastY = point.clientY;
  touchDragState.dropzone = card.parentElement?.closest(".kanban-dropzone") || null;
  touchDragState.active = false;
  touchDragState.holdTimer = window.setTimeout(() => {
    activateTouchKanbanDrag(card);
  }, 180);
}

function moveTouchKanbanDrag(event) {
  if (!touchDragState.card) {
    return;
  }

  if (event.type.startsWith("pointer") && event.pointerId !== touchDragState.pointerId) {
    return;
  }

  const point = getGesturePoint(event);

  if (!touchDragState.active) {
    const distance = Math.hypot(point.clientX - touchDragState.startX, point.clientY - touchDragState.startY);

    if (distance > 10) {
      activateTouchKanbanDrag(touchDragState.card);
    } else {
      return;
    }
  }

  event.preventDefault();
  touchDragState.lastX = point.clientX;
  touchDragState.lastY = point.clientY;
  updateBoardAutoScroll(point.clientX);

  const zone = getTouchDropzone(point.clientX, point.clientY);

  if (!zone) {
    return;
  }

  clearTouchDropzoneState();
  zone.classList.add("is-over");
  const card = touchDragState.card;
  touchDragState.dropzone = zone;
  placeTouchCardInDropzone(card, zone, point.clientY);
}

async function finishTouchKanbanDrag(event, cancelled = false) {
  if (!touchDragState.card) {
    return;
  }

  if (event.type.startsWith("pointer") && event.pointerId !== touchDragState.pointerId) {
    return;
  }

  clearTouchDragTimer();

  if (!touchDragState.active) {
    resetTouchDragState();
    return;
  }

  event.preventDefault();
  const card = touchDragState.card;
  const previousParent = touchDragState.previousParent;
  const previousNextSibling = touchDragState.previousNextSibling;
  const previousStatus = previousParent?.dataset.status || "";
  const lastDropzone = touchDragState.dropzone || getTouchDropzone(touchDragState.lastX, touchDragState.lastY);

  if (!cancelled && lastDropzone) {
    const targetStatus = lastDropzone.dataset.status || previousStatus;

    if (canMoveLeadToStatus(card, targetStatus)) {
      placeTouchCardInDropzone(card, lastDropzone, touchDragState.lastY);
    } else {
      cancelled = true;
    }
  }

  const finalParent = card.parentElement;
  const finalStatus = finalParent?.dataset.status || previousStatus;
  const leadId = card.dataset.leadId || "";

  card.classList.remove("is-dragging", "is-touch-dragging");
  resetTouchDragState();

  if (cancelled || !finalParent || !previousParent) {
    restoreTouchCard(card, previousParent, previousNextSibling, previousStatus);
    updateColumnCounts();
    return;
  }

  updateColumnCounts();

  try {
    const orders = { [finalStatus]: getLeadOrder(finalParent) };

    if (previousParent !== finalParent) {
      orders[previousStatus] = getLeadOrder(previousParent);
    }

    markLocalKanbanMove();
    await persistLeadStatus(leadId, finalStatus, orders);
    card.querySelectorAll("input[name='status']").forEach((input) => {
      input.value = finalStatus;
    });

    const mobileStatus = card.querySelector("[data-mobile-status]");

    if (mobileStatus) {
      mobileStatus.value = finalStatus;
    }

    updateDashboardMetrics(previousStatus, finalStatus);
    keepKanbanCardInView(card);
  } catch (error) {
    localKanbanMoveUntil = 0;
    restoreTouchCard(card, previousParent, previousNextSibling, previousStatus);
    updateColumnCounts();
    alert("Não foi possível mover o lead. Tente novamente.");
    console.error(error);
  }
}

cards.forEach((card) => {
  card.addEventListener("dragstart", () => {
    draggedCard = card;
    card.classList.add("is-dragging");
  });

  card.addEventListener("dragend", () => {
    card.classList.remove("is-dragging");
    draggedCard = null;
    stopBoardAutoScroll();
  });

  card.addEventListener("pointerdown", beginTouchKanbanDrag);
});

document.addEventListener("pointermove", moveTouchKanbanDrag, { passive: false });
document.addEventListener("pointerup", finishTouchKanbanDrag, { passive: false });
document.addEventListener("pointercancel", (event) => finishTouchKanbanDrag(event, true), { passive: false });

document.addEventListener("pointerdown", (event) => {
  if (event.pointerType !== "touch" || !touchDragState.card) {
    return;
  }

  if (event.pointerId !== touchDragState.pointerId) {
    resetTouchDragState();
  }
});

if (!window.PointerEvent) {
  cards.forEach((card) => {
    card.addEventListener("touchstart", beginTouchKanbanDrag, { passive: false });
  });
  document.addEventListener("touchmove", moveTouchKanbanDrag, { passive: false });
  document.addEventListener("touchend", finishTouchKanbanDrag, { passive: false });
  document.addEventListener("touchcancel", (event) => finishTouchKanbanDrag(event, true), { passive: false });
}

const syncMobileDraggable = () => {
  const isMobile = window.matchMedia("(max-width: 880px), (pointer: coarse)").matches;
  cards.forEach((card) => {
    card.draggable = !isMobile;
  });
};

syncMobileDraggable();
window.addEventListener("resize", syncMobileDraggable);

if (kanbanBoard) {
  kanbanBoard.addEventListener("dragover", (event) => {
    updateBoardAutoScroll(event.clientX);
  });

  kanbanBoard.addEventListener("dragleave", (event) => {
    if (!kanbanBoard.contains(event.relatedTarget)) {
      stopBoardAutoScroll();
    }
  });
}

dropzones.forEach((zone) => {
  zone.addEventListener("dragover", (event) => {
    event.preventDefault();
    updateBoardAutoScroll(event.clientX);
    zone.classList.add("is-over");
  });

  zone.addEventListener("dragleave", () => {
    zone.classList.remove("is-over");
  });

  zone.addEventListener("drop", async (event) => {
    event.preventDefault();
    zone.classList.remove("is-over");
    stopBoardAutoScroll();

    if (!draggedCard) {
      return;
    }

    const movedCard = draggedCard;
    const previousParent = movedCard.parentElement;
    const previousNextSibling = movedCard.nextElementSibling;
    const previousStatus = previousParent.dataset.status;
    const targetStatus = zone.dataset.status;
    const leadId = movedCard.dataset.leadId;
    const cardAfterPointer = getCardAfterPointer(zone, event.clientY);

    if (!canMoveLeadToStatus(movedCard, targetStatus)) {
      return;
    }

    zone.insertBefore(movedCard, cardAfterPointer);
    updateColumnCounts();

    try {
      const orders = {
        [targetStatus]: getLeadOrder(zone),
      };

    if (previousParent !== zone) {
      orders[previousStatus] = getLeadOrder(previousParent);
    }

      markLocalKanbanMove();
      await persistLeadStatus(leadId, targetStatus, orders);
      const statusInput = movedCard.querySelector("input[name='status']");

      if (statusInput) {
        statusInput.value = targetStatus;
      }

      const mobileStatus = movedCard.querySelector("[data-mobile-status]");

      if (mobileStatus) {
        mobileStatus.value = targetStatus;
      }

      updateDashboardMetrics(previousStatus, targetStatus);
      keepKanbanCardInView(movedCard);
    } catch (error) {
      localKanbanMoveUntil = 0;
      previousParent.insertBefore(
        movedCard,
        previousNextSibling?.parentElement === previousParent ? previousNextSibling : null
      );
      updateColumnCounts();
      if (error.code === "cpf_required") {
        guideLeadCpf(movedCard);
      } else {
        alert("Não foi possível mover o lead. Tente novamente.");
      }
      console.error(error);
    }
  });
});

function findKanbanDropzone(status) {
  return [...dropzones].find((zone) => zone.dataset.status === status) || null;
}

mobileStatusControls.forEach((control) => {
  control.addEventListener("click", (event) => event.stopPropagation());
  control.addEventListener("change", async () => {
    const card = control.closest(".kanban-card");
    const previousParent = card?.parentElement;
    const targetZone = findKanbanDropzone(control.value);

    if (!card || !previousParent || !targetZone) {
      return;
    }

    const previousStatus = previousParent.dataset.status || "";
    const targetStatus = control.value;

    if (previousStatus === targetStatus) {
      return;
    }

    if (!canMoveLeadToStatus(card, targetStatus)) {
      control.value = previousStatus;
      return;
    }

    const previousNextSibling = card.nextElementSibling;
    const leadId = card.dataset.leadId || "";
    targetZone.appendChild(card);
    control.disabled = true;
    card.classList.add("is-moving");
    updateColumnCounts();

    try {
      markLocalKanbanMove();
      await persistLeadStatus(leadId, targetStatus, {
        [previousStatus]: getLeadOrder(previousParent),
        [targetStatus]: getLeadOrder(targetZone),
      });

      card.querySelectorAll("input[name='status']").forEach((input) => {
        input.value = targetStatus;
      });

      updateDashboardMetrics(previousStatus, targetStatus);
      keepKanbanCardInView(card);
    } catch (error) {
      localKanbanMoveUntil = 0;
      previousParent.insertBefore(
        card,
        previousNextSibling?.parentElement === previousParent ? previousNextSibling : null
      );
      control.value = previousStatus;
      updateColumnCounts();
      if (error.code === "cpf_required") {
        guideLeadCpf(card);
      } else {
        alert("Não foi possível mover o lead. Tente novamente.");
      }
      console.error(error);
    } finally {
      control.disabled = false;
      card.classList.remove("is-moving");
    }
  });
});

function findModalCard(panel) {
  const storedCard = modalOrigins.get(panel);

  if (storedCard) {
    return storedCard;
  }

  const leadId = panel.dataset.modalLeadId;
  return leadId ? document.querySelector(`.kanban-card[data-lead-id="${leadId}"]`) : null;
}

function closeLeadModal(panel) {
  const card = findModalCard(panel);

  panel.hidden = true;
  document.body.classList.remove("modal-open");

  if (card) {
    card.classList.remove("has-open-modal");
    card.appendChild(panel);

    const cardButton = card.querySelector(".lead-actions [data-toggle-details]");

    if (cardButton) {
      cardButton.textContent = "Detalhes";
    }
  }
}

const leadDetailsRequests = new Map();

function setLeadDetailsLoading(panel) {
  const container = panel.querySelector("[data-lead-details-container]");

  if (!container || container.dataset.leadDetailsLoaded === "true") {
    return;
  }

  container.className = "lead-modal-card lead-modal-card-loading";
  container.replaceChildren();
  const loading = document.createElement("p");
  loading.className = "lead-details-loading";
  loading.setAttribute("role", "status");
  loading.textContent = "Carregando detalhes…";
  container.appendChild(loading);
}

function setLeadDetailsError(panel, message) {
  const container = panel.querySelector("[data-lead-details-container]");

  if (!container) {
    return;
  }

  delete container.dataset.leadDetailsLoaded;
  container.className = "lead-modal-card lead-modal-card-loading";
  container.replaceChildren();
  const error = document.createElement("p");
  error.className = "lead-details-loading is-error";
  error.setAttribute("role", "alert");
  error.textContent = message;
  container.appendChild(error);
}

function focusLeadCpfField(panel) {
  const card = findModalCard(panel);

  if (!card || card.dataset.focusCpfAfterDetails !== "true") {
    return;
  }

  const input = panel.querySelector("input[name='cpf']");

  if (!input) {
    return;
  }

  delete card.dataset.focusCpfAfterDetails;
  window.requestAnimationFrame(() => input.focus());
}

async function loadLeadDetails(panel) {
  const leadId = panel.dataset.modalLeadId || "";
  const container = panel.querySelector("[data-lead-details-container]");

  if (!leadId || !container || container.dataset.leadDetailsLoaded === "true") {
    return;
  }

  const previousRequest = leadDetailsRequests.get(leadId);

  if (previousRequest) {
    try {
      await previousRequest;
    } catch (error) {
      // The first opener already displayed the loading error. Avoid an
      // unhandled rejection if the user taps another control while loading.
    }
    return;
  }

  const request = (async () => {
    const endpoint = new URL("./api/lead-details.php", window.location.href);
    endpoint.searchParams.set("id", leadId);

    const response = await fetch(endpoint, {
      headers: { Accept: "text/html" },
      cache: "no-store",
    });

    if (!response.ok) {
      throw new Error("Não foi possível carregar os detalhes deste contato.");
    }

    const html = (await response.text()).trim();
    const template = document.createElement("template");
    template.innerHTML = html;
    const loadedContainer = template.content.firstElementChild;

    if (!loadedContainer?.matches(".lead-modal-card")) {
      throw new Error("A resposta dos detalhes está inválida.");
    }

    loadedContainer.dataset.leadDetailsContainer = "";
    loadedContainer.dataset.leadDetailsLoaded = "true";
    panel.replaceChild(loadedContainer, container);
    bindLeadFormControls(panel);
    renderTagPreviews(panel);
    focusLeadCpfField(panel);
  })();

  leadDetailsRequests.set(leadId, request);

  try {
    await request;
  } catch (error) {
    setLeadDetailsError(panel, error.message || "Não foi possível carregar os detalhes.");
  } finally {
    if (leadDetailsRequests.get(leadId) === request) {
      leadDetailsRequests.delete(leadId);
    }
  }
}

function openLeadModal(card, panel, button) {
  closeUtilityDialogs();
  document.querySelectorAll(".lead-details-panel:not([hidden])").forEach(closeLeadModal);

  modalOrigins.set(panel, card);
  document.body.appendChild(panel);
  panel.hidden = false;
  card.classList.add("has-open-modal");
  document.body.classList.add("modal-open");
  button.textContent = "Ocultar";
  setLeadDetailsLoading(panel);
  focusLeadCpfField(panel);
  void loadLeadDetails(panel);
}

document.addEventListener("click", (event) => {
  const button = event.target.closest?.("[data-toggle-details]");

  if (!button) {
    return;
  }

  const panel = button.closest(".lead-details-panel");

  if (button.classList.contains("modal-close") && panel) {
    closeLeadModal(panel);
    return;
  }

  const card = button.closest(".kanban-card");
  const cardPanel = card?.querySelector(".lead-details-panel");

  if (!card || !cardPanel) {
    return;
  }

  if (cardPanel.hidden) {
    openLeadModal(card, cardPanel, button);
  } else {
    closeLeadModal(cardPanel);
  }
});

function closeUtilityDialogs() {
  document.querySelectorAll(".utility-dialog:not([hidden])").forEach((dialog) => {
    dialog.hidden = true;
  });

  if (document.querySelectorAll(".lead-details-panel:not([hidden])").length === 0) {
    document.body.classList.remove("modal-open");
  }
}

dialogButtons.forEach((button) => {
  button.addEventListener("click", () => {
    const dialogName = button.dataset.openDialog;
    const dialog = dialogName ? document.querySelector(`.utility-dialog[data-dialog="${dialogName}"]`) : null;

    if (!dialog) {
      return;
    }

    document.querySelectorAll(".lead-details-panel:not([hidden])").forEach((panel) => {
      closeLeadModal(panel);
    });
    closeUtilityDialogs();
    dialog.hidden = false;
    document.body.classList.add("modal-open");
    renderTagPreviews(dialog);

    const firstField = dialog.querySelector("input, select, textarea, button[type='submit']");

    if (firstField) {
      firstField.focus();
    }
  });
});

function parseTagInput(value) {
  const uniqueTags = new Map();

  value.split(/[,;\n]+/).forEach((part) => {
    const tag = part.trim();

    if (!tag) {
      return;
    }

    uniqueTags.set(tag.toLocaleLowerCase("pt-BR"), tag.slice(0, 40));
  });

  return Array.from(uniqueTags.values());
}

function renderTagPreview(input) {
  const tagField = input.closest(".tag-field") || input.parentElement;
  const preview = tagField?.querySelector("[data-tags-preview]");

  if (!preview) {
    return;
  }

  const tags = parseTagInput(input.value);
  preview.innerHTML = "";
  preview.hidden = tags.length === 0;

  tags.forEach((tag) => {
    const chip = document.createElement("span");
    chip.textContent = tag;

    const removeButton = document.createElement("button");
    removeButton.type = "button";
    removeButton.className = "tag-preview-remove";
    removeButton.dataset.tagRemove = tag;
    removeButton.title = `Remover tag ${tag}`;
    removeButton.setAttribute("aria-label", `Remover tag ${tag}`);
    removeButton.textContent = "×";
    chip.appendChild(removeButton);

    preview.appendChild(chip);
  });
}

function renderTagPreviews(scope = document) {
  scope.querySelectorAll("[data-tags-input]").forEach(renderTagPreview);
}

function parseCurrencyDigits(value) {
  const normalized = String(value || "").replace(/[^\d,]/g, "");

  if (!normalized) {
    return { reais: "", cents: "00" };
  }

  const parts = normalized.split(",");
  const reais = (parts[0] || "").replace(/\D/g, "");
  const cents = (parts[1] || "").replace(/\D/g, "").slice(0, 2).padEnd(2, "0");

  return { reais, cents };
}

function formatCurrencyBRLFromParts(reaisDigits, centsDigits = "00") {
  const reais = String(reaisDigits || "").replace(/\D/g, "");

  if (!reais) {
    return "";
  }

  const cents = String(centsDigits || "00").replace(/\D/g, "").slice(0, 2).padEnd(2, "0");
  const amount = Number(`${reais}.${cents}`);

  return amount.toLocaleString("pt-BR", {
    style: "currency",
    currency: "BRL",
  });
}

function setCursorToEnd(input) {
  requestAnimationFrame(() => {
    const end = input.value.length;
    input.setSelectionRange(end, end);
  });
}

function syncCurrencyInput(input, reais, cents = "00") {
  input.dataset.currencyReais = String(reais || "").replace(/\D/g, "").replace(/^0+(?=\d)/, "");
  input.dataset.currencyCents = String(cents || "00").replace(/\D/g, "").slice(0, 2).padEnd(2, "0");
  input.value = formatCurrencyBRLFromParts(input.dataset.currencyReais, input.dataset.currencyCents);
  setCursorToEnd(input);
}

function bindCurrencyInputs(scope = document) {
  scope.querySelectorAll("[data-currency-input]:not([data-currency-bound])").forEach((input) => {
    input.dataset.currencyBound = "true";
    const initial = parseCurrencyDigits(input.value);
    syncCurrencyInput(input, initial.reais, initial.cents);

    input.addEventListener("beforeinput", (event) => {
      const type = event.inputType || "";
      let reais = input.dataset.currencyReais || "";
      let cents = input.dataset.currencyCents || "00";

      if (type === "insertText" && /^\d$/.test(event.data || "")) {
        event.preventDefault();
        reais = (reais + event.data).replace(/^0+(?=\d)/, "");
      } else if (type === "deleteContentBackward") {
        event.preventDefault();
        reais = reais.slice(0, -1);
      } else if (type === "deleteContentForward") {
        event.preventDefault();
        reais = "";
        cents = "00";
      } else if (type === "insertFromPaste") {
        return;
      } else {
        event.preventDefault();
        return;
      }

      syncCurrencyInput(input, reais, cents);
    });

    input.addEventListener("paste", (event) => {
      event.preventDefault();
      const text = event.clipboardData?.getData("text") || "";
      const parsed = parseCurrencyDigits(text);
      syncCurrencyInput(input, parsed.reais, parsed.cents);
    });

    input.addEventListener("input", () => {
      const parsed = parseCurrencyDigits(input.value);
      syncCurrencyInput(input, parsed.reais, parsed.cents);
    });

    input.addEventListener("blur", () => {
      const reais = input.dataset.currencyReais || "";
      const cents = input.dataset.currencyCents || "00";
      syncCurrencyInput(input, reais, cents);
    });
  });
}

function formatCpf(value) {
  const digits = String(value || "").replace(/\D/g, "").slice(0, 11);
  let formatted = digits.slice(0, 3);
  if (digits.length > 3) formatted += `.${digits.slice(3, 6)}`;
  if (digits.length > 6) formatted += `.${digits.slice(6, 9)}`;
  if (digits.length > 9) formatted += `-${digits.slice(9, 11)}`;
  return formatted;
}

function bindCpfInputs(scope = document) {
  scope.querySelectorAll("[data-cpf-input]:not([data-cpf-bound])").forEach((input) => {
    input.dataset.cpfBound = "true";
    input.value = formatCpf(input.value);
    input.addEventListener("input", () => {
      input.value = formatCpf(input.value);
    });
  });
}

function bindTagInputs(scope = document) {
  scope.querySelectorAll("[data-tags-input]:not([data-tags-bound])").forEach((input) => {
    input.dataset.tagsBound = "true";
    renderTagPreview(input);
    input.addEventListener("input", () => {
      renderTagPreview(input);
    });
  });
}

function bindLeadFormControls(scope = document) {
  bindCurrencyInputs(scope);
  bindCpfInputs(scope);
  bindTagInputs(scope);
}

bindLeadFormControls();

document.addEventListener("click", (event) => {
  const removeButton = event.target.closest("[data-tag-remove]");

  if (!removeButton) {
    return;
  }

  const preview = removeButton.closest("[data-tags-preview]");
  const container = removeButton.closest(".tag-field") || preview?.parentElement;
  const input = container?.querySelector("[data-tags-input]");
  const tagToRemove = String(removeButton.dataset.tagRemove || "");

  if (!input || !tagToRemove) {
    return;
  }

  event.preventDefault();
  input.value = parseTagInput(input.value)
    .filter((tag) => tag.toLocaleLowerCase("pt-BR") !== tagToRemove.toLocaleLowerCase("pt-BR"))
    .join(", ");
  renderTagPreview(input);
  input.dispatchEvent(new Event("input", { bubbles: true }));
  input.focus();
});

document.querySelectorAll("[data-tag-option]").forEach((button) => {
  button.addEventListener("click", () => {
    const tag = button.dataset.tagOption || "";
    const tagField = button.closest(".tag-field");
    const input = tagField?.querySelector("[data-tags-input]");

    if (!tag || !input) {
      return;
    }

    const tags = parseTagInput(input.value);
    const alreadyExists = tags.some((item) => item.toLocaleLowerCase("pt-BR") === tag.toLocaleLowerCase("pt-BR"));

    if (!alreadyExists) {
      tags.push(tag);
      input.value = tags.join(", ");
    }

    renderTagPreview(input);
    input.focus();
  });
});

document.querySelectorAll("[data-close-dialog]").forEach((button) => {
  button.addEventListener("click", closeUtilityDialogs);
});

document.querySelectorAll(".utility-dialog").forEach((dialog) => {
  dialog.addEventListener("click", (event) => {
    if (event.target === dialog) {
      closeUtilityDialogs();
    }
  });
});

document.querySelectorAll(".lead-details-panel").forEach((panel) => {
  panel.addEventListener("click", (event) => {
    if (event.target !== panel) {
      return;
    }

    closeLeadModal(panel);
  });
});

document.addEventListener("keydown", (event) => {
  if (event.key !== "Escape") {
    return;
  }

  document.querySelectorAll(".lead-details-panel:not([hidden])").forEach((panel) => {
    closeLeadModal(panel);
  });
  closeUtilityDialogs();
});

document.addEventListener("click", (event) => {
  const tabButton = event.target.closest?.(".lead-modal-tabs [data-lead-tab]");

  if (!tabButton) {
    return;
  }

  const modal = tabButton.closest(".lead-modal-card");
  const target = tabButton.dataset.leadTab;

  if (!modal || !target) {
    return;
  }

  modal.querySelectorAll("[data-lead-tab]").forEach((button) => {
    button.classList.toggle("active", button === tabButton);
  });

  modal.querySelectorAll("[data-lead-panel]").forEach((panel) => {
    panel.hidden = panel.dataset.leadPanel !== target;
    panel.classList.toggle("active", panel.dataset.leadPanel === target);
  });
});

const initialLeadId = new URLSearchParams(window.location.search).get("lead");

if (initialLeadId) {
  const initialLeadCard = [...document.querySelectorAll(".kanban-card")].find((card) => card.dataset.leadId === initialLeadId);
  const initialLeadButton = initialLeadCard?.querySelector("[data-toggle-details]");

  if (initialLeadCard && initialLeadButton) {
    initialLeadCard.scrollIntoView({ behavior: "smooth", block: "center", inline: "center" });
    window.setTimeout(() => initialLeadButton.click(), 180);
  }
}

// PWA, instalação e notificações direcionadas ao vendedor logado.
const pushOnboarding = document.querySelector("[data-push-onboarding]");
const pushEnableButton = document.querySelector("[data-push-enable]");
const pushStatus = document.querySelector("[data-push-status]");
const installButton = document.querySelector("[data-pwa-install]");
const pushCsrfToken = document.querySelector("meta[name='csrf-token']")?.content || "";
let deferredInstallPrompt = null;
let pushActivationInFlight = false;

function setPushStatus(message, isError = false) {
  if (!pushStatus) {
    return;
  }

  pushStatus.textContent = message;
  pushStatus.classList.toggle("is-error", isError);
}

function showPushUnavailable(message, buttonEnabled = false) {
  if (!pushOnboarding || !pushEnableButton) {
    return;
  }

  pushOnboarding.hidden = false;
  pushEnableButton.hidden = false;
  pushEnableButton.disabled = !buttonEnabled;
  pushEnableButton.textContent = buttonEnabled ? "Ativar notificações" : "Notificações indisponíveis";
  setPushStatus(message, true);
}

function base64UrlToUint8Array(value) {
  const padding = "=".repeat((4 - (value.length % 4)) % 4);
  const base64 = (value + padding).replace(/-/g, "+").replace(/_/g, "/");
  const raw = window.atob(base64);
  return Uint8Array.from([...raw].map((character) => character.charCodeAt(0)));
}

async function pushRequest(action, options = {}) {
  const controller = new AbortController();
  const timeoutId = window.setTimeout(() => controller.abort(), 12000);

  try {
    const response = await fetch(`./api/push.php?action=${encodeURIComponent(action)}`, {
      ...options,
      signal: controller.signal,
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-CSRF-Token": pushCsrfToken,
        ...(options.headers || {}),
      },
    });
    const data = await response.json().catch(() => ({}));

    if (!response.ok || data.ok === false) {
      throw new Error(data.error || "Não foi possível atualizar as notificações.");
    }

    return data;
  } catch (error) {
    if (error?.name === "AbortError") {
      throw new Error("O servidor demorou para responder. Tente novamente.");
    }

    throw error;
  } finally {
    window.clearTimeout(timeoutId);
  }
}

async function pushConfigRequest() {
  const controller = new AbortController();
  const timeoutId = window.setTimeout(() => controller.abort(), 12000);

  try {
    const response = await fetch("./api/push.php?action=config", {
      headers: { Accept: "application/json" },
      signal: controller.signal,
      cache: "no-store",
    });
    const config = await response.json().catch(() => ({}));

    if (!response.ok || config.ok === false) {
      throw new Error(config.error || "Não foi possível preparar as notificações.");
    }

    return config;
  } catch (error) {
    if (error?.name === "AbortError") {
      throw new Error("O servidor demorou para preparar as notificações. Tente novamente.");
    }

    throw error;
  } finally {
    window.clearTimeout(timeoutId);
  }
}

async function syncPushState() {
  if (!pushEnableButton || !pushOnboarding || pushActivationInFlight) {
    return;
  }

  const isIos = /iPad|iPhone|iPod/i.test(navigator.userAgent);
  const isStandalone = window.matchMedia("(display-mode: standalone)").matches || window.navigator.standalone === true;

  if (!window.isSecureContext) {
    showPushUnavailable("Abra o CRM por um endereço HTTPS para ativar notificações.");
    return;
  }

  if (!("serviceWorker" in navigator)) {
    showPushUnavailable("Este navegador não oferece suporte ao aplicativo.");
    return;
  }

  if (!("PushManager" in window)) {
    showPushUnavailable(
      isIos && !isStandalone
        ? "No iPhone, primeiro adicione o CRM à Tela de Início e abra pelo novo ícone."
        : "Este navegador não oferece suporte a notificações push.",
      isIos && !isStandalone
    );
    return;
  }

  try {
    const config = await pushConfigRequest();

    if (!config.configured) {
      pushOnboarding.hidden = true;
      return;
    }

    const registration = await Promise.race([
      navigator.serviceWorker.ready,
      new Promise((_, reject) => window.setTimeout(() => reject(new Error("O aplicativo demorou para iniciar. Tente novamente.")), 12000)),
    ]);
    const subscription = await registration.pushManager.getSubscription();
    const permission = window.Notification?.permission || "default";

    // A inscrição local é a fonte imediata de verdade no aparelho. Se o
    // servidor perdeu a linha da assinatura (por exemplo, após uma limpeza
    // de sessão), reaproveitamos a inscrição existente sem pedir permissão
    // novamente ao vendedor.
    if (subscription && permission === "granted" && Number(config.subscriptions || 0) !== 1) {
      const json = subscription.toJSON();
      await pushRequest("subscribe", {
        method: "POST",
        body: JSON.stringify({ endpoint: json.endpoint, keys: json.keys }),
      });
    }

    const subscribed = Boolean(subscription && permission === "granted");

    if (pushActivationInFlight) {
      return;
    }

    pushEnableButton.disabled = false;
    pushEnableButton.hidden = subscribed;
    pushOnboarding.hidden = subscribed;

    if (permission === "denied") {
      pushEnableButton.hidden = false;
      pushEnableButton.disabled = true;
      pushOnboarding.hidden = false;
      pushEnableButton.textContent = "Notificações bloqueadas";
      setPushStatus("Permita as notificações nas configurações do navegador.", true);
    } else if (subscribed) {
      setPushStatus("Alertas de novos leads ativos.");
    } else {
      pushEnableButton.textContent = "Ativar notificações";
      pushOnboarding.hidden = false;
      setPushStatus("Leva apenas um toque no botão e outro em Permitir.");
    }
  } catch (error) {
    if (pushActivationInFlight) {
      return;
    }

    pushOnboarding.hidden = false;
    pushEnableButton.hidden = false;
    pushEnableButton.disabled = true;
    setPushStatus(error.message || "Não foi possível carregar as notificações.", true);
  }
}

async function enablePushNotifications() {
  if (!pushEnableButton) {
    return;
  }

  if (pushActivationInFlight) {
    return;
  }

  pushActivationInFlight = true;

  pushEnableButton.disabled = true;
  pushEnableButton.textContent = "Ativando…";

  try {
    const isIos = /iPad|iPhone|iPod/i.test(navigator.userAgent);
    const isStandalone = window.matchMedia("(display-mode: standalone)").matches || window.navigator.standalone === true;

    if (isIos && !isStandalone) {
      throw new Error("No iPhone, primeiro use Compartilhar → Adicionar à Tela de Início.");
    }

    if (!("Notification" in window)) {
      throw new Error("Este navegador não oferece suporte a notificações push.");
    }

    const config = await pushConfigRequest();

    if (!config.configured || !config.public_key) {
      throw new Error("O administrador ainda precisa configurar o Web Push.");
    }

    const permission = await Promise.race([
      window.Notification.requestPermission(),
      new Promise((_, reject) => window.setTimeout(() => reject(new Error("A janela de permissão não respondeu. Tente novamente.")), 60000)),
    ]);

    if (permission !== "granted") {
      throw new Error("A permissão para notificações não foi concedida.");
    }

    // O usuário já autorizou o recurso. Removemos o onboarding imediatamente
    // para não deixar a tela presa enquanto o navegador termina a inscrição.
    pushEnableButton.hidden = true;
    pushOnboarding.hidden = true;

    const registration = await Promise.race([
      navigator.serviceWorker.ready,
      new Promise((_, reject) => window.setTimeout(() => reject(new Error("O aplicativo demorou para iniciar. Tente novamente.")), 12000)),
    ]);
    let subscription = await registration.pushManager.getSubscription();

    if (!subscription) {
      subscription = await Promise.race([
        registration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: base64UrlToUint8Array(config.public_key),
        }),
        new Promise((_, reject) => window.setTimeout(() => reject(new Error("O celular demorou para criar a inscrição. Tente novamente.")), 12000)),
      ]);
    }

    const json = subscription.toJSON();

    // A permissão e a inscrição local já estão prontas. A partir daqui a
    // tela não fica presa esperando o servidor ou o alerta de teste.
    pushEnableButton.hidden = true;
    pushOnboarding.hidden = true;
    pushEnableButton.disabled = false;

    try {
      await pushRequest("subscribe", {
        method: "POST",
        body: JSON.stringify({ endpoint: json.endpoint, keys: json.keys }),
      });
    } catch (subscribeError) {
      console.warn("A inscrição local foi criada, mas não foi sincronizada com o servidor.", subscribeError);
      pushEnableButton.hidden = false;
      pushOnboarding.hidden = false;
      pushEnableButton.textContent = "Tentar novamente";
      setPushStatus("Permissão concedida, mas não foi possível concluir a sincronização.", true);
      pushActivationInFlight = false;
      return;
    }

    try {
      await pushRequest("test", { method: "POST", body: "{}" });
      setPushStatus("Alertas ativos. Enviamos um teste para este dispositivo.");
    } catch (testError) {
      console.warn("A inscrição foi salva, mas o alerta de teste falhou.", testError);
      setPushStatus("Alertas ativos. O teste não pôde ser enviado agora.", true);
    }

    pushActivationInFlight = false;

  } catch (error) {
    pushActivationInFlight = false;
    pushEnableButton.disabled = false;
    pushEnableButton.hidden = false;
    pushOnboarding.hidden = false;
    pushEnableButton.textContent = "Tentar novamente";
    setPushStatus(error.message || "Não foi possível ativar os alertas.", true);
  }
}

if (pushEnableButton) {
  pushEnableButton.addEventListener("click", enablePushNotifications);
}

if (installButton) {
  const isIos = /iPad|iPhone|iPod/i.test(navigator.userAgent);
  const isStandalone = window.matchMedia("(display-mode: standalone)").matches || window.navigator.standalone === true;

  if (isIos && !isStandalone) {
    installButton.hidden = false;
  }

  window.addEventListener("beforeinstallprompt", (event) => {
    event.preventDefault();
    deferredInstallPrompt = event;
    installButton.hidden = false;
  });

  installButton.addEventListener("click", async () => {
    if (!deferredInstallPrompt) {
      setPushStatus("No iPhone, use Compartilhar → Adicionar à Tela de Início.");
      return;
    }

    deferredInstallPrompt.prompt();
    await deferredInstallPrompt.userChoice;
    deferredInstallPrompt = null;
    installButton.hidden = true;
  });

  window.addEventListener("appinstalled", () => {
    installButton.hidden = true;
    deferredInstallPrompt = null;
  });
}

if ("serviceWorker" in navigator) {
  navigator.serviceWorker.register("./sw.js?v=20260813-lazy-lead-details-v1", {
    scope: "./",
    updateViaCache: "none",
  })
    .then(syncPushState)
    .catch((error) => showPushUnavailable(error.message || "Não foi possível preparar o aplicativo."));
}

// Mantém o funil sincronizado quando uma nova mensagem cria um lead ou quando
// a roleta atribui o lead a um vendedor. A página continua leve porque o
// endpoint devolve apenas uma versão, sem recarregar todos os dados a cada ciclo.
if (document.body.classList.contains("leads-page")) {
  let leadFeedVersion = "";
  let leadFeedInFlight = false;
  let leadFeedReloadScheduled = false;

  const leadFeedHasOpenEditor = () => {
    if (document.querySelector(".utility-dialog:not([hidden]), .lead-modal:not([hidden])")) {
      return true;
    }

    const activeElement = document.activeElement;
    return Boolean(activeElement && activeElement.matches("input, textarea, select"));
  };

  const showLeadFeedRefreshNotice = () => {
    let notice = document.querySelector("[data-lead-feed-refresh]");

    if (!notice) {
      notice = document.createElement("div");
      notice.className = "live-refresh-toast";
      notice.dataset.leadFeedRefresh = "true";
      notice.setAttribute("role", "status");
      document.body.appendChild(notice);
    }

    notice.textContent = "Novo lead atribuído. Atualizando…";
    notice.hidden = false;
  };

  const syncLeadFeed = async () => {
    if (leadFeedInFlight || leadFeedReloadScheduled || document.visibilityState !== "visible" || leadFeedHasOpenEditor()) {
      return;
    }

    leadFeedInFlight = true;

    try {
      const response = await fetch(`./api/lead-feed.php?_=${Date.now()}`, {
        headers: { Accept: "application/json" },
        cache: "no-store",
      });
      const data = await response.json().catch(() => ({}));

      if (!response.ok || data.ok !== true || !data.version) {
        return;
      }

      if (leadFeedVersion === "") {
        leadFeedVersion = data.version;
        return;
      }

      if (leadFeedVersion !== data.version) {
        leadFeedVersion = data.version;

        if (consumeLocalKanbanMove()) {
          return;
        }

        leadFeedReloadScheduled = true;
        showLeadFeedRefreshNotice();
        window.setTimeout(() => window.location.reload(), 350);
      }
    } catch (error) {
      // Uma falha pontual de rede será recuperada no próximo ciclo.
    } finally {
      leadFeedInFlight = false;
    }
  };

  syncLeadFeed();
  window.setInterval(syncLeadFeed, 5000);
  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "visible") {
      syncLeadFeed();
    }
  });
}
