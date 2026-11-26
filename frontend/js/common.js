// Helpers shared by every page. Anything typed by a user is passed through esc()
// before it goes into innerHTML.

const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
const esc = (value) => String(value ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
const initials = (name) => name.split(/\s+/).filter(Boolean).slice(-2).map((w) => w[0].toUpperCase()).join("");
const fmtAge = (hours) => (hours < 24 ? hours.toFixed(1) + "h" : Math.floor(hours / 24) + "d " + Math.round(hours % 24) + "h");
const fmtDate = (iso) => new Date(iso).toLocaleString();
const STAGE_LABELS = { new: "New", assigned: "Assigned", in_progress: "In progress", resolved: "Resolved", closed: "Closed" };
const stageLabel = (status) => STAGE_LABELS[status] || status;
const isDone = (status) => status === "resolved" || status === "closed";

const OFFLINE_MESSAGE = "We couldn't connect just now. Please try again shortly.";

/** Calls the JSON API on the same origin. Throws an Error with a message that is safe to show. */
async function api(path, { method = "GET", body, token, form } = {}) {
  const headers = {};
  let payload;
  if (token) headers.Authorization = "Bearer " + token;
  if (form) {
    payload = new URLSearchParams(form);
    headers["Content-Type"] = "application/x-www-form-urlencoded";
  } else if (body !== undefined) {
    payload = JSON.stringify(body);
    headers["Content-Type"] = "application/json";
  }

  let res;
  try {
    res = await fetch("/api" + path, { method, headers, body: payload });
  } catch (error) {
    console.error("API request failed:", path, error);
    throw new Error(OFFLINE_MESSAGE);
  }

  const data = await res.json().catch(() => null);
  if (res.ok && data !== null) return data;
  if (!res.ok && data && typeof data.detail === "string" && res.status < 500 && data.detail !== "Not Found") {
    throw new Error(data.detail);
  }
  console.error("Unexpected API response:", path, res.status);
  throw new Error(OFFLINE_MESSAGE);
}

/* Public site header and footer. Staff pages live under /staff/ and are not linked from here. */
const NAV = [
  ["/", "Home"],
  ["/submit.html", "Submit a concern"],
  ["/track.html", "Track"],
  ["/community.html", "Guild updates"],
  ["/feed.html", "Public concerns"],
  ["/cabinet.html", "Cabinet"],
  ["/privacy.html", "Privacy"],
];

function renderChrome(active) {
  const header = $("#hdr");
  if (header) {
    header.outerHTML = `<header class="top">
      <a class="brand" href="/"><span class="logo"><img src="/assets/logo.png" alt="ISBAT University"></span>
        <span><b>Saagi SyncCampus</b><small>ISBAT University · Student concerns</small></span></a>
      <nav>${NAV.map(([href, label]) => `<a href="${href}"${href === active ? ' class="active"' : ""}>${label}</a>`).join("")}</nav>
    </header>`;
  }
  const footer = $("#ftr");
  if (footer) {
    footer.outerHTML = `<footer class="site">
      <div><b>Saagi SyncCampus</b><br><span class="muted">Student concern management and leadership decision support for the ISBAT University Guild.</span></div>
      <div class="links"><a href="/submit.html">Submit a concern</a><a href="/track.html">Track a concern</a><a href="/cabinet.html">Meet the cabinet</a><a href="/privacy.html">Privacy &amp; accountability</a></div>
    </footer>`;
  }
}

/** Cabinet member card, used on the home page and the cabinet page. */
function personCard(person, { details = false } = {}) {
  const position = person.position.toLowerCase();
  const president = position.includes("president") && !position.includes("vice");
  const portrait = person.photo
    ? `<img class="portrait" src="${esc(person.photo)}" alt="Portrait of ${esc(person.name)}" loading="lazy">`
    : `<div class="av">${esc(initials(person.name))}</div>`;
  const more = details && person.responsibilities
    ? `<details class="responsibilities"><summary>Responsibilities</summary><p>${esc(person.responsibilities)}</p></details>`
    : "";
  return `<article class="person${president ? " pres" : ""}">${portrait}<b>${esc(person.name)}</b><div class="role">${esc(person.position)}</div>${more}</article>`;
}

async function loadCabinet() {
  const res = await fetch("/data/cabinet.json");
  if (!res.ok) throw new Error("Cabinet data unavailable");
  return res.json();
}

if (document.body.dataset.page) renderChrome(document.body.dataset.page);
if ("serviceWorker" in navigator) navigator.serviceWorker.register("/sw.js").catch(() => {});
