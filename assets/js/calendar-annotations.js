(() => {
  const init = () => {
    const App = window.ScheduleApp;
    if (!App) return;

    const params = new URLSearchParams(window.location.search);
    const now = new Date();
    const year = Number(params.get("year") || now.getFullYear());
    const month = Number(params.get("month") || now.getMonth() + 1);

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
            if (date) cell.dataset.date = date;
            cell.dataset.period = period;
          });
        });
      });
    };

    const prepareCellContext = () => {
      document.querySelectorAll(".desktop-paper-week").forEach((week) => {
        const rosters = week.querySelectorAll(".desktop-paper-roster");
        const morningActivities = week.querySelector(".desktop-paper-activities-morning");
        const eveningActivities = week.querySelector(".desktop-paper-activities-evening");

        if (rosters[0] && morningActivities) {
          wireOneRoster(
            rosters[0],
            morningActivities,
            "morning",
            ".desktop-paper-roster-day",
            ".desktop-paper-event-marks",
            ".desktop-paper-activity-day",
            ".desktop-paper-activity",
          );
        }

        if (rosters.length > 1 && eveningActivities) {
          wireOneRoster(
            rosters[rosters.length - 1],
            eveningActivities,
            "evening",
            ".desktop-paper-roster-day",
            ".desktop-paper-event-marks",
            ".desktop-paper-activity-day",
            ".desktop-paper-activity",
          );
        }
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

        let multiplier = splitId ? (data.split_multipliers?.[splitId]?.[userId] ?? 1) : (data.normal_multipliers?.[date]?.[period]?.[userId] ?? 1);

        multiplier = Number(multiplier);

        if (Number.isFinite(multiplier) && multiplier !== 1) {
          addBadge(cell, `${multiplier}×`, "calendar-annotation-multiplier", `Point multiplier: ${multiplier}×`);
        }
      });
    };

    const load = async () => {
      try {
        const data = await App.post("ajax/calendar-annotations.php", { year, month });
        apply(data);
      } catch (error) {
        console.error("Could not load calendar annotations.", error);
      }
    };

    document.addEventListener("calendar-annotations-refresh", load);
    load();
  };

  // availability.js injects this file from inside its DOMContentLoaded handler.
  // Therefore this script may arrive after DOMContentLoaded has already fired.
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once: true });
  } else {
    init();
  }
})();
