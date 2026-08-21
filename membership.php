<?php

declare(strict_types=1);

?>

<!doctype html>

<html lang="en">

<head>

  <meta charset="utf-8">

  <meta
    name="viewport"
    content="width=device-width, initial-scale=1"
  >

  <title>
    Membership | Llama Scout
  </title>

  <meta
    name="description"
    content="Compare Llama Scout access levels. Browse for free, create a free member account for public planning information and photos, or unlock complete place reports with a paid membership."
  >


  <script src="/js/privacy.js"></script>


  <link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
  >

  <link
    rel="stylesheet"
    href="/css/style.css"
  >


  <link
    rel="apple-touch-icon"
    sizes="180x180"
    href="/icons/apple-touch-icon.png"
  >

  <link
    rel="icon"
    type="image/png"
    sizes="32x32"
    href="/icons/favicon-32x32.png"
  >

  <link
    rel="icon"
    type="image/png"
    sizes="16x16"
    href="/icons/favicon-16x16.png"
  >

  <link
    rel="icon"
    href="/icons/favicon.ico"
    sizes="any"
  >

  <link
    rel="manifest"
    href="/icons/site.webmanifest"
  >


  <style>

    /* =====================================================
       PUBLIC MEMBERSHIP PAGE
       ===================================================== */

    .public-membership-page {
      min-height: 100vh;

      background: #f6f3ec;
    }


    .public-membership-container {
      width:
        min(
          1120px,
          calc(100% - 48px)
        );

      margin: 0 auto;
    }


    /* =====================================================
       HERO
       ===================================================== */

    .public-membership-hero {
      padding:
        72px
        0
        56px;
    }


    .public-membership-eyebrow {
      margin:
        0
        0
        12px;

      color: #5c665f;

      font-size: .76rem;

      font-weight: 800;

      letter-spacing: .1em;

      text-transform: uppercase;
    }


    .public-membership-hero h1 {
      max-width: 820px;

      margin:
        0
        0
        22px;

      font-family:
        "Libre Baskerville",
        Georgia,
        serif;

      color: #203127;

      font-size:
        clamp(
          2.8rem,
          7vw,
          5.2rem
        );

      line-height: .98;

      letter-spacing: -.045em;
    }


    .public-membership-lede {
      max-width: 760px;

      margin: 0;

      color: #3f4943;

      font-size: 1.08rem;

      line-height: 1.75;
    }


    .public-membership-transparency {
      max-width: 800px;

      margin-top: 30px;

      padding:
        20px
        22px;

      display: grid;

      grid-template-columns:
        auto
        1fr;

      gap: 14px;

      border:
        1px solid
        rgba(32, 49, 39, .12);

      border-radius: 12px;

      background:
        rgba(255, 255, 255, .62);
    }


    .public-membership-transparency i {
      margin-top: 3px;

      color: #2f819e;

      font-size: 1.1rem;
    }


    .public-membership-transparency p {
      margin: 0;

      line-height: 1.65;
    }


    /* =====================================================
       PLAN CARDS
       ===================================================== */

    .public-membership-plans-section {
      padding:
        0
        0
        72px;
    }


    .public-membership-plan-grid {
      display: grid;

      grid-template-columns:
        repeat(
          3,
          minmax(0, 1fr)
        );

      gap: 18px;
    }


    .public-membership-plan {
      position: relative;

      display: flex;

      flex-direction: column;

      min-width: 0;

      padding:
        28px
        24px;

      border:
        1px solid
        rgba(31, 39, 35, .12);

      border-radius: 16px;

      background:
        rgba(255, 255, 255, .74);

      box-shadow:
        0 10px 28px
        rgba(41, 49, 44, .08);
    }


    .public-membership-plan-paid {
      border-color:
        rgba(47, 129, 158, .42);

      background:
        rgba(255, 255, 255, .92);

      box-shadow:
        0 16px 36px
        rgba(34, 102, 127, .14);
    }


    .public-membership-badge {
      width: fit-content;

      margin-bottom: 16px;

      padding:
        6px
        9px;

      border-radius: 999px;

      background: #172822;

      color: #fff;

      font-size: .7rem;

      font-weight: 800;

      letter-spacing: .05em;

      text-transform: uppercase;
    }


    .public-membership-plan-paid
    .public-membership-badge {
      background: #2f819e;
    }


    .public-membership-plan h2 {
      margin:
        0
        0
        8px;

      font-size: 1.55rem;
    }


    .public-membership-plan-price {
      margin:
        0
        0
        18px;

      font-size: 1rem;

      font-weight: 800;
    }


    .public-membership-plan-price strong {
      font-size: 2rem;
    }


    .public-membership-plan-price span {
      color: #5c665f;

      font-size: .85rem;

      font-weight: 500;
    }


    .public-membership-plan-description {
      margin:
        0
        0
        22px;

      color: #535d56;

      line-height: 1.6;
    }


    .public-membership-plan ul {
      margin:
        0
        0
        26px;

      padding: 0;

      display: grid;

      gap: 12px;

      list-style: none;
    }


    .public-membership-plan li {
      display: grid;

      grid-template-columns:
        20px
        1fr;

      gap: 9px;

      align-items: start;

      line-height: 1.45;
    }


    .public-membership-plan li i {
      margin-top: 3px;

      color: #2f819e;
    }


    .public-membership-plan li
    .fa-xmark {
      color: #8d928e;
    }


    .public-membership-plan-action {
      margin-top: auto;
    }


    .public-membership-plan-action a {
      width: 100%;

      min-height: 48px;

      display: inline-flex;

      align-items: center;

      justify-content: center;

      gap: 8px;

      padding:
        12px
        16px;

      border:
        1px solid
        #172822;

      border-radius: 8px;

      color: #172822;

      font-weight: 800;
    }


    .public-membership-plan-paid
    .public-membership-plan-action a {
      border-color: #22667f;

      background:
        linear-gradient(
          180deg,
          #2f819e,
          #22667f
        );

      color: #fff;
    }


    /* =====================================================
       PRINCIPLE
       ===================================================== */

    .public-membership-principle {
      padding:
        58px
        0;

      background: #21352f;

      color: #f6f3ec;
    }


    .public-membership-principle-inner {
      max-width: 820px;
    }


    .public-membership-principle
    .public-membership-eyebrow {
      color:
        rgba(246, 243, 236, .62);
    }


    .public-membership-principle h2 {
      margin:
        0
        0
        18px;

      font-family:
        "Libre Baskerville",
        Georgia,
        serif;

      font-size:
        clamp(
          2rem,
          5vw,
          3.4rem
        );

      line-height: 1.05;
    }


    .public-membership-principle p {
      max-width: 760px;

      margin:
        0
        0
        16px;

      color:
        rgba(246, 243, 236, .84);

      font-size: 1.02rem;

      line-height: 1.75;
    }


    .public-membership-principle strong {
      color: #fff;
    }


    /* =====================================================
       COMPARISON TABLE
       ===================================================== */

    .public-membership-comparison-section {
      padding:
        72px
        0;
    }


    .public-membership-section-heading {
      max-width: 760px;

      margin-bottom: 30px;
    }


    .public-membership-section-heading h2 {
      margin:
        0
        0
        12px;

      font-family:
        "Libre Baskerville",
        Georgia,
        serif;

      color: #203127;

      font-size:
        clamp(
          2rem,
          5vw,
          3rem
        );

      line-height: 1.08;
    }


    .public-membership-section-heading p {
      margin: 0;

      color: #59625c;

      line-height: 1.65;
    }


    .public-membership-table-wrap {
      overflow-x: auto;

      border:
        1px solid
        rgba(31, 39, 35, .12);

      border-radius: 14px;

      background:
        rgba(255, 255, 255, .72);
    }


    .public-membership-table {
      width: 100%;

      min-width: 720px;

      border-collapse: collapse;
    }


    .public-membership-table th,
    .public-membership-table td {
      padding:
        15px
        16px;

      border-bottom:
        1px solid
        rgba(31, 39, 35, .09);

      text-align: left;

      vertical-align: middle;
    }


    .public-membership-table th {
      background:
        rgba(23, 40, 34, .05);

      font-size: .84rem;

      font-weight: 800;
    }


    .public-membership-table th:first-child {
      width: 34%;
    }


    .public-membership-table td {
      font-size: .9rem;

      line-height: 1.4;
    }


    .public-membership-table
    tbody
    tr:last-child
    td {
      border-bottom: 0;
    }


    .membership-yes {
      color: #245b45;

      font-weight: 750;
    }


    .membership-no {
      color: #767d78;
    }


    .membership-paid {
      color: #22667f;

      font-weight: 800;
    }


    /* =====================================================
       PRICING
       ===================================================== */

    .public-membership-pricing {
      padding:
        0
        0
        72px;
    }


    .public-membership-pricing-grid {
      display: grid;

      grid-template-columns:
        repeat(
          2,
          minmax(0, 1fr)
        );

      gap: 18px;

      max-width: 820px;
    }


    .public-membership-price-card {
      padding:
        26px;

      border:
        1px solid
        rgba(31, 39, 35, .13);

      border-radius: 14px;

      background:
        rgba(255, 255, 255, .78);
    }


    .public-membership-price-card h3 {
      margin:
        0
        0
        8px;
    }


    .public-membership-big-price {
      margin:
        0
        0
        14px;

      color: #203127;

      font-size: 2.15rem;

      font-weight: 850;
    }


    .public-membership-big-price span {
      color: #667069;

      font-size: .9rem;

      font-weight: 500;
    }


    .public-membership-price-card p {
      margin:
        0
        0
        20px;

      color: #59625c;

      line-height: 1.6;
    }


    .public-membership-price-card a {
      display: inline-flex;

      align-items: center;

      justify-content: center;

      gap: 8px;

      min-height: 46px;

      padding:
        11px
        17px;

      border-radius: 8px;

      background: #172822;

      color: #fff;

      font-weight: 800;
    }


    /* =====================================================
       FAQ
       ===================================================== */

    .public-membership-faq {
      padding:
        0
        0
        80px;
    }


    .public-membership-faq-list {
      max-width: 860px;
    }


    .public-membership-faq details {
      padding:
        20px
        0;

      border-top:
        1px solid
        rgba(31, 39, 35, .12);
    }


    .public-membership-faq
    details:last-child {
      border-bottom:
        1px solid
        rgba(31, 39, 35, .12);
    }


    .public-membership-faq summary {
      cursor: pointer;

      font-weight: 800;

      line-height: 1.45;
    }


    .public-membership-faq details p {
      max-width: 760px;

      margin:
        14px
        0
        0;

      color: #59625c;

      line-height: 1.7;
    }


    /* =====================================================
       RESPONSIVE
       ===================================================== */

    @media (max-width: 850px) {

      .public-membership-plan-grid {
        grid-template-columns: 1fr;
      }


      .public-membership-plan {
        padding:
          24px
          22px;
      }


      .public-membership-pricing-grid {
        grid-template-columns: 1fr;
      }

    }


    @media (max-width: 600px) {

      .public-membership-container {
        width:
          calc(100% - 32px);
      }


      .public-membership-hero {
        padding:
          48px
          0
          40px;
      }


      .public-membership-transparency {
        grid-template-columns: 1fr;

        padding: 18px;
      }


      .public-membership-comparison-section {
        padding:
          52px
          0;
      }


      .public-membership-principle {
        padding:
          48px
          0;
      }

    }

  </style>

