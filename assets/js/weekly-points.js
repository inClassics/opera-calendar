(() => {
  const formatPoints = (value) => {
    const number = Number(value);
    if (!Number.isFinite(number)) return "—";
    return Number.isInteger(number) ? String(number) : number.toFixed(2).replace(/0+$/, "").replace(/\.$/, "");
  };

  const updateEstimate = (input) => {
    const balance = input.closest(".weekly-point-balance");
    const estimate = balance?.querySelector("[data-weekly-point-estimate]");
    if (!estimate) return;

    const raw = input.value.trim();

    if (raw === "") {
      estimate.textContent = "—";
      return;
    }

    const opening = Number(raw);
    const earned = Number(estimate.dataset.earned || 0);

    estimate.textContent = Number.isFinite(opening) && Number.isFinite(earned) ? formatPoints(opening + earned) : "—";
  };

  const save = async (input) => {
    if (!window.SECTION_SCHEDULE?.isAdmin) return;

    const body = new URLSearchParams({
      csrf: window.SECTION_SCHEDULE.csrfToken,
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

  document.addEventListener("input", (event) => {
    const input = event.target.closest(".weekly-point-opening-input");
    if (!input) return;
    updateEstimate(input);
  });

  document.addEventListener("change", (event) => {
    const input = event.target.closest(".weekly-point-opening-input");
    if (!input) return;
    updateEstimate(input);
    save(input);
  });
})();
