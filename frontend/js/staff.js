// Guild cabinet dashboard (/staff/).
//   president / vice_guild – priority queue, assignment, analytics, handover, invitations
//   delegate (ministers, GRCs, speaker, secretary) – the concerns assigned to them
// The system admin has a separate area at /admin/.

let workload = [];

initPortal({
  portal: "staff",
  home: "/staff/",
  render: async (account) => {
    if (account.role === "admin") {
      wrongPortal("This is the system admin account. Admins manage accounts at the admin page.", "/admin/", "Go to the admin page");
      return;
    }
    $("#workspaceLink").classList.remove("hide");
    if (account.role === "delegate") await delegateView();
    else await leaderView();
  },
});

/* ---------- Guild President / Vice ---------- */

async function leaderView() {
  const [stats, queue, delegates, escalations, handovers, performance] = await Promise.all([
    call("/leader/analytics"), call("/leader/queue"), call("/leader/workload"), call("/leader/escalations"), call("/leader/handover"),
    call("/leader/performance"),
  ]);
  workload = delegates;
  const maxCategory = Math.max(1, ...Object.values(stats.by_category));

  $("#app").innerHTML = `
    <div class="kpis">
      <div class="kpi"><small>Open concerns</small><div class="v">${stats.open}</div></div>
      <div class="kpi"><small>Critical + High</small><div class="v">${stats.critical_high}</div></div>
      <div class="kpi alert"><small>Overdue</small><div class="v">${stats.overdue}</div></div>
      <div class="kpi"><small>Resolution rate</small><div class="v">${stats.resolution_rate}%</div></div>
      <div class="kpi"><small>Reopened</small><div class="v">${stats.reopen_rate}%</div></div>
      <div class="kpi"><small>Student satisfaction</small><div class="v">${stats.avg_satisfaction == null ? "–" : stats.avg_satisfaction + "/5"}</div></div>
    </div>


    <div class="card"><h2>Priority queue</h2><p class="sub">Open concerns, highest score first. The system recommends a delegate; you make the final call.</p>
      <div class="tablewrap"><table>
        <thead><tr><th>Concern</th><th>Priority</th><th>Related</th><th>Assigned / recommended</th><th>Age</th></tr></thead>
        <tbody>${queue.map(queueRow).join("") || '<tr><td colspan="5" class="muted">No open concerns.</td></tr>'}</tbody>
      </table></div>
      <div class="err" id="queueErr"></div>
    </div>

    <div class="grid2">
      <div class="card"><h2>Delegate workload</h2><p class="sub">Open concerns held by each delegate</p>
        ${delegates.map(workloadBar).join("") || '<p class="muted">No delegates yet. Invite ministers and GRC delegates below.</p>'}</div>
      <div class="card"><h2>Concerns by category</h2><p class="sub">All time</p>
        ${Object.entries(stats.by_category).map(([name, n]) => `<div class="meter"><div class="meter-label"><span>${esc(name)}</span><b>${n}</b></div>
          <div class="bar"><i style="width:${(n / maxCategory) * 100}%"></i></div></div>`).join("") || '<p class="muted">No data yet.</p>'}</div>
    </div>

    ${performanceCard(performance)}

    <div class="card"><h2>Reminders and escalations</h2>
      <p class="sub">Created automatically when a concern stays open past the time allowed for its priority. <button class="btn sm alt" id="runEsc">Check now</button></p>
      ${escalations.map((e) => `<div class="upd"><span class="pill${e.type === "escalation" ? " over" : ""}">${esc(e.type)}</span> <b>${esc(e.title)}</b>
        <small>${esc(e.concern)} · ${fmtDate(e.at)}</small><br>${esc(e.reason)}
        ${e.acknowledged ? ' <span class="pill good">acknowledged</span>' : ` <button class="btn sm alt" data-ack="${e.id}">Acknowledge</button>`}</div>`).join("") || '<p class="muted">None yet.</p>'}
    </div>

    <div class="card"><h2>Leadership handover</h2><p class="sub">Saves a record of every open concern, active project and unfinished task for the incoming team.</p>
      <div class="row"><input id="term" placeholder="Term, e.g. 2026/27" maxlength="60"><button class="btn" id="handoverBtn">Generate handover record</button></div>
      <div id="handoverOut"></div>
      ${handovers.length ? `<h3>Saved records</h3>${handovers.map(handoverSummary).join("")}` : ""}
    </div>

    ${accountCard()}`;

  $$("[data-ack]").forEach((b) => (b.onclick = async () => {
    await call(`/leader/escalations/${b.dataset.ack}/acknowledge`, { method: "POST" });
    leaderView();
  }));
  $("#runEsc").onclick = async () => {
    await call("/leader/run-escalation", { method: "POST" });
    leaderView();
  };
  $("#handoverBtn").onclick = async () => {
    const term = $("#term").value.trim() || "Current term";
    const h = await call("/leader/handover?term=" + encodeURIComponent(term), { method: "POST" });
    $("#handoverOut").innerHTML = `<p class="ok">Saved for ${esc(h.term)}: ${h.count} open concern(s), ${h.active_projects.length} active project(s), ${h.open_project_tasks} open task(s).</p>
      ${handoverTable(h)}`;
  };
  $$("[data-accept]").forEach((b) => (b.onclick = () => assign(b.dataset.cid, b.dataset.accept)));
  $$("[data-assign]").forEach((b) => (b.onclick = () => assign(b.dataset.assign, $("#sel" + b.dataset.assign).value)));
  $$("details[data-explain]").forEach((d) => d.addEventListener("toggle", () => d.open && explain(d), { once: true }));
  bindAccountCard();
}

