(() => {
  const config = window.SECTION_SCHEDULE || {};
  const assignments = config.assignments || {};

  const assignmentFor = (sourceType, sourceId, userId) => {
    if (!sourceType || !sourceId || !userId) return null;

    const byType = assignments[sourceType];
    if (!byType) return null;

    const bySource = byType[String(sourceId)] || byType[Number(sourceId)];

    if (!bySource) return null;

    const value = bySource[String(userId)] ?? bySource[Number(userId)] ?? null;

    /*
     * Backward compatibility with the old payload, where an assignment
     * was simply `true`.
     */
    if (value === true) {
      return {
        assigned: true,
        replacement_name: "",
      };
    }

    if (value && typeof value === "object") {
      return {
        assigned: value.assigned !== false,
        replacement_name: String(value.replacement_name || "").trim(),
      };
    }

    return null;
  };

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

    /*
     * A linked split can still represent a canonical calendar source.
     * If there is no point editor, fall back to the split identity.
     */
    if (sources.length === 0) {
      const splitId = activity.dataset.splitEventId || "";

      if (splitId) {
        sources.push({
          type: "split",
          id: splitId,
        });
      }
    }

    return sources;
  };

  const removeOldAssignmentBadges = (cell) => {
    cell.querySelectorAll(".calendar-assignment-annotation").forEach((node) => node.remove());
  };

  const addReplacementBadge = (cell, replacementName) => {
    if (!replacementName) return;

    const badge = document.createElement("span");
    badge.className = "calendar-assignment-annotation calendar-replacement-annotation";
    badge.textContent = `REP: ${replacementName}`;
    badge.title = `External replacement: ${replacementName}`;

    cell.appendChild(badge);
  };

  const markCell = (cell, sources) => {
    const userId = cell.dataset.userId || "";

    const matches = sources
      .map((source) => ({
        source,
        assignment: assignmentFor(source.type, source.id, userId),
      }))
      .filter((item) => item.assignment?.assigned);

    const isAssigned = matches.length > 0;

    cell.classList.toggle("is-assigned", isAssigned);

    removeOldAssignmentBadges(cell);

    if (!isAssigned) {
      return;
    }

    /*
     * Normally one roster event maps to one assignment source.
     * If an unsplit visual column contains several calls, show every
     * distinct external replacement attached to those calls.
     */
    const replacementNames = [...new Set(matches.map((item) => item.assignment.replacement_name).filter(Boolean))];

    replacementNames.forEach((name) => {
      addReplacementBadge(cell, name);
    });

    const availability = cell.dataset.status === "available" ? "Available" : cell.dataset.status === "unavailable" ? "Unavailable" : "Availability unanswered";

    const replacementText = replacementNames.length ? ` · External replacement: ${replacementNames.join(", ")}` : "";

    cell.title = `${availability} · Scheduled to work${replacementText}`;

    cell.setAttribute("aria-label", `${availability}. Scheduled to work${replacementText}.`);
  };

  const connectDesktop = () => {
    document.querySelectorAll(".desktop-paper-week").forEach((week) => {
      ["morning", "evening"].forEach((period) => {
        const rosters = week.querySelectorAll(".desktop-paper-roster");

        const roster = period === "morning" ? rosters[0] : rosters[rosters.length - 1];

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
