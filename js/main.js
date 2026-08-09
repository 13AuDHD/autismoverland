async function includeHTML(selector, file) {
  const target = document.querySelector(selector);
  if (!target) return;

  try {
    const response = await fetch(file);
    if (!response.ok) throw new Error(`${file} could not be loaded`);
    target.innerHTML = await response.text();
  } catch (error) {
    console.warn(error.message);
  }
}

async function initIncludes() {
  await includeHTML('#site-header', 'header.html');
  await includeHTML('#site-footer', 'footer.html');

  const header = document.querySelector('[data-site-header]');
  const toggle = document.querySelector('.menu-toggle');
  const mobileNav = document.querySelector('.mobile-nav');

  if (toggle && mobileNav) {
    toggle.addEventListener('click', () => {
      const isOpen = mobileNav.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', String(isOpen));
      toggle.innerHTML = isOpen
        ? '<i class="fa-solid fa-xmark"></i>'
        : '<i class="fa-solid fa-bars"></i>';
    });
  }

  if (header) {
    window.addEventListener('scroll', () => {
      header.classList.toggle('is-scrolled', window.scrollY > 8);
    });
  }
}

document.addEventListener('DOMContentLoaded', initIncludes);