function queueRow(c) {
  const recommendedId = c.recommended ? c.recommended.delegate_id : null;
  const options = workload.map((w) => `<option value="${w.delegate_id}"${w.delegate_id === recommendedId ? " selected" : ""}>${esc(w.name)} (${w.open}/${w.max})</option>`).join("");
  let holder;
  if (c.assigned_to) {
    holder = `<b>${esc(c.assigned_to)}</b> <span class="muted">· ${esc(stageLabel(c.status))}</span>
      <a class="btn sm alt" target="_blank" rel="noopener" href="${whatsappLink(c)}">Notify on WhatsApp</a>`;
  } else if (c.recommended) {
    holder = `<b>${esc(c.recommended.name)}</b> <span class="muted">score ${c.recommended.score} · ${esc(c.recommended.reasons.join(", "))}</span><br>
      <button class="btn sm amber" data-cid="${c.id}" data-accept="${c.recommended.delegate_id}">Accept recommendation</button>`;
  } else {
    holder = '<span class="muted">No delegate available</span>';
  }

  return `<tr>
    <td><span class="muted">${esc(c.reference)}</span><br><b>${esc(c.title)}</b><br>
      <span class="muted">${esc(c.category)} · ${esc(c.campus || "campus not stated")}${c.location ? " · " + esc(c.location) : ""} · ${c.students_affected} student(s)</span><br>
      ${c.safety_flag ? '<span class="pill over">safety risk</span> ' : ""}${c.academic_impact ? '<span class="pill">academic</span> ' : ""}${c.financial_impact ? '<span class="pill">financial</span>' : ""}
      <details data-explain data-id="${c.id}"><summary>Details and score breakdown</summary><div class="why muted">Loading…</div></details></td>
    <td><span class="badge ${c.priority_level}">${c.priority_level}</span><br><b>${c.priority_score}</b>${c.is_overdue ? '<br><span class="pill over">Overdue</span>' : ""}</td>
    <td>${c.related_reports ? `<span class="pill">${c.related_reports} related</span><br><small>about ${c.group_students} students in total</small>` : "–"}</td>
    <td>${holder}<br><select id="sel${c.id}" class="assign-select">${options}</select>
      <button class="btn sm alt" data-assign="${c.id}"${options ? "" : " disabled"}>${c.assigned_to ? "Reassign" : "Assign"}</button></td>
    <td>${fmtAge(c.age_hours)}</td></tr>`;
}

function workloadBar(w) {
  const ratio = w.max ? w.open / w.max : 1;
  const colour = ratio > 0.8 ? "var(--red)" : ratio > 0.5 ? "var(--amber)" : "var(--green)";
  return `<div class="meter"><div class="meter-label"><b>${esc(w.name)}</b><span class="muted">${esc(w.portfolio)} · ${w.open}/${w.max}</span></div>
    <div class="bar"><i style="width:${Math.min(100, ratio * 100)}%;background:${colour}"></i></div></div>`;
}

