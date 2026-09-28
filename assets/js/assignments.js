(() => {
  const config = window.SECTION_SCHEDULE || {};
  const assignments = config.assignments || {};

  const assigned = (sourceType, sourceId, userId) => {
    if (!sourceType || !sourceId || !userId) return false;

    const byType = assignments[sourceType];
    if (!byType) return false;

    const bySource = byType[String(sourceId)] || byType[Number(sourceId)];
    if (!bySource) return false;

    return Boolean(bySource[String(userId)] || bySource[Number(userId)]);
  };

  /*
  |--------------------------------------------------------------------------
  | Resolve the activity identity shown above/below one roster event column
  |--------------------------------------------------------------------------
  |
  | Imported/manual normal activities already expose their exact source through
  | .activity-point-editor:
  |     calendar / 137
  |     slot / 4
  |
  | A manual split can fall back to its split-event id.
  |
  | If a period contains several imported activities in one unsplit roster
  | column, all source identities are kept. A musician gets the Scheduled
  | marker if assigned to any of those calls. Splitting the activity remains
  | the way to represent different availability/assignment columns.
  |
  */

  const sourcesForActivity = (activity) => {
    if (!activity) return [];

    const sources = [];
    const seen = new Set();

    activity.querySelectorAll(".activity-point-editor[data-point-source][data-point-id]").forEach((editor) => {
      const type = editor.dataset.pointSource || "";
      const id = editor.dataset.pointId || "";
      const key = `${type}:${id}`;

      if (type && id && !seen.has(key)) {
        seen.add(key);
        sources.push({ type, id });
      }
    });

    if (sources.length === 0) {
      const splitId = activity.dataset.splitEventId || "";
      if (splitId) {
        sources.push({ type: "split", id: splitId });
      }
    }

    return sources;
  };

  const markCell = (cell, sources) => {
    const userId = cell.dataset.userId || "";

    const isAssigned = sources.some((source) => assigned(source.type, source.id, userId));

    cell.classList.toggle("is-assigned", isAssigned);

    if (isAssigned) {
      const availability =
        cell.dataset.status === "available" ? "Available" : cell.dataset.status === "unavailable" ? "Unavailable" : "Availability unanswered";

      cell.title = `${availability} · Scheduled to work`;
      cell.setAttribute("aria-label", `${availability}. Scheduled to work.`);
    }
  };

  const connectDesktop = () => {
    document.querySelectorAll(".desktop-paper-week").forEach((week) => {
      ["morning", "evening"].forEach((period) => {
        const roster = period === "morning" ? week.querySelector(".desktop-paper-roster") : Array.from(week.querySelectorAll(".desktop-paper-roster")).at(-1);

        const activities = week.querySelector(`.desktop-paper-activities-${period}`);

        if (!roster || !activities) return;

        const rosterDays = roster.querySelectorAll(".desktop-paper-roster-day");
        const activityDays = activities.querySelectorAll(".desktop-paper-activity-day");

        rosterDays.forEach((rosterDay, dayIndex) => {
          const activityDay = activityDays[dayIndex];
          if (!activityDay) return;

          const rosterEvents = rosterDay.querySelectorAll(".desktop-paper-event-marks");
          const activityEvents = activityDay.querySelectorAll(".desktop-paper-activity");

          rosterEvents.forEach((rosterEvent, eventIndex) => {
            const activity = activityEvents[eventIndex] || activityEvents[0] || null;

            const sources = sourcesForActivity(activity);

            rosterEvent.querySelectorAll(".member-cell[data-user-id]").forEach((cell) => markCell(cell, sources));
          });
        });
      });
    });
  };

  const connectMobile = () => {
    document.querySelectorAll(".mobile-overview-week").forEach((week) => {
      ["morning", "evening"].forEach((period) => {
        const rosters = week.querySelectorAll(".mobile-overview-roster");
        const roster = period === "morning" ? rosters[0] : rosters[rosters.length - 1];

        const activities = week.querySelector(`.mobile-overview-activities-${period}`);

        if (!roster || !activities) return;

        const rosterDays = roster.querySelectorAll(".mobile-overview-roster-day");
        const activityDays = activities.querySelectorAll(".mobile-overview-activity-day");

        rosterDays.forEach((rosterDay, dayIndex) => {
          const activityDay = activityDays[dayIndex];
          if (!activityDay) return;

          const rosterEvents = rosterDay.querySelectorAll(".mobile-overview-event-marks");
          const activityEvents = activityDay.querySelectorAll(".mobile-overview-activity");

          rosterEvents.forEach((rosterEvent, eventIndex) => {
            const activity = activityEvents[eventIndex] || activityEvents[0] || null;

            const sources = sourcesForActivity(activity);

            rosterEvent.querySelectorAll(".member-cell[data-user-id]").forEach((cell) => markCell(cell, sources));
          });
        });
      });
    });
  };

  connectDesktop();
  connectMobile();
})();
