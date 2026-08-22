/* =========================================================
   LLAMA SCOUT
   PUBLIC PLACE CONTRIBUTION LINKS
   ========================================================= */


document.addEventListener(
  "DOMContentLoaded",
  initPlaceContributionLinks
);


async function initPlaceContributionLinks() {

  const params =
    new URLSearchParams(
      window.location.search
    );


  const place =
    params.get(
      "place"
    );


  if (!place) {
    return;
  }


  const sidebar =
    await waitForContributionSidebar();


  if (
    !sidebar
    ||
    sidebar.querySelector(
      "[data-place-contribute-card]"
    )
  ) {

    return;
  }


  const card =
    document.createElement(
      "div"
    );


  card.className =
    "place-sidebar-card";


  card.setAttribute(
    "data-place-contribute-card",
    "true"
  );


  const encoded =
    encodeURIComponent(
      place
    );


  card.innerHTML = `

    <h2>
      Help Keep This Place Current
    </h2>

    <p
      style="
        margin:0 0 14px;
        font-size:.84rem;
        line-height:1.55;
        opacity:.72;
      "
    >
      Visited recently or spotted information that needs
      correcting? Submit specific changes for review.
    </p>

    <div
      style="
        display:grid;
        gap:9px;
      "
    >

      <a
        href="/account/update-place.php?place=${encoded}"
        class="place-contribution-link"
        style="
          display:flex;
          align-items:center;
          gap:8px;
          padding:10px 12px;
          border-radius:9px;
          background:#172822;
          color:#fff;
          text-decoration:none;
          font-weight:750;
        "
      >

        <i
          class="fa-solid fa-pen-to-square"
          aria-hidden="true"
        ></i>

        Update this Place

      </a>

      <a
        href="/account/report-place.php?place=${encoded}"
        class="place-contribution-link"
        style="
          display:flex;
          align-items:center;
          gap:8px;
          padding:10px 12px;
          border:1px solid rgba(23,40,34,.14);
          border-radius:9px;
          color:inherit;
          text-decoration:none;
          font-weight:700;
        "
      >

        <i
          class="fa-solid fa-triangle-exclamation"
          aria-hidden="true"
        ></i>

        Report a Problem

      </a>

    </div>

  `;


  sidebar.appendChild(
    card
  );

}


function waitForContributionSidebar() {

  return new Promise(
    (resolve) => {

      const existing =
        document.querySelector(
          ".place-sidebar"
        );


      if (existing) {

        resolve(
          existing
        );

        return;
      }


      const page =
        document.getElementById(
          "place-page"
        );


      if (!page) {

        resolve(
          null
        );

        return;
      }


      const observer =
        new MutationObserver(
          () => {

            const sidebar =
              document.querySelector(
                ".place-sidebar"
              );


            if (!sidebar) {
              return;
            }


            observer.disconnect();

            resolve(
              sidebar
            );

          }
        );


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
