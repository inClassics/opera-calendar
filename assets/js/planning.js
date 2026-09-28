(() => {
  const cfg = window.PLANNING || {};
  const post = async (url, data) => {
    const body = new URLSearchParams({ ...data, csrf_token: cfg.csrfToken });
    const r = await fetch(url, { method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" }, body });
    const j = await r.json();
    if (!r.ok || j.success === false) throw new Error(j.message || "Request failed");
    return j;
  };
  const rows = [...document.querySelectorAll(".planning-activity")];
  const applyFilters = () => {
    const q = (document.querySelector("#planning-search")?.value || "").trim().toLowerCase();
    const u = document.querySelector("#filter-unconnected")?.checked;
    const p = document.querySelector("#filter-shortage")?.checked;
    rows.forEach((r) => (r.hidden = (q && !r.dataset.search.includes(q)) || (u && r.dataset.unconnected !== "1") || (p && r.dataset.problem !== "1")));
  };
  ["planning-search", "filter-unconnected", "filter-shortage"].forEach((id) => document.getElementById(id)?.addEventListener("input", applyFilters));

  document.querySelectorAll(".planning-piece,.planning-required").forEach((el) =>
    el.addEventListener("change", async () => {
      const row = el.closest(".planning-activity"),
        piece = row.querySelector(".planning-piece"),
        req = row.querySelector(".planning-required");
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

  const drawer = document.querySelector(".planning-drawer"),
    backdrop = document.querySelector(".planning-drawer-backdrop");
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
        const j = await post("ajax/planning-activity.php", { source_type: row.dataset.sourceType, source_id: row.dataset.sourceId });
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
          name.innerHTML = `<strong>${m.name}</strong><small>${mark} ${m.status}${m.uncertain ? " · uncertain" : ""}</small>`;
          line.append(c, name);
          box.append(line);
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
