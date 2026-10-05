(() => {
  const config = window.SECTION_SCHEDULE || {};
  const weeks = Array.isArray(config.weeklyPointUi) ? config.weeklyPointUi : [];

  const formatPoints = (value) => {
    if (value === null || value === "" || value === undefined) return "—";
    const n = Number(value);
    if (!Number.isFinite(n)) return "—";
    return Number.isInteger(n) ? String(n) : n.toFixed(2).replace(/0+$/, "").replace(/\.$/, "");
  };

  const build = (item, pointType, weekStart) => {
    const wrapper = document.createElement("span");
    wrapper.className = "weekly-point-balance";

    const input = document.createElement("input");
    input.type = "number";
    input.step = "0.01";
    input.inputMode = "decimal";
    input.className = "weekly-point-opening-input";
    input.placeholder = "—";
    input.value = item.opening === null || item.opening === undefined ? "" : formatPoints(item.opening);
    input.dataset.weekStart = weekStart;
    input.dataset.userId = String(item.userId);
    input.dataset.pointType = pointType;

    if (!config.isAdmin) input.disabled = true;

    const estimate = document.createElement("strong");
    estimate.className = "weekly-point-estimate";
    estimate.dataset.earned = String(item.earned || 0);

    const opening = Number(input.value);
    estimate.textContent = input.value.trim() !== "" && Number.isFinite(opening) ? formatPoints(opening + Number(item.earned || 0)) : "—";

    wrapper.append(input, estimate);
    return wrapper;
  };

  const upgrade = (article, week, mobile) => {
    if (!article || !week) return;

    const rosters = article.querySelectorAll(mobile ? ".mobile-overview-roster" : ".desktop-paper-roster");
    if (rosters.length < 2) return;

    [
      [rosters[0], "rehearsal", week.rehearsal || []],
      [rosters[1], "performance", week.performance || []],
    ].forEach(([roster, pointType, items]) => {
      const cells = roster.querySelectorAll(mobile ? ".mobile-overview-person-points" : ".desktop-paper-person-points");

      cells.forEach((cell, index) => {
        if (items[index]) {
          cell.replaceWith(build(items[index], pointType, week.weekStart));
        }
      });
    });
  };

  document.querySelectorAll(".desktop-paper-week").forEach((article, index) => {
    upgrade(article, weeks[index], false);
  });

  const mobileArticles = [...document.querySelectorAll(".mobile-overview-week")];
  const mobileWeeks = weeks.slice(-mobileArticles.length);

  mobileArticles.forEach((article, index) => {
    upgrade(article, mobileWeeks[index], true);
  });

  document.addEventListener("input", (event) => {
    const input = event.target.closest(".weekly-point-opening-input");
    if (!input) return;

    const estimate = input.closest(".weekly-point-balance")?.querySelector(".weekly-point-estimate");
    if (!estimate) return;

    if (input.value.trim() === "") {
      estimate.textContent = "—";
      return;
    }

    const opening = Number(input.value);
    const earned = Number(estimate.dataset.earned || 0);

    estimate.textContent = Number.isFinite(opening) ? formatPoints(opening + earned) : "—";
  });

  document.addEventListener("change", async (event) => {
    const input = event.target.closest(".weekly-point-opening-input");
    if (!input || !config.isAdmin) return;

    const body = new URLSearchParams({
      csrf: config.csrfToken || "",
      week_start: input.dataset.weekStart || "",
      user_id: input.dataset.userId || "",
      point_type: input.dataset.pointType || "",
      opening_points: input.value.trim(),
    });

    try {
      const response = await fetch("ajax/save-weekly-point-balance.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
        },
        body,
      });

      const result = await response.json();

      if (!response.ok || !result.ok) {
        throw new Error(result.error || "Could not save points.");
      }
    } catch (error) {
      window.alert(error.message || "Could not save points.");
    }
  });
})();
