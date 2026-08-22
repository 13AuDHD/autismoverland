/* =========================================================
   LLAMA SCOUT
   PUBLIC PLACE PROVENANCE

   Replaces the legacy verification presentation with the
   contribution-history based provenance model.

   ========================================================= */


document.addEventListener(
  "DOMContentLoaded",
  initPlaceProvenance
);


/* =========================================================
   INITIALIZE
   ========================================================= */

async function initPlaceProvenance() {

  const params =
    new URLSearchParams(
      window.location.search
    );


  const requestedPlace =
    params.get(
      "place"
    );


  if (!requestedPlace) {
    return;
  }


  try {

    const response =
      await fetch(
        "/api/place-provenance.php?place="
        +
        encodeURIComponent(
          requestedPlace
        ),
        {
          credentials: "include",
          cache: "no-store"
        }
      );


    if (!response.ok) {
      return;
    }


    const data =
      await response.json();


    if (
      !data
      ||
      !data.provenance
    ) {

      return;
    }


    await waitForPlaceRender();


    removeLegacyVerification();


    renderPublicProvenance(
      data
    );


  } catch (error) {

    console.error(
      "Llama Scout provenance error:",
      error
    );

  }

}


/* =========================================================
   WAIT FOR js/place.js

   place.js loads the Place asynchronously.

   MutationObserver allows this script to wait until the hero
   and sidebar exist without using arbitrary timers.
   ========================================================= */

function waitForPlaceRender() {

  return new Promise(
    (resolve) => {

      const ready =
        () =>
          document.querySelector(
            ".place-hero-content .container"
          )
          &&
          document.querySelector(
            ".place-sidebar"
          );


      if (ready()) {

        resolve();

        return;
      }


      const observer =
        new MutationObserver(
          () => {

            if (!ready()) {
              return;
            }


            observer.disconnect();

            resolve();

          }
        );


      const page =
        document.getElementById(
          "place-page"
        );


      if (!page) {

        observer.disconnect();

        resolve();

        return;
      }


      observer.observe(
        page,
        {
          childList: true,
          subtree: true
        }
      );

    }
  );

}


/* =========================================================
   REMOVE LEGACY VERIFICATION UI
   ========================================================= */

function removeLegacyVerification() {

  document
    .querySelectorAll(
      ".place-verified"
    )
    .forEach(
      (element) =>
        element.remove()
    );


  document
    .querySelectorAll(
      ".place-sidebar-card"
    )
    .forEach(
      (card) => {

        const heading =
          card.querySelector(
            "h2"
          );


        if (
          heading
          &&
          heading.textContent
            .trim()
            .toLowerCase()
          ===
          "verification"
        ) {

          card.remove();

        }

      }
    );

}


/* =========================================================
   MAIN RENDER
   ========================================================= */

function renderPublicProvenance(
  data
) {

  const provenance =
    data.provenance;


  const contributions =
    Array.isArray(
      data.contributions
    )
      ? data.contributions
      : [];


  renderProvenanceBadge(
    provenance
  );


  renderProvenanceCard(
    provenance,
    contributions
  );

}


/* =========================================================
   HERO BADGE
   ========================================================= */

function renderProvenanceBadge(
  provenance
) {

  const hero =
    document.querySelector(
      ".place-hero-content .container"
    );


  if (!hero) {
    return;
  }


  const saveArea =
    hero.querySelector(
      ".place-save-area"
    );


  const isScouted =
    provenance.status
    ===
    "llama-scouted";


  const badge =
    document.createElement(
      "span"
    );


  badge.className =
    "place-provenance-badge "
    +
    (
      isScouted
        ? "place-provenance-badge--scouted"
        : "place-provenance-badge--community"
    );


  badge.innerHTML = `

    <i
      class="fa-solid ${
        isScouted
          ? "fa-binoculars"
          : "fa-people-group"
      }"
      aria-hidden="true"
    ></i>

    ${escapeProvenanceHTML(
      provenance.label
      ||
      (
        isScouted
          ? "Llama Scouted"
          : "Community Contributed"
      )
    )}

  `;


  if (saveArea) {

    saveArea.before(
      badge
    );

  } else {

    hero.appendChild(
      badge
    );

  }

}


/* =========================================================
   SIDEBAR CARD
   ========================================================= */