async function explain(details) {
  const target = $(".why", details);
  try {
    const c = await call("/leader/concerns/" + details.dataset.id);
    const { factors, points, labels, score } = c.explanation;
    const parts = Object.entries(labels).map(([key, label]) => `${esc(label)} ${Number(factors[key]).toFixed(2)} → ${points[key]} pts`);
    target.innerHTML = `<p class="body">${esc(c.description)}</p>
      <p>${parts.join(" · ")} = <b>${score}</b>${points.safety_rule ? " · <b>safety rule applied (never below Critical)</b>" : ""}</p>
      ${c.related.length ? `<p>Related reports: ${c.related.map((r) => `${esc(r.reference)} (${esc(r.title)})`).join(", ")}</p>` : ""}
      ${c.updates.map((u) => `<div class="upd"><b>${esc(u.from)}</b> <small>${esc(u.visibility)} · ${fmtDate(u.at)}</small><br>${esc(u.message)}</div>`).join("")}`;
  } catch (error) {
    target.textContent = error.message;
  }
}

async function assign(concernId, delegateId) {
  try {
    await call(`/leader/concerns/${concernId}/assign`, { method: "POST", body: { delegate_id: Number(delegateId) } });
    leaderView();
  } catch (error) {
    $("#queueErr").textContent = error.message;
  }
}

function handoverTable(h) {
  if (!h.open_concerns.length) return '<p class="muted">No open concerns at the time of this record.</p>';
  return `<div class="tablewrap"><table><thead><tr><th>Ref</th><th>Title</th><th>Priority</th><th>Status</th><th>Assigned to</th></tr></thead><tbody>
    ${h.open_concerns.map((c) => `<tr><td>${esc(c.reference)}</td><td>${esc(c.title)}</td><td>${esc(c.priority)}${c.overdue ? " · overdue" : ""}</td>
      <td>${esc(stageLabel(c.status))}</td><td>${esc(c.assigned_to || "unassigned")}</td></tr>`).join("")}</tbody></table></div>`;
}

function handoverSummary(record) {
  const s = record.snapshot;
  return `<details class="upd"><summary><b>${esc(record.term)}</b> · ${fmtDate(record.generated_at)} · ${s.count} open concern(s), ${s.active_projects.length} project(s)</summary>
    ${handoverTable(s)}</details>`;
}

function performanceCard(rows) {
  const hrs = (v) => (v == null ? "–" : fmtAge(v));
  return `<div class="card"><h2>Delegate performance</h2><p class="sub">From recorded data. Visible to the Guild President, Vice and each delegate for themselves.</p>
    <div class="tablewrap"><table><thead><tr><th>Delegate</th><th>Assigned</th><th>Open</th><th>Resolved</th><th>Completed</th><th>Overdue</th>
      <th>First response</th><th>Resolution</th><th>Escalated</th><th>Reassigned</th><th>Satisfaction</th></tr></thead><tbody>
    ${rows.map((r) => `<tr><td><b>${esc(r.name)}</b><br><span class="muted">${esc(r.portfolio)}</span></td><td>${r.assigned}</td><td>${r.open}</td><td>${r.resolved}</td>
      <td>${r.completion_rate == null ? "–" : r.completion_rate + "%"}</td><td>${r.overdue}</td><td>${hrs(r.avg_first_response_hrs)}</td><td>${hrs(r.avg_resolution_hrs)}</td>
      <td>${r.escalated}</td><td>${r.reassigned_away}</td><td>${r.avg_satisfaction == null ? "–" : r.avg_satisfaction + "/5"}</td></tr>`).join("")
      || '<tr><td colspan="11" class="muted">No delegates yet.</td></tr>'}</tbody></table></div></div>`;
}

// Opens WhatsApp with a ready message; the leader picks the contact. No student details are included.
function whatsappLink(c) {
  const text = `SyncCampus: concern ${c.reference} "${c.title}" (${c.priority_level} priority) is assigned to you. Please sign in to the staff dashboard: ${location.origin}/staff/`;
  return "https://wa.me/?text=" + encodeURIComponent(text);
}

