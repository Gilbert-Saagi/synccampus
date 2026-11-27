// Guild workspace: a small management app for the cabinet.
// Leaders (President / Vice) see everything and manage it; delegates see projects,
// the calendar, updates, chat and handover records, and update their own tasks.

let token = null;
try { token = localStorage.getItem("saagi_token_staff"); } catch {}
const call = (path, options = {}) => api(path, { ...options, token });

const TASK_STATES = { open: "Open", in_progress: "In progress", completed: "Completed" };
const SUGGESTION_STATES = ["new", "reviewing", "accepted", "declined", "implemented"];
const KIND_LABELS = { project: "Project", announcement: "Announcement", event: "Meeting / event", media: "Media link" };

// Chart colours: the first three slots of the validated categorical palette.
const SERIES = ["#257de0", "#f47230", "#00b894"];

const ICON_PATHS = {
  overview: "M3 3h8v8H3zM13 3h8v5h-8zM13 10h8v11h-8zM3 13h8v8H3z",
  projects: "M3 6a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z",
  calendar: "M4 6h16v14H4zM4 10h16M8 3v4M16 3v4",
  updates: "M3 11v2l13 5V6zM16 9a3 3 0 0 1 0 6M6 14l1 5h3l-1-4",
  surveys: "M5 20V10M12 20V4M19 20v-7",
  suggestions: "M9 18h6M10 21h4M12 3a6 6 0 0 0-4 10.5V16h8v-2.5A6 6 0 0 0 12 3z",
  team: "M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21a7 7 0 0 1 14 0M17 11a3 3 0 1 0 0-6M22 21a6 6 0 0 0-5-6",
  chat: "M4 5h16v11H8l-4 4z",
  handover: "M4 7h16v13H4zM4 7l2-3h12l2 3M10 12h4",
  tasks: "M9 11l3 3 8-8M20 12v7a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h11",
  concerns: "M12 3l9 16H3zM12 10v4M12 17h.01",
};
const icon = (name) => `<svg class="ws-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="${ICON_PATHS[name] || ""}"/></svg>`;

const VIEWS = [
  { id: "overview", label: "Overview", sub: "How the Guild's work is going at a glance." },
  { id: "projects", label: "Projects", sub: "Projects and the tasks that make them up." },
  { id: "calendar", label: "Calendar", sub: "Meetings, events and due dates." },
  { id: "updates", label: "Updates", sub: "Announcements, events and media links, for staff or for students." },
  { id: "surveys", label: "Surveys", sub: "Ask students a question and see the results.", leader: true },
  { id: "suggestions", label: "Suggestions", sub: "Anonymous ideas from students.", leader: true },
  { id: "team", label: "Team", sub: "Your delegates, their workload, and invitations for new ones.", leader: true },
  { id: "chat", label: "Chat", sub: "Internal messages between Guild staff." },
  { id: "handover", label: "Handover", sub: "Records saved for the next leadership team." },
];

let me = null;
let isLeader = false;
let data = {};
let calendarMonth = new Date(new Date().getFullYear(), new Date().getMonth(), 1);

/* ---------- start-up and data ---------- */

async function boot() {
  if (!token) return;
  try {
    me = await call("/auth/me");
    if (!["president", "vice_guild", "delegate"].includes(me.role)) throw new Error("The workspace is for Guild leaders and delegates.");
  } catch (error) {
    $("#workspaceLogin").innerHTML = `<h2>Guild workspace</h2><p class="err">${esc(error.message)}</p><a class="btn" href="/staff/">Staff sign in</a>`;
    return;
  }
  isLeader = me.role === "president" || me.role === "vice_guild";
  $("#workspaceLogin").classList.add("hide");
  $("#workspace").classList.remove("hide");
  $("#wsUser").innerHTML = `<span class="ws-avatar">${esc(initials(me.name))}</span><span>${esc(me.name)}</span>`;
  renderNav();
  await reload();
  // Switching section closes any open form first.
  window.addEventListener("hashchange", () => {
    if ($("#wsDialog").open) $("#wsDialog").close();
    show();
  });
}

async function reload() {
  const [items, messages, handovers, workload, trends, surveys, suggestions, invitations] = await Promise.all([
    call("/workspace/items"),
    call("/staff/messages"),
    call("/leader/handover"),
    isLeader ? call("/leader/workload") : [],
    isLeader ? call("/leader/trends") : null,
    isLeader ? call("/leader/surveys") : [],
    isLeader ? call("/leader/suggestions") : [],
    isLeader ? call("/leader/invitations") : [],
  ]);
  data = { items, messages, handovers, workload, trends, surveys, suggestions, invitations };
  show();
}

