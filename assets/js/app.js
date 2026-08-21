document.addEventListener("DOMContentLoaded", () => {
  const csrfToken = window.SECTION_SCHEDULE?.csrfToken || "";

  /*
  |--------------------------------------------------------------------------
  | Editing mode
  |--------------------------------------------------------------------------
  |
  | Every page load starts safely in View mode.
  |
  */

  let editingMode = false;

  const editModeToggle = document.getElementById("edit-mode-toggle");

  /*
  |--------------------------------------------------------------------------
  | Context menu close function placeholder
  |--------------------------------------------------------------------------
  |
  | Defined properly later after the menu is created.
  |
  */

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

        /*
          |--------------------------------------------------------------------------
          | If an admin activity input happens to be open, finish it
          |--------------------------------------------------------------------------
          */

        const openInput = document.querySelector(".activity-input");

        if (openInput) {
          openInput.blur();
        }
      }
    });
  }

  updateEditingMode();

  /*
  |--------------------------------------------------------------------------
  | Render availability cell
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

    /*
    |--------------------------------------------------------------------------
    | Clearing availability also clears uncertainty
    |--------------------------------------------------------------------------
    */

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
  | Cell options menu
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

    /*
    |--------------------------------------------------------------------------
    | Can't make a blank cell uncertain
    |--------------------------------------------------------------------------
    */

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

    /*
      |--------------------------------------------------------------------------
      | User does not have permission for this cell
      |--------------------------------------------------------------------------
      */

    if (!cell.classList.contains("editable")) {
      return;
    }

    /*
      |--------------------------------------------------------------------------
      | Left click
      |
      | blank -> × -> • -> blank
      |--------------------------------------------------------------------------
      */

    cell.addEventListener("click", async () => {
      /*
          |--------------------------------------------------------------------------
          | View mode = no editing
          |--------------------------------------------------------------------------
          */

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

    /*
      |--------------------------------------------------------------------------
      | Right click
      |
      | View mode = normal browser menu
      | Edit mode = custom availability menu
      |--------------------------------------------------------------------------
      */

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
  | Context menu actions
  |--------------------------------------------------------------------------
  */

  menu.addEventListener("click", async (event) => {
    /*
      |--------------------------------------------------------------------------
      | Extra safeguard
      |--------------------------------------------------------------------------
      */

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

    /*
      |--------------------------------------------------------------------------
      | Toggle uncertainty
      |--------------------------------------------------------------------------
      */

    if (action === "uncertain") {
      if ((cell.dataset.status || "") === "") {
        return;
      }

      const next = cell.dataset.uncertain !== "1";

      await saveUncertain(cell, next);

      closeMenu();

      return;
    }

    /*
      |--------------------------------------------------------------------------
      | Clear availability
      |--------------------------------------------------------------------------
      */

    if (action === "clear") {
      await saveAvailability(cell, "");

      closeMenu();
    }
  });

  /*
  |--------------------------------------------------------------------------
  | Close context menu
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
  | Admin activity editing
  |--------------------------------------------------------------------------
  */

  document.querySelectorAll(".activity-editable").forEach((cell) => {
    cell.addEventListener("click", () => {
      /*
          |--------------------------------------------------------------------------
          | Admin must explicitly enter Edit mode
          |--------------------------------------------------------------------------
          */

      if (!editingMode) {
        return;
      }

      if (cell.querySelector("input")) {
        return;
      }

      closeMenu();

      const currentText = cell.textContent.trim();

      const input = document.createElement("input");

      input.type = "text";
      input.value = currentText;

      input.maxLength = 255;

      input.className = "activity-input";

      cell.textContent = "";

      cell.appendChild(input);

      input.focus();
      input.select();

      let finished = false;

      /*
          |--------------------------------------------------------------------------
          | Cancel editing
          |--------------------------------------------------------------------------
          */

      const restore = () => {
        if (finished) {
          return;
        }

        finished = true;

        cell.textContent = currentText;
      };

      /*
          |--------------------------------------------------------------------------
          | Save activity
          |--------------------------------------------------------------------------
          */

      const save = async () => {
        if (finished) {
          return;
        }

        finished = true;

        const newText = input.value.trim();

        const formData = new FormData();

        formData.append("csrf_token", csrfToken);

        formData.append("date", cell.dataset.date);

        formData.append("period", cell.dataset.period);

        formData.append("activity", newText);

        try {
          const response = await fetch("ajax/update-activity.php", {
            method: "POST",
            body: formData,
          });

          const result = await response.json();

          if (!response.ok || !result.success) {
            throw new Error(result.message || "Could not save activity.");
          }

          cell.textContent = result.activity;
        } catch (error) {
          cell.textContent = currentText;

          alert(error.message);
        }
      };

      input.addEventListener("blur", save, {
        once: true,
      });

      input.addEventListener("keydown", (event) => {
        if (event.key === "Enter") {
          input.blur();
        }

        if (event.key === "Escape") {
          event.preventDefault();

          restore();
        }
      });
    });
  });
});
