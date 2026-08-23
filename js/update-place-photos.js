(() => {
  "use strict";

  const form =
    document.querySelector(
      'form[action="update-place.php"]'
    );

  if (!form) {
    return;
  }

  const csrfInput =
    form.querySelector(
      'input[name="csrf_token"]'
    );

  if (!csrfInput) {
    return;
  }

  const notesField =
    form.querySelector(
      'textarea[name="contributor_notes"]'
    );

  const visitCard =
    notesField
      ? notesField.closest(
          ".update-place-card"
        )
      : null;

  if (!visitCard) {
    return;
  }

  const editInput =
    form.querySelector(
      'input[name="edit_update_id"]'
    );

  const maxPhotos = 5;

  let photos = [];
  let busy = false;


  /* =======================================================
     STYLES
     ======================================================= */

  const style =
    document.createElement(
      "style"
    );

  style.textContent = `
    .update-photo-uploader {
      margin-top: 16px;
    }

    .update-photo-drop {
      position: relative;
      display: grid;
      place-items: center;
      min-height: 150px;
      padding: 22px;
      border: 2px dashed rgba(23,40,34,.22);
      border-radius: 14px;
      background: rgba(23,40,34,.025);
      text-align: center;
      cursor: pointer;
      transition:
        border-color .16s ease,
        background .16s ease,
        transform .16s ease;
    }

    .update-photo-drop:hover,
    .update-photo-drop.is-dragging {
      border-color: rgba(23,40,34,.55);
      background: rgba(23,40,34,.06);
    }

    .update-photo-drop.is-dragging {
      transform: scale(.995);
    }

    .update-photo-drop input {
      position: absolute;
      width: 1px;
      height: 1px;
      opacity: 0;
      pointer-events: none;
    }

    .update-photo-drop-icon {
      font-size: 1.7rem;
      margin-bottom: 8px;
    }

    .update-photo-drop strong {
      display: block;
      margin-bottom: 5px;
    }

    .update-photo-drop small {
      display: block;
      max-width: 560px;
      line-height: 1.45;
      opacity: .65;
    }

    .update-photo-toolbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-top: 12px;
      font-size: .84rem;
    }

    .update-photo-status {
      opacity: .7;
    }

    .update-photo-status.is-error {
      color: #7f2d2d;
      opacity: 1;
    }

    .update-photo-grid {
      display: grid;
      grid-template-columns:
        repeat(
          auto-fit,
          minmax(170px, 1fr)
        );
      gap: 14px;
      margin-top: 16px;
    }

    .update-photo-item {
      overflow: hidden;
      border: 1px solid rgba(23,40,34,.12);
      border-radius: 12px;
      background: #fff;
    }

    .update-photo-image {
      position: relative;
      aspect-ratio: 4 / 3;
      overflow: hidden;
      background: rgba(23,40,34,.06);
    }

    .update-photo-image img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .update-photo-number {
      position: absolute;
      top: 8px;
      left: 8px;
      display: grid;
      place-items: center;
      min-width: 28px;
      height: 28px;
      padding: 0 7px;
      border-radius: 999px;
      background: rgba(23,40,34,.9);
      color: #fff;
      font-size: .76rem;
      font-weight: 800;
    }

    .update-photo-remove {
      position: absolute;
      top: 8px;
      right: 8px;
      display: grid;
      place-items: center;
      width: 32px;
      height: 32px;
      border: 0;
      border-radius: 999px;
      background: rgba(255,255,255,.94);
      color: #172822;
      cursor: pointer;
      box-shadow: 0 2px 10px rgba(0,0,0,.12);
    }

    .update-photo-caption {
      padding: 11px;
    }

    .update-photo-caption label {
      display: grid;
      gap: 5px;
      font-size: .78rem;
      font-weight: 800;
    }

    .update-photo-caption input {
      width: 100%;
      box-sizing: border-box;
      padding: 9px 10px;
      border: 1px solid rgba(23,40,34,.16);
      border-radius: 8px;
      background: #fff;
      font: inherit;
    }

    .update-photo-empty {
      margin-top: 14px;
      padding: 16px;
      border-radius: 11px;
      background: rgba(23,40,34,.035);
      text-align: center;
      font-size: .88rem;
      opacity: .68;
    }

    .update-photo-uploading {
      pointer-events: none;
      opacity: .62;
    }
  `;

  document.head.appendChild(
    style
  );


  /* =======================================================
     SECTION
     ======================================================= */

  const section =
    document.createElement(
      "section"
    );

  section.className =
    "update-place-card";

  section.innerHTML = `
    <h2>Photos from this visit</h2>

    <p>
      Optional, but useful. Add up to 5 current photos that help
      show the overall site or document what changed. Photos are
      reviewed with your update before they can be added to the
      public Place gallery.
    </p>

    <div class="update-photo-uploader">

      <label class="update-photo-drop">

        <input
          type="file"
          accept="image/*,.heic,.heif,.avif"
          multiple
          data-update-photo-files
        >

        <span>
          <i
            class="fa-solid fa-images update-photo-drop-icon"
            aria-hidden="true"
          ></i>

          <strong>
            Choose Photos
          </strong>

          <small>
            JPEG, PNG, WebP, HEIC, HEIF, or AVIF. Up to 5 photos,
            15 MB each. Images are resized and location metadata
            is removed before storage.
          </small>
        </span>

      </label>

      <div class="update-photo-toolbar">
        <span data-update-photo-count>
          0 of 5 photos
        </span>

        <span
          class="update-photo-status"
          data-update-photo-status
          aria-live="polite"
        ></span>
      </div>

      <div
        class="update-photo-grid"
        data-update-photo-grid
      ></div>

      <div
        class="update-photo-empty"
        data-update-photo-empty
      >
        No photos added yet.
      </div>

    </div>
  `;

  visitCard.parentNode.insertBefore(
    section,
    visitCard
  );


  const fileInput =
    section.querySelector(
      "[data-update-photo-files]"
    );

  const dropZone =
    section.querySelector(
      ".update-photo-drop"
    );

  const grid =
    section.querySelector(
      "[data-update-photo-grid]"
    );

  const empty =
    section.querySelector(
      "[data-update-photo-empty]"
    );

  const count =
    section.querySelector(
      "[data-update-photo-count]"
    );

  const status =
    section.querySelector(
      "[data-update-photo-status]"
    );


  const hidden =
    document.createElement(
      "input"
    );

  hidden.type =
    "hidden";

  hidden.name =
    "update_photos_json";

  hidden.value =
    "[]";

  form.appendChild(
    hidden
  );


  function cleanPhotoForSubmit(
    photo
  ) {

    return {
      url:
        String(
          photo.url
          || ""
        ),

      filename:
        String(
          photo.filename
          || ""
        ),

      width:
        Number(
          photo.width
          || 0
        ),

      height:
        Number(
          photo.height
          || 0
        ),

      size:
        Number(
          photo.size
          || 0
        ),

      alt:
        String(
          photo.alt
          || ""
        ).trim(),
    };

  }


  function syncHidden() {

    hidden.value =
      JSON.stringify(
        photos.map(
          cleanPhotoForSubmit
        )
      );

  }


  function setStatus(
    message,
    isError = false
  ) {

    status.textContent =
      message || "";

    status.classList.toggle(
      "is-error",
      Boolean(
        isError
      )
    );

  }


  function render() {

    count.textContent =
      `${photos.length} of ${maxPhotos} photos`;


    empty.hidden =
      photos.length > 0;


    grid.innerHTML =
      "";


    photos.forEach(
      (
        photo,
        index
      ) => {

        const item =
          document.createElement(
            "article"
          );

        item.className =
          "update-photo-item";


        const imageWrap =
          document.createElement(
            "div"
          );

        imageWrap.className =
          "update-photo-image";


        const image =
          document.createElement(
            "img"
          );

        image.src =
          photo.url;

        image.alt =
          photo.alt
          ||
          `Update photo ${index + 1}`;

        image.loading =
          "lazy";


        const number =
          document.createElement(
            "span"
          );

        number.className =
          "update-photo-number";

        number.textContent =
          String(
            index + 1
          );


        const remove =
          document.createElement(
            "button"
          );

        remove.type =
          "button";

        remove.className =
          "update-photo-remove";

        remove.setAttribute(
          "aria-label",
          `Remove photo ${index + 1}`
        );

        remove.innerHTML =
          '<i class="fa-solid fa-xmark" aria-hidden="true"></i>';


        remove.addEventListener(
          "click",
          async () => {

            const removed =
              photos[
                index
              ];


            photos.splice(
              index,
              1
            );


            syncHidden();
            render();


            if (
              removed
              &&
              removed._new === true
              &&
              removed.url
            ) {

              const body =
                new FormData();

              body.append(
                "csrf_token",
                csrfInput.value
              );

              body.append(
                "action",
                "delete"
              );

              body.append(
                "url",
                removed.url
              );


              try {

                await fetch(
                  "upload-update-photos.php",
                  {
                    method:
                      "POST",

                    body,
                  }
                );

              } catch (
                error
              ) {

                console.warn(
                  "Llama Scout could not clean up a removed staged photo.",
                  error
                );

              }

            }

          }
        );


        imageWrap.append(
          image,
          number,
          remove
        );


        const captionWrap =
          document.createElement(
            "div"
          );

        captionWrap.className =
          "update-photo-caption";


        const label =
          document.createElement(
            "label"
          );

        label.textContent =
          "What does this photo show?";


        const caption =
          document.createElement(
            "input"
          );

        caption.type =
          "text";

        caption.maxLength =
          300;

        caption.placeholder =
          "Example: New picnic table beside the fire ring";

        caption.value =
          photo.alt
          || "";


        caption.addEventListener(
          "input",
          () => {

            photo.alt =
              caption.value;

            image.alt =
              caption.value.trim()
              ||
              `Update photo ${index + 1}`;

            syncHidden();

          }
        );


        label.appendChild(
          caption
        );

        captionWrap.appendChild(
          label
        );


        item.append(
          imageWrap,
          captionWrap
        );


        grid.appendChild(
          item
        );

      }
    );


    syncHidden();

  }


  async function uploadFiles(
    files
  ) {

    const selected =
      Array.from(
        files
        || []
      );


    if (
      selected.length === 0
    ) {

      return;

    }


    if (
      photos.length
      +
      selected.length
      >
      maxPhotos
    ) {

      setStatus(
        `You can include up to ${maxPhotos} photos with an update.`,
        true
      );

      fileInput.value =
        "";

      return;

    }


    if (
      busy
    ) {

      return;

    }


    busy =
      true;

    section.classList.add(
      "update-photo-uploading"
    );

    setStatus(
      "Uploading and cleaning photos..."
    );


    const body =
      new FormData();

    body.append(
      "csrf_token",
      csrfInput.value
    );


    selected.forEach(
      file => {

        body.append(
          "photos[]",
          file
        );

      }
    );


    try {

      const response =
        await fetch(
          "upload-update-photos.php",
          {
            method:
              "POST",

            body,
          }
        );


      const data =
        await response.json();


      if (
        !response.ok
        ||
        !data.success
      ) {

        throw new Error(
          data.message
          ||
          "The photos could not be uploaded."
        );

      }


      const uploaded =
        Array.isArray(
          data.photos
        )
          ? data.photos
          : [];


      uploaded.forEach(
        photo => {

          photos.push({
            ...photo,
            alt:
              "",
            _new:
              true,
          });

        }
      );


      render();


      setStatus(
        data.message
        ||
        "Photos uploaded."
      );


    } catch (
      error
    ) {

      setStatus(
        error instanceof Error
          ? error.message
          : "The photos could not be uploaded.",
        true
      );


    } finally {

      busy =
        false;

      section.classList.remove(
        "update-photo-uploading"
      );

      fileInput.value =
        "";

    }

  }


  fileInput.addEventListener(
    "change",
    () => {

      uploadFiles(
        fileInput.files
      );

    }
  );


  [
    "dragenter",
    "dragover",
  ].forEach(
    eventName => {

      dropZone.addEventListener(
        eventName,
        event => {

          event.preventDefault();

          dropZone.classList.add(
            "is-dragging"
          );

        }
      );

    }
  );


  [
    "dragleave",
    "drop",
  ].forEach(
    eventName => {

      dropZone.addEventListener(
        eventName,
        event => {

          event.preventDefault();

          dropZone.classList.remove(
            "is-dragging"
          );

        }
      );

    }
  );


  dropZone.addEventListener(
    "drop",
    event => {

      uploadFiles(
        event
          .dataTransfer
          ?.files
      );

    }
  );


  async function loadExistingPhotos() {

    if (
      !editInput
      ||
      !editInput.value
    ) {

      render();

      return;

    }


    setStatus(
      "Loading photos..."
    );


    try {

      const response =
        await fetch(
          `upload-update-photos.php?edit=${encodeURIComponent(editInput.value)}`,
          {
            headers: {
              "Accept":
                "application/json",
            },
          }
        );


      const data =
        await response.json();


      if (
        !response.ok
        ||
        !data.success
      ) {

        throw new Error(
          data.message
          ||
          "Existing update photos could not be loaded."
        );

      }


      photos =
        (
          Array.isArray(
            data.photos
          )
            ? data.photos
            : []
        )
        .slice(
          0,
          maxPhotos
        )
        .map(
          photo => ({
            ...photo,
            _new:
              false,
          })
        );


      render();
      setStatus("");


    } catch (
      error
    ) {

      render();

      setStatus(
        error instanceof Error
          ? error.message
          : "Existing update photos could not be loaded.",
        true
      );

    }

  }


  loadExistingPhotos();

})();