const visibleViews = () => VIEWS.filter((v) => !v.leader || isLeader);
const currentView = () => {
  const id = location.hash.slice(1);
  return visibleViews().find((v) => v.id === id) || visibleViews()[0];
};

function renderNav() {
  $("#wsNav").innerHTML = visibleViews().map((v) => `<a href="#${v.id}" data-nav="${v.id}">${icon(v.id)}<span>${v.label}</span></a>`).join("");
}

function show() {
  const view = currentView();
  $$("#wsNav a").forEach((a) => a.classList.toggle("active", a.dataset.nav === view.id));
  $("#wsTitle").textContent = view.label;
  $("#wsSub").textContent = view.sub;
  $("#wsActions").innerHTML = actionsFor(view.id);
  $("#wsView").innerHTML = RENDER[view.id]();
  BIND[view.id]?.();
  $$("[data-open]").forEach((b) => (b.onclick = () => openDialog(b.dataset.open)));
}

/* ---------- derived numbers ---------- */

const projects = () => data.items.filter((x) => x.kind === "project");
const allTasks = () => projects().flatMap((p) => p.tasks);
const daysFromNow = (iso) => (new Date(iso).getTime() - Date.now()) / 86400000;

function calendarEntries() {
  const entries = [];
  for (const item of data.items) {
    if (item.starts_at) entries.push({ at: item.starts_at, title: item.title, kind: item.kind === "project" ? "deadline" : item.kind, where: item.location });
    if (item.due_at) entries.push({ at: item.due_at, title: item.title + " (due)", kind: "deadline", where: "" });
    for (const task of item.tasks) {
      if (task.due_at && task.status !== "completed") entries.push({ at: task.due_at, title: task.title + " (task due)", kind: "deadline", where: task.assigned_to || "" });
    }
  }
  return entries.sort((a, b) => new Date(a.at) - new Date(b.at));
}

/* ---------- charts ---------- */

// Donut with a 2px surface gap between segments; every segment is also named with its
// count in the legend beside it, so it never relies on colour alone.
function donut(parts, centerLabel) {
  const total = parts.reduce((s, p) => s + p.value, 0);
  if (!total) return '<p class="muted">Nothing to show yet.</p>';
  const r = 52, c = 2 * Math.PI * r, gap = parts.filter((p) => p.value).length > 1 ? 2 : 0;
  let offset = 0;
  const arcs = parts.filter((p) => p.value).map((p) => {
    const len = (p.value / total) * c;
    const arc = `<circle cx="70" cy="70" r="${r}" fill="none" stroke="${p.color}" stroke-width="18"
      stroke-dasharray="${Math.max(len - gap, 0.5)} ${c}" stroke-dashoffset="${-offset}" transform="rotate(-90 70 70)">
      <title>${esc(p.label)}: ${p.value} (${Math.round((100 * p.value) / total)}%)</title></circle>`;
    offset += len;
    return arc;
  }).join("");
  return `<div class="ws-donut">
      <svg viewBox="0 0 140 140" role="img" aria-label="${esc(centerLabel)}">${arcs}
        <text x="70" y="68" text-anchor="middle" class="ws-donut-total">${total}</text>
        <text x="70" y="86" text-anchor="middle" class="ws-donut-label">${esc(centerLabel)}</text></svg>
      <ul class="ws-legend">${parts.map((p) => `<li><i style="background:${p.color}"></i><span>${esc(p.label)}</span><b>${p.value}</b></li>`).join("")}</ul>
    </div>`;
}

// Single-series column chart: one hue, rounded tops, value on each column (few columns).
function columns(rows, unit) {
  if (!rows.length) return '<p class="muted">No data yet.</p>';
  const peak = Math.max(1, ...rows.map((r) => r.value));
  return `<div class="ws-columns" role="img" aria-label="${esc(unit)}">${rows.map((r) => `
      <div class="ws-col" title="${esc(r.label)}: ${r.value} ${esc(unit)}">
        <b>${r.value}</b><span class="ws-col-bar" style="height:${Math.max(4, (r.value / peak) * 100)}%"></span><small>${esc(r.short || r.label)}</small>
      </div>`).join("")}</div>`;
}

