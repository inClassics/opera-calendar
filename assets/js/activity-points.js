
document.addEventListener("DOMContentLoaded", () => {
  const csrfToken = window.SECTION_SCHEDULE?.csrfToken || "";

  const saveEditor = async (editor) => {
    if (editor.dataset.saving === "1") return false;

    const input = editor.querySelector(".activity-point-input");
    const selected = editor.querySelector(".activity-point-type-button.selected");
    const rawValue = (input?.value || "").trim();

    if (rawValue === "" || Number.isNaN(Number(rawValue))) {
      alert("Point value must be a number.");
      return false;
    }

    const numericValue = Number(rawValue);
    if (numericValue < 0 || numericValue > 9999) {
      alert("Point value must be between 0 and 9999.");
      return false;
    }

    editor.dataset.saving = "1";
    editor.classList.add("is-saving");
    editor.classList.remove("is-error");

    const body = new FormData();
    body.append("csrf_token", csrfToken);
    body.append("source_type", editor.dataset.pointSource || "");
    body.append("source_id", editor.dataset.pointId || "");
    body.append("point_value", String(numericValue));
    body.append("point_type", selected?.dataset.pointType || "");

    try {
      const response = await fetch("ajax/update-activity-points.php", {
        method: "POST",
        body,
      });

      let result;
      try {
        result = await response.json();
      } catch {
        throw new Error("The server did not return valid JSON.");
      }

      if (!response.ok || !result.success) {
        throw new Error(result.message || "Could not save point settings.");
      }

      window.location.reload();
      return true;
    } catch (error) {
      editor.classList.add("is-error");
      alert(error.message);
      return false;
    } finally {
      editor.dataset.saving = "0";
      editor.classList.remove("is-saving");
    }
  };

  document.querySelectorAll(".activity-point-editor").forEach((editor) => {
    editor.addEventListener("click", e => e.stopPropagation());
    editor.addEventListener("contextmenu", e => e.stopPropagation());

    const input = editor.querySelector(".activity-point-input");
    if (input) {
      let original = input.value;

      input.addEventListener("focus", () => {
        original = input.value;
        input.select();
      });

      input.addEventListener("keydown", (event) => {
        event.stopPropagation();

        if (event.key === "Enter") {
          event.preventDefault();
          input.blur();
        }

        if (event.key === "Escape") {
          event.preventDefault();
          input.value = original;
          input.blur();
        }
      });

      input.addEventListener("change", () => saveEditor(editor));
    }

    const buttons = editor.querySelectorAll(".activity-point-type-button");

    buttons.forEach((button) => {
      button.addEventListener("click", async (event) => {
        event.preventDefault();
        event.stopPropagation();

        if (!document.body.classList.contains("editing-mode")) return;

        buttons.forEach(b => b.classList.remove("selected"));
        button.classList.add("selected");

        await saveEditor(editor);
      });
    });
  });
});
