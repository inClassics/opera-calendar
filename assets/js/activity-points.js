document.addEventListener("DOMContentLoaded", () => {
  const App = window.ScheduleApp;

  if (!App) {
    console.error("ScheduleApp core is missing.");
    return;
  }

  const updateBadge = (editor, pointValue, pointType) => {
    const item = editor.closest(".desktop-paper-activity-item");

    if (!item) {
      return;
    }

    let badge = item.querySelector(".desktop-paper-point-badge");

    if (
      pointValue <= 0
      || !["rehearsal", "performance"].includes(pointType)
    ) {
      badge?.remove();
      return;
    }

    if (!badge) {
      badge = document.createElement("div");
      badge.className = "desktop-paper-point-badge";
      badge.innerHTML = `
        <span class="desktop-paper-point-letter"></span>
        <span class="desktop-paper-point-divider">·</span>
        <strong class="desktop-paper-point-number"></strong>
      `;

      item.appendChild(badge);
    }

    badge.classList.remove(
      "desktop-paper-point-badge-rehearsal",
      "desktop-paper-point-badge-performance"
    );

    badge.classList.add(
      `desktop-paper-point-badge-${pointType}`
    );

    badge.querySelector(".desktop-paper-point-letter").textContent =
      pointType === "rehearsal" ? "R" : "P";

    badge.querySelector(".desktop-paper-point-number").textContent =
      String(pointValue);

    badge.title =
      `${pointType === "rehearsal" ? "Rehearsal" : "Performance"} · `
      + `${pointValue} ${pointValue === 1 ? "point" : "points"}`;
  };

  const saveEditor = async (editor) => {
    if (editor.dataset.saving === "1") {
      return false;
    }

    const input = editor.querySelector(".activity-point-input");
    const selected = editor.querySelector(
      ".activity-point-type-button.selected"
    );

    const rawValue = (input?.value || "").trim();

    if (rawValue === "" || !Number.isInteger(Number(rawValue))) {
      alert("Point value must be a whole number.");
      return false;
    }

    const pointValue = Number(rawValue);

    if (pointValue < 0 || pointValue > 9999) {
      alert("Point value must be between 0 and 9999.");
      return false;
    }

    const pointType = selected?.dataset.pointType || "";

    editor.dataset.saving = "1";
    editor.classList.add("is-saving");
    editor.classList.remove("is-error");

    try {
      const result = await App.post(
        "ajax/update-activity-points.php",
        {
          source_type: editor.dataset.pointSource || "",
          source_id: editor.dataset.pointId || "",
          point_value: pointValue,
          point_type: pointType,
        }
      );

      editor.dataset.pointType = result.point_type || "";

      editor.classList.add("is-saved");

      updateBadge(
        editor,
        Number(result.point_value || 0),
        result.point_type || ""
      );

      setTimeout(() => {
        editor.classList.remove("is-saved");
      }, 700);

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
    editor.addEventListener("click", (event) => {
      event.stopPropagation();
    });

    editor.addEventListener("contextmenu", (event) => {
      event.stopPropagation();
    });

    const input = editor.querySelector(".activity-point-input");

    if (input) {
      let original = input.value;
      let saveTimer = null;

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
          clearTimeout(saveTimer);
          input.value = original;
          input.blur();
        }
      });

      const scheduleSave = () => {
        if (!App.isEditing()) {
          return;
        }

        clearTimeout(saveTimer);

        saveTimer = setTimeout(() => {
          saveEditor(editor);
        }, 400);
      };

      input.addEventListener("input", scheduleSave);
      input.addEventListener("change", scheduleSave);
    }

    const buttons = editor.querySelectorAll(
      ".activity-point-type-button"
    );

    buttons.forEach((button) => {
      button.addEventListener("click", async (event) => {
        event.preventDefault();
        event.stopPropagation();

        if (!App.isEditing()) {
          return;
        }

        buttons.forEach((item) => {
          item.classList.remove("selected");
        });

        button.classList.add("selected");

        await saveEditor(editor);
      });
    });
  });
});
