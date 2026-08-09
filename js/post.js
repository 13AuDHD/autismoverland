document.addEventListener("DOMContentLoaded", initPost);

async function initPost() {
  const page = document.getElementById("post-page");
  if (!page) return;

  const slug = new URLSearchParams(window.location.search).get("post");

  if (!slug) {
    renderPostNotFound(page);
    return;
  }

  try {
    const response = await fetch("data/posts.json");
    if (!response.ok) throw new Error("Could not load posts.json");

    const posts = await response.json();
    const post = posts.find((item) => item.slug === slug);

    if (!post) {
      renderPostNotFound(page);
      return;
    }

    renderPost(page, post, posts);
  } catch (error) {
    console.error("AutismOverland post error:", error);
    page.innerHTML = `
      <section class="post-error">
        <h1>Something went wrong.</h1>
        <p>This field guide could not be loaded.</p>
      </section>
    `;
  }
}

function renderPost(page, post, allPosts) {
  document.title = `${post.title} | AutismOverland`;

  const description = document.getElementById("post-meta-description");
  if (description) description.content = post.excerpt || "";

  const keywords = document.getElementById("post-meta-keywords");
  if (keywords) keywords.content = (post.keywords || []).join(", ");

  const related = allPosts
    .filter((item) => item.slug !== post.slug && item.category === post.category)
    .slice(0, 3);

  page.innerHTML = `
    <article class="field-guide">
      <header class="post-hero">
        <div class="container post-hero-inner">
          <a class="post-back" href="blog.html">
            <i class="fa-solid fa-arrow-left"></i>
            Field Guide
          </a>

          <p class="eyebrow">${escapeHTML(post.category)}</p>

          <h1>${escapeHTML(post.title)}</h1>

          <p class="post-deck">${escapeHTML(post.excerpt)}</p>

          <div class="post-meta">
            <span>
              <i class="fa-regular fa-clock"></i>
              ${escapeHTML(post.readTime || "")}
            </span>
            <span>
              <i class="fa-regular fa-calendar"></i>
              Updated ${formatDate(post.updated || post.published)}
            </span>
          </div>
        </div>
      </header>

      ${
        post.image
          ? `
            <div class="container post-featured-image">
              <img src="${post.image}" alt="${escapeHTML(post.imageAlt || post.title)}">
            </div>
          `
          : ""
      }

      <div class="container post-layout">
        <div class="post-content">
          ${renderSections(post.sections || [])}

          <section class="post-disclaimer">
            <h2>Before you go</h2>
            <p>
              Conditions, closures, fire restrictions, road surfaces,
              weather, and land-use rules can change quickly. Always
              check current information from the appropriate land
              manager before traveling.
            </p>
          </section>
        </div>

        <aside class="post-sidebar">
          <div class="post-sidebar-card">
            <h2>In this guide</h2>
            <nav>
              ${(post.sections || [])
                .map((section, index) => `
                  <a href="#section-${index + 1}">
                    ${escapeHTML(section.heading)}
                  </a>
                `)
                .join("")}
            </nav>
          </div>

          <div class="post-sidebar-card">
            <h2>Find a place</h2>
            <p>
              Compare road access, noise, privacy, connectivity,
              and sensory conditions.
            </p>
            <a class="small-btn" href="places.html">
              Browse Places
            </a>
          </div>
        </aside>
      </div>

      ${
        related.length
          ? `
            <section class="related-guides">
              <div class="container">
                <div class="section-heading">
                  <h2>Related Guides</h2>
                </div>

                <div class="related-guide-grid">
                  ${related.map((item) => `
                    <article>
                      <p class="eyebrow">${escapeHTML(item.category)}</p>
                      <h3>
                        <a href="post.html?post=${encodeURIComponent(item.slug)}">
                          ${escapeHTML(item.title)}
                        </a>
                      </h3>
                      <p>${escapeHTML(item.excerpt)}</p>
                    </article>
                  `).join("")}
                </div>
              </div>
            </section>
          `
          : ""
      }
    </article>
  `;
}

function renderSections(sections) {
  return sections.map((section, index) => `
    <section id="section-${index + 1}" class="post-section">
      <h2>${escapeHTML(section.heading)}</h2>

      ${(section.paragraphs || [])
        .map((paragraph) => `<p>${escapeHTML(paragraph)}</p>`)
        .join("")}

      ${
        section.bullets?.length
          ? `
            <ul>
              ${section.bullets
                .map((bullet) => `<li>${escapeHTML(bullet)}</li>`)
                .join("")}
            </ul>
          `
          : ""
      }

      ${
        section.tip
          ? `
            <aside class="post-tip">
              <i class="fa-solid fa-circle-info"></i>
              <p>${escapeHTML(section.tip)}</p>
            </aside>
          `
          : ""
      }
    </section>
  `).join("");
}

function renderPostNotFound(page) {
  page.innerHTML = `
    <section class="post-error">
      <i class="fa-solid fa-compass"></i>
      <h1>Guide not found.</h1>
      <p>That field guide does not exist or has moved.</p>
      <a class="small-btn" href="blog.html">Browse the Field Guide</a>
    </section>
  `;
}

function formatDate(value) {
  if (!value) return "";
  const date = new Date(`${value}T12:00:00`);
  return date.toLocaleDateString(
    "en-US",
    { year: "numeric", month: "long", day: "numeric" }
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
