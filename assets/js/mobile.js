document.addEventListener("DOMContentLoaded", () => {
  document.querySelectorAll(".mobile-options-button").forEach((button) => {
    button.addEventListener("click", (event) => {
      event.preventDefault();
      event.stopPropagation();

      const row = button.closest(".mobile-member-row");
      const cell = row?.querySelector(".availability-cell.editable");
      if (!cell) return;

      const rect = button.getBoundingClientRect();
      cell.dispatchEvent(
        new MouseEvent("contextmenu", {
          bubbles: true,
          cancelable: true,
          clientX: rect.left,
          clientY: Math.min(rect.bottom + 4, window.innerHeight - 8),
        })
      );
    });
  });
});
