const places = [
  {
    name: "Tomichi Pass Dispersed Camp",
    type: "dispersed",
    cell: "no",
    access: "high-clearance",
    tags: ["quiet", "low-sensory"],
    elevation: "11,100 ft",
    image: "https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=900&q=80"
  },
  {
    name: "Little Snake Overlook",
    type: "viewpoint",
    cell: "yes",
    access: "easy",
    tags: ["quiet", "shade"],
    elevation: "8,420 ft",
    image: "https://images.unsplash.com/photo-1470770841072-f978cf4d019e?auto=format&fit=crop&w=900&q=80"
  },
  {
    name: "Clear Creek Reservoir Camp",
    type: "campground",
    cell: "yes",
    access: "easy",
    tags: ["shade", "water"],
    elevation: "8,880 ft",
    image: "https://images.unsplash.com/photo-1445308394109-4ec2920981b1?auto=format&fit=crop&w=900&q=80"
  }
];

async function loadIncludes() {
  const includeNodes = document.querySelectorAll("[data-include]");
  for (const node of includeNodes) {
    const file = node.getAttribute("data-include");
    try {
      const response = await fetch(file);
      if (!response.ok) throw new Error(`${file} failed to load`);
      node.outerHTML = await response.text();
    } catch (error) {
      node.innerHTML = `<!-- Could not load ${file}. Use a local server, not file://, for HTML includes. -->`;
      console.warn(error);
    }
  }
  initHeader();
  setYear();
}

function initHeader() {
  const button = document.querySelector(".nav-toggle");
  const nav = document.querySelector("#siteNav");
  if (!button || !nav) return;
  button.addEventListener("click", () => {
    const isOpen = nav.classList.toggle("is-open");
    button.setAttribute("aria-expanded", String(isOpen));
    const icon = button.querySelector("i");
    if (icon) icon.className = isOpen ? "fa-solid fa-xmark" : "fa-solid fa-bars";
  });
}

function setYear() {
  const year = document.querySelector("#year");
  if (year) year.textContent = new Date().getFullYear();
}

function renderLocations() {
  const grid = document.querySelector("#locationGrid");
  if (!grid) return;

  const search = document.querySelector("#siteSearch")?.value.toLowerCase() || "";
  const type = document.querySelector("#siteType")?.value || "all";
  const cell = document.querySelector("#cellFilter")?.value || "all";
  const access = document.querySelector("#accessFilter")?.value || "all";
  const activeTags = [...document.querySelectorAll("[data-tag-filter]:checked")].map(input => input.dataset.tagFilter);

  const filtered = places.filter(place => {
    const matchesSearch = place.name.toLowerCase().includes(search);
    const matchesType = type === "all" || place.type === type;
    const matchesCell = cell === "all" || place.cell === cell;
    const matchesAccess = access === "all" || place.access === access;
    const matchesTags = activeTags.every(tag => place.tags.includes(tag));
    return matchesSearch && matchesType && matchesCell && matchesAccess && matchesTags;
  });

  grid.innerHTML = filtered.map(place => `
    <article class="location-card">
      <div class="card-image" style="background-image:url('${place.image}')"></div>
      <div class="card-body">
        <p class="card-kicker"><i class="fa-solid fa-${place.type === "trailhead" ? "person-hiking" : place.type === "viewpoint" ? "binoculars" : place.type === "campground" ? "campground" : "tent"}"></i> ${label(place.type)}</p>
        <h3>${place.name}</h3>
        <div class="meta-list">
          <span><i class="fa-solid fa-mountain"></i> ${place.elevation}</span>
          <span><i class="fa-solid fa-road"></i> ${label(place.access)}</span>
          <span><i class="fa-solid fa-signal"></i> ${place.cell === "yes" ? "Cell service" : "No cell service"}</span>
        </div>
        <p>${place.tags.map(tag => `<span class="pill">${label(tag)}</span>`).join(" ")}</p>
      </div>
    </article>
  `).join("") || `<p>No places match those filters yet.</p>`;
}

function label(value) {
  return value.replaceAll("-", " ").replace(/\b\w/g, letter => letter.toUpperCase());
}

function initFilters() {
  document.querySelectorAll("#siteSearch, #siteType, #cellFilter, #accessFilter, [data-tag-filter]").forEach(input => {
    input.addEventListener("input", renderLocations);
    input.addEventListener("change", renderLocations);
  });
}

function initMapMarkers() {
  const toast = document.querySelector("#mapToast");
  document.querySelectorAll(".map-marker").forEach(marker => {
    marker.addEventListener("click", () => {
      if (toast) toast.textContent = marker.dataset.place || "Selected marker";
    });
  });
}

document.addEventListener("DOMContentLoaded", async () => {
  await loadIncludes();
  initFilters();
  initMapMarkers();
  renderLocations();
});
