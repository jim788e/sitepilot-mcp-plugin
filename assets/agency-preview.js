document.addEventListener("click", async (event) => {
  if (event.target.closest(".sitepilot-print-report")) {
    window.print();
    return;
  }

  const button = event.target.closest(".sitepilot-copy-workflow");
  if (!button) return;

  const prompt = document.getElementById(button.dataset.promptId);
  const status = button.parentElement.querySelector(".sitepilot-copy-status");
  if (!prompt || !status) return;

  try {
    await navigator.clipboard.writeText(prompt.value);
    status.textContent = "Copied. Paste this prompt into your MCP client.";
  } catch {
    prompt.focus();
    prompt.select();
    status.textContent = document.execCommand("copy")
      ? "Copied. Paste this prompt into your MCP client."
      : "Copy was blocked. Select the prompt and copy it manually.";
  }
});
