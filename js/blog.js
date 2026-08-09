document.addEventListener("DOMContentLoaded", initBlog);

let allPosts = [];

async function initBlog() {
  const grid = document.getElementById("blog-grid");
  if (!grid) return;

  try {
    const response = await fetch("data/posts.json");
    if (!response.ok) throw new Error("Could not load posts.json");

    allPosts = await response.json();

    allPosts.sort(
      (a, b) =>
        new Date(b.updated || b.published) -
        new Date(a.updated || a.published)
    );

    populateCategories(allPosts);
    bindBlogControls();
    renderPosts(allPosts);
  } catch (error) {
    console.error("AutismOverland blog error:", error);
    grid.innerHTML = `
      <div class="blog-error">
        <h2>The field guide could not be loaded.</h2>
      </div>
    `;
  }
}

function bindBlogControls() {
  document.getElementById("blog-search")
    ?.addEventListener("input", applyBlogFilters);

  document.getElementById("blog-category")
    ?.addEventListener("change", applyBlogFilters);
}

function applyBlogFilters() {
  const search =
    document.getElementById("blog-search")
      ?.value.trim().toLowerCase() || "";

  const category =
    document.getElementById("blog-category")
      ?.value || "all";

  const filtered = allPosts.filter((post) => {
    const searchable = [
      post.title,
      post.excerpt,
      post.category,
      ...(post.keywords || [])
    ].filter(Boolean).join(" ").toLowerCase();

    return (
      (!search || searchable.includes(search)) &&
      (category === "all" || post.category === category)
    );
  });

  renderPosts(filtered);
}

function populateCategories(posts) {
  const select = document.getElementById("blog-category");
  if (!select) return;

  [...new Set(posts.map((post) => post.category).filter(Boolean))]
    .sort()
    .forEach((category) => {
      const option = document.createElement("option");
      option.value = category;
      option.textContent = category;
      select.appendChild(option);
    });
}

function renderPosts(posts) {
  const grid = document.getElementById("blog-grid");
  const count = document.getElementById("blog-count");
  const empty = document.getElementById("blog-empty");
  if (!grid) return;

  grid.innerHTML = "";

  if (count) {
    count.textContent =
      posts.length === 1
        ? "1 field guide"
        : `${posts.length} field guides`;
  }

  if (!posts.length) {
    if (empty) empty.hidden = false;
    return;
  }

  if (empty) empty.hidden = true;

  posts.forEach((post) => {
    const article = document.createElement("article");
    article.className = "blog-index-card";

    article.innerHTML = `
      ${
        post.image
          ? `
            <a class="blog-index-image-link" href="post.html?post=${encodeURIComponent(post.slug)}">
              <img class="blog-index-image" src="${post.image}" alt="${escapeHTML(post.imageAlt || post.title)}">
            </a>
          `
          : ""
      }

      <div class="blog-index-card-body">
        <div class="blog-index-meta">
          <span>${escapeHTML(post.category)}</span>
          <span>${escapeHTML(post.readTime || "")}</span>
        </div>

        <h2>
          <a href="post.html?post=${encodeURIComponent(post.slug)}">
            ${escapeHTML(post.title)}
          </a>
        </h2>

        <p>${escapeHTML(post.excerpt)}</p>

        <div class="blog-index-footer">
          <span>Updated ${formatDate(post.updated || post.published)}</span>
          <a href="post.html?post=${encodeURIComponent(post.slug)}">
            Read guide <i class="fa-solid fa-arrow-right"></i>
          </a>
        </div>
      </div>
    `;

    grid.appendChild(article);
  });
}

function formatDate(value) {
  if (!value) return "";
  const date = new Date(`${value}T12:00:00`);
  return date.toLocaleDateString(
    "en-US",
    { year: "numeric", month: "short", day: "numeric" }
  );
}

function escapeHTML(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}
