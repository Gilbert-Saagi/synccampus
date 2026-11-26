// Home page: live figures, a preview of the cabinet and recent public concerns.

function renderStats(s) {
  const firstResponse = s.avg_first_response_hrs == null ? "–" : s.avg_first_response_hrs + "h";
  $("#stats").innerHTML = `
    <div class="stat"><small>Concerns received</small><div class="v">${s.total}</div><div class="n">${s.open} still open</div></div>
    <div class="stat"><small>Resolved</small><div class="v">${s.resolved}</div><div class="n">${s.resolution_rate}% of all concerns</div></div>
    <div class="stat"><small>Average first response</small><div class="v">${firstResponse}</div><div class="n">time until a leader replies</div></div>
    <div class="stat${s.overdue_now ? " warn" : ""}"><small>Overdue right now</small><div class="v">${s.overdue_now}</div><div class="n">${s.overdue_pct}% of open concerns</div></div>`;

  const max = Math.max(1, ...Object.values(s.stages));
  $("#stages").innerHTML = Object.entries(s.stages).map(([stage, n]) =>
    `<div class="stagebar"><span>${stageLabel(stage)}</span><div class="bar"><i style="width:${(n / max) * 100}%"></i></div><b>${n}</b></div>`).join("");

  $("#topcats").innerHTML = s.top_categories.map(([name, n]) =>
    `<div class="stagebar topic"><span>${esc(name)}</span><b>${n}</b></div>`).join("")
    || '<p class="muted">Topics appear once concerns are submitted.</p>';
}

function renderRecent(rows) {
  $("#recent").innerHTML = rows.map((r) =>
    `<div class="upd"><b>${esc(r.title)}</b> <span class="pill${isDone(r.status) ? " good" : ""}">${esc(stageLabel(r.status))}</span><br>
      <small>${esc(r.category)} · ${esc(r.campus)}</small></div>`).join("")
    || '<p class="muted">No public concerns yet. Be the first to raise one.</p>';
}

(async () => {
  const [stats, cabinet, recent] = await Promise.allSettled([api("/public/stats"), loadCabinet(), api("/public/concerns?limit=3")]);

  if (stats.status === "fulfilled") renderStats(stats.value);
  else $("#stages").textContent = "Live numbers are unavailable right now.";

  if (cabinet.status === "fulfilled") {
    $("#people").innerHTML = cabinet.value.slice(0, 4).map((p) => personCard(p)).join("") || '<p class="muted">The cabinet list will appear here.</p>';
  } else {
    $("#people").textContent = "Cabinet list is unavailable right now.";
  }

  if (recent.status === "fulfilled") renderRecent(recent.value);
  else $("#recent").textContent = "Unavailable right now.";
})();
