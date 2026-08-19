document.addEventListener("DOMContentLoaded", initHomepageBlog);

async function initHomepageBlog() {
  const grid = document.getElementById("homepage-blog-grid");
  if (!grid) return;

  try {
    const response = await fetch("data/posts.json");
    if (!response.ok) throw new Error("Could not load posts.json");

    const posts = await response.json();

    posts.sort(
      (a, b) =>
        new Date(b.updated || b.published) -
        new Date(a.updated || a.published)
    );

    grid.innerHTML = posts.slice(0, 3).map((post) => `
      <article class="blog-card">
        ${
          post.image
            ? `
              <a href="post.php?post=${encodeURIComponent(post.slug)}">
                <img src="${post.image}" alt="${escapeHomeHTML(post.imageAlt || post.title)}">
              </a>
            `
            : ""
        }

        <div class="card-body">
          <p class="eyebrow">${escapeHomeHTML(post.category)}</p>

          <h3>
            <a href="post.php?post=${encodeURIComponent(post.slug)}">
              ${escapeHomeHTML(post.title)}
            </a>
          </h3>

          <p>${escapeHomeHTML(post.excerpt)}</p>

          <a class="small-btn" href="post.php?post=${encodeURIComponent(post.slug)}">
            Read Guide
          </a>
        </div>
      </article>
    `).join("");

  } catch (error) {
    console.error("Llama Scout homepage blog error:", error);
  }
}

function escapeHomeHTML(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}
