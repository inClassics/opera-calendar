document.addEventListener("DOMContentLoaded", () => {
  const csrfToken = window.SECTION_SCHEDULE?.csrfToken || "";

  /*
  |--------------------------------------------------------------------------
  | Editing mode
  |--------------------------------------------------------------------------
  */

  let editingMode = false;

  const editModeToggle = document.getElementById("edit-mode-toggle");

  let closeMenu = () => {};

  const updateEditingMode = () => {
    document.body.classList.toggle("editing-mode", editingMode);

    if (!editModeToggle) {
      return;
    }

    editModeToggle.textContent = editingMode ? "Done editing" : "Edit schedule";

    editModeToggle.setAttribute("aria-pressed", editingMode ? "true" : "false");
  };

  if (editModeToggle) {
    editModeToggle.addEventListener("click", () => {
      editingMode = !editingMode;

      updateEditingMode();

      if (!editingMode) {
        closeMenu();

        if (!activityEditor.hidden) {
          closeActivityEditor();
        }
      }
    });
  }

  updateEditingMode();

  /*
  |--------------------------------------------------------------------------
  | Render availability
  |--------------------------------------------------------------------------
  */

  const renderCell = (cell) => {
    const status = cell.dataset.status || "";

    const uncertain = cell.dataset.uncertain === "1";

    cell.classList.remove("available", "unavailable", "uncertain");

    cell.textContent = "";

    if (status === "available") {
      cell.textContent = uncertain ? "×?" : "×";

      cell.classList.add("available");
    } else if (status === "unavailable") {
      cell.textContent = uncertain ? "•?" : "•";

      cell.classList.add("unavailable");
    }

    if (uncertain) {
      cell.classList.add("uncertain");
    }
  };

  /*
  |--------------------------------------------------------------------------
  | Save normal availability
  |--------------------------------------------------------------------------
  */

  const saveAvailability = async (cell, nextStatus) => {
    const previousStatus = cell.dataset.status || "";

    const previousUncertain = cell.dataset.uncertain === "1";

    cell.dataset.status = nextStatus;

    if (nextStatus === "") {
      cell.dataset.uncertain = "0";
    }

    renderCell(cell);

    cell.dataset.saving = "1";

    const formData = new FormData();

    formData.append("csrf_token", csrfToken);

    formData.append("user_id", cell.dataset.userId);

    formData.append("date", cell.dataset.date);

    formData.append("period", cell.dataset.period);

    formData.append("status", nextStatus);

    try {
      const response = await fetch("ajax/update-availability.php", {
        method: "POST",
        body: formData,
      });

      const result = await response.json();

      if (!response.ok || !result.success) {
        throw new Error(result.message || "Could not save availability.");
      }

      return true;
    } catch (error) {
      cell.dataset.status = previousStatus;

      cell.dataset.uncertain = previousUncertain ? "1" : "0";

      renderCell(cell);

      alert(error.message);

      return false;
    } finally {
      cell.dataset.saving = "0";
    }
  };

  /*
  |--------------------------------------------------------------------------
  | Save uncertainty
  |--------------------------------------------------------------------------
  */

  const saveUncertain = async (cell, nextUncertain) => {
    const previousUncertain = cell.dataset.uncertain === "1";

    cell.dataset.uncertain = nextUncertain ? "1" : "0";

    renderCell(cell);

    cell.dataset.saving = "1";

    const formData = new FormData();

    formData.append("csrf_token", csrfToken);

    formData.append("user_id", cell.dataset.userId);

    formData.append("date", cell.dataset.date);

    formData.append("period", cell.dataset.period);

    formData.append("uncertain", nextUncertain ? "1" : "0");

    try {
      const response = await fetch("ajax/toggle-uncertain.php", {
        method: "POST",
        body: formData,
      });

      const result = await response.json();

      if (!response.ok || !result.success) {
        throw new Error(result.message || "Could not save uncertainty.");
      }

      return true;
    } catch (error) {
      cell.dataset.uncertain = previousUncertain ? "1" : "0";

      renderCell(cell);

      alert(error.message);

      return false;
    } finally {
      cell.dataset.saving = "0";
    }
  };

  /*
  |--------------------------------------------------------------------------
  | Availability context menu
  |--------------------------------------------------------------------------
  */

  const menu = document.createElement("div");

  menu.className = "cell-options-menu";

  menu.hidden = true;

  menu.innerHTML = `
    <div class="cell-options-title">
      Availability options
    </div>

    <button
      type="button"
      class="cell-options-item"
      data-action="uncertain"
    >
      <span class="cell-options-check"></span>
      <span>Uncertain</span>
    </button>

    <div class="cell-options-separator"></div>

    <button
      type="button"
      class="cell-options-item danger"
      data-action="clear"
    >
      Clear availability
    </button>
  `;

  document.body.appendChild(menu);

  let activeCell = null;

  closeMenu = () => {
    menu.hidden = true;
    activeCell = null;
  };

  const positionMenu = (event) => {
    menu.hidden = false;

    menu.style.left = "0px";

    menu.style.top = "0px";

    const rect = menu.getBoundingClientRect();

    const padding = 8;

    let left = event.clientX;

    let top = event.clientY;

    if (left + rect.width + padding > window.innerWidth) {
      left = window.innerWidth - rect.width - padding;
    }

    if (top + rect.height + padding > window.innerHeight) {
      top = window.innerHeight - rect.height - padding;
    }

    menu.style.left = `${Math.max(padding, left)}px`;

    menu.style.top = `${Math.max(padding, top)}px`;
  };

  const openMenu = (cell, event) => {
    activeCell = cell;

    const status = cell.dataset.status || "";

    const uncertain = cell.dataset.uncertain === "1";

    const uncertainButton = menu.querySelector('[data-action="uncertain"]');

    const check = uncertainButton.querySelector(".cell-options-check");

    uncertainButton.disabled = status === "";

    uncertainButton.classList.toggle("selected", uncertain);

    check.textContent = uncertain ? "✓" : "";

    const clearButton = menu.querySelector('[data-action="clear"]');

    clearButton.disabled = status === "";

    positionMenu(event);
  };

  /*
  |--------------------------------------------------------------------------
  | Availability cells
  |--------------------------------------------------------------------------
  */

  document.querySelectorAll(".availability-cell").forEach((cell) => {
    renderCell(cell);

    if (!cell.classList.contains("editable")) {
      return;
    }

    cell.addEventListener("click", async () => {
      if (!editingMode) {
        return;
      }

      if (cell.dataset.saving === "1") {
        return;
      }

      closeMenu();

      const current = cell.dataset.status || "";

      const next = current === "" ? "available" : current === "available" ? "unavailable" : "";

      await saveAvailability(cell, next);
    });

    cell.addEventListener("contextmenu", (event) => {
      if (!editingMode) {
        return;
      }

      event.preventDefault();

      if (cell.dataset.saving === "1") {
        return;
      }

      openMenu(cell, event);
    });
  });

  /*
  |--------------------------------------------------------------------------
  | Availability menu actions
  |--------------------------------------------------------------------------
  */

  menu.addEventListener("click", async (event) => {
    if (!editingMode) {
      closeMenu();
      return;
    }

    const button = event.target.closest("[data-action]");

    if (!button || button.disabled || !activeCell) {
      return;
    }

    const cell = activeCell;

    const action = button.dataset.action;

    if (cell.dataset.saving === "1") {
      return;
    }

    if (action === "uncertain") {
      if ((cell.dataset.status || "") === "") {
        return;
      }

      const next = cell.dataset.uncertain !== "1";

      await saveUncertain(cell, next);

      closeMenu();

      return;
    }

    if (action === "clear") {
      await saveAvailability(cell, "");

      closeMenu();
    }
  });

  /*
  |--------------------------------------------------------------------------
  | Close availability menu
  |--------------------------------------------------------------------------
  */

  document.addEventListener("click", (event) => {
    if (!menu.hidden && !menu.contains(event.target)) {
      closeMenu();
    }
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      closeMenu();
    }
  });

  window.addEventListener("scroll", closeMenu, true);

  window.addEventListener("resize", closeMenu);

  /*
  |--------------------------------------------------------------------------
  | Floating activity editor
  |--------------------------------------------------------------------------
  */

  const activityEditor = document.createElement("div");

  activityEditor.className = "activity-editor-overlay";

  activityEditor.hidden = true;

  activityEditor.innerHTML = `
    <div
      class="activity-editor-dialog"
      role="dialog"
      aria-modal="true"
      aria-labelledby="activity-editor-title"
    >
      <div class="activity-editor-header">

        <div>
          <div
            class="activity-editor-title"
            id="activity-editor-title"
          >
            Edit activity
          </div>

          <div class="activity-editor-meta"></div>
        </div>

        <button
          type="button"
          class="activity-editor-close"
          aria-label="Close"
        >
          ×
        </button>

      </div>

      <div class="activity-editor-body">

        <label class="activity-editor-label">
          Activity

          <textarea
            class="activity-editor-textarea"
            rows="7"
            maxlength="255"
            spellcheck="true"
          ></textarea>
        </label>

        <div class="activity-editor-help">
          You can use multiple lines.
          <br>
          Example:
          <br>
          <strong>11:00–14:00</strong>
          <br>
          Salome
          <br>
          (skatuves mēģinājums ar orķestri)
        </div>

      </div>

      <div class="activity-editor-footer">

        <button
          type="button"
          class="button activity-editor-cancel"
        >
          Cancel
        </button>

        <button
          type="button"
          class="button activity-editor-save"
        >
          Save
        </button>

      </div>
    </div>
  `;

  document.body.appendChild(activityEditor);

  const activityEditorTextarea = activityEditor.querySelector(".activity-editor-textarea");

  const activityEditorMeta = activityEditor.querySelector(".activity-editor-meta");

  const activityEditorSave = activityEditor.querySelector(".activity-editor-save");

  const activityEditorCancel = activityEditor.querySelector(".activity-editor-cancel");

  const activityEditorClose = activityEditor.querySelector(".activity-editor-close");

  let activityEditorCell = null;

  /*
  |--------------------------------------------------------------------------
  | Build clean activity text
  |--------------------------------------------------------------------------
  */

  const getActivityText = (cell) => {
    const time = cell.querySelector(".desktop-paper-activity-time")?.textContent?.trim() || "";

    const title = cell.querySelector(".desktop-paper-activity-title")?.textContent?.trim() || "";

    const details = cell.querySelector(".desktop-paper-activity-details")?.textContent?.trim() || "";

    if (time || title || details) {
      return [time, title, details].filter(Boolean).join("\n");
    }

    const textContainer = cell.querySelector(".desktop-paper-activity-text");

    if (textContainer) {
      return textContainer.textContent.replace(/\s+/g, " ").trim();
    }

    /*
    |--------------------------------------------------------------------------
    | Mobile fallback
    |--------------------------------------------------------------------------
    */

    const clone = cell.cloneNode(true);

    clone.querySelectorAll(".activity-point-editor").forEach((element) => element.remove());

    return clone.textContent.replace(/\s+/g, " ").trim();
  };

  /*
  |--------------------------------------------------------------------------
  | Open activity editor
  |--------------------------------------------------------------------------
  */

  const openActivityEditor = (cell) => {
    activityEditorCell = cell;

    const activity = getActivityText(cell);

    activityEditorTextarea.value = activity;

    const date = cell.dataset.date || "";

    const period = cell.dataset.period || "";

    activityEditorMeta.textContent = [date, period ? period.charAt(0).toUpperCase() + period.slice(1) : ""].filter(Boolean).join(" · ");

    activityEditor.hidden = false;

    document.body.classList.add("activity-editor-open");

    requestAnimationFrame(() => {
      activityEditorTextarea.focus();

      activityEditorTextarea.setSelectionRange(activityEditorTextarea.value.length, activityEditorTextarea.value.length);
    });
  };

  /*
  |--------------------------------------------------------------------------
  | Close activity editor
  |--------------------------------------------------------------------------
  */

  const closeActivityEditor = () => {
    activityEditor.hidden = true;

    document.body.classList.remove("activity-editor-open");

    activityEditorCell = null;

    activityEditorTextarea.value = "";
  };

  /*
  |--------------------------------------------------------------------------
  | Open editor when clicking activity
  |--------------------------------------------------------------------------
  */

  document.querySelectorAll(".activity-editable").forEach((cell) => {
    cell.addEventListener("click", (event) => {
      if (!editingMode) {
        return;
      }

      /*
          |--------------------------------------------------------------------------
          | Do not open editor when clicking points / R / P
          |--------------------------------------------------------------------------
          */

      if (event.target.closest(".activity-point-editor")) {
        return;
      }

      event.preventDefault();
      event.stopPropagation();

      closeMenu();

      openActivityEditor(cell);
    });
  });

  /*
  |--------------------------------------------------------------------------
  | Save activity
  |--------------------------------------------------------------------------
  */

  activityEditorSave.addEventListener("click", async () => {
    if (!activityEditorCell) {
      return;
    }

    const cell = activityEditorCell;

    const activity = activityEditorTextarea.value.trim();

    activityEditorSave.disabled = true;

    activityEditorTextarea.disabled = true;

    const formData = new FormData();

    formData.append("csrf_token", csrfToken);

    formData.append("date", cell.dataset.date);

    formData.append("period", cell.dataset.period);

    formData.append("activity", activity);

    try {
      const response = await fetch("ajax/update-activity.php", {
        method: "POST",
        body: formData,
      });

      const result = await response.json();

      if (!response.ok || !result.success) {
        throw new Error(result.message || "Could not save activity.");
      }

      /*
        |--------------------------------------------------------------------------
        | Reload after changing text
        |--------------------------------------------------------------------------
        |
        | This rebuilds the formatted vertical display.
        |
        */

      window.location.reload();
    } catch (error) {
      alert(error.message);

      activityEditorSave.disabled = false;

      activityEditorTextarea.disabled = false;

      activityEditorTextarea.focus();
    }
  });

  /*
  |--------------------------------------------------------------------------
  | Cancel / close
  |--------------------------------------------------------------------------
  */

  activityEditorCancel.addEventListener("click", closeActivityEditor);

  activityEditorClose.addEventListener("click", closeActivityEditor);

  /*
  |--------------------------------------------------------------------------
  | Click outside dialog
  |--------------------------------------------------------------------------
  */

  activityEditor.addEventListener("mousedown", (event) => {
    if (event.target === activityEditor) {
      closeActivityEditor();
    }
  });

  /*
  |--------------------------------------------------------------------------
  | Keyboard
  |--------------------------------------------------------------------------
  */

  document.addEventListener("keydown", (event) => {
    if (activityEditor.hidden) {
      return;
    }

    if (event.key === "Escape") {
      event.preventDefault();

      closeActivityEditor();

      return;
    }

    /*
      |--------------------------------------------------------------------------
      | Cmd/Ctrl + Enter = Save
      |--------------------------------------------------------------------------
      */

    if (event.key === "Enter" && (event.metaKey || event.ctrlKey)) {
      event.preventDefault();

      activityEditorSave.click();
    }
  });

  /*
  |--------------------------------------------------------------------------
  | IMPORTANT
  |--------------------------------------------------------------------------
  |
  | app.js does NOT handle right-click activity management.
  |
  | split-events.js owns:
  |
  | - Split
  | - Edit split event
  | - Add split event
  | - Delete split event
  | - Merge back
  |
  */
});
