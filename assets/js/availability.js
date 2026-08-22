document.addEventListener("DOMContentLoaded", () => {
  const App = window.ScheduleApp;

  if (!App) {
    console.error("ScheduleApp core is missing.");
    return;
  }

  const cells = document.querySelectorAll(
    ".availability-cell, .split-availability-cell"
  );

  cells.forEach(App.renderAvailability);

  const menu = document.createElement("div");
  menu.className = "cell-options-menu";
  menu.hidden = true;
  menu.innerHTML = `
    <div class="cell-options-title">Availability options</div>

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

  const closeMenu = () => {
    menu.hidden = true;
    activeCell = null;
  };

  const isSplitCell = (cell) =>
    cell.classList.contains("split-availability-cell");

  const saveStatus = async (cell, nextStatus) => {
    if (cell.dataset.saving === "1") {
      return false;
    }

    const previousStatus = cell.dataset.status || "";
    const previousUncertain = cell.dataset.uncertain === "1";

    cell.dataset.status = nextStatus;

    if (nextStatus === "") {
      cell.dataset.uncertain = "0";
    }

    App.renderAvailability(cell);
    cell.dataset.saving = "1";

    try {
      if (isSplitCell(cell)) {
        await App.post("ajax/update-split-availability.php", {
          split_event_id: cell.dataset.splitEventId,
          user_id: cell.dataset.userId,
          status: nextStatus,
        });
      } else {
        await App.post("ajax/update-availability.php", {
          user_id: cell.dataset.userId,
          date: cell.dataset.date,
          period: cell.dataset.period,
          status: nextStatus,
        });
      }

      return true;
    } catch (error) {
      cell.dataset.status = previousStatus;
      cell.dataset.uncertain = previousUncertain ? "1" : "0";
      App.renderAvailability(cell);

      alert(error.message);
      return false;
    } finally {
      cell.dataset.saving = "0";
    }
  };

  const saveUncertain = async (cell, nextUncertain) => {
    if (cell.dataset.saving === "1") {
      return false;
    }

    const previousUncertain = cell.dataset.uncertain === "1";

    cell.dataset.uncertain = nextUncertain ? "1" : "0";
    App.renderAvailability(cell);
    cell.dataset.saving = "1";

    try {
      if (isSplitCell(cell)) {
        await App.post("ajax/toggle-split-uncertain.php", {
          split_event_id: cell.dataset.splitEventId,
          user_id: cell.dataset.userId,
          uncertain: nextUncertain ? 1 : 0,
        });
      } else {
        await App.post("ajax/toggle-uncertain.php", {
          user_id: cell.dataset.userId,
          date: cell.dataset.date,
          period: cell.dataset.period,
          uncertain: nextUncertain ? 1 : 0,
        });
      }

      return true;
    } catch (error) {
      cell.dataset.uncertain = previousUncertain ? "1" : "0";
      App.renderAvailability(cell);

      alert(error.message);
      return false;
    } finally {
      cell.dataset.saving = "0";
    }
  };

  const openMenu = (cell, x, y) => {
    activeCell = cell;

    const status = cell.dataset.status || "";
    const uncertain = cell.dataset.uncertain === "1";

    const uncertainButton = menu.querySelector('[data-action="uncertain"]');
    const clearButton = menu.querySelector('[data-action="clear"]');

    uncertainButton.disabled = status === "";
    clearButton.disabled = status === "";

    uncertainButton.classList.toggle("selected", uncertain);

    uncertainButton.querySelector(".cell-options-check").textContent =
      uncertain ? "✓" : "";

    App.positionFloating(menu, x, y);
  };

  cells.forEach((cell) => {
    if (!cell.classList.contains("editable")) {
      return;
    }

    cell.addEventListener("click", async () => {
      if (!App.isEditing() || cell.dataset.saving === "1") {
        return;
      }

      closeMenu();

      const current = cell.dataset.status || "";
      const next =
        current === ""
          ? "available"
          : current === "available"
            ? "unavailable"
            : "";

      await saveStatus(cell, next);
    });

    cell.addEventListener("contextmenu", (event) => {
      if (!App.isEditing() || cell.dataset.saving === "1") {
        return;
      }

      event.preventDefault();
      event.stopPropagation();

      openMenu(cell, event.clientX, event.clientY);
    });
  });

  /*
  |--------------------------------------------------------------------------
  | One mobile options implementation for normal AND split availability
  |--------------------------------------------------------------------------
  */

  document.querySelectorAll(".mobile-options-button").forEach((button) => {
    button.addEventListener("click", (event) => {
      event.preventDefault();
      event.stopPropagation();

      if (!App.isEditing()) {
        return;
      }

      const row = button.closest(".mobile-member-row");

      const cell = row?.querySelector(
        ".availability-cell.editable, .split-availability-cell.editable"
      );

      if (!cell) {
        return;
      }

      const rect = button.getBoundingClientRect();

      openMenu(
        cell,
        rect.left,
        Math.min(rect.bottom + 4, window.innerHeight - 8)
      );
    });
  });

  menu.addEventListener("click", async (event) => {
    const button = event.target.closest("[data-action]");

    if (
      !button
      || button.disabled
      || !activeCell
      || !App.isEditing()
    ) {
      return;
    }

    const cell = activeCell;

    if (button.dataset.action === "uncertain") {
      await saveUncertain(cell, cell.dataset.uncertain !== "1");
    } else if (button.dataset.action === "clear") {
      await saveStatus(cell, "");
    }

    closeMenu();
  });

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

  App.onEditingChange((editing) => {
    if (!editing) {
      closeMenu();
    }
  });
});
