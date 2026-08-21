document.addEventListener("DOMContentLoaded", () => {
  const csrfToken = window.SECTION_SCHEDULE?.csrfToken || "";

  const renderCell = (cell) => {
    const status = cell.dataset.status || "";
    const uncertain = cell.dataset.uncertain === "1";

    cell.classList.remove("available", "unavailable", "uncertain");

    cell.textContent = "";

    if (status === "available") {
      cell.textContent = uncertain ? "×?" : "×";

      cell.classList.add("available");
    } else if (status === "unavailable") {
      cell.textContent = uncertain ? "•?" : "•";

      cell.classList.add("unavailable");
    }

    if (uncertain) {
      cell.classList.add("uncertain");
    }

    /*
    |--------------------------------------------------------------------------
    | Show ? button only when × or • exists
    |--------------------------------------------------------------------------
    */

    const container = cell.closest(".availability-td");

    const questionButton = container?.querySelector(".uncertain-toggle");

    if (questionButton) {
      questionButton.hidden = status === "";

      questionButton.classList.toggle("active", uncertain);
    }
  };

  document.querySelectorAll(".availability-cell").forEach((cell) => {
    renderCell(cell);
    if (!cell.classList.contains("editable")) return;

    cell.addEventListener("click", async () => {
      if (cell.dataset.saving === "1") return;

      const previousStatus = cell.dataset.status || "";
      const nextStatus = previousStatus === "" ? "available" : previousStatus === "available" ? "unavailable" : "";

      cell.dataset.status = nextStatus;

      if (nextStatus === "") {
        cell.dataset.uncertain = "0";
      }

      renderCell(cell);
      cell.dataset.saving = "1";

      const formData = new FormData();
      formData.append("csrf_token", csrfToken);
      formData.append("user_id", cell.dataset.userId);
      formData.append("date", cell.dataset.date);
      formData.append("period", cell.dataset.period);
      formData.append("status", nextStatus);

      try {
        const response = await fetch("ajax/update-availability.php", { method: "POST", body: formData });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || "Could not save availability.");
      } catch (error) {
        cell.dataset.status = previousStatus;
        renderCell(cell);
        alert(error.message);
      } finally {
        cell.dataset.saving = "0";
      }
    });
  });

  document.querySelectorAll(".activity-editable").forEach((cell) => {
    cell.addEventListener("click", () => {
      if (cell.querySelector("input")) return;

      const currentText = cell.textContent.trim();
      const input = document.createElement("input");
      input.type = "text";
      input.value = currentText;
      input.maxLength = 255;
      input.className = "activity-input";

      cell.textContent = "";
      cell.appendChild(input);
      input.focus();
      input.select();

      let finished = false;

      const restore = () => {
        if (finished) return;
        finished = true;
        cell.textContent = currentText;
      };

      const save = async () => {
        if (finished) return;
        finished = true;
        const newText = input.value.trim();

        const formData = new FormData();
        formData.append("csrf_token", csrfToken);
        formData.append("date", cell.dataset.date);
        formData.append("period", cell.dataset.period);
        formData.append("activity", newText);

        try {
          const response = await fetch("ajax/update-activity.php", { method: "POST", body: formData });
          const result = await response.json();
          if (!response.ok || !result.success) throw new Error(result.message || "Could not save activity.");
          cell.textContent = result.activity;
        } catch (error) {
          cell.textContent = currentText;
          alert(error.message);
        }
      };

      input.addEventListener("blur", save, { once: true });
      input.addEventListener("keydown", (event) => {
        if (event.key === "Enter") input.blur();
        if (event.key === "Escape") {
          event.preventDefault();
          restore();
        }
      });
    });
  });

  document.querySelectorAll(".uncertain-toggle").forEach((button) => {
    button.addEventListener("click", async (event) => {
      event.stopPropagation();

      const container = button.closest(".availability-td");

      const cell = container?.querySelector(".availability-cell");

      if (!cell) {
        return;
      }

      const status = cell.dataset.status || "";

      /*
        |--------------------------------------------------------------------------
        | No ? on an unanswered cell
        |--------------------------------------------------------------------------
        */

      if (status === "") {
        return;
      }

      if (button.dataset.saving === "1") {
        return;
      }

      const previous = cell.dataset.uncertain === "1";

      const next = !previous;

      /*
        |--------------------------------------------------------------------------
        | Immediate visual update
        |--------------------------------------------------------------------------
        */

      cell.dataset.uncertain = next ? "1" : "0";

      renderCell(cell);

      button.dataset.saving = "1";

      const formData = new FormData();

      formData.append("csrf_token", csrfToken);

      formData.append("user_id", cell.dataset.userId);

      formData.append("date", cell.dataset.date);

      formData.append("period", cell.dataset.period);

      formData.append("uncertain", next ? "1" : "0");

      try {
        const response = await fetch("ajax/toggle-uncertain.php", {
          method: "POST",
          body: formData,
        });

        const result = await response.json();

        if (!response.ok || !result.success) {
          throw new Error(result.message || "Could not save uncertainty.");
        }
      } catch (error) {
        cell.dataset.uncertain = previous ? "1" : "0";

        renderCell(cell);

        alert(error.message);
      } finally {
        button.dataset.saving = "0";
      }
    });
  });
});
