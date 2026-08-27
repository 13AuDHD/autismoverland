<?php

declare(strict_types=1);

require_once
    __DIR__
    . '/app/database.php';

require_once
    __DIR__
    . '/app/memberships.php';


$db =
    db();


/*
 * Membership storage is initialized through the Owner
 * Memberships panel. Public pages only read from it.
 */

$offers =
    llama_membership_offers(
        $db
    );


$monthlyOffer =
    $offers[
        LLAMA_MEMBERSHIP_INTERVAL_MONTHLY
    ]
    ?? null;


$annualOffer =
    $offers[
        LLAMA_MEMBERSHIP_INTERVAL_ANNUAL
    ]
    ?? null;


/* =========================================================
   DISPLAY HELPERS
   ========================================================= */

function public_membership_e(
    mixed $value
): string {

    return htmlspecialchars(
        (string)
        $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function public_membership_interval_suffix(
    string $interval
): string {

    return match (
        $interval
    ) {

        LLAMA_MEMBERSHIP_INTERVAL_MONTHLY =>
            '/ month',

        LLAMA_MEMBERSHIP_INTERVAL_ANNUAL =>
            '/ year',

        default =>
            '',

    };
}


function public_membership_offer_price(
    ?array $offer
): string {

    if (
        !$offer
    ) {

        return 'Unavailable';

    }


    $plan =
        $offer[
            'plan'
        ];


    return llama_membership_format_money(
        (int)
        $offer[
            'effective_price_cents'
        ],
        (string)
        $plan[
            'currency'
        ]
    );
}


function public_membership_regular_price(
    ?array $offer
): string {

    if (
        !$offer
    ) {

        return '';

    }


    $plan =
        $offer[
            'plan'
        ];


    return llama_membership_format_money(
        (int)
        $offer[
            'base_price_cents'
        ],
        (string)
        $plan[
            'currency'
        ]
    );
}


function public_membership_sale_label(
    ?array $offer
): ?string {

    if (
        !$offer
        ||
        empty(
            $offer[
                'on_sale'
            ]
        )
    ) {

        return null;

    }


    $promotion =
        $offer[
            'promotion'
        ]
        ?? null;


    if (
        !$promotion
    ) {

        return 'Sale';

    }


    $label =
        trim(
            (string) (
                $promotion[
                    'public_label'
                ]
                ?? ''
            )
        );


    return
        $label !== ''
            ? $label
            : 'Sale';
}


function public_membership_sale_description(
    ?array $offer
): ?string {

    if (
        !$offer
        ||
        empty(
            $offer[
                'on_sale'
            ]
        )
    ) {

        return null;

    }


    $promotion =
        $offer[
            'promotion'
        ]
        ?? null;


    if (
        !$promotion
    ) {

        return null;

    }


    $description =
        trim(
            (string) (
                $promotion[
                    'public_description'
                ]
                ?? ''
            )
        );


    return
        $description !== ''
            ? $description
            : null;
}


function public_membership_sale_end(
    ?array $offer
): ?string {

    if (
        !$offer
        ||
        empty(
            $offer[
                'on_sale'
            ]
        )
    ) {

        return null;

    }


    $value =
        $offer[
            'promotion'
        ][
            'ends_at'
        ]
        ?? null;


    if (
        !$value
    ) {

        return null;

    }


    try {

        $date =
            new DateTimeImmutable(
                (string)
                $value,
                new DateTimeZone(
                    'UTC'
                )
            );


        return
            $date
                ->format(
                    'M j, Y'
                );

    } catch (
        Throwable
    ) {

        return null;

    }
}


$monthlyPrice =
    public_membership_offer_price(
        $monthlyOffer
    );


$annualPrice =
    public_membership_offer_price(
        $annualOffer
    );


$monthlyRegular =
    public_membership_regular_price(
        $monthlyOffer
    );


$annualRegular =
    public_membership_regular_price(
        $annualOffer
    );


$monthlySaleLabel =
    public_membership_sale_label(
        $monthlyOffer
    );


$annualSaleLabel =
    public_membership_sale_label(
        $annualOffer
    );


$anySale =
    $monthlySaleLabel !== null
    ||
    $annualSaleLabel !== null;

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
    content="Compare Llama Scout access levels and current membership pricing."
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
          Some Llama Scout information is intentionally
          reserved for paid members. The comparison below
          shows exactly what each level of access includes.
        </p>

      </div>


      <?php if ($anySale): ?>

        <div class="public-membership-sale-banner">

          <strong>
            <?php

            echo public_membership_e(
                $monthlySaleLabel
                ?:
                $annualSaleLabel
                ?:
                'Membership Sale'
            );

            ?>
          </strong>

          Current promotional pricing is shown below and will
          be applied automatically when you choose an eligible
          membership.

        </div>

      <?php endif; ?>

    </div>

  </section>


  <!-- =====================================================
       ACCESS LEVELS
       ===================================================== -->

  <section class="public-membership-plans-section">

    <div class="public-membership-container">

      <div class="public-membership-plan-grid">


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
              <i class="fa-solid fa-check"></i>
              <span>Browse public place listings</span>
            </li>

            <li>
              <i class="fa-solid fa-check"></i>
              <span>Limited basic place information</span>
            </li>

            <li>
              <i class="fa-solid fa-check"></i>
              <span>Approximate general area</span>
            </li>

            <li>
              <i class="fa-solid fa-check"></i>
              <span>Main header image</span>
            </li>

            <li>
              <i class="fa-solid fa-xmark"></i>
              <span>
                No exact coordinates or identifying location
              </span>
            </li>

            <li>
              <i class="fa-solid fa-xmark"></i>
              <span>
                No sensory, road, or connectivity details
              </span>
            </li>

          </ul>

          <div class="public-membership-plan-action">

            <a href="/places.php">
              Browse Places
              <i class="fa-solid fa-arrow-right"></i>
            </a>

          </div>

        </article>


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
              <i class="fa-solid fa-check"></i>
              <span>
                Everything available to Free visitors
              </span>
            </li>

            <li>
              <i class="fa-solid fa-check"></i>
              <span>Full place photo gallery</span>
            </li>

            <li>
              <i class="fa-solid fa-check"></i>
              <span>
                Land manager and managing agency information
              </span>
            </li>

            <li>
              <i class="fa-solid fa-check"></i>
              <span>
                Ranger district, county, and regional information
              </span>
            </li>

            <li>
              <i class="fa-solid fa-check"></i>
              <span>
                Public restrictions and regulations
              </span>
            </li>

            <li>
              <i class="fa-solid fa-check"></i>
              <span>
                Weather and other publicly available planning data
              </span>
            </li>

            <li>
              <i class="fa-solid fa-check"></i>
              <span>Limited public warnings</span>
            </li>

            <li>
              <i class="fa-solid fa-check"></i>
              <span>Save places to your account</span>
            </li>

            <li>
              <i class="fa-solid fa-xmark"></i>
              <span>No exact location or coordinates</span>
            </li>

            <li>
              <i class="fa-solid fa-xmark"></i>
              <span>
                No information describing what the site itself
                is like
              </span>
            </li>

          </ul>

          <div class="public-membership-plan-action">

            <a
              href="https://account.llamascout.com/register.php"
            >
              Create Free Account
              <i class="fa-solid fa-arrow-right"></i>
            </a>

          </div>

        </article>


        <article
          class="
            public-membership-plan
            public-membership-plan-paid
          "
        >

          <span class="public-membership-badge">
            <?= public_membership_e(
                $monthlySaleLabel
                ?:
                'Full Access'
            ) ?>
          </span>

          <h2>
            Paid Member
          </h2>

          <p class="public-membership-plan-price">

            <strong>
              <?= public_membership_e(
                  $monthlyPrice
              ) ?>
            </strong>

            <span>
              / month
            </span>

            <?php if (
                $monthlyOffer
                &&
                !empty(
                    $monthlyOffer[
                        'on_sale'
                    ]
                )
            ): ?>

              <span class="public-membership-regular-price">
                Regularly
                <del>
                  <?= public_membership_e(
                      $monthlyRegular
                  ) ?>
                </del>
              </span>

            <?php endif; ?>

          </p>

          <p class="public-membership-plan-description">
            The complete Llama Scout place report, including
            field-based information that cannot simply be
            looked up online.
          </p>

          <ul>

            <li>
              <i class="fa-solid fa-check"></i>
              <span>
                Everything included with a free Member account
              </span>
            </li>

            <li>
              <i class="fa-solid fa-location-crosshairs"></i>
              <span>Exact location and coordinates</span>
            </li>

            <li>
              <i class="fa-solid fa-brain"></i>
              <span>Complete sensory information</span>
            </li>

            <li>
              <i class="fa-solid fa-road"></i>
              <span>Road conditions and access details</span>
            </li>

            <li>
              <i class="fa-solid fa-car"></i>
              <span>
                Vehicle suitability and site access information
              </span>
            </li>

            <li>
              <i class="fa-solid fa-signal"></i>
              <span>
                Cell service, connectivity, and Starlink information
              </span>
            </li>

            <li>
              <i class="fa-solid fa-eye"></i>
              <span>
                Privacy, crowds, noise, and activity details
              </span>
            </li>

            <li>
              <i class="fa-solid fa-triangle-exclamation"></i>
              <span>
                Complete warnings and site-specific concerns
              </span>
            </li>

            <li>
              <i class="fa-solid fa-check"></i>
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
              <i class="fa-solid fa-arrow-right"></i>
            </a>

          </div>

        </article>


      </div>

    </div>

  </section>


  <!-- =====================================================
       PRINCIPLE
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
            publicly. Paid Members get the complete
            Llama Scout field report.
          </strong>
        </p>

      </div>

    </div>

  </section>


  <!-- =====================================================
       COMPARISON
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
              <th scope="col">Feature</th>
              <th scope="col">Free</th>
              <th scope="col">Member</th>
              <th scope="col">Paid Member</th>
            </tr>

          </thead>

          <tbody>

            <tr>
              <td>Browse places</td>
              <td class="membership-yes">Yes</td>
              <td class="membership-yes">Yes</td>
              <td class="membership-paid">Yes</td>
            </tr>

            <tr>
              <td>Basic place information</td>
              <td>Limited</td>
              <td>Expanded public information</td>
              <td class="membership-paid">Complete</td>
            </tr>

            <tr>
              <td>Location</td>
              <td>Approximate</td>
              <td>Approximate</td>
              <td class="membership-paid">Exact</td>
            </tr>

            <tr>
              <td>Exact coordinates</td>
              <td class="membership-no">No</td>
              <td class="membership-no">No</td>
              <td class="membership-paid">Yes</td>
            </tr>

            <tr>
              <td>Photos</td>
              <td>Header image only</td>
              <td class="membership-yes">Full gallery</td>
              <td class="membership-paid">Full gallery</td>
            </tr>

            <tr>
              <td>Land manager / agency</td>
              <td>Limited</td>
              <td class="membership-yes">Yes</td>
              <td class="membership-paid">Yes</td>
            </tr>

            <tr>
              <td>Ranger district / county</td>
              <td class="membership-no">No</td>
              <td class="membership-yes">Yes</td>
              <td class="membership-paid">Yes</td>
            </tr>

            <tr>
              <td>Public restrictions and regulations</td>
              <td class="membership-no">No</td>
              <td class="membership-yes">Yes</td>
              <td class="membership-paid">Yes</td>
            </tr>

            <tr>
              <td>Weather and public planning data</td>
              <td class="membership-no">No</td>
              <td class="membership-yes">Yes</td>
              <td class="membership-paid">Yes</td>
            </tr>

            <tr>
              <td>Warnings</td>
              <td class="membership-no">No</td>
              <td>Limited public warnings</td>
              <td class="membership-paid">Complete</td>
            </tr>

            <tr>
              <td>Sensory details</td>
              <td class="membership-no">No</td>
              <td class="membership-no">No</td>
              <td class="membership-paid">Yes</td>
            </tr>

            <tr>
              <td>Road and access details</td>
              <td class="membership-no">No</td>
              <td class="membership-no">No</td>
              <td class="membership-paid">Yes</td>
            </tr>

            <tr>
              <td>Vehicle suitability</td>
              <td class="membership-no">No</td>
              <td class="membership-no">No</td>
              <td class="membership-paid">Yes</td>
            </tr>

            <tr>
              <td>Connectivity / cell / Starlink</td>
              <td class="membership-no">No</td>
              <td class="membership-no">No</td>
              <td class="membership-paid">Yes</td>
            </tr>

            <tr>
              <td>Privacy, crowds, and noise</td>
              <td class="membership-no">No</td>
              <td class="membership-no">No</td>
              <td class="membership-paid">Yes</td>
            </tr>

            <tr>
              <td>Save places</td>
              <td class="membership-no">No</td>
              <td class="membership-yes">Yes</td>
              <td class="membership-paid">Yes</td>
            </tr>

          </tbody>

        </table>

      </div>

    </div>

  </section>


  <!-- =====================================================
       PRICING
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
          access. The only difference is price and billing
          period.
        </p>

      </div>


      <div class="public-membership-pricing-grid">


        <?php if ($monthlyOffer): ?>

          <article
            class="
              public-membership-price-card
              <?= !empty(
                  $monthlyOffer[
                      'on_sale'
                  ]
              )
                  ? 'is-sale'
                  : ''
              ?>
            "
          >

            <?php if ($monthlySaleLabel): ?>

              <span class="public-membership-price-card-badge">
                <?= public_membership_e(
                    $monthlySaleLabel
                ) ?>
              </span>

            <?php endif; ?>

            <h3>
              Monthly
            </h3>

            <p class="public-membership-big-price">

              <?php if (
                  !empty(
                      $monthlyOffer[
                          'on_sale'
                      ]
                  )
              ): ?>

                <del>
                  <?= public_membership_e(
                      $monthlyRegular
                  ) ?>
                </del>

              <?php endif; ?>

              <?= public_membership_e(
                  $monthlyPrice
              ) ?>

              <span>
                / month
              </span>

            </p>

            <p>

              <?php if (
                  $description =
                      public_membership_sale_description(
                          $monthlyOffer
                      )
              ): ?>

                <?= public_membership_e(
                    $description
                ) ?>

              <?php else: ?>

                Full access with month-to-month billing.

              <?php endif; ?>


              <?php if (
                  $saleEnd =
                      public_membership_sale_end(
                          $monthlyOffer
                      )
              ): ?>

                Sale ends
                <?= public_membership_e(
                    $saleEnd
                ) ?>.

              <?php endif; ?>

            </p>

            <a
              href="https://account.llamascout.com/membership.php?plan=monthly"
            >
              Choose Monthly
              <i class="fa-solid fa-arrow-right"></i>
            </a>

          </article>

        <?php endif; ?>


        <?php if ($annualOffer): ?>

          <article
            class="
              public-membership-price-card
              <?= !empty(
                  $annualOffer[
                      'on_sale'
                  ]
              )
                  ? 'is-sale'
                  : ''
              ?>
            "
          >

            <?php if ($annualSaleLabel): ?>

              <span class="public-membership-price-card-badge">
                <?= public_membership_e(
                    $annualSaleLabel
                ) ?>
              </span>

            <?php endif; ?>

            <h3>
              Annual
            </h3>

            <p class="public-membership-big-price">

              <?php if (
                  !empty(
                      $annualOffer[
                          'on_sale'
                      ]
                  )
              ): ?>

                <del>
                  <?= public_membership_e(
                      $annualRegular
                  ) ?>
                </del>

              <?php endif; ?>

              <?= public_membership_e(
                  $annualPrice
              ) ?>

              <span>
                / year
              </span>

            </p>

            <p>

              <?php if (
                  $description =
                      public_membership_sale_description(
                          $annualOffer
                      )
              ): ?>

                <?= public_membership_e(
                    $description
                ) ?>

              <?php else: ?>

                The same full access with one annual renewal.

              <?php endif; ?>


              <?php if (
                  $saleEnd =
                      public_membership_sale_end(
                          $annualOffer
                      )
              ): ?>

                Sale ends
                <?= public_membership_e(
                    $saleEnd
                ) ?>.

              <?php endif; ?>

            </p>

            <a
              href="https://account.llamascout.com/membership.php?plan=annual"
            >
              Choose Annual
              <i class="fa-solid fa-arrow-right"></i>
            </a>

          </article>

        <?php endif; ?>


      </div>


      <div class="public-membership-billing-note">

        <strong>
          Payments are securely processed by Stripe.
        </strong>

        Llama Scout does not collect or store your 
        payment card information. For membership or billing
        assistance, contact
        <a href="mailto:billing@llamascout.com">
          billing@llamascout.com
        </a>.
        We will do our best to help. Some payment-method,
        authorization, or card issues may need to be resolved
        through Stripe or your financial institution.

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
            report. A lot of work goes into maintaining 
            Llama Scout. Paid membership supports the work involved
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
            privacy, crowds, noise, complete warnings, extended 
            weather and other site-specific planning information.
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


        <details>

          <summary>
            Who processes membership payments?
          </summary>

          <p>
            Stripe securely processes Llama Scout membership
            payments. Llama Scout does not collect or store
            any payment card details. For membership or
            billing assistance, contact
            <a href="mailto:billing@llamascout.com">
              billing@llamascout.com
            </a> and we will do our best to help, but unfortunately
              most problems require reaching out to Stripe or
              your financial institution. 
          </p>

        </details>

          
        <details>

          <summary>
            What do llama's eat?
          </summary>

          <p>
            Mostly grass, hay, and other leafy plants.
            They are surprisingly efficient eaters and
            generally need less food than you might expect
            for an animal their size. They also appreciate
            the occasional carrot or apple as a treat.
            Llama Scout, however, runs primarily on servers, maps,
            questionable roads, and curiosity.
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


<script src="/js/header.js"></script>

</body>

</html>
