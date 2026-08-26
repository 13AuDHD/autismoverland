"use strict";


/* =========================================================
   LLAMA SCOUT
   COMMUNITY PROFILE EDITOR
   js/community-profile-editor.js
   ========================================================= */


(() => {

  const config =
    window.LLAMA_PROFILE_IMAGES
    || {};

  const csrfToken =
    config.csrfToken
    || "";

  const defaultImage =
    config.defaultImage
    || "/images/default-llama-profile.png";


  const uploadForm =
    document.getElementById(
      "profile-image-upload-form"
    );

  const fileInput =
    document.getElementById(
      "profile-images"
    );

  const imageGrid =
    document.querySelector(
      "[data-profile-image-grid]"
    );

  const statusBox =
    document.querySelector(
      "[data-profile-image-status]"
    );

  const avatar =
    document.querySelector(
      "[data-profile-avatar]"
    );


  if (
    !imageGrid
  ) {
    return;
  }


  /* =======================================================
     STATUS
     ======================================================= */

  function showStatus(
    message,
    type = "success"
  ) {

    if (
      !statusBox
    ) {
      return;
    }


    statusBox.hidden =
      false;

    statusBox.textContent =
      message;


    statusBox.classList.remove(
      "account-status--success",
      "account-status--error"
    );


    statusBox.classList.add(
      type === "error"
        ? "account-status--error"
        : "account-status--success"
    );
  }


  function clearStatus() {

    if (
      !statusBox
    ) {
      return;
    }


    statusBox.hidden =
      true;

    statusBox.textContent =
      "";

    statusBox.classList.remove(
      "account-status--success",
      "account-status--error"
    );
  }


  /* =======================================================
     REQUEST HELPER
     ======================================================= */

  async function postForm(
    url,
    formData
  ) {

    const response =
      await fetch(
        url,
        {
          method:
            "POST",

          body:
            formData,

          credentials:
            "include",

          cache:
            "no-store"
        }
      );


    let payload =
      null;


const rawResponse =
  await response.text();


try {

  payload =
    JSON.parse(
      rawResponse
    );

} catch (
  error
) {

  console.error(
    "Profile image request failed:",
    {
      status:
        response.status,

      statusText:
        response.statusText,

      response:
        rawResponse
    }
  );


  if (
    response.status >= 500
  ) {

    throw new Error(
      "The llamas are chewing on network cords again. Please try again in a moment."
    );
  }


  throw new Error(
    "The server returned an uh-oh opsie. Please try again."
  );
}

    if (
      !response.ok
      ||
      !payload
      ||
      payload.success !== true
    ) {

      throw new Error(
        payload?.message
        ||
        "Something went wrong."
      );
    }


    return payload;
  }


  /* =======================================================
     UPLOAD
     ======================================================= */

  if (
    uploadForm
    &&
    fileInput
  ) {

    uploadForm.addEventListener(
      "submit",
      async (
        event
      ) => {

        event.preventDefault();

        clearStatus();


        if (
          !fileInput.files
          ||
          fileInput.files.length < 1
        ) {

          showStatus(
            "Choose at least one profile image.",
            "error"
          );

          return;
        }


        const formData =
          new FormData();


        formData.append(
          "csrf_token",
          csrfToken
        );


        for (
          const file
          of fileInput.files
        ) {

          formData.append(
            "photos[]",
            file
          );
        }


        const submitButton =
          uploadForm.querySelector(
            'button[type="submit"]'
          );


        if (
          submitButton
        ) {

          submitButton.disabled =
            true;

          submitButton.textContent =
            "Uploading...";
        }


        try {

          const payload =
            await postForm(
              "/upload-profile-images.php",
              formData
            );


          showStatus(
            payload.message
            ||
            "Profile images uploaded."
          );


          window.location.reload();


        } catch (
          error
        ) {

          showStatus(
            error instanceof Error
              ? error.message
              : "The profile images could not be uploaded.",
            "error"
          );


        } finally {

          if (
            submitButton
          ) {

            submitButton.disabled =
              false;

            submitButton.textContent =
              "Upload Photos";
          }
        }
      }
    );
  }


  /* =======================================================
     SET PRIMARY IMAGE
     ======================================================= */

  imageGrid.addEventListener(
    "click",
    async (
      event
    ) => {

      const button =
        event.target.closest(
          "[data-set-primary-profile-image]"
        );


      if (
        !button
      ) {
        return;
      }


      clearStatus();


      const imageId =
        button.dataset.imageId
        || "";


      if (
        !imageId
      ) {
        return;
      }


      const formData =
        new FormData();


      formData.append(
        "csrf_token",
        csrfToken
      );


      formData.append(
        "image_id",
        imageId
      );


      button.disabled =
        true;

      const oldText =
        button.textContent;

      button.textContent =
        "Updating...";


      try {

        const payload =
          await postForm(
            "/set-primary-profile-image.php",
            formData
          );


        if (
          avatar
          &&
          payload.image_src
        ) {

          avatar.src =
            payload.image_src;
        }


        showStatus(
          payload.message
          ||
          "Profile photo updated."
        );


        window.location.reload();


      } catch (
        error
      ) {

        showStatus(
          error instanceof Error
            ? error.message
            : "The profile photo could not be updated.",
          "error"
        );


        button.disabled =
          false;

        button.textContent =
          oldText;
      }
    }
  );


  /* =======================================================
     DELETE IMAGE
     ======================================================= */

  imageGrid.addEventListener(
    "click",
    async (
      event
    ) => {

      const button =
        event.target.closest(
          "[data-delete-profile-image]"
        );


      if (
        !button
      ) {
        return;
      }


      clearStatus();


      const imageId =
        button.dataset.imageId
        || "";


      if (
        !imageId
      ) {
        return;
      }


      const card =
        button.closest(
          "[data-profile-image-id]"
        );


      const confirmed =
        window.confirm(
          "Delete this profile image?"
        );


      if (
        !confirmed
      ) {
        return;
      }


      const formData =
        new FormData();


      formData.append(
        "csrf_token",
        csrfToken
      );


      formData.append(
        "image_id",
        imageId
      );


      button.disabled =
        true;

      const oldText =
        button.textContent;

      button.textContent =
        "Deleting...";


      try {

        const payload =
          await postForm(
            "/delete-profile-image.php",
            formData
          );


        if (
          card
        ) {

          card.remove();
        }


        if (
          avatar
          &&
          payload.profile_image
        ) {

          avatar.src =
            payload.profile_image;
        }


        showStatus(
          payload.message
          ||
          "Profile image deleted."
        );


        /*
         * Reload so:
         *
         * - the primary-image button states are correct
         * - upload controls re-enable when below five images
         * - llama fallback is shown accurately
         */

        window.location.reload();


      } catch (
        error
      ) {

        showStatus(
          error instanceof Error
            ? error.message
            : "The profile image could not be deleted.",
          "error"
        );


        button.disabled =
          false;

        button.textContent =
          oldText;
      }
    }
  );


})();
