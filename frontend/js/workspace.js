// Guild workspace: projects and tasks, public updates, surveys, suggestions, trends and staff chat.
// Leaders (President / Vice) manage everything; delegates see projects, update their own tasks and chat.

const token = sessionStorage.getItem("saagi_token");
const call = (path, options = {}) => api(path, { ...options, token });
const TASK_STATES = { open: "Open", in_progress: "In progress", completed: "Completed" };
const SUGGESTION_STATES = ["new", "reviewing", "accepted", "declined", "implemented"];

let me = null;
let isLeader = false;

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
  render();
}

async function render() {
  const [items, messages, staff, trends, handovers] = await Promise.all([
    call("/workspace/items"),
    call("/staff/messages"),
    isLeader ? call("/leader/workload") : [],
    isLeader ? call("/leader/trends") : null,
    call("/leader/handover"),
  ]);
  const projects = items.filter((x) => x.kind === "project");
  const updates = items.filter((x) => x.kind !== "project");

  $("#workspace").innerHTML = `
    <h1>Guild workspace</h1><p class="sub">Projects, public updates, surveys and internal coordination.</p>
    ${isLeader ? leaderForms() : ""}
    ${calendarCard(items)}
    ${trends ? trendsCard(trends) : ""}
    <h2 class="section">Projects</h2>
    <div class="grid2">${projects.map((p) => projectCard(p, staff)).join("") || '<div class="card muted">No projects yet.</div>'}</div>
    <div class="card"><h2>Announcements, events and media</h2>
      ${updates.map((x) => `<div class="upd"><b>${esc(x.title)}</b> <span class="pill">${esc(x.kind)} · ${x.visibility === "public" ? "public" : "staff only"}</span>
        <p>${esc(x.description)}</p>${x.starts_at ? `<small>${fmtDate(x.starts_at)}</small>` : ""}${x.location ? ` · ${esc(x.location)}` : ""}
        ${x.url ? ` · <a href="${esc(x.url)}" target="_blank" rel="noopener">Open link</a>` : ""}</div>`).join("") || '<p class="muted">Nothing posted yet.</p>'}</div>
    ${isLeader ? '<div class="card"><h2>Surveys</h2><div id="surveyResults">Loading…</div></div>' : ""}
    ${isLeader ? '<div class="card"><h2>Student suggestions</h2><div id="suggestionInbox">Loading…</div></div>' : ""}
    ${handoverCard(handovers)}
    <div class="card"><h2>Internal staff chat</h2>
      <div id="chatMessages">${messages.map((m) => `<div class="upd"><b>${esc(m.author)}</b> <small>${fmtDate(m.at)}</small><br>${esc(m.message)}</div>`).join("") || '<p class="muted">No messages yet.</p>'}</div>
      <form id="chatForm"><textarea name="message" required maxlength="3000" placeholder="Message Guild staff"></textarea><button class="btn sm">Send message</button></form>
    </div>`;

  bindForms();
  if (isLeader) {
    renderSurveys();
    renderSuggestions();
  }
}

function leaderForms() {
  return `<div class="grid2">
    <div class="card"><h2>New workspace item</h2><p class="sub">Media entries are links to photos or documents stored elsewhere (e.g. Google Drive).</p>
      <form id="itemForm">
        <div class="row">
          <div><label>Type</label><select name="kind"><option value="project">Project</option><option value="announcement">Announcement</option><option value="event">Meeting / event</option><option value="media">Media link</option></select></div>
          <div><label>Visibility</label><select name="visibility"><option value="staff">Staff only</option><option value="public">Public to students</option></select></div>
        </div>
        <label>Title</label><input name="title" required minlength="2" maxlength="200">
        <label>Description</label><textarea name="description" maxlength="5000"></textarea>
        <div class="row">
          <div><label>Date / start time</label><input name="starts_at" type="datetime-local"></div>
          <div><label>Due date</label><input name="due_at" type="datetime-local"></div>
        </div>
        <div class="row">
          <div><label>Location</label><input name="location" maxlength="160"></div>
          <div><label>Link</label><input name="url" type="url" maxlength="1000" placeholder="https://"></div>
        </div>
        <button class="btn" type="submit">Save item</button> <span class="formStatus err"></span>
      </form></div>
    <div class="card"><h2>New survey</h2>
      <form id="surveyForm">
        <label>Title</label><input name="title" required maxlength="200">
        <label>Question</label><textarea name="question" required maxlength="2000"></textarea>
        <label>Answer options (one per line)</label><textarea name="options" required placeholder="Very satisfied&#10;Satisfied&#10;Not satisfied"></textarea>
        <label class="check"><input type="checkbox" name="is_published"> Publish immediately</label>
        <button class="btn" type="submit">Save survey</button> <span class="formStatus err"></span>
      </form></div>
  </div>`;
}