function renderProvenanceCard(
  provenance,
  contributions
) {

  const sidebar =
    document.querySelector(
      ".place-sidebar"
    );


  if (!sidebar) {
    return;
  }


  const card =
    document.createElement(
      "div"
    );


  card.className =
    "place-sidebar-card place-provenance-card";


  const isScouted =
    provenance.status
    ===
    "llama-scouted";


  const lastScouted =
    formatProvenanceDate(
      provenance.lastScoutedAt
    );


  const established =
    formatProvenanceDate(
      provenance.establishedAt
    );


  card.innerHTML = `

    <h2>
      Place History
    </h2>


    <p
      class="
        place-provenance-status
        ${
          isScouted
            ? "is-scouted"
            : "is-community"
        }
      "
    >

      <i
        class="fa-solid ${
          isScouted
            ? "fa-binoculars"
            : "fa-people-group"
        }"
        aria-hidden="true"
      ></i>

      <strong>
        ${escapeProvenanceHTML(
          provenance.label
        )}
      </strong>

    </p>


    <div class="place-provenance-summary">

      ${
        provenance.originLabel
          ? provenanceFact(
              "Originally contributed by",
              provenance.originLabel
            )
          : ""
      }


      ${
        established
          ? provenanceFact(
              "Added",
              established
            )
          : ""
      }


      ${
        lastScouted
          ? provenanceFact(
              "Last Llama Scouted",
              lastScouted
            )
          : ""
      }

    </div>


    <p class="place-provenance-explainer">

      ${
        isScouted
          ? `
            A Llama Scout, Master Scout, Admin, or Owner has
            personally visited this place and contributed
            field information.
          `
          : `
            This place currently comes from community
            contribution history and does not yet have a
            qualifying Llama Scout field visit on record.
          `
      }

    </p>


    ${
      renderContributionHistory(
        contributions
      )
    }

  `;


  sidebar.appendChild(
    card
  );

}


/* =========================================================
   CONTRIBUTION HISTORY
   ========================================================= */

function renderContributionHistory(
  contributions
) {

  if (
    !Array.isArray(
      contributions
    )
    ||
    !contributions.length
  ) {

    return `
      <p class="place-provenance-empty">
        No public contribution history is available yet.
      </p>
    `;

  }


  const rows =
    contributions
      .map(
        (contribution) => {

          const date =
            formatProvenanceDate(
              contribution.visitedAt
              ||
              contribution.approvedAt
              ||
              contribution.submittedAt
            );


          const visitLabel =
            contribution.visitedAt
              ? "Visited "
              : "";


          const username =
            contribution.username
              ? `
                <span class="place-contributor-username">
                  @${escapeProvenanceHTML(
                    contribution.username
                  )}
                </span>
              `
              : "";


          return `

            <li class="place-contribution-item">

              <div class="place-contribution-person">

                <strong>
                  ${escapeProvenanceHTML(
                    contribution.name
                    ||
                    "Llama Scout contributor"
                  )}
                </strong>

                ${username}

              </div>


              <div class="place-contribution-meta">

                <span>
                  ${escapeProvenanceHTML(
                    contribution.roleLabel
                    ||
                    "Community Member"
                  )}
                </span>

                <span aria-hidden="true">
                  ·
                </span>

                <span>
                  ${escapeProvenanceHTML(
                    contribution.typeLabel
                    ||
                    "Contributed information"
                  )}
                </span>

              </div>


              ${
                date
                  ? `
                    <div class="place-contribution-date">
                      ${escapeProvenanceHTML(
                        visitLabel
                        +
                        date
                      )}
                    </div>
                  `
                  : ""
              }

            </li>

          `;

        }
      )
      .join("");


  return `

    <div class="place-contribution-history">

      <h3>
        Contributors
      </h3>

      <ul>
        ${rows}
      </ul>

    </div>

  `;

}


/* =========================================================
   FACT
   ========================================================= */

function provenanceFact(
  label,
  value
) {

  if (
    value === null
    ||
    value === undefined
    ||
    String(
      value
    ).trim() === ""
  ) {

    return "";

  }


  return `

    <div class="place-fact">

      <span>
        ${escapeProvenanceHTML(
          label
        )}
      </span>

      <strong>
        ${escapeProvenanceHTML(
          value
        )}
      </strong>

    </div>

  `;

}


/* =========================================================
   DATE
   ========================================================= */

function formatProvenanceDate(
  value
) {

  if (!value) {
    return null;
  }


  const normalized =
    String(
      value
    )
      .replace(
        " ",
        "T"
      );


  const date =
    new Date(
      normalized
    );


  if (
    Number.isNaN(
      date.getTime()
    )
  ) {

    return String(
      value
    );

  }


  return date.toLocaleDateString(
    undefined,
    {
      year: "numeric",
      month: "short",
      day: "numeric"
    }
  );

}


/* =========================================================
   ESCAPE HTML
   ========================================================= */

function escapeProvenanceHTML(
  value
) {

  return String(
    value
    ??
    ""
  )
    .replaceAll(
      "&",
      "&amp;"
    )
    .replaceAll(
      "<",
      "&lt;"
    )
    .replaceAll(
      ">",
      "&gt;"
    )
    .replaceAll(
      "\"",
      "&quot;"
    )
    .replaceAll(
      "'",
      "&#039;"
    );

}
