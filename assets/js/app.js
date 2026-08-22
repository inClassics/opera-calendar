document.addEventListener("DOMContentLoaded", () => {
  const App = window.ScheduleApp;

  if (!App) {
    console.error("ScheduleApp core is missing.");
    return;
  }

  /*
  |--------------------------------------------------------------------------
  | Edit mode
  |--------------------------------------------------------------------------
  */

  const toggle = document.getElementById("edit-mode-toggle");

  const updateToggle = () => {
    if (!toggle) {
      return;
    }

    const editing = App.isEditing();

    toggle.textContent = editing ? "Done editing" : "Edit schedule";
    toggle.setAttribute("aria-pressed", editing ? "true" : "false");
  };

  if (toggle) {
    toggle.addEventListener("click", () => {
      App.setEditing(!App.isEditing());
      updateToggle();
    });
  }

  App.setEditing(false);
  updateToggle();

  /*
  |--------------------------------------------------------------------------
  | Rich activity editor
  |--------------------------------------------------------------------------
  */

  const overlay = document.createElement("div");
  overlay.className = "activity-editor-overlay";
  overlay.hidden = true;

  overlay.innerHTML = `
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
          Select text and use Bold or Italic. Press Enter for a new line.
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

  document.body.appendChild(overlay);

  const editor = overlay.querySelector(".activity-rich-editor");
  const meta = overlay.querySelector(".activity-editor-meta");
  const saveButton = overlay.querySelector(".activity-editor-save");
  const cancelButton = overlay.querySelector(".activity-editor-cancel");
  const closeButton = overlay.querySelector(".activity-editor-close");

  let activeCell = null;

  const escapeHtml = (value) => {
    const div = document.createElement("div");
    div.textContent = value;
    return div.innerHTML;
  };

  const markupToHtml = (value) => {
    let html = escapeHtml(value);

    html = html.replace(/\*\*(.+?)\*\*/gs, "<strong>$1</strong>");
    html = html.replace(/\*([^*\n]+?)\*/g, "<em>$1</em>");
    html = html.replace(/\r?\n/g, "<br>");

    return html;
  };

  const htmlToMarkup = (root) => {
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

      if (tag === "div" || tag === "p") {
        return `${children}\n`;
      }

      return children;
    };

    return Array.from(root.childNodes)
      .map(walk)
      .join("")
      .replace(/\u00a0/g, " ")
      .replace(/\n{3,}/g, "\n\n")
      .trim();
  };

  const getRawActivity = (cell) => {
    if (cell.dataset.activityRaw) {
      return cell.dataset.activityRaw;
    }

    const text = cell.querySelector(".desktop-paper-activity-text");

    if (text) {
      return text.textContent.replace(/\s+/g, " ").trim();
    }

    const clone = cell.cloneNode(true);

    clone
      .querySelectorAll(
        ".activity-point-editor, .desktop-paper-point-badge"
      )
      .forEach((element) => element.remove());

    return clone.textContent.replace(/\s+/g, " ").trim();
  };

  const closeEditor = () => {
    overlay.hidden = true;
    document.body.classList.remove("activity-editor-open");

    activeCell = null;
    editor.innerHTML = "";
    editor.contentEditable = "true";
    saveButton.disabled = false;
  };

  const openEditor = (cell) => {
    if (!App.isEditing()) {
      return;
    }

    activeCell = cell;

    const raw = getRawActivity(cell);

    editor.innerHTML = markupToHtml(raw);

    const period = cell.dataset.period || "";
    const date = cell.dataset.date || "";

    meta.textContent = [
      date,
      period
        ? period.charAt(0).toUpperCase() + period.slice(1)
        : "",
    ]
      .filter(Boolean)
      .join(" · ");

    overlay.hidden = false;
    document.body.classList.add("activity-editor-open");

    requestAnimationFrame(() => {
      editor.focus();
    });
  };

  /*
  | Expose the same editor to split-events.js.
  */
  App.openActivityEditor = openEditor;

  document
    .querySelectorAll(".activity-editable, .split-activity-cell")
    .forEach((cell) => {
      cell.addEventListener("click", (event) => {
        if (!App.isEditing()) {
          return;
        }

        if (
          event.target.closest(
            ".activity-point-editor, .desktop-paper-point-badge"
          )
        ) {
          return;
        }

        event.preventDefault();
        event.stopPropagation();

        openEditor(cell);
      });
    });

  overlay
    .querySelectorAll(".activity-editor-tool[data-command]")
    .forEach((button) => {
      button.addEventListener("mousedown", (event) => {
        /*
        | Preserve the text selection when toolbar buttons are pressed.
        */
        event.preventDefault();
      });

      button.addEventListener("click", () => {
        editor.focus();

        document.execCommand(
          button.dataset.command,
          false,
          null
        );
      });
    });

  const clearFormat = overlay.querySelector(
    ".activity-editor-clear-format"
  );

  clearFormat.addEventListener("mousedown", (event) => {
    event.preventDefault();
  });

  clearFormat.addEventListener("click", () => {
    editor.focus();
    document.execCommand("removeFormat", false, null);
  });

  editor.addEventListener("paste", (event) => {
    /*
    | Paste as plain text so Word/web formatting cannot enter the database.
    */
    event.preventDefault();

    const text =
      event.clipboardData?.getData("text/plain") || "";

    document.execCommand("insertText", false, text);
  });

  saveButton.addEventListener("click", async () => {
    if (!activeCell) {
      return;
    }

    const activity = htmlToMarkup(editor);

    if (activity === "") {
      const confirmed = confirm(
        "The activity is empty. Save it as an empty slot?"
      );

      if (!confirmed) {
        return;
      }
    }

    if (activity.length > 255) {
      alert(
        "Activity is too long. Maximum is 255 characters including formatting."
      );
      return;
    }

    const cell = activeCell;

    saveButton.disabled = true;
    editor.contentEditable = "false";

    try {
      const splitEventId = cell.dataset.splitEventId || "";

      if (splitEventId) {
        await App.post("ajax/update-split-event.php", {
          split_event_id: splitEventId,
          activity,
        });
      } else {
        await App.post("ajax/update-activity.php", {
          date: cell.dataset.date,
          period: cell.dataset.period,
          activity,
        });
      }

      window.location.reload();
    } catch (error) {
      alert(error.message);

      saveButton.disabled = false;
      editor.contentEditable = "true";
      editor.focus();
    }
  });

  cancelButton.addEventListener("click", closeEditor);
  closeButton.addEventListener("click", closeEditor);

  overlay.addEventListener("mousedown", (event) => {
    if (event.target === overlay) {
      closeEditor();
    }
  });

  document.addEventListener("keydown", (event) => {
    if (overlay.hidden) {
      return;
    }

    if (event.key === "Escape") {
      event.preventDefault();
      closeEditor();
      return;
    }

    if (
      event.key === "Enter"
      && (event.metaKey || event.ctrlKey)
    ) {
      event.preventDefault();
      saveButton.click();
    }
  });

  App.onEditingChange((editing) => {
    updateToggle();

    if (!editing && !overlay.hidden) {
      closeEditor();
    }
  });
});
