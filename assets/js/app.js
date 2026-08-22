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
| Floating rich activity editor
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

      <div class="activity-editor-toolbar">

        <button
          type="button"
          class="activity-editor-tool"
          data-command="bold"
          title="Bold"
        >
          <strong>B</strong>
        </button>

        <button
          type="button"
          class="activity-editor-tool"
          data-command="italic"
          title="Italic"
        >
          <em>I</em>
        </button>

        <div class="activity-editor-toolbar-separator"></div>

        <button
          type="button"
          class="activity-editor-tool activity-editor-clear-format"
          title="Clear formatting"
        >
          Clear formatting
        </button>

      </div>

      <div
        class="activity-rich-editor"
        contenteditable="true"
        spellcheck="true"
        role="textbox"
        aria-multiline="true"
      ></div>

      <div class="activity-editor-help">
        Select text and use Bold or Italic.
        Press Enter for a new line.
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

  const richEditor = activityEditor.querySelector(".activity-rich-editor");

  const activityEditorMeta = activityEditor.querySelector(".activity-editor-meta");

  const activityEditorSave = activityEditor.querySelector(".activity-editor-save");

  const activityEditorCancel = activityEditor.querySelector(".activity-editor-cancel");

  const activityEditorClose = activityEditor.querySelector(".activity-editor-close");

  let activityEditorCell = null;

  /*
|--------------------------------------------------------------------------
| Escape HTML
|--------------------------------------------------------------------------
*/

  const escapeHtml = (value) => {
    const div = document.createElement("div");

    div.textContent = value;

    return div.innerHTML;
  };

  /*
|--------------------------------------------------------------------------
| Stored markup -> editor HTML
|--------------------------------------------------------------------------
|
| Database:
|
| **bold**
| *italic*
| newline
|
*/

  const markupToHtml = (value) => {
    let html = escapeHtml(value);

    /*
  | Bold first.
  */

    html = html.replace(/\*\*(.+?)\*\*/gs, "<strong>$1</strong>");

    /*
  | Italic.
  */

    html = html.replace(/\*([^*\n]+?)\*/g, "<em>$1</em>");

    html = html.replace(/\r?\n/g, "<br>");

    return html;
  };

  /*
|--------------------------------------------------------------------------
| Rich editor HTML -> safe stored markup
|--------------------------------------------------------------------------
*/

  const htmlToMarkup = (editor) => {
    const walk = (node) => {
      if (node.nodeType === Node.TEXT_NODE) {
        return node.nodeValue || "";
      }

      if (node.nodeType !== Node.ELEMENT_NODE) {
        return "";
      }

      const tag = node.tagName.toLowerCase();

      const children = Array.from(node.childNodes).map(walk).join("");

      if (tag === "strong" || tag === "b") {
        return `**${children}**`;
      }

      if (tag === "em" || tag === "i") {
        return `*${children}*`;
      }

      if (tag === "br") {
        return "\n";
      }

      /*
      | Browsers often create DIV/P on Enter.
      */

      if (tag === "div" || tag === "p") {
        return `${children}\n`;
      }

      /*
      | Ignore all other formatting/tags,
      | keeping only their text.
      */

      return children;
    };

    let result = Array.from(editor.childNodes).map(walk).join("");

    result = result
      .replace(/\u00a0/g, " ")
      .replace(/\n{3,}/g, "\n\n")
      .trim();

    return result;
  };

  /*
|--------------------------------------------------------------------------
| Clean activity value from displayed cell
|--------------------------------------------------------------------------
*/

  const getActivityText = (cell) => {
    /*
  | Prefer original raw value if PHP provides it.
  */

    if (cell.dataset.activityRaw) {
      return cell.dataset.activityRaw;
    }

    const time = cell.querySelector(".desktop-paper-activity-time")?.textContent?.trim() || "";

    const title = cell.querySelector(".desktop-paper-activity-title")?.textContent?.trim() || "";

    const details = cell.querySelector(".desktop-paper-activity-details")?.textContent?.trim() || "";

    if (time || title || details) {
      return [time, title, details].filter(Boolean).join("\n");
    }

    const clone = cell.cloneNode(true);

    clone.querySelectorAll(".activity-point-editor").forEach((element) => element.remove());

    return clone.textContent.replace(/\s+/g, " ").trim();
  };

  /*
|--------------------------------------------------------------------------
| Open editor
|--------------------------------------------------------------------------
*/

  const openActivityEditor = (cell) => {
    activityEditorCell = cell;

    const value = getActivityText(cell);

    richEditor.innerHTML = markupToHtml(value);

    const date = cell.dataset.date || "";

    const period = cell.dataset.period || "";

    activityEditorMeta.textContent = [date, period ? period.charAt(0).toUpperCase() + period.slice(1) : ""].filter(Boolean).join(" · ");

    activityEditor.hidden = false;

    document.body.classList.add("activity-editor-open");

    requestAnimationFrame(() => {
      richEditor.focus();
    });
  };

  /*
|--------------------------------------------------------------------------
| Close editor
|--------------------------------------------------------------------------
*/

  const closeActivityEditor = () => {
    activityEditor.hidden = true;

    document.body.classList.remove("activity-editor-open");

    activityEditorCell = null;

    richEditor.innerHTML = "";
  };

  /*
|--------------------------------------------------------------------------
| Rich formatting toolbar
|--------------------------------------------------------------------------
*/

  activityEditor.querySelectorAll(".activity-editor-tool[data-command]").forEach((button) => {
    button.addEventListener("mousedown", (event) => {
      /*
          | Prevent selection disappearing.
          */

      event.preventDefault();
    });

    button.addEventListener("click", () => {
      const command = button.dataset.command;

      richEditor.focus();

      document.execCommand(command, false, null);
    });
  });

  /*
|--------------------------------------------------------------------------
| Clear formatting
|--------------------------------------------------------------------------
*/

  activityEditor.querySelector(".activity-editor-clear-format").addEventListener("mousedown", (event) => {
    event.preventDefault();
  });

  activityEditor.querySelector(".activity-editor-clear-format").addEventListener("click", () => {
    richEditor.focus();

    document.execCommand("removeFormat", false, null);
  });

  /*
|--------------------------------------------------------------------------
| Prevent pasted Word/web HTML
|--------------------------------------------------------------------------
|
| Paste becomes plain text.
|
*/

  richEditor.addEventListener("paste", (event) => {
    event.preventDefault();

    const text = event.clipboardData?.getData("text/plain") || "";

    document.execCommand("insertText", false, text);
  });

  /*
|--------------------------------------------------------------------------
| Open normal activity
|--------------------------------------------------------------------------
*/

  document.querySelectorAll(".activity-editable").forEach((cell) => {
    cell.addEventListener("click", (event) => {
      if (!editingMode) {
        return;
      }

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
| Save
|--------------------------------------------------------------------------
*/

  activityEditorSave.addEventListener("click", async () => {
    if (!activityEditorCell) {
      return;
    }

    const cell = activityEditorCell;

    const activity = htmlToMarkup(richEditor);

    /*
    |--------------------------------------------------------------------------
    | Current DB fields are VARCHAR(255)
    |--------------------------------------------------------------------------
    */

    if (activity.length > 255) {
      alert("Activity is too long. Maximum is 255 characters including formatting.");

      return;
    }

    activityEditorSave.disabled = true;

    richEditor.contentEditable = "false";

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

      window.location.reload();
    } catch (error) {
      alert(error.message);

      activityEditorSave.disabled = false;

      richEditor.contentEditable = "true";

      richEditor.focus();
    }
  });

  /*
|--------------------------------------------------------------------------
| Cancel
|--------------------------------------------------------------------------
*/

  activityEditorCancel.addEventListener("click", closeActivityEditor);

  activityEditorClose.addEventListener("click", closeActivityEditor);

  /*
|--------------------------------------------------------------------------
| Outside click
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

    if (event.key === "Enter" && (event.metaKey || event.ctrlKey)) {
      event.preventDefault();

      activityEditorSave.click();
    }
  });
});