// Single-series horizontal bars with the value at the end.
function hbars(rows) {
  if (!rows.length) return '<p class="muted">No data yet.</p>';
  const peak = Math.max(1, ...rows.map((r) => r.value));
  return `<div class="ws-hbars">${rows.map((r) => `<div class="ws-hbar" title="${esc(r.label)}: ${r.value}">
      <span>${esc(r.label)}</span><div class="ws-hbar-track"><i style="width:${(r.value / peak) * 100}%"></i></div><b>${r.value}</b></div>`).join("")}</div>`;
}

const progressBar = (done, total) => {
  const pct = total ? Math.round((100 * done) / total) : 0;
  return `<div class="ws-progress" title="${done} of ${total} tasks completed"><i style="width:${pct}%"></i></div><small class="muted">${pct}% · ${done}/${total} tasks</small>`;
};

/* ---------- views ---------- */

function actionsFor(view) {
  if (!isLeader) return "";
  const buttons = {
    overview: [["item", "+ New item"]],
    projects: [["project", "+ New project"]],
    calendar: [["event", "+ New event"]],
    updates: [["item", "+ New update"]],
    surveys: [["survey", "+ New survey"]],
    team: [["invite", "+ Invite a delegate"]],
  }[view] || [];
  return buttons.map(([kind, label]) => `<button class="btn" data-open="${kind}">${label}</button>`).join("");
}

