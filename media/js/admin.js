/**
 * mod_pposmap — obsługa przycisku „Usuń wszystkie punkty” w panelu administratora.
 *
 * Kasowanie dzieje się wyłącznie na wierszach formularza. Nic nie idzie do bazy,
 * dopóki administrator nie kliknie „Zapisz”, więc wyjście z modułu bez zapisu
 * cofa operację w całości. Do tego dochodzi pytanie potwierdzające z liczbą
 * punktów oraz przycisk „Cofnij”, który wstawia wiersze z powrotem na ich
 * pierwotne miejsca.
 */
(() => {
  "use strict";

  const SUBFORM_TAG = "joomla-field-subform";

  /*
   * Custom element subformu nie dostaje atrybutu id, tylko name w postaci
   * jform[params][listofpoints] — stąd dopasowanie po końcówce nazwy.
   */
  const findSubform = (root, target) => {
    const suffix = `[${target}]`;

    for (const element of root.querySelectorAll(SUBFORM_TAG)) {
      const name = element.getAttribute("name") || "";

      if (name === target || name.endsWith(suffix)) {
        return element;
      }
    }

    return null;
  };

  const countRows = (subform) =>
    typeof subform.getRows === "function" ? subform.getRows().length : 0;

  /*
   * Joomla czyta pliki .ini w trybie INI_SCANNER_RAW, więc sekwencja \n zostaje
   * w napisie dosłownie, jako dwa znaki. Bez tej zamiany okno potwierdzenia
   * pokazałoby „\n” zamiast przejść do nowego akapitu.
   */
  const format = (template, count) =>
    String(template).replace(/\\n/g, "\n").replace("%d", count);

  const initWidget = (widget) => {
    if (widget.dataset.pposmapClearReady === "1") return;
    widget.dataset.pposmapClearReady = "1";

    let strings = {};

    try {
      strings = JSON.parse(widget.dataset.strings || "{}");
    } catch (e) {
      strings = {};
    }

    const actionButton = widget.querySelector("[data-pposmap-clear-action]");
    const undoButton = widget.querySelector("[data-pposmap-clear-undo]");
    const status = widget.querySelector("[data-pposmap-clear-status]");
    const form = widget.closest("form") || document;
    const subform = findSubform(form, widget.dataset.target || "listofpoints");

    if (!subform) {
      // Brak subformu oznacza zmianę w strukturze formularza, a nie błąd użytkownika.
      actionButton.disabled = true;
      status.textContent = strings.missing || "";
      return;
    }

    // Wiersze zdjęte z formularza, razem z miejscem, z którego pochodzą.
    let removed = [];

    const refresh = () => {
      const count = countRows(subform);

      actionButton.disabled = count === 0;
      actionButton.setAttribute("aria-disabled", count === 0 ? "true" : "false");

      // Ostatni węzeł przycisku to tekst za ikoną — podmieniamy sam tekst,
      // żeby nie zgubić elementu <span> z ikoną.
      const label = actionButton.lastChild;

      if (label && label.nodeType === Node.TEXT_NODE) {
        label.textContent = ` ${strings.button || ""}${count ? ` (${count})` : ""}`;
      }
    };

    const showUndo = (visible) => {
      undoButton.classList.toggle("d-none", !visible);
    };

    actionButton.addEventListener("click", () => {
      const rows = typeof subform.getRows === "function" ? subform.getRows() : [];

      if (!rows.length) {
        status.textContent = strings.empty || "";
        return;
      }

      if (!window.confirm(format(strings.confirm || "", rows.length))) {
        return;
      }

      removed = [];

      for (const row of rows) {
        // Zapamiętujemy sąsiada, żeby „Cofnij” odtworzyło także kolejność.
        const parent = row.parentNode;
        const nextSibling = row.nextSibling;

        subform.removeRow(row);

        /*
         * removeRow odmawia zejścia poniżej atrybutu minimum, więc nie zakładamy,
         * że każdy wiersz faktycznie zniknął — liczymy tylko te odłączone.
         */
        if (!row.isConnected) {
          removed.push({ row, parent, nextSibling });
        }
      }

      status.textContent = format(strings.done || "", removed.length);
      showUndo(removed.length > 0);
      refresh();
    });

    undoButton.addEventListener("click", () => {
      // Od końca, bo nextSibling każdego wiersza wraca na miejsce dopiero
      // wtedy, gdy stoi już tam element, przed którym ma zostać wstawiony.
      for (let i = removed.length - 1; i >= 0; i--) {
        const { row, parent, nextSibling } = removed[i];
        parent.insertBefore(row, nextSibling);
      }

      status.textContent = format(strings.restored || "", removed.length);
      removed = [];
      showUndo(false);
      refresh();
    });

    /*
     * Dodanie albo skasowanie wiersza ręcznie ma odświeżyć licznik na przycisku.
     * Oba zdarzenia lecą, zanim DOM się ustabilizuje (removeRow wysyła je jeszcze
     * przed odpięciem wiersza), więc liczymy dopiero w następnym obrocie pętli.
     */
    const refreshLater = () => window.setTimeout(refresh, 0);

    subform.addEventListener("subform-row-add", refreshLater);
    subform.addEventListener("subform-row-remove", refreshLater);

    refresh();
  };

  const initAll = () => {
    document.querySelectorAll("[data-pposmap-clear]").forEach(initWidget);
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initAll, { once: true });
  } else {
    initAll();
  }
})();