function trendsCard(trends) {
  const peak = Math.max(1, ...trends.monthly.map((x) => x.count));
  return `<div class="card"><h2>Concern trends</h2><p class="sub">Concerns received per month and per campus.</p>
    <div class="grid2">
      <div>${trends.monthly.map((x) => `<div class="meter"><div class="meter-label"><span>${esc(x.month)}</span><b>${x.count}</b></div>
        <div class="bar"><i style="width:${(100 * x.count) / peak}%"></i></div></div>`).join("") || '<p class="muted">No data yet.</p>'}</div>
      <div>${trends.by_campus.map((x) => `<div class="meter-label"><span>${esc(x.campus)}</span><b>${x.count}</b></div>`).join("") || '<p class="muted">No data yet.</p>'}</div>
    </div></div>`;
}

// Upcoming meetings, events and due dates in date order, grouped by day.
function calendarCard(items) {
  const now = Date.now() - 86400000;
  const entries = [];
  for (const item of items) {
    if (item.starts_at) entries.push({ at: item.starts_at, title: item.title, what: item.kind, where: item.location });
    if (item.due_at) entries.push({ at: item.due_at, title: item.title, what: item.kind + " due", where: "" });
    for (const task of item.tasks) {
      if (task.due_at && task.status !== "completed") entries.push({ at: task.due_at, title: task.title, what: "task due · " + (task.assigned_to || "unassigned"), where: "" });
    }
  }
  const upcoming = entries.filter((e) => new Date(e.at).getTime() >= now).sort((a, b) => new Date(a.at) - new Date(b.at)).slice(0, 30);
  const days = new Map();
  for (const e of upcoming) {
    const day = new Date(e.at).toLocaleDateString(undefined, { weekday: "short", day: "numeric", month: "short", year: "numeric" });
    if (!days.has(day)) days.set(day, []);
    days.get(day).push(e);
  }
  const html = [...days].map(([day, list]) => `<div class="cal-day"><b>${esc(day)}</b>${list.map((e) =>
    `<div class="upd"><small>${new Date(e.at).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}</small> ${esc(e.title)} <span class="pill">${esc(e.what)}</span>${e.where ? " · " + esc(e.where) : ""}</div>`).join("")}</div>`).join("");
  return `<div class="card"><h2>Calendar</h2><p class="sub">Upcoming meetings, events and due dates.</p>${html || '<p class="muted">Nothing scheduled.</p>'}</div>`;
}

// Q35: incoming leaders and cabinet members can read every saved handover record.
function handoverCard(records) {
  const list = records.map((r) => {
    const s = r.snapshot;
    const history = s.resolved_history || { total: 0, by_category: {} };
    return `<details class="upd"><summary><b>${esc(r.term)}</b> · ${fmtDate(r.generated_at)} · by ${esc(s.generated_by)}</summary>
      <h3>Unresolved concerns (${s.count})</h3>
      ${s.open_concerns.map((c) => `<div>${esc(c.reference)} – ${esc(c.title)} · ${esc(c.priority)} · ${esc(c.assigned_to || "unassigned")}${c.last_note ? `<br><small>Last note: ${esc(c.last_note)}</small>` : ""}</div>`).join("") || '<p class="muted">None.</p>'}
      <h3>Projects and pending tasks</h3>
      ${s.active_projects.map((p) => `<div><b>${esc(p.title)}</b>: ${p.tasks.map((t) => esc(t.title) + " (" + esc(t.assigned_to || "unassigned") + ")").join(", ") || "no open tasks"}</div>`).join("") || '<p class="muted">None.</p>'}
      <h3>Resolved history</h3><p>${history.total} resolved. ${Object.entries(history.by_category).map(([k, v]) => esc(k) + ": " + v).join(", ")}</p>
      ${s.delegate_roles ? `<h3>Roles and responsibilities</h3>${s.delegate_roles.map((d) => `<div>${esc(d.name)} – ${esc(d.portfolio)}${d.categories_handled ? " (" + esc(d.categories_handled) + ")" : ""}</div>`).join("")}` : ""}
      ${s.upcoming_meetings && s.upcoming_meetings.length ? `<h3>Upcoming meetings and events</h3>${s.upcoming_meetings.map((m) => `<div>${fmtDate(m.starts_at)} – ${esc(m.title)}${m.location ? " · " + esc(m.location) : ""}</div>`).join("")}` : ""}
    </details>`;
  }).join("");
  return `<div class="card"><h2>Handover records</h2><p class="sub">Created by the Guild President or Vice at the end of a term.</p>${list || '<p class="muted">No handover records yet.</p>'}</div>`;
}

