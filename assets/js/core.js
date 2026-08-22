(() => {
  const config = window.SECTION_SCHEDULE || {};
  let editing = false;

  const editingListeners = new Set();

  const setEditing = (value) => {
    editing = Boolean(value);
    document.body.classList.toggle("editing-mode", editing);

    editingListeners.forEach((listener) => {
      try {
        listener(editing);
      } catch (error) {
        console.error(error);
      }
    });

    document.dispatchEvent(
      new CustomEvent("schedule:editing-changed", {
        detail: { editing },
      })
    );
  };

  const isEditing = () => editing;

  const onEditingChange = (listener) => {
    editingListeners.add(listener);
    return () => editingListeners.delete(listener);
  };

  const post = async (endpoint, payload = {}) => {
    const body = new FormData();
    body.append("csrf_token", config.csrfToken || "");

    Object.entries(payload).forEach(([key, value]) => {
      body.append(key, value == null ? "" : String(value));
    });

    const response = await fetch(endpoint, {
      method: "POST",
      body,
      credentials: "same-origin",
    });

    let result;

    try {
      result = await response.json();
    } catch {
      throw new Error("The server returned an invalid response.");
    }

    if (!response.ok || !result.success) {
      const error = new Error(
        result.message || `Request failed (${response.status}).`
      );

      error.status = response.status;
      error.payload = result;

      throw error;
    }

    return result;
  };

  const renderAvailability = (cell) => {
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
  };

  const positionFloating = (element, x, y, padding = 8) => {
    element.hidden = false;
    element.style.left = "0px";
    element.style.top = "0px";

    const rect = element.getBoundingClientRect();

    const left = Math.max(
      padding,
      Math.min(x, window.innerWidth - rect.width - padding)
    );

    const top = Math.max(
      padding,
      Math.min(y, window.innerHeight - rect.height - padding)
    );

    element.style.left = `${left}px`;
    element.style.top = `${top}px`;
  };

  window.ScheduleApp = {
    config,
    setEditing,
    isEditing,
    onEditingChange,
    post,
    renderAvailability,
    positionFloating,
  };
})();
