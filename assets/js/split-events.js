document.addEventListener("DOMContentLoaded", () => {
  const App = window.ScheduleApp;

  if (!App) {
    console.error("ScheduleApp core is missing.");
    return;
  }

  /*
  |--------------------------------------------------------------------------
  | Unsplit activity menu
  |--------------------------------------------------------------------------
  */

  const activityMenu = document.createElement("div");
  activityMenu.className = "cell-options-menu activity-options-menu";
  activityMenu.hidden = true;

  activityMenu.innerHTML = `
    <div class="cell-options-title">Activity options</div>

    <button
      type="button"
      class="cell-options-item"
      data-action="split"
    >
      Split into separate events
    </button>
  `;

  document.body.appendChild(activityMenu);

  /*
  |--------------------------------------------------------------------------
  | Split activity menu
  |--------------------------------------------------------------------------
  */

  const splitMenu = document.createElement("div");
  splitMenu.className = "cell-options-menu activity-options-menu";
  splitMenu.hidden = true;

  splitMenu.innerHTML = `
    <div class="cell-options-title">Split event</div>

    <button
      type="button"
      class="cell-options-item"
      data-action="edit"
    >
      Edit event text
    </button>

    <button
      type="button"
      class="cell-options-item"
      data-action="add"
    >
      Add another event
    </button>

    <div class="cell-options-separator"></div>

    <button
      type="button"
      class="cell-options-item danger"
      data-action="delete"
    >
      Delete this event
    </button>

    <button
      type="button"
      class="cell-options-item"
      data-action="merge"
    >
      Merge slot back
    </button>
  `;

  document.body.appendChild(splitMenu);

  let activeActivity = null;
  let activeSplitActivity = null;

  const closeActivityMenu = () => {
    activityMenu.hidden = true;
    activeActivity = null;
  };

  const closeSplitMenu = () => {
    splitMenu.hidden = true;
    activeSplitActivity = null;
  };

  const closeAllMenus = () => {
    closeActivityMenu();
    closeSplitMenu();
  };

  const rawActivity = (cell) =>
    cell.dataset.activityRaw
    || cell.textContent.replace(/\s+/g, " ").trim();

  /*
  |--------------------------------------------------------------------------
  | Right click: unsplit slot
  |--------------------------------------------------------------------------
  */

  document.querySelectorAll(".activity-editable").forEach((cell) => {
    cell.addEventListener("contextmenu", (event) => {
      if (!App.isEditing()) {
        return;
      }

      event.preventDefault();
      event.stopPropagation();

      closeSplitMenu();
      activeActivity = cell;

      const count = Number(cell.dataset.eventCount || 1);

      const raw = rawActivity(cell);

      const lines = raw
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter(Boolean);

      const button = activityMenu.querySelector('[data-action="split"]');

      /*
      | Multiple imported point items are enough to split even when formatting
      | has changed the raw activity into fewer visible lines.
      */
      button.disabled = count < 2 && lines.length < 2;

      button.title = button.disabled
        ? "This slot contains only one event."
        : "";

      App.positionFloating(
        activityMenu,
        event.clientX,
        event.clientY
      );
    });
  });

  /*
  |--------------------------------------------------------------------------
  | Right click: already split event
  |--------------------------------------------------------------------------
  */

  document.querySelectorAll(".split-activity-cell").forEach((cell) => {
    cell.addEventListener("contextmenu", (event) => {
      if (!App.isEditing()) {
        return;
      }

      event.preventDefault();
      event.stopPropagation();

      closeActivityMenu();
      activeSplitActivity = cell;

      App.positionFloating(
        splitMenu,
        event.clientX,
        event.clientY
      );
    });
  });

  /*
  |--------------------------------------------------------------------------
  | Split slot
  |--------------------------------------------------------------------------
  */

  activityMenu.addEventListener("click", async (event) => {
    const button = event.target.closest('[data-action="split"]');

    if (
      !button
      || button.disabled
      || !activeActivity
      || !App.isEditing()
    ) {
      return;
    }

    const cell = activeActivity;

    const confirmed = confirm(
      "Split this slot into separate events? Existing availability will be copied to each event."
    );

    if (!confirmed) {
      return;
    }

    button.disabled = true;

    try {
      await App.post("ajax/split-slot.php", {
        date: cell.dataset.date,
        period: cell.dataset.period,
        activity: rawActivity(cell),
      });

      window.location.reload();
    } catch (error) {
      alert(error.message);
      button.disabled = false;
    }
  });

  /*
  |--------------------------------------------------------------------------
  | Split event management
  |--------------------------------------------------------------------------
  */

  splitMenu.addEventListener("click", async (event) => {
    const button = event.target.closest("[data-action]");

    if (
      !button
      || !activeSplitActivity
      || !App.isEditing()
    ) {
      return;
    }

    const cell = activeSplitActivity;
    const action = button.dataset.action;

    const splitEventId = cell.dataset.splitEventId;
    const date = cell.dataset.date;
    const period = cell.dataset.period;

    closeSplitMenu();

    if (action === "edit") {
      App.openActivityEditor?.(cell);
      return;
    }

    if (action === "add") {
      const activity = prompt("New event text:");

      if (activity === null || activity.trim() === "") {
        return;
      }

      try {
        await App.post("ajax/add-split-event.php", {
          split_event_id: splitEventId,
          activity: activity.trim(),
        });

        window.location.reload();
      } catch (error) {
        alert(error.message);
      }

      return;
    }

    if (action === "delete") {
      if (
        !confirm(
          "Delete this split event? Its individual availability will also be deleted."
        )
      ) {
        return;
      }

      try {
        await App.post("ajax/delete-split-event.php", {
          split_event_id: splitEventId,
        });

        window.location.reload();
      } catch (error) {
        alert(error.message);
      }

      return;
    }

    if (action === "merge") {
      if (
        !confirm(
          "Merge all events in this slot back into one activity?"
        )
      ) {
        return;
      }

      try {
        try {
          await App.post("ajax/merge-split-slot.php", {
            date,
            period,
            force: 0,
          });
        } catch (error) {
          if (
            error.status !== 409
            || !error.payload?.needs_confirmation
          ) {
            throw error;
          }

          const count = Number(error.payload.conflict_count || 0);

          const proceed = confirm(
            `${count} member(s) have different availability between these events.\n\n`
            + "If you continue, conflicting answers will become blank in the merged slot. Continue?"
          );

          if (!proceed) {
            return;
          }

          await App.post("ajax/merge-split-slot.php", {
            date,
            period,
            force: 1,
          });
        }

        window.location.reload();
      } catch (error) {
        alert(error.message);
      }
    }
  });

  document.addEventListener("click", (event) => {
    if (
      !activityMenu.hidden
      && !activityMenu.contains(event.target)
    ) {
      closeActivityMenu();
    }

    if (
      !splitMenu.hidden
      && !splitMenu.contains(event.target)
    ) {
      closeSplitMenu();
    }
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      closeAllMenus();
    }
  });

  window.addEventListener("scroll", closeAllMenus, true);
  window.addEventListener("resize", closeAllMenus);

  App.onEditingChange((editing) => {
    if (!editing) {
      closeAllMenus();
    }
  });
});
