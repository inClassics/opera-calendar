document.addEventListener("DOMContentLoaded", () => {
  const App = window.ScheduleApp;

  if (!App) {
    console.error("ScheduleApp core is missing.");
    return;
  }

  const cells = document.querySelectorAll(
    ".availability-cell, .split-availability-cell"
  );

  /*
  |--------------------------------------------------------------------------
  | Render availability
  |--------------------------------------------------------------------------
  */

  const renderAvailability = (cell) => {
    const status =
      cell.dataset.status || "";

    const uncertain =
      cell.dataset.uncertain === "1";

    const countsForPoints =
      cell.dataset.countsForPoints !== "0";

    cell.classList.remove(
      "available",
      "unavailable",
      "uncertain",
      "no-points"
    );

    cell.textContent = "";

    if (status === "available") {
      if (countsForPoints) {
        cell.textContent =
          uncertain
            ? "×?"
            : "×";
      } else {
        cell.textContent =
          uncertain
            ? "×⁰?"
            : "×⁰";

        cell.classList.add(
          "no-points"
        );
      }

      cell.classList.add(
        "available"
      );
    } else if (
      status === "unavailable"
    ) {
      cell.textContent =
        uncertain
          ? "•?"
          : "•";

      cell.classList.add(
        "unavailable"
      );
    }

    if (uncertain) {
      cell.classList.add(
        "uncertain"
      );
    }
  };

  /*
  |--------------------------------------------------------------------------
  | Initial render
  |--------------------------------------------------------------------------
  */

  cells.forEach(
    (cell) => {
      if (
        typeof cell.dataset.countsForPoints
        === "undefined"
      ) {
        cell.dataset.countsForPoints =
          "1";
      }

      renderAvailability(
        cell
      );
    }
  );

  /*
  |--------------------------------------------------------------------------
  | Context menu
  |--------------------------------------------------------------------------
  */

  const menu =
    document.createElement(
      "div"
    );

  menu.className =
    "cell-options-menu";

  menu.hidden =
    true;

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

    <button
      type="button"
      class="cell-options-item"
      data-action="no-points"
    >
      <span class="cell-options-check"></span>
      <span>Exclude from points</span>
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

  document.body.appendChild(
    menu
  );

  let activeCell =
    null;

  const closeMenu =
    () => {
      menu.hidden =
        true;

      activeCell =
        null;
    };

  const isSplitCell =
    (cell) =>
      cell.classList.contains(
        "split-availability-cell"
      );

  /*
  |--------------------------------------------------------------------------
  | Save availability status
  |--------------------------------------------------------------------------
  */

  const saveStatus =
    async (
      cell,
      nextStatus
    ) => {
      if (
        cell.dataset.saving
        === "1"
      ) {
        return false;
      }

      const previousStatus =
        cell.dataset.status || "";

      const previousUncertain =
        cell.dataset.uncertain
        === "1";

      const previousCountsForPoints =
        cell.dataset.countsForPoints
        !== "0";

      cell.dataset.status =
        nextStatus;

      if (
        nextStatus === ""
      ) {
        cell.dataset.uncertain =
          "0";
      }

      /*
      |--------------------------------------------------------------------------
      | New crosses count by default
      |--------------------------------------------------------------------------
      |
      | If a blank/dot becomes a cross, reset the special exclusion.
      |
      */

      if (
        nextStatus === "available"
        &&
        previousStatus !== "available"
      ) {
        cell.dataset.countsForPoints =
          "1";
      }

      renderAvailability(
        cell
      );

      cell.dataset.saving =
        "1";

      try {
        if (
          isSplitCell(
            cell
          )
        ) {
          await App.post(
            "ajax/update-split-availability.php",
            {
              split_event_id:
                cell.dataset.splitEventId,

              user_id:
                cell.dataset.userId,

              status:
                nextStatus,
            }
          );
        } else {
          await App.post(
            "ajax/update-availability.php",
            {
              user_id:
                cell.dataset.userId,

              date:
                cell.dataset.date,

              period:
                cell.dataset.period,

              status:
                nextStatus,
            }
          );
        }

        return true;

      } catch (error) {
        cell.dataset.status =
          previousStatus;

        cell.dataset.uncertain =
          previousUncertain
            ? "1"
            : "0";

        cell.dataset.countsForPoints =
          previousCountsForPoints
            ? "1"
            : "0";

        renderAvailability(
          cell
        );

        alert(
          error.message
        );

        return false;

      } finally {
        cell.dataset.saving =
          "0";
      }
    };

  /*
  |--------------------------------------------------------------------------
  | Save uncertainty
  |--------------------------------------------------------------------------
  */

  const saveUncertain =
    async (
      cell,
      nextUncertain
    ) => {
      if (
        cell.dataset.saving
        === "1"
      ) {
        return false;
      }

      const previousUncertain =
        cell.dataset.uncertain
        === "1";

      cell.dataset.uncertain =
        nextUncertain
          ? "1"
          : "0";

      renderAvailability(
        cell
      );

      cell.dataset.saving =
        "1";

      try {
        if (
          isSplitCell(
            cell
          )
        ) {
          await App.post(
            "ajax/toggle-split-uncertain.php",
            {
              split_event_id:
                cell.dataset.splitEventId,

              user_id:
                cell.dataset.userId,

              uncertain:
                nextUncertain
                  ? 1
                  : 0,
            }
          );
        } else {
          await App.post(
            "ajax/toggle-uncertain.php",
            {
              user_id:
                cell.dataset.userId,

              date:
                cell.dataset.date,

              period:
                cell.dataset.period,

              uncertain:
                nextUncertain
                  ? 1
                  : 0,
            }
          );
        }

        return true;

      } catch (error) {
        cell.dataset.uncertain =
          previousUncertain
            ? "1"
            : "0";

        renderAvailability(
          cell
        );

        alert(
          error.message
        );

        return false;

      } finally {
        cell.dataset.saving =
          "0";
      }
    };

  /*
  |--------------------------------------------------------------------------
  | Save point-counting override
  |--------------------------------------------------------------------------
  */

  const savePointCounting =
    async (
      cell,
      countsForPoints
    ) => {
      if (
        cell.dataset.saving
        === "1"
      ) {
        return false;
      }

      const previous =
        cell.dataset.countsForPoints
        !== "0";

      cell.dataset.countsForPoints =
        countsForPoints
          ? "1"
          : "0";

      renderAvailability(
        cell
      );

      cell.dataset.saving =
        "1";

      try {
        if (
          isSplitCell(
            cell
          )
        ) {
          await App.post(
            "ajax/update-point-counting.php",
            {
              scope:
                "split",

              split_event_id:
                cell.dataset.splitEventId,

              user_id:
                cell.dataset.userId,

              counts_for_points:
                countsForPoints
                  ? 1
                  : 0,
            }
          );
        } else {
          await App.post(
            "ajax/update-point-counting.php",
            {
              scope:
                "normal",

              user_id:
                cell.dataset.userId,

              date:
                cell.dataset.date,

              period:
                cell.dataset.period,

              counts_for_points:
                countsForPoints
                  ? 1
                  : 0,
            }
          );
        }

        return true;

      } catch (error) {
        cell.dataset.countsForPoints =
          previous
            ? "1"
            : "0";

        renderAvailability(
          cell
        );

        alert(
          error.message
        );

        return false;

      } finally {
        cell.dataset.saving =
          "0";
      }
    };

  /*
  |--------------------------------------------------------------------------
  | Open menu
  |--------------------------------------------------------------------------
  */

  const openMenu =
    (
      cell,
      x,
      y
    ) => {
      activeCell =
        cell;

      const status =
        cell.dataset.status
        || "";

      const uncertain =
        cell.dataset.uncertain
        === "1";

      const countsForPoints =
        cell.dataset.countsForPoints
        !== "0";

      const uncertainButton =
        menu.querySelector(
          '[data-action="uncertain"]'
        );

      const noPointsButton =
        menu.querySelector(
          '[data-action="no-points"]'
        );

      const clearButton =
        menu.querySelector(
          '[data-action="clear"]'
        );

      uncertainButton.disabled =
        status === "";

      uncertainButton.classList.toggle(
        "selected",
        uncertain
      );

      uncertainButton
        .querySelector(
          ".cell-options-check"
        )
        .textContent =
          uncertain
            ? "✓"
            : "";

      /*
      |--------------------------------------------------------------------------
      | Point exclusion only makes sense for a cross
      |--------------------------------------------------------------------------
      */

      noPointsButton.disabled =
        status !== "available";

      noPointsButton.classList.toggle(
        "selected",
        !countsForPoints
      );

      noPointsButton
        .querySelector(
          ".cell-options-check"
        )
        .textContent =
          !countsForPoints
            ? "✓"
            : "";

      clearButton.disabled =
        status === "";

      App.positionFloating(
        menu,
        x,
        y
      );
    };

  /*
  |--------------------------------------------------------------------------
  | Normal cell interactions
  |--------------------------------------------------------------------------
  */

  cells.forEach(
    (cell) => {
      if (
        !cell.classList.contains(
          "editable"
        )
      ) {
        return;
      }

      cell.addEventListener(
        "click",
        async () => {
          if (
            !App.isEditing()
            ||
            cell.dataset.saving
            === "1"
          ) {
            return;
          }

          closeMenu();

          const current =
            cell.dataset.status
            || "";

          const next =
            current === ""
              ? "available"
              : current === "available"
                ? "unavailable"
                : "";

          await saveStatus(
            cell,
            next
          );
        }
      );

      cell.addEventListener(
        "contextmenu",
        (event) => {
          if (
            !App.isEditing()
            ||
            cell.dataset.saving
            === "1"
          ) {
            return;
          }

          event.preventDefault();
          event.stopPropagation();

          openMenu(
            cell,
            event.clientX,
            event.clientY
          );
        }
      );
    }
  );

  /*
  |--------------------------------------------------------------------------
  | Mobile options button
  |--------------------------------------------------------------------------
  */

  document
    .querySelectorAll(
      ".mobile-options-button"
    )
    .forEach(
      (button) => {
        button.addEventListener(
          "click",
          (event) => {
            event.preventDefault();
            event.stopPropagation();

            if (
              !App.isEditing()
            ) {
              return;
            }

            const row =
              button.closest(
                ".mobile-member-row"
              );

            const cell =
              row?.querySelector(
                ".availability-cell.editable, .split-availability-cell.editable"
              );

            if (!cell) {
              return;
            }

            const rect =
              button.getBoundingClientRect();

            openMenu(
              cell,
              rect.left,
              Math.min(
                rect.bottom + 4,
                window.innerHeight - 8
              )
            );
          }
        );
      }
    );

  /*
  |--------------------------------------------------------------------------
  | Menu actions
  |--------------------------------------------------------------------------
  */

  menu.addEventListener(
    "click",
    async (event) => {
      const button =
        event.target.closest(
          "[data-action]"
        );

      if (
        !button
        ||
        button.disabled
        ||
        !activeCell
        ||
        !App.isEditing()
      ) {
        return;
      }

      const cell =
        activeCell;

      const action =
        button.dataset.action;

      if (
        action === "uncertain"
      ) {
        await saveUncertain(
          cell,
          cell.dataset.uncertain
          !== "1"
        );

        closeMenu();

        return;
      }

      if (
        action === "no-points"
      ) {
        if (
          (
            cell.dataset.status
            || ""
          )
          !== "available"
        ) {
          return;
        }

        const currentlyCounts =
          cell.dataset.countsForPoints
          !== "0";

        await savePointCounting(
          cell,
          !currentlyCounts
        );

        closeMenu();

        return;
      }

      if (
        action === "clear"
      ) {
        await saveStatus(
          cell,
          ""
        );

        closeMenu();
      }
    }
  );

  /*
  |--------------------------------------------------------------------------
  | Close menu
  |--------------------------------------------------------------------------
  */

  document.addEventListener(
    "click",
    (event) => {
      if (
        !menu.hidden
        &&
        !menu.contains(
          event.target
        )
      ) {
        closeMenu();
      }
    }
  );

  document.addEventListener(
    "keydown",
    (event) => {
      if (
        event.key
        === "Escape"
      ) {
        closeMenu();
      }
    }
  );

  window.addEventListener(
    "scroll",
    closeMenu,
    true
  );

  window.addEventListener(
    "resize",
    closeMenu
  );

  App.onEditingChange(
    (editing) => {
      if (!editing) {
        closeMenu();
      }
    }
  );
});
