(() => {
  "use strict";

  const config = window.SECTION_SCHEDULE || {};

  const state = config.scheduleChanges || null;

  if (!state || !Array.isArray(state.changes) || state.changes.length === 0) {
    return;
  }

  const qsa = (selector) => Array.from(document.querySelectorAll(selector));

  const appendTitle = (element, change) => {
    const details = [];

    if (change.description) {
      details.push(change.description);
    }

    details.push(change.actor_name ? "Changed by " + change.actor_name : "Changed by system");

    if (change.created_at) {
      details.push(change.created_at);
    }

    const text = details.join("\n");

    const oldTitle = element.getAttribute("title");

    element.setAttribute("title", oldTitle ? oldTitle + "\n\n" + text : text);
  };

  const mark = (elements, change, personal = false) => {
    elements.forEach((element) => {
      element.classList.add("schedule-change-highlight");

      if (personal) {
        element.classList.add("schedule-change-personal");
      }

      appendTitle(element, change);
    });
  };

  state.changes.forEach((change) => {
    const date = change.schedule_date || "";

    const period = change.period || "";

    const entityId = Number(change.entity_id || 0);

    const affectedUserId = Number(change.affected_user_id || 0);

    if (affectedUserId > 0) {
      let targets = [];

      if (entityId > 0 && (change.entity_type === "split_event" || String(change.action || "").includes("split"))) {
        targets = qsa(".split-availability-cell" + '[data-split-event-id="' + entityId + '"]' + '[data-user-id="' + affectedUserId + '"]');
      }

      if (targets.length === 0 && date && period) {
        targets = qsa(".availability-cell" + '[data-date="' + date + '"]' + '[data-period="' + period + '"]' + '[data-user-id="' + affectedUserId + '"]');
      }

      if (targets.length > 0) {
        mark(targets, change, true);
        return;
      }
    }

    let targets = [];

    if (entityId > 0 && change.entity_type === "split_event") {
      targets = qsa(".split-activity-cell" + '[data-split-event-id="' + entityId + '"]');
    }

    if (targets.length === 0 && date && period) {
      targets = qsa(".activity-cell" + '[data-date="' + date + '"]' + '[data-period="' + period + '"]');
    }

    if (targets.length > 0) {
      mark(targets, change);
    }
  });

  const button = document.getElementById("mark-schedule-changes-seen");

  const notice = document.getElementById("schedule-change-notice");

  if (!button) {
    return;
  }

  button.addEventListener("click", async () => {
    if (button.disabled) {
      return;
    }

    const originalText = button.textContent;

    button.disabled = true;
    button.textContent = "Saving…";

    const body = new URLSearchParams();

    body.set("csrf_token", config.csrfToken || "");

    body.set("month", state.month || "");

    try {
      const response = await fetch("ajax/mark-schedule-changes-seen.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
        },
        body: body.toString(),
      });

      const result = await response.json();

      if (!response.ok || !result.success) {
        throw new Error(result.message || "Could not mark changes as seen.");
      }

      document.querySelectorAll(".schedule-change-highlight").forEach((element) => {
        element.classList.remove("schedule-change-highlight", "schedule-change-personal");
      });

      if (notice) {
        notice.remove();
      }
    } catch (error) {
      alert(error.message || "Could not mark changes as seen.");

      button.disabled = false;
      button.textContent = originalText;
    }
  });
})();