const RENDER = {
  overview() {
    const tasks = allTasks();
    const myTasks = tasks.filter((t) => t.assigned_to_user_id === me.id && t.status !== "completed");
    const weekEvents = calendarEntries().filter((e) => daysFromNow(e.at) >= -0.5 && daysFromNow(e.at) <= 7);
    const tiles = [
      ["projects", "Active projects", projects().filter((p) => p.status !== "completed").length, "#projects"],
      ["tasks", isLeader ? "Open tasks" : "My open tasks", isLeader ? tasks.filter((t) => t.status !== "completed").length : myTasks.length, "#projects"],
      ["calendar", "This week", weekEvents.length, "#calendar"],
      ...(isLeader ? [
        ["surveys", "Survey responses", data.surveys.reduce((s, x) => s + x.response_count, 0), "#surveys"],
        ["suggestions", "New suggestions", data.suggestions.filter((s) => s.status === "new").length, "#suggestions"],
        ["team", "Delegates", data.workload.length, "#team"],
      ] : [["chat", "Messages", data.messages.length, "#chat"]]),
    ];

    const taskParts = [
      { label: "Open", value: tasks.filter((t) => t.status === "open").length, color: SERIES[0] },
      { label: "In progress", value: tasks.filter((t) => t.status === "in_progress").length, color: SERIES[1] },
      { label: "Completed", value: tasks.filter((t) => t.status === "completed").length, color: SERIES[2] },
    ];
    const upcoming = calendarEntries().filter((e) => daysFromNow(e.at) >= -0.5).slice(0, 6);

    const counts = new Map(isLeader ? data.trends.monthly.map((m) => [m.month, m.count]) : []);
    const months = isLeader ? [5, 4, 3, 2, 1, 0].map((back) => {
      const d = new Date(new Date().getFullYear(), new Date().getMonth() - back, 1);
      const key = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
      return { label: d.toLocaleDateString(undefined, { month: "long", year: "numeric" }), value: counts.get(key) || 0,
               short: d.toLocaleDateString(undefined, { month: "short" }) };
    }) : [];
    const suggestionRows = isLeader ? SUGGESTION_STATES.map((s) => ({ label: s[0].toUpperCase() + s.slice(1), value: data.suggestions.filter((x) => x.status === s).length })) : [];

    return `<div class="ws-tiles">${tiles.map(([ic, label, value, href]) => `<a class="ws-tile" href="${href}">
        <span class="ws-tile-icon">${icon(ic)}</span><span><small>${label}</small><b>${value}</b></span></a>`).join("")}</div>
      <div class="ws-grid">
        <section class="ws-card"><h2>Task progress</h2><p class="sub">All project tasks by status</p>${donut(taskParts, "tasks")}</section>
        ${isLeader ? `<section class="ws-card"><h2>Concerns per month</h2><p class="sub">Last six months</p>${columns(months, "concerns")}</section>` : ""}
        <section class="ws-card"><h2>Upcoming activities</h2><p class="sub">Next meetings, events and deadlines</p>
          ${upcoming.map((e) => `<div class="ws-activity"><span class="ws-date"><b>${new Date(e.at).getDate()}</b><small>${new Date(e.at).toLocaleDateString(undefined, { month: "short" })}</small></span>
            <span><b>${esc(e.title)}</b><small class="muted">${new Date(e.at).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}${e.where ? " · " + esc(e.where) : ""}</small></span>
            <span class="ws-chip ws-chip-${e.kind}">${esc(e.kind === "deadline" ? "Deadline" : KIND_LABELS[e.kind] || e.kind)}</span></div>`).join("") || '<p class="muted">Nothing scheduled.</p>'}
          <a class="more" href="#calendar">Open the calendar →</a></section>
        ${isLeader ? `<section class="ws-card"><h2>Suggestions by status</h2><p class="sub">${data.suggestions.length} from students</p>${hbars(suggestionRows)}</section>` : ""}
      </div>
      <section class="ws-card"><h2>Projects</h2>
        ${projects().length ? `<div class="tablewrap"><table><thead><tr><th>Project</th><th>Progress</th><th>Due</th><th>Visibility</th></tr></thead><tbody>
          ${projects().map((p) => `<tr><td><b>${esc(p.title)}</b><br><small class="muted">${esc(p.description).slice(0, 90)}</small></td>
            <td class="ws-progress-cell">${progressBar(p.tasks.filter((t) => t.status === "completed").length, p.tasks.length)}</td>
            <td>${p.due_at ? new Date(p.due_at).toLocaleDateString() : "–"}</td><td>${p.visibility === "public" ? "Public" : "Staff"}</td></tr>`).join("")}
          </tbody></table></div>` : `<p class="muted">No projects yet.${isLeader ? ' <button class="btn sm alt" data-open="project">Create the first one</button>' : ""}</p>`}
      </section>`;
  },

  projects() {
    const list = projects();
    if (!list.length) return `<div class="ws-card ws-empty"><p>No projects yet.</p>${isLeader ? '<button class="btn" data-open="project">Create a project</button>' : ""}</div>`;
    return `<div class="ws-grid">${list.map((p) => {
      const done = p.tasks.filter((t) => t.status === "completed").length;
      const tasks = p.tasks.map((t) => {
        const canUpdate = isLeader || t.assigned_to_user_id === me.id;
        return `<li class="ws-task ws-task-${t.status}"><span><b>${esc(t.title)}</b><small class="muted">${esc(t.assigned_to || "Unassigned")}${t.due_at ? " · due " + new Date(t.due_at).toLocaleDateString() : ""}</small></span>
          ${canUpdate ? `<select data-task="${t.id}" aria-label="Status of ${esc(t.title)}">${Object.entries(TASK_STATES).map(([v, l]) => `<option value="${v}"${v === t.status ? " selected" : ""}>${l}</option>`).join("")}</select>`
                      : `<span class="ws-chip">${TASK_STATES[t.status]}</span>`}</li>`;
      }).join("");
      return `<section class="ws-card"><div class="ws-card-head"><h2>${esc(p.title)}</h2><span class="ws-chip">${p.visibility === "public" ? "Public" : "Staff"}</span></div>
        <p>${esc(p.description)}</p>${progressBar(done, p.tasks.length)}
        <ul class="ws-tasks">${tasks || '<li class="muted">No tasks yet.</li>'}</ul>
        ${isLeader ? `<form class="ws-inline" data-task-form="${p.id}">
            <input name="title" required minlength="2" placeholder="New task">
            <select name="assigned_to_user_id"><option value="">Unassigned</option>${data.workload.map((d) => `<option value="${d.user_id}">${esc(d.name)}</option>`).join("")}</select>
            <input name="due_at" type="date" aria-label="Due date">
            <button class="btn sm">Add</button></form>` : ""}</section>`;
    }).join("")}</div>`;
  },

  calendar() {
    const year = calendarMonth.getFullYear(), month = calendarMonth.getMonth();
    const first = new Date(year, month, 1);
    const start = new Date(year, month, 1 - ((first.getDay() + 6) % 7)); // Monday first
    const entries = calendarEntries();
    const key = (d) => d.toDateString();
    const byDay = new Map();
    for (const e of entries) {
      const k = key(new Date(e.at));
      if (!byDay.has(k)) byDay.set(k, []);
      byDay.get(k).push(e);
    }
    const today = key(new Date());
    let cells = "";
    for (let i = 0; i < 42; i++) {
      const d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
      const list = byDay.get(key(d)) || [];
      cells += `<div class="ws-day${d.getMonth() !== month ? " other" : ""}${key(d) === today ? " today" : ""}">
          <span class="ws-day-n">${d.getDate()}</span>
          ${list.slice(0, 3).map((e) => `<span class="ws-chip ws-chip-${e.kind}" title="${esc(e.title)}">${esc(e.title)}</span>`).join("")}
          ${list.length > 3 ? `<small class="muted">+${list.length - 3} more</small>` : ""}</div>`;
    }
    const monthEntries = entries.filter((e) => new Date(e.at).getMonth() === month && new Date(e.at).getFullYear() === year);
    return `<section class="ws-card">
        <div class="ws-card-head"><h2>${first.toLocaleDateString(undefined, { month: "long", year: "numeric" })}</h2>
          <div class="actions"><button class="btn sm alt" data-month="-1" aria-label="Previous month">‹</button>
          <button class="btn sm alt" data-month="0">Today</button><button class="btn sm alt" data-month="1" aria-label="Next month">›</button></div></div>
        <div class="ws-legend-row"><span class="ws-chip ws-chip-event">Meeting / event</span><span class="ws-chip ws-chip-announcement">Announcement</span><span class="ws-chip ws-chip-deadline">Deadline</span></div>
        <div class="ws-cal">${["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"].map((d) => `<b class="ws-cal-head">${d}</b>`).join("")}${cells}</div>
      </section>
      <section class="ws-card"><h2>This month</h2>
        ${monthEntries.map((e) => `<div class="ws-activity"><span class="ws-date"><b>${new Date(e.at).getDate()}</b><small>${new Date(e.at).toLocaleDateString(undefined, { weekday: "short" })}</small></span>
          <span><b>${esc(e.title)}</b><small class="muted">${new Date(e.at).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}${e.where ? " · " + esc(e.where) : ""}</small></span></div>`).join("") || '<p class="muted">Nothing this month.</p>'}
      </section>`;
  },

  updates() {
    const list = data.items.filter((x) => x.kind !== "project");
    if (!list.length) return `<div class="ws-card ws-empty"><p>Nothing posted yet.</p>${isLeader ? '<button class="btn" data-open="item">Post an update</button>' : ""}</div>`;
    return `<section class="ws-card"><div class="tablewrap"><table><thead><tr><th>Title</th><th>Type</th><th>When</th><th>Who sees it</th></tr></thead><tbody>
      ${list.map((x) => `<tr><td><b>${esc(x.title)}</b><br><small class="muted">${esc(x.description)}</small>
          ${x.url ? `<br><a class="more" href="${esc(x.url)}" target="_blank" rel="noopener">Open link</a>` : ""}</td>
        <td><span class="ws-chip ws-chip-${x.kind}">${esc(KIND_LABELS[x.kind])}</span></td>
        <td>${x.starts_at ? fmtDate(x.starts_at) : "–"}${x.location ? `<br><small class="muted">${esc(x.location)}</small>` : ""}</td>
        <td>${x.visibility === "public" ? "Students and staff" : "Staff only"}</td></tr>`).join("")}</tbody></table></div></section>`;
  },

  surveys() {
    if (!data.surveys.length) return '<div class="ws-card ws-empty"><p>No surveys yet.</p><button class="btn" data-open="survey">Create a survey</button></div>';
    return `<div class="ws-grid">${data.surveys.map((s) => `<section class="ws-card">
        <div class="ws-card-head"><h2>${esc(s.title)}</h2><span class="ws-chip${s.is_published ? " ws-chip-live" : ""}">${s.is_published ? "Published" : "Draft"}</span></div>
        <p>${esc(s.question)}</p>
        ${hbars(s.options.map((o) => ({ label: o, value: s.results[o] ?? 0 })))}
        <p class="muted small">${s.response_count} response(s)</p>
        <button class="btn sm alt" data-publish="${s.id}">${s.is_published ? "Unpublish" : "Publish to students"}</button></section>`).join("")}</div>`;
  },

  suggestions() {
    if (!data.suggestions.length) return '<div class="ws-card ws-empty"><p>No suggestions from students yet.</p></div>';
    return `<section class="ws-card"><div class="tablewrap"><table><thead><tr><th>Suggestion</th><th>Received</th><th>Status and reply</th></tr></thead><tbody>
      ${data.suggestions.map((s) => `<tr><td><b>${esc(s.title)}</b> <small class="muted">${esc(s.reference)}</small><br><small>${esc(s.description)}</small></td>
        <td>${new Date(s.created_at).toLocaleDateString()}</td>
        <td><form class="ws-inline" data-suggestion="${s.id}">
          <select name="status">${SUGGESTION_STATES.map((x) => `<option${x === s.status ? " selected" : ""}>${x}</option>`).join("")}</select>
          <input name="message" placeholder="Reply to the student (optional)"><button class="btn sm alt">Save</button></form></td></tr>`).join("")}
      </tbody></table></div></section>`;
  },

  team() {
    const pending = data.invitations.filter((i) => i.status === "pending");
    return `<section class="ws-card"><div class="ws-card-head"><h2>Delegates</h2></div>
        ${data.workload.length ? `<div class="tablewrap"><table><thead><tr><th>Name</th><th>Portfolio</th><th>Campus</th><th>Workload</th><th>Status</th></tr></thead><tbody>
          ${data.workload.map((d) => {
            const ratio = d.max ? d.open / d.max : 1;
            return `<tr><td><span class="ws-avatar sm">${esc(initials(d.name))}</span> <b>${esc(d.name)}</b></td><td>${esc(d.portfolio)}</td><td>${esc(d.campus || "Both")}</td>
              <td class="ws-progress-cell"><div class="ws-progress${ratio > 0.8 ? " high" : ""}"><i style="width:${Math.min(100, ratio * 100)}%"></i></div><small class="muted">${d.open} of ${d.max} open</small></td>
              <td>${d.available ? '<span class="ws-chip ws-chip-live">Available</span>' : '<span class="ws-chip">Away</span>'}</td></tr>`;
          }).join("")}</tbody></table></div>` : '<p class="muted">No delegates yet. Invite your ministers and GRCs.</p>'}
      </section>
      <section class="ws-card"><h2>Invitations</h2><p class="sub">${pending.length} waiting to be used. Links work once and expire after 7 days.</p>
        ${data.invitations.length ? `<div class="tablewrap"><table><thead><tr><th>For</th><th>Portfolio</th><th>Sent by</th><th>Status</th><th></th></tr></thead><tbody>
          ${data.invitations.map((i) => `<tr><td>${esc(i.invitee_name || "–")}<br><small class="muted">${esc(i.invitee_email || "")}</small></td><td>${esc(i.portfolio)}</td>
            <td>${esc(i.created_by)}<br><small class="muted">${fmtDate(i.created_at)}</small></td>
            <td><span class="ws-chip${i.status === "used" ? " ws-chip-live" : ""}">${i.status}</span></td>
            <td>${i.status === "pending" ? `<button class="btn sm alt" data-revoke="${i.id}">Cancel</button>` : ""}</td></tr>`).join("")}
          </tbody></table></div>` : '<p class="muted">No invitations sent yet.</p>'}
      </section>`;
  },

  chat() {
    return `<section class="ws-card ws-chat">
        <div class="ws-messages" id="wsMessages">${data.messages.map((m) => {
          const mine = m.author === me.name;
          return `<div class="ws-msg${mine ? " mine" : ""}"><small>${esc(mine ? "You" : m.author)} · ${fmtDate(m.at)}</small><p>${esc(m.message)}</p></div>`;
        }).join("") || '<p class="muted">No messages yet. Say hello to the team.</p>'}</div>
        <form id="chatForm" class="ws-inline"><input name="message" required maxlength="3000" placeholder="Message Guild staff" autocomplete="off"><button class="btn">Send</button></form>
      </section>`;
  },

  handover() {
    if (!data.handovers.length) return '<div class="ws-card ws-empty"><p>No handover records yet. The Guild President or Vice creates one from the concern dashboard at the end of a term.</p></div>';
    return data.handovers.map((r) => {
      const s = r.snapshot;
      const history = s.resolved_history || { total: 0, by_category: {} };
      return `<details class="ws-card"><summary><b>${esc(r.term)}</b> · ${fmtDate(r.generated_at)} · by ${esc(s.generated_by)}</summary>
        <h3>Unresolved concerns (${s.count})</h3>
        ${s.open_concerns.map((c) => `<div>${esc(c.reference)} – ${esc(c.title)} · ${esc(c.priority)} · ${esc(c.assigned_to || "unassigned")}${c.last_note ? `<br><small class="muted">Last note: ${esc(c.last_note)}</small>` : ""}</div>`).join("") || '<p class="muted">None.</p>'}
        <h3>Projects and pending tasks</h3>
        ${s.active_projects.map((p) => `<div><b>${esc(p.title)}</b>: ${p.tasks.map((t) => esc(t.title) + " (" + esc(t.assigned_to || "unassigned") + ")").join(", ") || "no open tasks"}</div>`).join("") || '<p class="muted">None.</p>'}
        <h3>Resolved history</h3><p>${history.total} resolved. ${Object.entries(history.by_category).map(([k, v]) => esc(k) + ": " + v).join(", ")}</p>
        ${s.delegate_roles ? `<h3>Roles and responsibilities</h3>${s.delegate_roles.map((d) => `<div>${esc(d.name)} – ${esc(d.portfolio)}${d.categories_handled ? " (" + esc(d.categories_handled) + ")" : ""}</div>`).join("")}` : ""}
        ${s.upcoming_meetings && s.upcoming_meetings.length ? `<h3>Upcoming meetings and events</h3>${s.upcoming_meetings.map((m) => `<div>${fmtDate(m.starts_at)} – ${esc(m.title)}${m.location ? " · " + esc(m.location) : ""}</div>`).join("")}` : ""}
      </details>`;
    }).join("");
  },
};