/* ---------- delegates ---------- */

async function delegateView() {
  const [rows, mine, availability] = await Promise.all([call("/delegate/concerns"), call("/delegate/performance"), call("/delegate/availability")]);
  $("#app").innerHTML = `<div class="card"><h2>My status</h2>
      <label class="check"><input type="checkbox" id="available"${availability.available ? " checked" : ""}> I am available to take new concerns</label>
      <p class="sub">Untick this when you are away so the system stops recommending you.</p>
      ${mine.assigned !== undefined ? `<div class="kpis">
        <div class="kpi"><small>Open now</small><div class="v">${mine.open}</div></div>
        <div class="kpi"><small>Resolved</small><div class="v">${mine.resolved}</div></div>
        <div class="kpi"><small>Avg first response</small><div class="v">${mine.avg_first_response_hrs == null ? "–" : fmtAge(mine.avg_first_response_hrs)}</div></div>
        <div class="kpi"><small>Avg resolution</small><div class="v">${mine.avg_resolution_hrs == null ? "–" : fmtAge(mine.avg_resolution_hrs)}</div></div>
        <div class="kpi alert"><small>Overdue</small><div class="v">${mine.overdue}</div></div>
        <div class="kpi"><small>Student satisfaction</small><div class="v">${mine.avg_satisfaction == null ? "–" : mine.avg_satisfaction + "/5"}</div></div>
      </div>` : ""}</div>
    <div class="card"><h2>My assigned concerns</h2>
      <p class="sub">Highest priority first. Replies to the student appear on their tracking page; internal notes stay within the Guild.
      Mark a concern resolved when it is fixed – the student then confirms or reopens it.</p></div>` +
    (rows.map(delegateCard).join("") || '<div class="card muted">Nothing is assigned to you right now.</div>') + accountCard();
  bindAccountCard();

  $("#available").onchange = (e) => call("/delegate/availability", { method: "POST", body: { available: e.target.checked } });
  $$("[data-send]").forEach((button) => {
    button.onclick = async () => {
      const id = button.dataset.send;
      try {
        await call(`/delegate/concerns/${id}/update`, { method: "POST", body: {
          message: $("#msg" + id).value,
          visibility: $("#vis" + id).value,
          status: $("#st" + id).value || null,
        } });
        delegateView();
      } catch (error) {
        $("#err" + id).textContent = error.message;
      }
    };
  });
}

function delegateCard(c) {
  const history = c.updates.map((u) => `<div class="upd"><b>${esc(u.from)}</b> <small>${esc(u.visibility)} · ${fmtDate(u.at)}</small><br>${esc(u.message)}</div>`).join("");
  return `<div class="card">
    <span class="badge ${c.priority_level}">${c.priority_level}</span> <b>${esc(c.title)}</b>
    <span class="pill">${esc(stageLabel(c.status))}</span>${c.is_new ? ' <span class="pill good">New</span>' : ""}
    ${c.is_overdue || c.escalated ? ' <span class="pill over">Overdue – escalated to the President</span>' : c.reminded ? ' <span class="pill over">Reminder: respond soon</span>' : ""}
    <p class="muted">${esc(c.reference)} · ${esc(c.category)} · ${esc(c.campus)}${c.location ? " · " + esc(c.location) : ""} · open ${fmtAge(c.age_hours)}</p>
    <p class="body">${esc(c.description)}</p>
    ${history ? `<details><summary>Conversation (${c.updates.length})</summary>${history}</details>` : ""}
    <textarea id="msg${c.id}" placeholder="Write an update or reply"></textarea>
    <div class="row">
      <select id="vis${c.id}"><option value="private">Reply to student (private)</option>${c.is_sensitive ? "" : '<option value="public">Public reply</option>'}<option value="internal">Internal note</option></select>
      <select id="st${c.id}"><option value="">Keep status</option><option value="in_progress">Mark in progress</option><option value="resolved">Mark resolved</option></select>
    </div>
    <button class="btn sm" data-send="${c.id}">Post update</button> <span class="err" id="err${c.id}"></span>
  </div>`;
}

