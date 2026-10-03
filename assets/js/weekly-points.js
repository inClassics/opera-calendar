(() => {
  const config = window.SECTION_SCHEDULE || {};
  const weeks = Array.isArray(config.weeklyPointUi) ? config.weeklyPointUi : [];

  const formatPoints = (value) => {
    const number = Number(value);
    if (!Number.isFinite(number)) return "—";
    return Number.isInteger(number) ? String(number) : number.toFixed(2).replace(/0+$/, "").replace(/\.$/, "");
  };

  const save = async (input) => {
    if (!config.isAdmin) return;

    const body = new URLSearchParams({
      csrf: config.csrfToken || "",
      week_start: input.dataset.weekStart || "",
      user_id: input.dataset.userId || "",
      point_type: input.dataset.pointType || "",
      opening_points: input.value.trim(),
    });

    input.classList.add("is-saving");

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

      input.classList.remove("is-error");
      input.classList.add("is-saved");
      window.setTimeout(() => input.classList.remove("is-saved"), 900);
    } catch (error) {
      input.classList.add("is-error");
      window.alert(error.message || "Could not save points.");
    } finally {
      input.classList.remove("is-saving");
    }
  };

  const buildBalance = (item, pointType, weekStart) => {
    const wrapper = document.createElement("span");
    wrapper.className = "weekly-point-balance";

    let openingElement;

    if (config.isAdmin) {
      const input = document.createElement("input");
      input.type = "number";
      input.step = "0.01";
      input.inputMode = "decimal";
      input.className = "weekly-point-opening-input";
      input.value = formatPoints(item.opening);
      input.dataset.weekStart = weekStart;
      input.dataset.userId = String(item.userId);
      input.dataset.pointType = pointType;
      openingElement = input;
      wrapper.appendChild(input);
    } else {
      const opening = document.createElement("strong");
      opening.className = "weekly-point-opening-value";
      opening.textContent = formatPoints(item.opening);
      openingElement = opening;
      wrapper.appendChild(opening);
    }

    const arrow = document.createElement("span");
    arrow.className = "weekly-point-arrow";
    arrow.textContent = "→";
    wrapper.appendChild(arrow);

    const estimate = document.createElement("strong");
    estimate.className = "weekly-point-estimate";
    estimate.dataset.weeklyPointEstimate = "1";
    estimate.dataset.earned = String(item.earned || 0);
    estimate.textContent = formatPoints(Number(item.opening) + Number(item.earned || 0));
    wrapper.appendChild(estimate);

    return { wrapper, openingElement, estimate };
  };

  const upgradeWeek = (article, week, mobile = false) => {
    if (!article || !week) return;

    const selector = mobile ? ".mobile-overview-roster" : ".desktop-paper-roster";

    const rosters = article.querySelectorAll(selector);
    if (rosters.length < 2) return;

    [
      [rosters[0], "rehearsal", week.rehearsal || []],
      [rosters[1], "performance", week.performance || []],
    ].forEach(([roster, pointType, items]) => {
      const pointSelector = mobile ? ".mobile-overview-person-points" : ".desktop-paper-person-points";

      const pointCells = roster.querySelectorAll(pointSelector);

      pointCells.forEach((cell, index) => {
        const item = items[index];
        if (!item) return;

        const { wrapper } = buildBalance(item, pointType, week.weekStart);
        cell.replaceWith(wrapper);
      });

      if (mobile) {
        const label = roster.querySelector(".mobile-overview-roster-labels span:last-child");
        if (label) {
          label.textContent = pointType === "rehearsal" ? "Reh. start → end" : "Conc. start → end";
        }
      }
    });
  };

  document.querySelectorAll(".desktop-paper-week").forEach((article, index) => {
    upgradeWeek(article, weeks[index], false);
  });

  // Mobile begins at the current week, so match by the week number/date sequence.
  const mobileArticles = document.querySelectorAll(".mobile-overview-week");
  if (mobileArticles.length) {
    const visibleWeeks = weeks.slice(-mobileArticles.length);
    mobileArticles.forEach((article, index) => {
      upgradeWeek(article, visibleWeeks[index], true);
    });
  }

  document.addEventListener("input", (event) => {
    const input = event.target.closest(".weekly-point-opening-input");
    if (!input) return;

    const balance = input.closest(".weekly-point-balance");
    const estimate = balance?.querySelector("[data-weekly-point-estimate]");
    if (!estimate) return;

    const opening = Number(input.value);
    const earned = Number(estimate.dataset.earned || 0);
    estimate.textContent = Number.isFinite(opening) && Number.isFinite(earned) ? formatPoints(opening + earned) : "—";
  });

  document.addEventListener("change", (event) => {
    const input = event.target.closest(".weekly-point-opening-input");
    if (!input) return;
    save(input);
  });
})();