/* ---------- view behaviour ---------- */

async function act(work) {
  try {
    await work();
    await reload();
  } catch (error) {
    alert(error.message);
  }
}

const BIND = {
  projects() {
    $$("[data-task]").forEach((select) => {
      select.onchange = () => act(() => call(`/workspace/tasks/${select.dataset.task}`, { method: "PATCH", body: { status: select.value } }));
    });
    $$("[data-task-form]").forEach((form) => {
      form.onsubmit = (e) => {
        e.preventDefault();
        const f = new FormData(form);
        act(() => call(`/workspace/projects/${form.dataset.taskForm}/tasks`, { method: "POST", body: {
          title: f.get("title"),
          assigned_to_user_id: f.get("assigned_to_user_id") ? Number(f.get("assigned_to_user_id")) : null,
          due_at: f.get("due_at") ? new Date(f.get("due_at") + "T17:00:00").toISOString() : null,
        } }));
      };
    });
  },
  calendar() {
    $$("[data-month]").forEach((b) => {
      b.onclick = () => {
        const step = Number(b.dataset.month);
        calendarMonth = step === 0 ? new Date(new Date().getFullYear(), new Date().getMonth(), 1)
          : new Date(calendarMonth.getFullYear(), calendarMonth.getMonth() + step, 1);
        show();
      };
    });
  },
  surveys() {
    $$("[data-publish]").forEach((b) => (b.onclick = () => act(() => call(`/leader/surveys/${b.dataset.publish}/publish`, { method: "PATCH" }))));
  },
  suggestions() {
    $$("[data-suggestion]").forEach((form) => {
      form.onsubmit = (e) => {
        e.preventDefault();
        const f = new FormData(form);
        act(() => call(`/leader/suggestions/${form.dataset.suggestion}/update`, { method: "POST", body: { status: f.get("status"), message: f.get("message") } }));
      };
    });
  },
  team() {
    $$("[data-revoke]").forEach((b) => (b.onclick = () => act(() => call(`/leader/invitations/${b.dataset.revoke}/revoke`, { method: "POST" }))));
  },
  chat() {
    const box = $("#wsMessages");
    box.scrollTop = box.scrollHeight;
    $("#chatForm").onsubmit = (e) => {
      e.preventDefault();
      const message = new FormData(e.target).get("message");
      act(() => call("/staff/messages", { method: "POST", body: { message } }));
    };
  },
};

