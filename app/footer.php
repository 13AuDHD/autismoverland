<?php

declare(strict_types=1);

$footerSiteBase =
    'https://llamascout.com';

$footerAccountBase =
    'https://account.llamascout.com';

?>

<footer class="site-footer">

  <div class="footer-inner">


    <div class="footer-brand-block">

      <a
        class="footer-brand"
        href="<?= $footerSiteBase ?>/"
      >

        <img
          src="<?= $footerSiteBase ?>/images/logo-footer.png"
          alt="Llama Scout logo"
        >

      </a>


      <p class="footer-tagline">
        Detailed, field-scouted information
        for outdoor travel.
      </p>


      <div
        class="social-links"
        aria-label="Social media links"
      >


        <a
          href="https://instagram.com/thellamascout"
          aria-label="Instagram"
          target="_blank"
          rel="noopener noreferrer"
        >

          <i
            class="fa-brands fa-instagram"
            aria-hidden="true"
          ></i>

        </a>


        <a
          href="https://tiktok.com/@thellamascout"
          aria-label="TikTok"
          target="_blank"
          rel="noopener noreferrer"
        >

          <i
            class="fa-brands fa-tiktok"
            aria-hidden="true"
          ></i>

        </a>


        <a
          href="https://x.com/thellamascout"
          aria-label="X"
          target="_blank"
          rel="noopener noreferrer"
        >

          <i
            class="fa-brands fa-x-twitter"
            aria-hidden="true"
          ></i>

        </a>


        <a
          href="https://bsky.app/profile/llamascout.com"
          aria-label="Bluesky"
          target="_blank"
          rel="noopener noreferrer"
        >

          <i
            class="fa-brands fa-bluesky"
            aria-hidden="true"
          ></i>

        </a>


        <a
          href="https://facebook.com/thellamascout"
          aria-label="Facebook"
          target="_blank"
          rel="noopener noreferrer"
        >

          <i
            class="fa-brands fa-facebook-f"
            aria-hidden="true"
          ></i>

        </a>


      </div>

    </div>


    <div class="footer-link-columns">


      <nav
        class="footer-link-column"
        aria-label="Explore"
      >

        <h2>
          Explore
        </h2>

        <a href="<?= $footerSiteBase ?>/map.php">
          Map
        </a>

        <a href="<?= $footerSiteBase ?>/places.php">
          Places
        </a>

        <a href="<?= $footerSiteBase ?>/blog.php">
          Field Guide
        </a>

        <a href="<?= $footerSiteBase ?>/membership.php">
          Membership
        </a>

        <a href="<?= $footerSiteBase ?>/about.php">
          About
        </a>

        <a href="<?= $footerAccountBase ?>/scout-place.php">
          Scout a Place
        </a>

      </nav>


      <nav
        class="footer-link-column"
        aria-label="Legal"
      >

        <h2>
          Legal
        </h2>

        <a href="<?= $footerSiteBase ?>/privacy.php">
          Privacy Policy
        </a>

        <a href="<?= $footerSiteBase ?>/privacy-choices.php">
          Privacy Choices
        </a>

        <a href="<?= $footerSiteBase ?>/terms.php">
          Terms of Use
        </a>

        <a href="<?= $footerSiteBase ?>/accessibility.php">
          Accessibility
        </a>

        <a href="<?= $footerSiteBase ?>/disclaimer.php">
          Outdoor Disclaimer
        </a>

      </nav>


    </div>

  </div>


  <div class="footer-bottom">

    <span>
      © 2026 Llama Scout. All Rights Reserved.
    </span>

    <span>
      Know the place before you go.
    </span>

  </div>

</footer>