</head>


<body>


<?php

require_once
    __DIR__
    . '/app/header.php';

?>


<main class="public-membership-page">


  <!-- =====================================================
       HERO
       ===================================================== -->

  <section class="public-membership-hero">


    <div class="public-membership-container">


      <p class="public-membership-eyebrow">
        Llama Scout Membership
      </p>


      <h1>
        Know what you get before you pay for anything.
      </h1>


      <p class="public-membership-lede">
        Llama Scout is free to explore, and creating a free
        member account unlocks additional public planning
        information and place photos. Paid membership unlocks
        the complete place report, including the information
        you need to actually find, evaluate, and plan for a
        specific location.
      </p>


      <div class="public-membership-transparency">


        <i
          class="fa-solid fa-circle-info"
          aria-hidden="true"
        ></i>


        <p>
          We do not want the membership model to be a surprise.
          Some Llama Scout information is intentionally reserved
          for paid members. The comparison below shows exactly
          what each level of access includes.
        </p>


      </div>


    </div>


  </section>


  <!-- =====================================================
       THREE ACCESS LEVELS
       ===================================================== -->

  <section class="public-membership-plans-section">


    <div class="public-membership-container">


      <div class="public-membership-plan-grid">


        <!-- FREE -->

        <article class="public-membership-plan">


          <span class="public-membership-badge">
            Free
          </span>


          <h2>
            Free
          </h2>


          <p class="public-membership-plan-price">
            <strong>$0</strong>
          </p>


          <p class="public-membership-plan-description">
            A basic preview of places available through
            Llama Scout.
          </p>


          <ul>


            <li>

              <i
                class="fa-solid fa-check"
                aria-hidden="true"
              ></i>

              <span>
                Browse public place listings
              </span>

            </li>


            <li>

              <i
                class="fa-solid fa-check"
                aria-hidden="true"
              ></i>

              <span>
                Limited basic place information
              </span>

            </li>


            <li>

              <i
                class="fa-solid fa-check"
                aria-hidden="true"
              ></i>

              <span>
                Approximate general area
              </span>

            </li>


            <li>

              <i
                class="fa-solid fa-check"
                aria-hidden="true"
              ></i>

              <span>
                Main header image
              </span>

            </li>


            <li>

              <i
                class="fa-solid fa-xmark"
                aria-hidden="true"
              ></i>

              <span>
                No exact coordinates or identifying location
              </span>

            </li>


            <li>

              <i
                class="fa-solid fa-xmark"
                aria-hidden="true"
              ></i>

              <span>
                No sensory, road, or connectivity details
              </span>

            </li>


          </ul>


          <div class="public-membership-plan-action">

            <a href="/places.php">

              Browse Places

              <i
                class="fa-solid fa-arrow-right"
                aria-hidden="true"
              ></i>

            </a>

          </div>


        </article>


        <!-- MEMBER -->

        <article class="public-membership-plan">


          <span class="public-membership-badge">
            Free Account
          </span>


          <h2>
            Member
          </h2>


          <p class="public-membership-plan-price">
            <strong>$0</strong>
          </p>


          <p class="public-membership-plan-description">
            More context using public information, plus
            account tools and the complete photo gallery.
          </p>


          <ul>


            <li>

              <i
                class="fa-solid fa-check"
                aria-hidden="true"
              ></i>

              <span>
                Everything available to Free visitors
              </span>

            </li>


            <li>

              <i
                class="fa-solid fa-check"
                aria-hidden="true"
              ></i>

              <span>
                Full place photo gallery
              </span>

            </li>


            <li>

              <i
                class="fa-solid fa-check"
                aria-hidden="true"
              ></i>

              <span>
                Land manager and managing agency information
              </span>

            </li>


            <li>

              <i
                class="fa-solid fa-check"
                aria-hidden="true"
              ></i>

              <span>
                Ranger district, county, and regional information
              </span>

            </li>


            <li>

              <i
                class="fa-solid fa-check"
                aria-hidden="true"
              ></i>

              <span>
                Public restrictions and regulations
              </span>

            </li>


            <li>

              <i
                class="fa-solid fa-check"
                aria-hidden="true"
              ></i>

              <span>
                Weather and other publicly available planning data
              </span>

            </li>


            <li>

              <i
                class="fa-solid fa-check"
                aria-hidden="true"
              ></i>

              <span>
                Limited public warnings
              </span>

            </li>


            <li>

              <i
                class="fa-solid fa-check"
                aria-hidden="true"
              ></i>

              <span>
                Save places to your account
              </span>

            </li>


            <li>

              <i
                class="fa-solid fa-xmark"
                aria-hidden="true"
              ></i>

              <span>
                No exact location or coordinates
              </span>

            </li>


            <li>

              <i
                class="fa-solid fa-xmark"
                aria-hidden="true"
              ></i>

              <span>
                No information describing what the site
                itself is like
              </span>

            </li>


          </ul>


          <div class="public-membership-plan-action">

            <a
              href="https://account.llamascout.com/register.php"
            >

              Create Free Account

              <i
                class="fa-solid fa-arrow-right"
                aria-hidden="true"
              ></i>

            </a>

          </div>


        </article>


        <!-- PAID MEMBER -->

        <article
          class="
            public-membership-plan
            public-membership-plan-paid
          "
        >


          <span class="public-membership-badge">
            Full Access
          </span>


          <h2>
            Paid Member
          </h2>


          <p class="public-membership-plan-price">

            <strong>
              $6.99
            </strong>

            <span>
              / month
            </span>

          </p>


          <p class="public-membership-plan-description">
            The complete Llama Scout place report, including
            field-based information that cannot simply be
            looked up online.
          </p>


          <ul>


            <li>

              <i
                class="fa-solid fa-check"
                aria-hidden="true"
              ></i>

              <span>
                Everything included with a free Member account
              </span>

            </li>


            <li>

              <i
                class="fa-solid fa-location-crosshairs"
                aria-hidden="true"
              ></i>

              <span>
                Exact location and coordinates
              </span>

            </li>


            <li>

              <i
                class="fa-solid fa-brain"
                aria-hidden="true"
              ></i>

              <span>
                Complete sensory information
              </span>

            </li>


            <li>

              <i
                class="fa-solid fa-road"
                aria-hidden="true"
              ></i>

              <span>
                Road conditions and access details
              </span>

            </li>


            <li>

              <i
                class="fa-solid fa-car"
                aria-hidden="true"
              ></i>

              <span>
                Vehicle suitability and site access information
              </span>

            </li>


            <li>

              <i
                class="fa-solid fa-signal"
                aria-hidden="true"
              ></i>

              <span>
                Cell service, connectivity, and Starlink information
              </span>

            </li>


            <li>

              <i
                class="fa-solid fa-eye"
                aria-hidden="true"
              ></i>

              <span>
                Privacy, crowds, noise, and activity details
              </span>

            </li>


            <li>

              <i
                class="fa-solid fa-triangle-exclamation"
                aria-hidden="true"
              ></i>

              <span>
                Complete warnings and site-specific concerns
              </span>

            </li>


            <li>

              <i
                class="fa-solid fa-check"
                aria-hidden="true"
              ></i>

              <span>
                Complete planning information for the place
              </span>

            </li>


          </ul>


          <div class="public-membership-plan-action">

            <a
              href="https://account.llamascout.com/membership.php"
            >

              Get Full Access

              <i
                class="fa-solid fa-arrow-right"
                aria-hidden="true"
              ></i>

            </a>

          </div>


        </article>


      </div>


    </div>


  </section>


  <!-- =====================================================
       WHY THE DIFFERENCE EXISTS
       ===================================================== -->

  <section class="public-membership-principle">


    <div class="public-membership-container">


      <div class="public-membership-principle-inner">


        <p class="public-membership-eyebrow">
          Why Membership Works This Way
        </p>


        <h2>
          Public information is one thing. Knowing the place
          is another.
        </h2>


        <p>
          Free Members receive useful information that can
          generally be researched from public sources, such
          as the land manager, ranger district, public
          restrictions, weather, and other planning context.
        </p>


        <p>
          Paid Members receive the information that requires
          actually documenting the location: what the road is
          like, how private it feels, what you can hear,
          whether your vehicle can reach it, whether you will
          have cell service, and exactly where the place is.
        </p>


        <p>
          <strong>
            Members get information that can be researched
            publicly. Paid Members get information that had
            to be Llama Scouted.
          </strong>
        </p>


      </div>


    </div>


  </section>


  <!-- =====================================================
       FULL COMPARISON
       ===================================================== -->

  <section class="public-membership-comparison-section">


    <div class="public-membership-container">


      <div class="public-membership-section-heading">


        <p class="public-membership-eyebrow">
          Compare Access
        </p>


        <h2>
          What each level includes
        </h2>


        <p>
          Paid membership is the only customer-facing level
          that includes exact locations and detailed
          descriptions of what a place is actually like.
        </p>


      </div>


      <div class="public-membership-table-wrap">


        <table class="public-membership-table">


          <thead>

            <tr>

              <th scope="col">
                Feature
              </th>

              <th scope="col">
                Free
              </th>

              <th scope="col">
                Member
              </th>

              <th scope="col">
                Paid Member
              </th>

            </tr>

          </thead>


          <tbody>


            <tr>

              <td>
                Browse places
              </td>

              <td class="membership-yes">
                Yes
              </td>

              <td class="membership-yes">
                Yes
              </td>

              <td class="membership-paid">
                Yes
              </td>

            </tr>


            <tr>

              <td>
                Basic place information
              </td>

              <td>
                Limited
              </td>

              <td>
                Expanded public information
              </td>

              <td class="membership-paid">
                Complete
              </td>

            </tr>


            <tr>

              <td>
                Location
              </td>

              <td>
                Approximate
              </td>

              <td>
                Approximate
              </td>

              <td class="membership-paid">
                Exact
              </td>

            </tr>


            <tr>

              <td>
                Exact coordinates
              </td>

              <td class="membership-no">
                No
              </td>

              <td class="membership-no">
                No
              </td>

              <td class="membership-paid">
                Yes
              </td>

            </tr>


            <tr>

              <td>
                Photos
              </td>

              <td>
                Header image only
              </td>

              <td class="membership-yes">
                Full gallery
              </td>

              <td class="membership-paid">
                Full gallery
              </td>

            </tr>


            <tr>

              <td>
                Land manager / agency
              </td>

              <td>
                Limited
              </td>

              <td class="membership-yes">
                Yes
              </td>

              <td class="membership-paid">
                Yes
              </td>

            </tr>


            <tr>

              <td>
                Ranger district / county
              </td>

              <td class="membership-no">
                No
              </td>

              <td class="membership-yes">
                Yes
              </td>

              <td class="membership-paid">
                Yes
              </td>

            </tr>


            <tr>

              <td>
                Public restrictions and regulations
              </td>

              <td class="membership-no">
                No
              </td>

              <td class="membership-yes">
                Yes
              </td>

              <td class="membership-paid">
                Yes
              </td>

            </tr>


            <tr>

              <td>
                Weather and public planning data
              </td>

              <td class="membership-no">
                No
              </td>

              <td class="membership-yes">
                Yes
              </td>

              <td class="membership-paid">
                Yes
              </td>

            </tr>


            <tr>

              <td>
                Warnings
              </td>

              <td class="membership-no">
                No
              </td>

              <td>
                Limited public warnings
              </td>

              <td class="membership-paid">
                Complete
              </td>

            </tr>


            <tr>

              <td>
                Sensory details
              </td>

              <td class="membership-no">
                No
              </td>

              <td class="membership-no">
                No
              </td>

              <td class="membership-paid">
                Yes
              </td>

            </tr>


            <tr>

              <td>
                Road and access details
              </td>

              <td class="membership-no">
                No
              </td>

              <td class="membership-no">
                No
              </td>

              <td class="membership-paid">
                Yes
              </td>

            </tr>


            <tr>

              <td>
                Vehicle suitability
              </td>

              <td class="membership-no">
                No
              </td>

              <td class="membership-no">
                No
              </td>

              <td class="membership-paid">
                Yes
              </td>

            </tr>


            <tr>

              <td>
                Connectivity / cell / Starlink
              </td>

              <td class="membership-no">
                No
              </td>

              <td class="membership-no">
                No
              </td>

              <td class="membership-paid">
                Yes
              </td>

            </tr>


            <tr>

              <td>
                Privacy, crowds, and noise
              </td>

              <td class="membership-no">
                No
              </td>

              <td class="membership-no">
                No
              </td>

              <td class="membership-paid">
                Yes
              </td>

            </tr>


            <tr>

              <td>
                Save places
              </td>

              <td class="membership-no">
                No
              </td>

              <td class="membership-yes">
                Yes
              </td>

              <td class="membership-paid">
                Yes
              </td>

            </tr>


          </tbody>


        </table>


      </div>


    </div>


  </section>


  <!-- =====================================================
       PAID PRICING
       ===================================================== -->

  <section class="public-membership-pricing">


    <div class="public-membership-container">


      <div class="public-membership-section-heading">


        <p class="public-membership-eyebrow">
          Full Access
        </p>


        <h2>
          Choose monthly or annual membership
        </h2>


        <p>
          Both paid plans include the same full Llama Scout
          access. The only difference is the billing period.
        </p>


      </div>


      <div class="public-membership-pricing-grid">


        <article class="public-membership-price-card">


          <h3>
            Monthly
          </h3>


          <p class="public-membership-big-price">

            $6.99

            <span>
              / month
            </span>

          </p>


          <p>
            Full access with month-to-month billing.
          </p>


          <a
            href="https://account.llamascout.com/membership.php"
          >

            Choose Monthly

            <i
              class="fa-solid fa-arrow-right"
              aria-hidden="true"
            ></i>

          </a>


        </article>


        <article class="public-membership-price-card">


          <h3>
            Annual
          </h3>


          <p class="public-membership-big-price">

            $59.99

            <span>
              / year
            </span>

          </p>


          <p>
            The same full access with one annual renewal.
          </p>


          <a
            href="https://account.llamascout.com/membership.php"
          >

            Choose Annual

            <i
              class="fa-solid fa-arrow-right"
              aria-hidden="true"
            ></i>

          </a>


        </article>


      </div>


    </div>


  </section>


  <!-- =====================================================
       FAQ
       ===================================================== -->

  <section class="public-membership-faq">


    <div class="public-membership-container">


      <div class="public-membership-section-heading">


        <p class="public-membership-eyebrow">
          Membership FAQ
        </p>


        <h2>
          Before you join
        </h2>


      </div>


      <div class="public-membership-faq-list">


        <details>

          <summary>
            Do I have to pay to use Llama Scout?
          </summary>

          <p>
            No. Anyone can browse the public portions of
            Llama Scout for free. A free Member account adds
            public planning information, photos, saved places,
            and other account features.
          </p>

        </details>


        <details>

          <summary>
            Why are exact locations paid information?
          </summary>

          <p>
            Exact locations are part of the complete place
            report. Paid membership supports the work involved
            in finding, documenting, maintaining, and presenting
            detailed information about individual locations.
          </p>

        </details>


        <details>

          <summary>
            What does a free Member account include?
          </summary>

          <p>
            Free Members can see the full place photo gallery
            along with publicly obtainable planning information
            such as land management, ranger district, county,
            restrictions, weather, and limited public warnings.
            Exact locations and descriptions of what the site
            itself is like remain part of paid membership.
          </p>

        </details>


        <details>

          <summary>
            What does paid membership unlock?
          </summary>

          <p>
            Paid membership unlocks the complete place report,
            including exact location and coordinates, sensory
            conditions, road and vehicle access, connectivity,
            privacy, crowds, noise, complete warnings, and other
            site-specific planning information.
          </p>

        </details>


        <details>

          <summary>
            Is the monthly membership different from annual?
          </summary>

          <p>
            No. Monthly and annual memberships provide the same
            full access. They differ only in price and billing
            frequency.
          </p>

        </details>


      </div>


    </div>


  </section>


</main>


<?php

require_once
    __DIR__
    . '/app/footer.php';

?>


<script
  src="/js/header.js"
></script>


</body>

</html>