/* ---------- dialogs (all "+ New" forms) ---------- */

const ITEM_FORM = (kind) => `
  <div class="row">
    <div><label>Type</label><select name="kind">${Object.entries(KIND_LABELS).map(([v, l]) => `<option value="${v}"${v === kind ? " selected" : ""}>${l}</option>`).join("")}</select></div>
    <div><label>Who can see it</label><select name="visibility"><option value="staff">Staff only</option><option value="public">Students and staff</option></select></div>
  </div>
  <label>Title</label><input name="title" required minlength="2" maxlength="200">
  <label>Description</label><textarea name="description" maxlength="5000"></textarea>
  <div class="row">
    <div><label>Date / start time</label><input name="starts_at" type="datetime-local"></div>
    <div><label>Due date</label><input name="due_at" type="datetime-local"></div>
  </div>
  <div class="row">
    <div><label>Location</label><input name="location" maxlength="160"></div>
    <div><label>Link (photos, documents)</label><input name="url" type="url" maxlength="1000" placeholder="https://"></div>
  </div>`;

const DIALOGS = {
  item: { title: "New workspace item", form: () => ITEM_FORM("announcement"), save: saveItem },
  project: { title: "New project", form: () => ITEM_FORM("project"), save: saveItem },
  event: { title: "New meeting or event", form: () => ITEM_FORM("event"), save: saveItem },
  survey: {
    title: "New survey",
    form: () => `<label>Title</label><input name="title" required maxlength="200">
      <label>Question</label><textarea name="question" required maxlength="2000"></textarea>
      <label>Answer options (one per line)</label><textarea name="options" required placeholder="Very satisfied&#10;Satisfied&#10;Not satisfied"></textarea>
      <label class="check"><input type="checkbox" name="is_published"> Publish to students straight away</label>`,
    save: (f) => call("/leader/surveys", { method: "POST", body: {
      title: f.get("title"), question: f.get("question"), options: f.get("options").split("\n"), is_published: f.has("is_published"),
    } }),
  },
  invite: {
    title: "Invite a delegate",
    form: () => `<p class="sub">Creates a one-use sign-up link for a minister or GRC. It expires in 7 days.</p>
      <div class="row"><div><label>Name</label><input name="invitee_name" required maxlength="120" placeholder="Their full name"></div>
        <div><label>Portfolio / position</label><input name="portfolio" required maxlength="120" placeholder="e.g. Minister of ICT"></div></div>
      <label>Categories they handle (comma separated)</label><input name="categories_handled" maxlength="300" placeholder="e.g. ICT / Internet, Registration">
      <div class="row"><div><label>Campus</label><select name="campus"><option value="">Both campuses</option><option>Main Campus</option><option>City Campus</option></select></div>
        <div><label>Maximum open concerns</label><input name="max_open_load" type="number" min="1" max="50" value="5"></div></div>
      <label>Email (optional – only this address can use the link)</label><input name="invitee_email" type="email" maxlength="160">`,
    save: async (f) => {
      const invitation = await call("/staff/invitations", { method: "POST", body: {
        role: "delegate", invitee_name: f.get("invitee_name"), portfolio: f.get("portfolio"),
        categories_handled: f.get("categories_handled"), campus: f.get("campus"),
        max_open_load: Number(f.get("max_open_load")) || 5, invitee_email: f.get("invitee_email") || null,
      } });
      const link = `${location.origin}/staff/#invite=${encodeURIComponent(invitation.token)}`;
      const message = `Hello ${f.get("invitee_name")}, here is your SyncCampus sign-up link for ${invitation.portfolio}. It works once and expires in 7 days: ${link}`;
      return { keepOpen: `<div class="notice"><b>Invitation for ${esc(f.get("invitee_name"))} created.</b> Send this link to them:
          <input id="wsInviteLink" readonly value="${esc(link)}">
          <div class="actions"><button class="btn sm alt" type="button" id="wsCopy">Copy link</button>
          <a class="btn sm" target="_blank" rel="noopener" href="https://wa.me/?text=${encodeURIComponent(message)}">Send on WhatsApp</a></div></div>` };
    },
  },
};

