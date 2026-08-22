document.addEventListener("DOMContentLoaded", () => {
  const csrfToken =
    window.SECTION_SCHEDULE?.csrfToken || "";

  const editors =
    document.querySelectorAll(
      ".activity-point-editor"
    );

  const saveEditor =
    async (editor) => {

      if (
        editor.dataset.saving === "1"
      ) {
        return;
      }

      const input =
        editor.querySelector(
          ".activity-point-input"
        );

      const selected =
        editor.querySelector(
          ".activity-point-type-button.selected"
        );

      const rawValue =
        input?.value?.trim() || "0";

      if (
        rawValue === ""
        ||
        Number.isNaN(
          Number(rawValue)
        )
      ) {
        alert(
          "Point value must be a number."
        );

        return;
      }

      const numericValue =
        Number(rawValue);

      if (
        numericValue < 0
        ||
        numericValue > 9999
      ) {
        alert(
          "Point value must be between 0 and 9999."
        );

        return;
      }

      editor.dataset.saving = "1";
      editor.classList.add(
        "is-saving"
      );
      editor.classList.remove(
        "is-error"
      );

      const body =
        new FormData();

      body.append(
        "csrf_token",
        csrfToken
      );

      body.append(
        "source_type",
        editor.dataset.pointSource
      );

      body.append(
        "source_id",
        editor.dataset.pointId
      );

      body.append(
        "point_value",
        String(numericValue)
      );

      body.append(
        "point_type",
        selected?.dataset.pointType || ""
      );

      try {

        const response =
          await fetch(
            "ajax/update-activity-points.php",
            {
              method: "POST",
              body,
            }
          );

        const result =
          await response.json();

        if (
          !response.ok
          ||
          !result.success
        ) {
          throw new Error(
            result.message
            ||
            "Could not save point settings."
          );
        }

        input.value =
          String(
            result.point_value
          );

        editor.dataset.pointType =
          result.point_type || "";

      } catch (error) {

        editor.classList.add(
          "is-error"
        );

        alert(
          error.message
        );

      } finally {

        editor.dataset.saving = "0";

        editor.classList.remove(
          "is-saving"
        );
      }
    };

  editors.forEach((editor) => {

    const input =
      editor.querySelector(
        ".activity-point-input"
      );

    let lastSavedValue =
      input?.value || "0";

    if (input) {

      input.addEventListener(
        "click",
        (event) => {
          event.stopPropagation();
        }
      );

      input.addEventListener(
        "contextmenu",
        (event) => {
          event.stopPropagation();
        }
      );

      input.addEventListener(
        "change",
        async () => {

          await saveEditor(
            editor
          );

          lastSavedValue =
            input.value;
        }
      );

      input.addEventListener(
        "keydown",
        (event) => {

          if (
            event.key === "Enter"
          ) {
            event.preventDefault();
            input.blur();
          }

          if (
            event.key === "Escape"
          ) {
            event.preventDefault();

            input.value =
              lastSavedValue;

            input.blur();
          }
        }
      );
    }

    editor
      .querySelectorAll(
        ".activity-point-type-button"
      )
      .forEach((button) => {

        button.addEventListener(
          "click",
          async (event) => {

            event.preventDefault();
            event.stopPropagation();

            if (
              !document.body.classList.contains(
                "editing-mode"
              )
            ) {
              return;
            }

            editor
              .querySelectorAll(
                ".activity-point-type-button"
              )
              .forEach(
                (other) =>
                  other.classList.remove(
                    "selected"
                  )
              );

            button.classList.add(
              "selected"
            );

            await saveEditor(
              editor
            );
          }
        );

        button.addEventListener(
          "contextmenu",
          (event) => {
            event.stopPropagation();
          }
        );
      });
  });
});
