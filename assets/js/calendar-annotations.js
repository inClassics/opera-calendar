document.addEventListener("DOMContentLoaded", () => {
  const App = window.ScheduleApp;

  if (!App) return;

  const params = new URLSearchParams(window.location.search);
  const now = new Date();
  const year = Number(params.get("year") || now.getFullYear());
  const month = Number(params.get("month") || now.getMonth() + 1);

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

    document
      .querySelectorAll(".availability-cell[data-user-id][data-date][data-period], " + ".split-availability-cell[data-user-id][data-date][data-period]")
      .forEach((cell) => {
        const userId = String(cell.dataset.userId || "");
        const date = cell.dataset.date || "";
        const period = cell.dataset.period || "";
        const splitId = cell.dataset.splitEventId || "";

        const status = data.day_statuses?.[date]?.[userId] || data.day_statuses?.[date]?.[Number(userId)] || "";

        if (status === "sick") {
          cell.classList.add("has-day-status-sick");
          addBadge(cell, "SICK", "calendar-annotation-sick", "Sick leave");
        } else if (status === "unpaid_leave") {
          cell.classList.add("has-day-status-unpaid-leave");
          addBadge(cell, "UL", "calendar-annotation-unpaid", "Unpaid leave");
        }

        let multiplier = 1;

        if (splitId) {
          multiplier = data.split_multipliers?.[splitId]?.[userId] ?? data.split_multipliers?.[splitId]?.[Number(userId)] ?? 1;
        } else {
          multiplier = data.normal_multipliers?.[date]?.[period]?.[userId] ?? data.normal_multipliers?.[date]?.[period]?.[Number(userId)] ?? 1;
        }

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
});
