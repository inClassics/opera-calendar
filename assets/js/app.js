document.addEventListener("DOMContentLoaded", () => {
  const App = window.ScheduleApp;

  if (!App) {
    console.error("ScheduleApp core is missing.");
    return;
  }

  const toggle = document.getElementById("edit-mode-toggle");

  const updateToggle = () => {
    if (!toggle) return;
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

  const style = document.createElement("style");
  style.textContent = `
    .activity-piece-fields {
      display:grid;
      grid-template-columns:minmax(0,2fr) minmax(160px,1fr);
      gap:12px;
      margin-top:18px;
      padding-top:18px;
      border-top:1px solid var(--border);
    }
    .activity-piece-default {
      grid-column:1/-1;
      font-size:12px;
    }
    @media(max-width:600px) {
      .activity-piece-fields { grid-template-columns:1fr; }
      .activity-piece-default { grid-column:auto; }
    }
  `;
  document.head.appendChild(style);

  const overlay = document.createElement("div");
  overlay.className = "activity-editor-overlay";
  overlay.hidden = true;

  overlay.innerHTML = `
    <div class="activity-editor-dialog" role="dialog" aria-modal="true" aria-labelledby="activity-editor-title">
      <div class="activity-editor-header">
        <div>
          <div class="activity-editor-title" id="activity-editor-title">Edit activity</div>
          <div class="activity-editor-meta"></div>
        </div>
        <button type="button" class="activity-editor-close" aria-label="Close">×</button>
      </div>

      <div class="activity-editor-body">
        <div class="activity-editor-toolbar">
          <button type="button" class="activity-editor-tool" data-command="bold" title="Bold"><strong>B</strong></button>
          <button type="button" class="activity-editor-tool" data-command="italic" title="Italic"><em>I</em></button>
          <div class="activity-editor-toolbar-separator"></div>
          <button type="button" class="activity-editor-tool activity-editor-clear-format" title="Clear formatting">Clear formatting</button>
        </div>

        <div class="activity-rich-editor" contenteditable="true" spellcheck="true" role="textbox" aria-multiline="true"></div>

        <div class="activity-editor-help">
          Select text and use Bold or Italic. Press Enter for a new line.
        </div>

        <div class="activity-piece-fields">
          <label>
            Piece
            <select class="activity-piece-select">
              <option value="">No piece assigned</option>
            </select>
          </label>

          <label>
            Required basses for this activity
            <input class="activity-required-basses" type="number" min="1" max="7" inputmode="numeric" placeholder="Use piece default">
          </label>

          <div class="activity-piece-default muted"></div>
        </div>
      </div>

      <div class="activity-editor-footer">
        <button type="button" class="button activity-editor-cancel">Cancel</button>
        <button type="button" class="button activity-editor-save">Save</button>
      </div>
    </div>
  `;

  document.body.appendChild(overlay);

  const editor = overlay.querySelector(".activity-rich-editor");
  const meta = overlay.querySelector(".activity-editor-meta");
  const saveButton = overlay.querySelector(".activity-editor-save");
  const cancelButton = overlay.querySelector(".activity-editor-cancel");
  const closeButton = overlay.querySelector(".activity-editor-close");
  const pieceSelect = overlay.querySelector(".activity-piece-select");
  const requiredBassesInput = overlay.querySelector(".activity-required-basses");
  const pieceDefault = overlay.querySelector(".activity-piece-default");

  let activeCell = null;
  let activeSourceType = "";
  let activeSourceId = "";

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
      if (node.nodeType === Node.TEXT_NODE) return node.nodeValue || "";
      if (node.nodeType !== Node.ELEMENT_NODE) return "";

      const tag = node.tagName.toLowerCase();
      const children = Array.from(node.childNodes).map(walk).join("");

      if (tag === "strong" || tag === "b") return `**${children}**`;
      if (tag === "em" || tag === "i") return `*${children}*`;
      if (tag === "br") return "\n";
      if (tag === "div" || tag === "p") return `${children}\n`;
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
    if (cell.dataset.activityRaw) return cell.dataset.activityRaw;

    const text = cell.querySelector(".desktop-paper-activity-text");
    if (text) return text.textContent.replace(/\s+/g, " ").trim();

    const clone = cell.cloneNode(true);
    clone.querySelectorAll(".activity-point-editor, .desktop-paper-point-badge").forEach((element) => element.remove());
    return clone.textContent.replace(/\s+/g, " ").trim();
  };

  const findSource = (cell, clickedElement = null) => {
    const splitEventId = cell.dataset.splitEventId || "";
    if (splitEventId) return { type: "split", id: splitEventId };

    const item = clickedElement?.closest(".desktop-paper-activity-item, .mobile-overview-activity-item");
    const itemPointEditor = item?.querySelector(".activity-point-editor");
    if (itemPointEditor?.dataset.pointSource && itemPointEditor?.dataset.pointId) {
      return {
        type: itemPointEditor.dataset.pointSource,
        id: itemPointEditor.dataset.pointId,
      };
    }

    const editors = cell.querySelectorAll(".activity-point-editor");
    if (editors.length === 1) {
      return {
        type: editors[0].dataset.pointSource || "",
        id: editors[0].dataset.pointId || "",
      };
    }

    return { type: "", id: "" };
  };

  const populatePieceOptions = (pieces, selectedId) => {
    pieceSelect.innerHTML = '<option value="">No piece assigned</option>';

    pieces.forEach((piece) => {
      const option = document.createElement("option");
      option.value = String(piece.id);
      option.textContent = `${piece.title} (${piece.type})`;
      option.dataset.defaultBasses = String(piece.default_basses);
      pieceSelect.appendChild(option);
    });

    pieceSelect.value = selectedId ? String(selectedId) : "";
  };

  const updatePieceDefault = () => {
    const selected = pieceSelect.selectedOptions[0];
    const defaultBasses = selected?.dataset.defaultBasses || "";

    pieceDefault.textContent = defaultBasses
      ? `Piece default: ${defaultBasses} bass${defaultBasses === "1" ? "" : "es"}. Leave Required basses blank to use the default.`
      : "No piece assigned.";
  };

  pieceSelect.addEventListener("change", updatePieceDefault);

  const loadPieceData = async () => {
    const params = new URLSearchParams({
      source_type: activeSourceType,
      source_id: activeSourceId,
    });

    const response = await fetch(`ajax/activity-piece-data.php?${params.toString()}`, {
      credentials: "same-origin",
    });

    const result = await response.json();

    if (!response.ok || !result.success) {
      throw new Error(result.message || "Could not load piece information.");
    }

    populatePieceOptions(result.pieces || [], result.piece_id);
    requiredBassesInput.value = result.required_basses_override == null ? "" : String(result.required_basses_override);

    updatePieceDefault();
  };

  const closeEditor = () => {
    overlay.hidden = true;
    document.body.classList.remove("activity-editor-open");
    activeCell = null;
    activeSourceType = "";
    activeSourceId = "";
    editor.innerHTML = "";
    editor.contentEditable = "true";
    saveButton.disabled = false;
  };

  const openEditor = async (cell, clickedElement = null) => {
    if (!App.isEditing()) return;

    activeCell = cell;
    const source = findSource(cell, clickedElement);
    activeSourceType = source.type;
    activeSourceId = source.id;

    const raw = getRawActivity(cell);
    editor.innerHTML = markupToHtml(raw);

    const period = cell.dataset.period || "";
    const date = cell.dataset.date || "";
    meta.textContent = [date, period ? period.charAt(0).toUpperCase() + period.slice(1) : ""].filter(Boolean).join(" · ");

    pieceSelect.innerHTML = '<option value="">Loading…</option>';
    requiredBassesInput.value = "";
    pieceDefault.textContent = "";

    overlay.hidden = false;
    document.body.classList.add("activity-editor-open");

    if (activeSourceType && activeSourceId) {
      try {
        await loadPieceData();
      } catch (error) {
        pieceSelect.innerHTML = '<option value="">Unavailable</option>';
        pieceDefault.textContent = error.message;
      }
    } else {
      pieceSelect.innerHTML = '<option value="">No piece assigned</option>';
      pieceDefault.textContent = "This slot contains several Lydian activities. Split the slot first, then assign a Piece to each activity.";
    }

    requestAnimationFrame(() => editor.focus());
  };

  App.openActivityEditor = openEditor;

  document.querySelectorAll(".activity-editable, .split-activity-cell").forEach((cell) => {
    cell.addEventListener("click", (event) => {
      if (!App.isEditing()) return;

      if (event.target.closest(".activity-point-editor, .desktop-paper-point-badge")) return;

      event.preventDefault();
      event.stopPropagation();
      openEditor(cell, event.target);
    });
  });

  overlay.querySelectorAll(".activity-editor-tool[data-command]").forEach((button) => {
    button.addEventListener("mousedown", (event) => event.preventDefault());
    button.addEventListener("click", () => {
      editor.focus();
      document.execCommand(button.dataset.command, false, null);
    });
  });

  const clearFormat = overlay.querySelector(".activity-editor-clear-format");
  clearFormat.addEventListener("mousedown", (event) => event.preventDefault());
  clearFormat.addEventListener("click", () => {
    editor.focus();
    document.execCommand("removeFormat", false, null);
  });

  editor.addEventListener("paste", (event) => {
    event.preventDefault();
    const text = event.clipboardData?.getData("text/plain") || "";
    document.execCommand("insertText", false, text);
  });

  saveButton.addEventListener("click", async () => {
    if (!activeCell) return;

    const activity = htmlToMarkup(editor);

    if (activity === "") {
      const confirmed = confirm("The activity is empty. Save it as an empty slot?");
      if (!confirmed) return;
    }

    if (activity.length > 255) {
      alert("Activity is too long. Maximum is 255 characters including formatting.");
      return;
    }

    const cell = activeCell;
    const pieceId = pieceSelect.value || "";
    const requiredBassesOverride = requiredBassesInput.value.trim();

    if (pieceId === "" && requiredBassesOverride !== "") {
      alert("Assign a piece before setting a required-basses override.");
      return;
    }

    if (requiredBassesOverride !== "") {
      const required = Number(requiredBassesOverride);
      if (!Number.isInteger(required) || required < 1 || required > 7) {
        alert("Required basses must be a whole number between 1 and 7.");
        return;
      }
    }

    saveButton.disabled = true;
    editor.contentEditable = "false";

    try {
      const splitEventId = cell.dataset.splitEventId || "";

      /*
       * Keep the existing activity-text behaviour. Imported Lydian activities
       * are not converted into manual text; only their Piece metadata is saved.
       */
      if (activeSourceType !== "calendar") {
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
      }

      if (activeSourceType && activeSourceId) {
        await App.post("ajax/update-activity-piece.php", {
          source_type: activeSourceType,
          source_id: activeSourceId,
          piece_id: pieceId,
          required_basses_override: requiredBassesOverride,
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
    if (event.target === overlay) closeEditor();
  });

  document.addEventListener("keydown", (event) => {
    if (overlay.hidden) return;

    if (event.key === "Escape") {
      event.preventDefault();
      closeEditor();
      return;
    }

    if (event.key === "Enter" && (event.metaKey || event.ctrlKey)) {
      event.preventDefault();
      saveButton.click();
    }
  });

  App.onEditingChange((editing) => {
    updateToggle();
    if (!editing && !overlay.hidden) closeEditor();
  });
});