function projectCard(project, staff) {
  const tasks = project.tasks.map((t) => {
    const mine = t.assigned_to_user_id === me.id;
    const canUpdate = isLeader || mine;
    const picker = canUpdate
      ? ` <select data-task="${t.id}">${Object.entries(TASK_STATES).map(([v, label]) => `<option value="${v}"${v === t.status ? " selected" : ""}>${label}</option>`).join("")}</select>
          <button class="btn sm alt" data-task-save="${t.id}">Update</button>`
      : "";
    return `<div class="upd"><b>${esc(t.title)}</b> · ${esc(TASK_STATES[t.status] || t.status)} · ${esc(t.assigned_to || "unassigned")}${picker}</div>`;
  }).join("");

  const addTask = isLeader
    ? `<form data-task-form="${project.id}" class="row inline-form">
        <input name="title" required minlength="2" placeholder="New task">
        <div><select name="assigned_to_user_id"><option value="">Unassigned</option>${staff.map((d) => `<option value="${d.user_id}">${esc(d.name)}</option>`).join("")}</select>
        <button class="btn sm">Add task</button></div>
      </form>`
    : "";

  return `<div class="card"><h2>${esc(project.title)}</h2><p>${esc(project.description)}</p>
    ${project.due_at ? `<p class="muted small">Due ${fmtDate(project.due_at)}</p>` : ""}
    ${tasks || '<p class="muted">No tasks yet.</p>'}${addTask}</div>`;
}

function bindForms() {
  const submitting = (form, work) => {
    form.onsubmit = async (e) => {
      e.preventDefault();
      const status = $(".formStatus", form);
      if (status) status.textContent = "";
      try {
        await work(new FormData(form));
        render();
      } catch (error) {
        if (status) status.textContent = error.message;
        else alert(error.message);
      }
    };
  };

  if (isLeader) {
    submitting($("#itemForm"), (f) => {
      const body = Object.fromEntries(f);
      for (const key of ["starts_at", "due_at"]) body[key] = body[key] ? new Date(body[key]).toISOString() : null;
      return call("/workspace/items", { method: "POST", body });
    });
    submitting($("#surveyForm"), (f) => call("/leader/surveys", { method: "POST", body: {
      title: f.get("title"), question: f.get("question"), options: f.get("options").split("\n"), is_published: f.has("is_published"),
    } }));
  }

  $$("[data-task-form]").forEach((form) => submitting(form, (f) => call(`/workspace/projects/${form.dataset.taskForm}/tasks`, { method: "POST", body: {
    title: f.get("title"),
    assigned_to_user_id: f.get("assigned_to_user_id") ? Number(f.get("assigned_to_user_id")) : null,
  } })));

  $$("[data-task-save]").forEach((button) => {
    button.onclick = async () => {
      const id = button.dataset.taskSave;
      try {
        await call(`/workspace/tasks/${id}`, { method: "PATCH", body: { status: $(`[data-task="${id}"]`).value } });
        render();
      } catch (error) {
        alert(error.message);
      }
    };
  });

  submitting($("#chatForm"), (f) => call("/staff/messages", { method: "POST", body: { message: f.get("message") } }));
}

async function renderSurveys() {
  const surveys = await call("/leader/surveys");
  $("#surveyResults").innerHTML = surveys.map((s) => `<div class="upd">
      <b>${esc(s.title)}</b> · ${s.is_published ? "Published" : "Draft"} · ${s.response_count} response(s)
      <p>${esc(s.question)}</p>
      ${s.options.map((o) => `<span class="pill">${esc(o)}: ${s.results[o] ?? 0}</span>`).join(" ")}
      <button class="btn sm alt" data-publish="${s.id}">${s.is_published ? "Unpublish" : "Publish"}</button>
    </div>`).join("") || '<p class="muted">No surveys yet.</p>';

  $$("[data-publish]").forEach((b) => {
    b.onclick = async () => {
      await call(`/leader/surveys/${b.dataset.publish}/publish`, { method: "PATCH" });
      renderSurveys();
    };
  });
}

async function renderSuggestions() {
  const rows = await call("/leader/suggestions");
  $("#suggestionInbox").innerHTML = rows.map((s) => `<div class="upd">
      <b>${esc(s.title)}</b> · ${esc(s.reference)} · ${esc(s.status)} · <small>${fmtDate(s.created_at)}</small>
      <p>${esc(s.description)}</p>
      <form data-suggestion="${s.id}" class="row inline-form">
        <select name="status">${SUGGESTION_STATES.map((x) => `<option${x === s.status ? " selected" : ""}>${x}</option>`).join("")}</select>
        <div><input name="message" placeholder="Reply to the student (optional)"> <button class="btn sm alt">Save and reply</button></div>
      </form>
    </div>`).join("") || '<p class="muted">No suggestions yet.</p>';

  $$("[data-suggestion]").forEach((form) => {
    form.onsubmit = async (e) => {
      e.preventDefault();
      const f = new FormData(form);
      try {
        await call(`/leader/suggestions/${form.dataset.suggestion}/update`, { method: "POST", body: { status: f.get("status"), message: f.get("message") } });
        renderSuggestions();
      } catch (error) {
        alert(error.message);
      }
    };
  });
}

boot();
