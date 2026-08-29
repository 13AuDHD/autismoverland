/* =========================================================
   LLAMA SCOUT
   MFA SETUP QR
   js/mfa-setup.js
   ========================================================= */


document.addEventListener(
  'DOMContentLoaded',
  function () {

    const target =
      document.getElementById(
        'mfa-qr'
      );


    if (!target) {

      return;
    }


    const uri =
      target.dataset.otpauth
      || '';


    if (
      uri === ''
      ||
      typeof QRCode ===
        'undefined'
    ) {

      return;
    }


    target.textContent =
      '';


    new QRCode(
      target,
      {
        text: uri,
        width: 220,
        height: 220,
        correctLevel:
          QRCode.CorrectLevel.M
      }
    );

  }
);