function saveItem(f) {
  const body = Object.fromEntries(f);
  for (const key of ["starts_at", "due_at"]) body[key] = body[key] ? new Date(body[key]).toISOString() : null;
  return call("/workspace/items", { method: "POST", body });
}

function openDialog(kind) {
  const spec = DIALOGS[kind];
  const dialog = $("#wsDialog");
  dialog.innerHTML = `<form method="dialog" id="wsDialogForm">
      <div class="ws-dialog-head"><h2>${spec.title}</h2><button type="button" class="ws-close" aria-label="Close">×</button></div>
      ${spec.form()}
      <div class="err" id="wsDialogErr"></div>
      <div class="actions"><button class="btn" type="submit">Save</button><button class="btn alt" type="button" data-cancel>Cancel</button></div>
    </form>`;
  const close = () => dialog.close();
  $(".ws-close", dialog).onclick = close;
  $("[data-cancel]", dialog).onclick = close;
  $("#wsDialogForm").onsubmit = async (e) => {
    e.preventDefault();
    $("#wsDialogErr").textContent = "";
    try {
      const result = await spec.save(new FormData(e.target));
      await reload();
      if (result && result.keepOpen) {
        $("#wsDialogForm").innerHTML = `<div class="ws-dialog-head"><h2>${spec.title}</h2><button type="button" class="ws-close" aria-label="Close">×</button></div>
          ${result.keepOpen}<div class="actions"><button class="btn alt" type="button" data-cancel>Done</button></div>`;
        $(".ws-close", dialog).onclick = close;
        $("[data-cancel]", dialog).onclick = close;
        if ($("#wsCopy")) $("#wsCopy").onclick = async () => {
          try { await navigator.clipboard.writeText($("#wsInviteLink").value); $("#wsCopy").textContent = "Copied"; }
          catch { $("#wsInviteLink").select(); }
        };
      } else {
        close();
      }
    } catch (error) {
      $("#wsDialogErr").textContent = error.message;
    }
  };
  dialog.showModal();
}

boot();
