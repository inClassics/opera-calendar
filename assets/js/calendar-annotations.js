document.addEventListener("DOMContentLoaded", () => {
  const App = window.ScheduleApp;

  if (!App) return;

  const params = new URLSearchParams(window.location.search);

  const now = new Date();

  const year = Number(params.get("year") || now.getFullYear());

  const month = Number(params.get("month") || now.getMonth() + 1);

  /*
   * Split availability buttons historically did not carry date/period.
   * Derive those values from the activity column that represents the
   * same visual event. This makes day status work for normal AND split
   * calls without changing the schedule views.
   */
  const connectCellContext = (rosterSelector, activitiesSelector, period) => {
    document.querySelectorAll(rosterSelector).forEach((roster, rosterIndex) => {
      const scope = roster.closest(".desktop-paper-week, .mobile-overview-week");

      if (!scope) return;

      const activities = scope.querySelector(activitiesSelector);

      if (!activities) return;

      const rosterDays = roster.querySelectorAll(
        roster.classList.contains("desktop-paper-roster") ? ".desktop-paper-roster-day" : ".mobile-overview-roster-day",
      );

      const activityDays = activities.querySelectorAll(
        activities.classList.contains("desktop-paper-activities") ? ".desktop-paper-activity-day" : ".mobile-overview-activity-day",
      );

      rosterDays.forEach((rosterDay, dayIndex) => {
        const activityDay = activityDays[dayIndex];

        if (!activityDay) return;

        const activityCells = activityDay.querySelectorAll(".activity-cell[data-date]");

        const fallbackDate = activityCells[0]?.dataset.date || "";

        const eventSelector = roster.classList.contains("desktop-paper-roster") ? ".desktop-paper-event-marks" : ".mobile-overview-event-marks";

        const rosterEvents = rosterDay.querySelectorAll(eventSelector);

        rosterEvents.forEach((rosterEvent, eventIndex) => {
          const activity = activityCells[eventIndex] || activityCells[0] || null;

          const date = activity?.dataset.date || fallbackDate;

          rosterEvent.querySelectorAll(".member-cell[data-user-id]").forEach((cell) => {
            if (!cell.dataset.date && date) {
              cell.dataset.date = date;
            }

            if (!cell.dataset.period) {
              cell.dataset.period = period;
            }
          });
        });
      });
    });
  };

  const prepareCellContext = () => {
    document.querySelectorAll(".desktop-paper-week").forEach((week) => {
      const rosters = week.querySelectorAll(".desktop-paper-roster");

      if (rosters[0]) {
        rosters[0].dataset.annotationRole = "morning";
      }

      if (rosters.length > 1) {
        rosters[rosters.length - 1].dataset.annotationRole = "evening";
      }
    });

    document.querySelectorAll('.desktop-paper-roster[data-annotation-role="morning"]').forEach((roster) => {
      const scope = roster.closest(".desktop-paper-week");

      const activities = scope?.querySelector(".desktop-paper-activities-morning");

      if (!activities) return;

      wireOneRoster(
        roster,
        activities,
        "morning",
        ".desktop-paper-roster-day",
        ".desktop-paper-event-marks",
        ".desktop-paper-activity-day",
        ".desktop-paper-activity",
      );
    });

    document.querySelectorAll('.desktop-paper-roster[data-annotation-role="evening"]').forEach((roster) => {
      const scope = roster.closest(".desktop-paper-week");

      const activities = scope?.querySelector(".desktop-paper-activities-evening");

      if (!activities) return;

      wireOneRoster(
        roster,
        activities,
        "evening",
        ".desktop-paper-roster-day",
        ".desktop-paper-event-marks",
        ".desktop-paper-activity-day",
        ".desktop-paper-activity",
      );
    });

    document.querySelectorAll(".mobile-overview-week").forEach((week) => {
      const rosters = week.querySelectorAll(".mobile-overview-roster");

      const morningActivities = week.querySelector(".mobile-overview-activities-morning");

      const eveningActivities = week.querySelector(".mobile-overview-activities-evening");

      if (rosters[0] && morningActivities) {
        wireOneRoster(
          rosters[0],
          morningActivities,
          "morning",
          ".mobile-overview-roster-day",
          ".mobile-overview-event-marks",
          ".mobile-overview-activity-day",
          ".mobile-overview-activity",
        );
      }

      if (rosters.length > 1 && eveningActivities) {
        wireOneRoster(
          rosters[rosters.length - 1],
          eveningActivities,
          "evening",
          ".mobile-overview-roster-day",
          ".mobile-overview-event-marks",
          ".mobile-overview-activity-day",
          ".mobile-overview-activity",
        );
      }
    });
  };

  const wireOneRoster = (roster, activities, period, rosterDaySelector, eventSelector, activityDaySelector, activitySelector) => {
    const rosterDays = roster.querySelectorAll(rosterDaySelector);

    const activityDays = activities.querySelectorAll(activityDaySelector);

    rosterDays.forEach((rosterDay, dayIndex) => {
      const activityDay = activityDays[dayIndex];

      if (!activityDay) return;

      const activityCells = activityDay.querySelectorAll(activitySelector);

      const rosterEvents = rosterDay.querySelectorAll(eventSelector);

      rosterEvents.forEach((rosterEvent, eventIndex) => {
        const activity = activityCells[eventIndex] || activityCells[0] || null;

        const date = activity?.dataset.date || "";

        rosterEvent.querySelectorAll(".member-cell[data-user-id]").forEach((cell) => {
          if (date) {
            cell.dataset.date = date;
          }

          cell.dataset.period = period;
        });
      });
    });
  };

  const clearAnnotations = () => {
    document.querySelectorAll(".calendar-annotation-badges").forEach((item) => item.remove());

    document.querySelectorAll(".has-day-status-sick, .has-day-status-unpaid-leave").forEach((cell) => {
      cell.classList.remove("has-day-status-sick", "has-day-status-unpaid-leave");
    });
  };

  const addBadge = (cell, text, className, title) => {
    let wrap = cell.querySelector(".calendar-annotation-badges");

    if (!wrap) {
      wrap = document.createElement("span");

      wrap.className = "calendar-annotation-badges";

      cell.appendChild(wrap);
    }

    const badge = document.createElement("span");

    badge.className = `calendar-annotation-badge ${className}`;

    badge.textContent = text;
    badge.title = title;

    wrap.appendChild(badge);
  };

  const apply = (data) => {
    clearAnnotations();
    prepareCellContext();

    document.querySelectorAll(".member-cell[data-user-id][data-date][data-period]").forEach((cell) => {
      const userId = String(cell.dataset.userId || "");

      const date = cell.dataset.date || "";

      const period = cell.dataset.period || "";

      const splitId = cell.dataset.splitEventId || "";

      const status = data.day_statuses?.[date]?.[userId] || "";

      if (status === "sick") {
        cell.classList.add("has-day-status-sick");

        addBadge(cell, "SICK", "calendar-annotation-sick", "Sick leave");
      } else if (status === "unpaid_leave") {
        cell.classList.add("has-day-status-unpaid-leave");

        addBadge(cell, "UL", "calendar-annotation-unpaid", "Unpaid leave");
      }

      let multiplier = 1;

      if (splitId) {
        multiplier = data.split_multipliers?.[splitId]?.[userId] ?? 1;
      } else {
        multiplier = data.normal_multipliers?.[date]?.[period]?.[userId] ?? 1;
      }

      multiplier = Number(multiplier);

      if (Number.isFinite(multiplier) && multiplier !== 1) {
        addBadge(cell, `${multiplier}×`, "calendar-annotation-multiplier", `Point multiplier: ${multiplier}×`);
      }
    });
  };

  const load = async () => {
    try {
      const data = await App.post("ajax/calendar-annotations.php", {
        year,
        month,
      });

      apply(data);
    } catch (error) {
      console.error("Could not load calendar annotations.", error);
    }
  };

  document.addEventListener("calendar-annotations-refresh", load);

  load();
});
