(() => {
  const rosters = document.querySelectorAll(".desktop-paper-roster");

  const clearRoster = (roster) => {
    roster.querySelectorAll(".roster-row-hover").forEach((element) => element.classList.remove("roster-row-hover"));
  };

  const memberIdsForRoster = (roster) => {
    const firstMarks = roster.querySelector(".desktop-paper-event-marks");
    if (!firstMarks) return [];

    return Array.from(firstMarks.querySelectorAll(".desktop-paper-mark-wrap .member-cell[data-user-id]")).map((cell) => cell.dataset.userId || "");
  };

  const highlightUser = (roster, userId) => {
    clearRoster(roster);
    if (!userId) return;

    const ids = memberIdsForRoster(roster);
    const memberIndex = ids.indexOf(String(userId));

    if (memberIndex >= 0) {
      const person = roster.querySelectorAll(".desktop-paper-person")[memberIndex];
      person?.classList.add("roster-row-hover");
    }

    roster.querySelectorAll(`.desktop-paper-mark-wrap .member-cell[data-user-id="${CSS.escape(String(userId))}"]`).forEach((cell) => {
      cell.closest(".desktop-paper-mark-wrap")?.classList.add("roster-row-hover");
    });
  };

  rosters.forEach((roster) => {
    const people = Array.from(roster.querySelectorAll(".desktop-paper-person"));
    const ids = memberIdsForRoster(roster);

    people.forEach((person, index) => {
      const userId = ids[index] || "";

      person.addEventListener("mouseenter", () => {
        highlightUser(roster, userId);
      });
    });

    roster.addEventListener("mouseover", (event) => {
      const cell = event.target.closest(".member-cell[data-user-id]");
      if (!cell || !roster.contains(cell)) return;

      highlightUser(roster, cell.dataset.userId || "");
    });

    roster.addEventListener("mouseleave", () => {
      clearRoster(roster);
    });
  });
})();
