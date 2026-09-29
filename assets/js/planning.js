(() => {
  const cfg = window.PLANNING || {};

  const post = async (url, data) => {
    const body = new URLSearchParams({
      ...data,
      csrf_token: cfg.csrfToken,
    });

    const r = await fetch(url, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
      },
      body,
    });

    const j = await r.json();

    if (!r.ok || j.success === false) {
      throw new Error(j.message || "Request failed");
    }

    return j;
  };

  const rows = [...document.querySelectorAll(".planning-activity")];

  const applyFilters = () => {
    const q = (document.querySelector("#planning-search")?.value || "").trim().toLowerCase();

    const u = document.querySelector("#filter-unconnected")?.checked;

    const p = document.querySelector("#filter-shortage")?.checked;

    rows.forEach((r) => {
      r.hidden = (q && !r.dataset.search.includes(q)) || (u && r.dataset.unconnected !== "1") || (p && r.dataset.problem !== "1");
    });
  };

  ["planning-search", "filter-unconnected", "filter-shortage"].forEach((id) => document.getElementById(id)?.addEventListener("input", applyFilters));

  document.querySelectorAll(".planning-piece,.planning-required").forEach((el) =>
    el.addEventListener("change", async () => {
      const row = el.closest(".planning-activity");
      const piece = row.querySelector(".planning-piece");
      const req = row.querySelector(".planning-required");

      el.disabled = true;

      try {
        await post("ajax/update-activity-piece.php", {
          source_type: row.dataset.sourceType,
          source_id: row.dataset.sourceId,
          piece_id: piece.value,
          required_basses_override: req.value,
        });

        row.dataset.unconnected = piece.value ? "0" : "1";

        const opt = piece.selectedOptions[0];
        req.placeholder = opt?.dataset.default || "—";
      } catch (e) {
        alert(e.message);
        location.reload();
      } finally {
        el.disabled = false;
      }
    }),
  );

  /*
  |--------------------------------------------------------------------------
  | Call settings
  |--------------------------------------------------------------------------
  |
  | Morning/evening, manual points and rehearsal/performance are saved
  | together. Suggested values are only visual until the administrator changes
  | one of these controls.
  |
  */

  document.querySelectorAll(".planning-period,.planning-points,.planning-point-type").forEach((el) =>
    el.addEventListener("change", async () => {
      const row = el.closest(".planning-activity");
      const period = row.querySelector(".planning-period");
      const points = row.querySelector(".planning-points");
      const pointType = row.querySelector(".planning-point-type");

      const controls = [period, points, pointType];

      controls.forEach((control) => (control.disabled = true));

      try {
        const result = await post("ajax/update-planning-call.php", {
          source_type: row.dataset.sourceType,
          source_id: row.dataset.sourceId,
          period: period.value,
          point_value: points.value,
          point_type: pointType.value,
        });

        period.value = result.period;
        points.value = String(result.point_value);
        pointType.value = result.point_type || "";

        period.classList.remove("is-suggested");
        pointType.classList.remove("is-suggested");

        row.querySelectorAll(".planning-suggestion").forEach((node) => node.remove());
      } catch (e) {
        alert(e.message);
        location.reload();
      } finally {
        controls.forEach((control) => (control.disabled = false));
      }
    }),
  );

  /*
  |--------------------------------------------------------------------------
  | Remove an effective schedule entry
  |--------------------------------------------------------------------------
  |
  | Imported Lydian events are locally excluded rather than forgotten. The
  | importer migration-aware code keeps them excluded on later syncs.
  */

  document.querySelectorAll(".planning-remove").forEach((button) => {
    button.addEventListener("click", async () => {
      const row = button.closest(".planning-activity");
      const title = row.querySelector(".planning-title strong")?.textContent?.trim() || "this activity";

      if (!confirm(`Remove "${title}" from Planning and Calendar?\n\n` + "This is a local scheduling exclusion. Existing history is kept.")) {
        return;
      }

      button.disabled = true;

      try {
        await post("ajax/planning-remove-activity.php", {
          source_type: row.dataset.sourceType,
          source_id: row.dataset.sourceId,
        });

        row.remove();
      } catch (e) {
        alert(e.message);
        button.disabled = false;
      }
    });
  });

  const drawer = document.querySelector(".planning-drawer");

  const backdrop = document.querySelector(".planning-drawer-backdrop");

  const close = () => {
    drawer.hidden = true;
    backdrop.hidden = true;
  };

  document.querySelector(".planning-drawer-close")?.addEventListener("click", close);

  backdrop?.addEventListener("click", close);

  document.querySelectorAll(".planning-manage").forEach((btn) =>
    btn.addEventListener("click", async () => {
      const row = btn.closest(".planning-activity");

      btn.disabled = true;

      try {
        const [j, replacementResult] = await Promise.all([
          post("ajax/planning-activity.php", {
            source_type: row.dataset.sourceType,
            source_id: row.dataset.sourceId,
          }),
          post("ajax/planning-replacements.php", {
            source_type: row.dataset.sourceType,
            source_id: row.dataset.sourceId,
          }),
        ]);

        const replacements = replacementResult.replacements || {};

        drawer.querySelector(".planning-drawer-meta").textContent = `${j.meta.schedule_date} · ${j.meta.period}`;

        const box = drawer.querySelector(".planning-members");

        box.innerHTML = "";

        j.members.forEach((m) => {
          const line = document.createElement("label");

          line.className = "planning-member";

          const c = document.createElement("input");

          c.type = "checkbox";
          c.checked = !!m.assigned;

          const mark = m.status === "available" ? "×" : m.status === "unavailable" ? "•" : "—";

          const name = document.createElement("span");

          const strong = document.createElement("strong");

          strong.textContent = m.name;

          const small = document.createElement("small");

          small.textContent = `${mark} ${m.status}` + (m.uncertain ? " · uncertain" : "");

          name.append(strong, small);

          const replacementWrap = document.createElement("div");
          replacementWrap.className = "planning-replacement";

          const replacementLabel = document.createElement("span");
          replacementLabel.textContent = "External replacement";

          const replacementInput = document.createElement("input");
          replacementInput.type = "text";
          replacementInput.placeholder = "Replacement name (optional)";
          replacementInput.value = replacements[String(m.id)] || "";
          replacementInput.disabled = !c.checked;

          const replacementHelp = document.createElement("small");
          replacementHelp.textContent = "The selected section member keeps this assignment and earns the points.";

          replacementWrap.append(replacementLabel, replacementInput, replacementHelp);

          line.append(c, name, replacementWrap);
          box.append(line);

          let replacementTimer = null;

          const saveReplacement = async () => {
            if (!c.checked) {
              return;
            }

            replacementInput.disabled = true;

            try {
              await post("ajax/planning-replacement-save.php", {
                source_type: row.dataset.sourceType,
                source_id: row.dataset.sourceId,
                user_id: m.id,
                replacement_name: replacementInput.value.trim(),
              });
            } catch (e) {
              alert(e.message);
            } finally {
              replacementInput.disabled = false;
            }
          };

          replacementInput.addEventListener("input", () => {
            clearTimeout(replacementTimer);
            replacementTimer = setTimeout(saveReplacement, 500);
          });

          replacementInput.addEventListener("change", saveReplacement);

          c.addEventListener("change", async () => {
            c.disabled = true;

            try {
              await post("ajax/planning-assignment.php", {
                source_type: row.dataset.sourceType,
                source_id: row.dataset.sourceId,
                user_id: m.id,
                assigned: c.checked ? "1" : "0",
              });

              const count = [...box.querySelectorAll('input[type="checkbox"]')].filter((x) => x.checked).length;

              row.querySelector(".assigned-count").textContent = String(count);

              replacementInput.disabled = !c.checked;

              if (!c.checked) {
                replacementInput.value = "";

                await post("ajax/planning-replacement-save.php", {
                  source_type: row.dataset.sourceType,
                  source_id: row.dataset.sourceId,
                  user_id: m.id,
                  replacement_name: "",
                });
              }
            } catch (e) {
              c.checked = !c.checked;
              alert(e.message);
            } finally {
              c.disabled = false;
            }
          });
        });

        drawer.hidden = false;
        backdrop.hidden = false;
      } catch (e) {
        alert(e.message);
      } finally {
        btn.disabled = false;
      }
    }),
  );
})();
