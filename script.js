const leadForm = document.querySelector("#leadForm");
const formStatus = document.querySelector("#formStatus");

const attributionFields = [
  "utm_source",
  "utm_medium",
  "utm_campaign",
  "utm_content",
  "utm_term",
];

function readMarketingAttribution() {
  const current = new URLSearchParams(window.location.search);
  let stored = {};

  try {
    stored = JSON.parse(sessionStorage.getItem("mmdesign_attribution") || "{}");
  } catch {
    stored = {};
  }

  const attribution = {};

  attributionFields.forEach((field) => {
    const value = String(current.get(field) || stored[field] || "").trim();

    if (value !== "") {
      attribution[field] = value;
    }
  });

  if (Object.keys(attribution).length > 0) {
    try {
      sessionStorage.setItem("mmdesign_attribution", JSON.stringify(attribution));
    } catch {
      // Storage may be unavailable in private browsing; the current URL still works.
    }
  }

  return attribution;
}

leadForm?.addEventListener("submit", (event) => {
  event.preventDefault();

  const data = new FormData(leadForm);
  const name = String(data.get("name") || "").trim();
  const whatsapp = String(data.get("whatsapp") || "").trim();
  const company = String(data.get("company") || "").trim();
  const submitButton = leadForm.querySelector("button[type='submit']");
  const attribution = readMarketingAttribution();

  const payload = {
    name,
    whatsapp,
    company,
    segment: "Página Sierra",
    advertises: "Lead captado pela página Sierra",
    message: "Solicitou contato pela página Sierra.",
    page: window.location.href,
    landing_path: window.location.pathname,
    referrer: document.referrer,
    ...attribution,
  };

  formStatus.textContent = "Enviando para o CRM...";
  submitButton.disabled = true;

  fetch("./crm/api/leads.php", {
    method: "POST",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
    },
    body: JSON.stringify(payload),
  })
    .then(async (response) => {
      const responseText = await response.text();
      let responseData = null;

      try {
        responseData = responseText ? JSON.parse(responseText) : null;
      } catch {
        responseData = null;
      }

      if (!response.ok || responseData?.ok !== true) {
        throw new Error(responseData?.error || "Nao foi possivel salvar o lead.");
      }

      formStatus.textContent = responseData.created === false
        ? "Este contato ja estava no CRM da Sierra."
        : "Lead salvo no CRM da Sierra.";
      leadForm.reset();
    })
    .catch((error) => {
      formStatus.textContent = error.message || "Erro ao enviar. Tente novamente.";
    })
    .finally(() => {
      submitButton.disabled = false;
    });
});
