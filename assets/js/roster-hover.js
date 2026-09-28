(() => {
  const rosters = document.querySelectorAll(".desktop-paper-roster");

  const clear = (roster) => {
    roster.querySelectorAll(".roster-row-hover").forEach((el) => el.classList.remove("roster-row-hover"));
  };

  const highlight = (roster, userId) => {
    clear(roster);
    if (!userId) return;

    const people = Array.from(roster.querySelectorAll(".desktop-paper-person"));

    const firstEvent = roster.querySelector(".desktop-paper-event-marks");
    const firstCells = firstEvent ? Array.from(firstEvent.querySelectorAll(".member-cell[data-user-id]")) : [];

    const index = firstCells.findIndex((cell) => String(cell.dataset.userId) === String(userId));

    if (index >= 0 && people[index]) {
      people[index].classList.add("roster-row-hover");
    }

    roster.querySelectorAll(".member-cell[data-user-id]").forEach((cell) => {
      if (String(cell.dataset.userId) === String(userId)) {
        cell.closest(".desktop-paper-mark-wrap")?.classList.add("roster-row-hover");
      }
    });
  };

  rosters.forEach((roster) => {
    const people = Array.from(roster.querySelectorAll(".desktop-paper-person"));

    const firstEvent = roster.querySelector(".desktop-paper-event-marks");
    const firstCells = firstEvent ? Array.from(firstEvent.querySelectorAll(".member-cell[data-user-id]")) : [];

    people.forEach((person, index) => {
      const userId = firstCells[index]?.dataset.userId || "";

      person.addEventListener("mouseenter", () => {
        highlight(roster, userId);
      });
    });

    roster.addEventListener("mouseover", (event) => {
      const cell = event.target.closest(".member-cell[data-user-id]");
      if (!cell || !roster.contains(cell)) return;

      highlight(roster, cell.dataset.userId || "");
    });

    roster.addEventListener("mouseleave", () => clear(roster));
  });
})();
