document.addEventListener("DOMContentLoaded", () => {
  const csrfToken = window.SECTION_SCHEDULE?.csrfToken || "";
  const isEditing = () => document.body.classList.contains("editing-mode");

  const renderSplitCell = (cell) => {
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

    if (uncertain) cell.classList.add("uncertain");
  };

  const positionMenu = (menu, x, y) => {
    menu.hidden = false;
    menu.style.left = "0px";
    menu.style.top = "0px";
    const rect = menu.getBoundingClientRect();
    const p = 8;
    menu.style.left = `${Math.max(p, Math.min(x, window.innerWidth - rect.width - p))}px`;
    menu.style.top = `${Math.max(p, Math.min(y, window.innerHeight - rect.height - p))}px`;
  };

  const saveSplitAvailability = async (cell, nextStatus) => {
    const oldStatus = cell.dataset.status || "";
    const oldUncertain = cell.dataset.uncertain === "1";

    cell.dataset.status = nextStatus;
    if (nextStatus === "") cell.dataset.uncertain = "0";
    renderSplitCell(cell);
    cell.dataset.saving = "1";

    const body = new FormData();
    body.append("csrf_token", csrfToken);
    body.append("split_event_id", cell.dataset.splitEventId);
    body.append("user_id", cell.dataset.userId);
    body.append("status", nextStatus);

    try {
      const response = await fetch("ajax/update-split-availability.php", {
        method: "POST",
        body,
      });
      const result = await response.json();
      if (!response.ok || !result.success) {
        throw new Error(result.message || "Could not save availability.");
      }
    } catch (error) {
      cell.dataset.status = oldStatus;
      cell.dataset.uncertain = oldUncertain ? "1" : "0";
      renderSplitCell(cell);
      alert(error.message);
    } finally {
      cell.dataset.saving = "0";
    }
  };

  const saveSplitUncertain = async (cell, nextUncertain) => {
    const old = cell.dataset.uncertain === "1";
    cell.dataset.uncertain = nextUncertain ? "1" : "0";
    renderSplitCell(cell);
    cell.dataset.saving = "1";

    const body = new FormData();
    body.append("csrf_token", csrfToken);
    body.append("split_event_id", cell.dataset.splitEventId);
    body.append("user_id", cell.dataset.userId);
    body.append("uncertain", nextUncertain ? "1" : "0");

    try {
      const response = await fetch("ajax/toggle-split-uncertain.php", {
        method: "POST",
        body,
      });
      const result = await response.json();
      if (!response.ok || !result.success) {
        throw new Error(result.message || "Could not save uncertainty.");
      }
    } catch (error) {
      cell.dataset.uncertain = old ? "1" : "0";
      renderSplitCell(cell);
      alert(error.message);
    } finally {
      cell.dataset.saving = "0";
    }
  };

  const splitMenu = document.createElement("div");
  splitMenu.className = "cell-options-menu split-options-menu";
  splitMenu.hidden = true;
  splitMenu.innerHTML = `
    <div class="cell-options-title">Availability options</div>
    <button type="button" class="cell-options-item" data-split-action="uncertain">
      <span class="cell-options-check"></span><span>Uncertain</span>
    </button>
    <div class="cell-options-separator"></div>
    <button type="button" class="cell-options-item danger" data-split-action="clear">Clear availability</button>
  `;
  document.body.appendChild(splitMenu);

  let activeSplitCell = null;

  const closeSplitMenu = () => {
    splitMenu.hidden = true;
    activeSplitCell = null;
  };

  const openSplitMenu = (cell, x, y) => {
    activeSplitCell = cell;
    const status = cell.dataset.status || "";
    const uncertain = cell.dataset.uncertain === "1";
    const uncertainButton = splitMenu.querySelector('[data-split-action="uncertain"]');
    const clearButton = splitMenu.querySelector('[data-split-action="clear"]');

    uncertainButton.disabled = status === "";
    clearButton.disabled = status === "";
    uncertainButton.querySelector(".cell-options-check").textContent = uncertain ? "✓" : "";

    positionMenu(splitMenu, x, y);
  };

  document.querySelectorAll(".split-availability-cell").forEach((cell) => {
    renderSplitCell(cell);
    if (!cell.classList.contains("editable")) return;

    cell.addEventListener("click", async () => {
      if (!isEditing() || cell.dataset.saving === "1") return;
      closeSplitMenu();
      const current = cell.dataset.status || "";
      const next = current === "" ? "available" : current === "available" ? "unavailable" : "";
      await saveSplitAvailability(cell, next);
    });

    cell.addEventListener("contextmenu", (event) => {
      if (!isEditing()) return;
      event.preventDefault();
      if (cell.dataset.saving === "1") return;
      openSplitMenu(cell, event.clientX, event.clientY);
    });
  });

  document.querySelectorAll(".split-mobile-options-button").forEach((button) => {
    button.addEventListener("click", (event) => {
      event.preventDefault();
      event.stopPropagation();
      if (!isEditing()) return;

      const row = button.closest(".mobile-member-row");
      const cell = row?.querySelector(".split-availability-cell.editable");
      if (!cell) return;

      const rect = button.getBoundingClientRect();
      openSplitMenu(cell, rect.left, rect.bottom + 4);
    });
  });

  splitMenu.addEventListener("click", async (event) => {
    const button = event.target.closest("[data-split-action]");
    if (!button || button.disabled || !activeSplitCell || !isEditing()) return;

    const cell = activeSplitCell;

    if (button.dataset.splitAction === "uncertain") {
      await saveSplitUncertain(cell, cell.dataset.uncertain !== "1");
    } else if (button.dataset.splitAction === "clear") {
      await saveSplitAvailability(cell, "");
    }

    closeSplitMenu();
  });

  const activityMenu = document.createElement("div");
  activityMenu.className = "cell-options-menu activity-options-menu";
  activityMenu.hidden = true;
  activityMenu.innerHTML = `
    <div class="cell-options-title">Activity options</div>
    <button type="button" class="cell-options-item" data-activity-action="split">
      Split into separate events
    </button>
  `;
  document.body.appendChild(activityMenu);

  const splitActivityMenu = document.createElement("div");
  splitActivityMenu.className = "cell-options-menu activity-options-menu";
  splitActivityMenu.hidden = true;
  splitActivityMenu.innerHTML = `
    <div class="cell-options-title">Split event</div>

    <button type="button" class="cell-options-item" data-split-activity-action="edit">
      Edit event text
    </button>

    <button type="button" class="cell-options-item" data-split-activity-action="add">
      Add another event
    </button>

    <div class="cell-options-separator"></div>

    <button type="button" class="cell-options-item danger" data-split-activity-action="delete">
      Delete this event
    </button>

    <button type="button" class="cell-options-item" data-split-activity-action="merge">
      Merge slot back
    </button>
  `;
  document.body.appendChild(splitActivityMenu);

  let activeActivity = null;
  let activeSplitActivity = null;

  const closeActivityMenu = () => {
    activityMenu.hidden = true;
    activeActivity = null;
  };

  const closeSplitActivityMenu = () => {
    splitActivityMenu.hidden = true;
    activeSplitActivity = null;
  };

  document.querySelectorAll(".activity-editable").forEach((cell) => {
    cell.addEventListener("contextmenu", (event) => {
      if (!isEditing()) return;

      event.preventDefault();
      event.stopPropagation();

      activeActivity = cell;

      const lines = cell.textContent
        .split(/\r?\n/)
        .map((value) => value.trim())
        .filter(Boolean);

      const splitButton =
        activityMenu.querySelector('[data-activity-action="split"]');

      splitButton.disabled =
        lines.length < 2;

      splitButton.title =
        lines.length < 2
          ? "This slot needs at least two activity lines."
          : "";

      positionMenu(
        activityMenu,
        event.clientX,
        event.clientY
      );
    });
  });

  /*
  |--------------------------------------------------------------------------
  | Existing split event right-click menu
  |--------------------------------------------------------------------------
  */

  document
    .querySelectorAll(".split-activity-cell")
    .forEach((cell) => {
      cell.addEventListener(
        "contextmenu",
        (event) => {
          if (!isEditing()) return;

          event.preventDefault();
          event.stopPropagation();

          activeSplitActivity = cell;

          positionMenu(
            splitActivityMenu,
            event.clientX,
            event.clientY
          );
        }
      );
    });

  activityMenu.addEventListener(
    "click",
    async (event) => {
      const button =
        event.target.closest(
          '[data-activity-action="split"]'
        );

      if (
        !button
        ||
        button.disabled
        ||
        !activeActivity
        ||
        !isEditing()
      ) {
        return;
      }

      const activity =
        activeActivity.textContent.trim();

      const date =
        activeActivity.dataset.date;

      const period =
        activeActivity.dataset.period;

      if (
        !confirm(
          "Split this slot into separate events? Existing availability will be copied to each event."
        )
      ) {
        return;
      }

      button.disabled = true;

      const body =
        new FormData();

      body.append(
        "csrf_token",
        csrfToken
      );

      body.append(
        "date",
        date
      );

      body.append(
        "period",
        period
      );

      body.append(
        "activity",
        activity
      );

      try {
        const response =
          await fetch(
            "ajax/split-slot.php",
            {
              method: "POST",
              body,
            }
          );

        const result =
          await response.json();

        if (
          !response.ok
          ||
          !result.success
        ) {
          throw new Error(
            result.message
            ||
            "Could not split activity."
          );
        }

        window.location.reload();

      } catch (error) {

        alert(error.message);

        button.disabled = false;
      }
    }
  );

  const postSplitManagement = async (
    endpoint,
    payload
  ) => {
    const body =
      new FormData();

    body.append(
      "csrf_token",
      csrfToken
    );

    Object.entries(payload).forEach(
      ([key, value]) => {
        body.append(
          key,
          String(value)
        );
      }
    );

    const response =
      await fetch(
        endpoint,
        {
          method: "POST",
          body,
        }
      );

    let result;

    try {
      result =
        await response.json();
    } catch {
      throw new Error(
        "The server returned an invalid response."
      );
    }

    return {
      response,
      result,
    };
  };

  splitActivityMenu.addEventListener(
    "click",
    async (event) => {
      const button =
        event.target.closest(
          "[data-split-activity-action]"
        );

      if (
        !button
        ||
        !activeSplitActivity
        ||
        !isEditing()
      ) {
        return;
      }

      const cell =
        activeSplitActivity;

      const action =
        button.dataset.splitActivityAction;

      const eventId =
        cell.dataset.splitEventId;

      const date =
        cell.dataset.date;

      const period =
        cell.dataset.period;

      closeSplitActivityMenu();

      /*
      |--------------------------------------------------------------------------
      | Edit text
      |--------------------------------------------------------------------------
      */

      if (action === "edit") {

        const current =
          cell.textContent.trim();

        const next =
          prompt(
            "Edit event text:",
            current
          );

        if (
          next === null
          ||
          next.trim() === ""
          ||
          next.trim() === current
        ) {
          return;
        }

        try {

          const {
            response,
            result,
          } =
            await postSplitManagement(
              "ajax/update-split-event.php",
              {
                split_event_id:
                  eventId,
                activity:
                  next.trim(),
              }
            );

          if (
            !response.ok
            ||
            !result.success
          ) {
            throw new Error(
              result.message
              ||
              "Could not update event."
            );
          }

          window.location.reload();

        } catch (error) {

          alert(error.message);
        }

        return;
      }

      /*
      |--------------------------------------------------------------------------
      | Add event
      |--------------------------------------------------------------------------
      */

      if (action === "add") {

        const activity =
          prompt(
            "New event text:"
          );

        if (
          activity === null
          ||
          activity.trim() === ""
        ) {
          return;
        }

        try {

          const {
            response,
            result,
          } =
            await postSplitManagement(
              "ajax/add-split-event.php",
              {
                split_event_id:
                  eventId,
                activity:
                  activity.trim(),
              }
            );

          if (
            !response.ok
            ||
            !result.success
          ) {
            throw new Error(
              result.message
              ||
              "Could not add event."
            );
          }

          window.location.reload();

        } catch (error) {

          alert(error.message);
        }

        return;
      }

      /*
      |--------------------------------------------------------------------------
      | Delete event
      |--------------------------------------------------------------------------
      */

      if (action === "delete") {

        if (
          !confirm(
            "Delete this split event? Its individual availability will also be deleted."
          )
        ) {
          return;
        }

        try {

          const {
            response,
            result,
          } =
            await postSplitManagement(
              "ajax/delete-split-event.php",
              {
                split_event_id:
                  eventId,
              }
            );

          if (
            !response.ok
            ||
            !result.success
          ) {
            throw new Error(
              result.message
              ||
              "Could not delete event."
            );
          }

          window.location.reload();

        } catch (error) {

          alert(error.message);
        }

        return;
      }

      /*
      |--------------------------------------------------------------------------
      | Merge back
      |--------------------------------------------------------------------------
      */

      if (action === "merge") {

        if (
          !confirm(
            "Merge all events in this slot back into one activity?"
          )
        ) {
          return;
        }

        try {

          let {
            response,
            result,
          } =
            await postSplitManagement(
              "ajax/merge-split-slot.php",
              {
                date,
                period,
                force: 0,
              }
            );

          /*
          |--------------------------------------------------------------------------
          | Conflicting member answers
          |--------------------------------------------------------------------------
          */

          if (
            response.status === 409
            &&
            result.needs_confirmation
          ) {

            const proceed =
              confirm(
                `${result.conflict_count} member(s) have different availability between these events.\n\nIf you continue, those conflicting answers will become blank in the merged slot. Continue?`
              );

            if (!proceed) {
              return;
            }

            ({
              response,
              result,
            } =
              await postSplitManagement(
                "ajax/merge-split-slot.php",
                {
                  date,
                  period,
                  force: 1,
                }
              ));
          }

          if (
            !response.ok
            ||
            !result.success
          ) {
            throw new Error(
              result.message
              ||
              "Could not merge slot."
            );
          }

          window.location.reload();

        } catch (error) {

          alert(error.message);
        }
      }
    }
  );

  document.addEventListener(
    "click",
    (event) => {
      if (
        !splitMenu.hidden
        &&
        !splitMenu.contains(
          event.target
        )
      ) {
        closeSplitMenu();
      }

      if (
        !activityMenu.hidden
        &&
        !activityMenu.contains(
          event.target
        )
      ) {
        closeActivityMenu();
      }

      if (
        !splitActivityMenu.hidden
        &&
        !splitActivityMenu.contains(
          event.target
        )
      ) {
        closeSplitActivityMenu();
      }
    }
  );

  document.addEventListener(
    "keydown",
    (event) => {
      if (event.key === "Escape") {
        closeSplitMenu();
        closeActivityMenu();
        closeSplitActivityMenu();
      }
    }
  );

  window.addEventListener(
    "scroll",
    () => {
      closeSplitMenu();
      closeActivityMenu();
      closeSplitActivityMenu();
    },
    true
  );

  window.addEventListener(
    "resize",
    () => {
      closeSplitMenu();
      closeActivityMenu();
      closeSplitActivityMenu();
    }
  );
});
