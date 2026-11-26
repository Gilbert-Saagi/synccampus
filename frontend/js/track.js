let creds = null;

function concernHtml(c) {
  const updates = c.updates.map((u) => `<div class="upd"><b>${esc(u.from)}</b> <small>${fmtDate(u.at)}</small><br>${esc(u.message)}</div>`).join("");
  const askConfirm = c.status === "resolved"
    ? `<div class="notice"><b>Was your concern resolved?</b>
        <label for="rating">How satisfied are you with how it was handled?</label>
        <select id="rating"><option value="5">Very satisfied</option><option value="4" selected>Satisfied</option><option value="3">Neutral</option>
          <option value="2">Dissatisfied</option><option value="1">Very dissatisfied</option></select>
        <div class="actions"><button class="btn sm" id="yes">Yes, it is resolved</button> <button class="btn sm alt" id="no">No, still a problem</button></div></div>`
    : "";
  const addInfo = c.status !== "closed"
    ? `<label for="more">Add more information</label><textarea id="more"></textarea><button class="btn sm" id="moreBtn">Send</button>`
    : "";

  return `<hr class="sep"><h2>${esc(c.title)}</h2>
    <p><span class="pill${isDone(c.status) ? " good" : ""}">${esc(stageLabel(c.status))}</span> <span class="muted">${esc(c.category)} · ${esc(c.reference)}</span></p>
    <p class="body">${esc(c.description)}</p>
    <h3>Replies and updates</h3>
    ${updates || '<p class="muted">No replies yet.</p>'}
    ${askConfirm}${addInfo}
    <div class="err" id="actErr"></div>`;
}

async function showConcern() {
  const view = $("#trackView");
  try {
    const concern = await api("/track", { method: "POST", body: creds });
    view.innerHTML = concernHtml(concern);
  } catch (error) {
    $("#trackErr").textContent = error.message;
    view.innerHTML = "";
    return;
  }

  const act = async (path, extra) => {
    try {
      await api(path, { method: "POST", body: { ...creds, ...extra } });
      showConcern();
    } catch (error) {
      $("#actErr").textContent = error.message;
    }
  };
  if ($("#yes")) {
    $("#yes").onclick = () => act("/track/confirm", { resolved: true, rating: Number($("#rating").value) });
    $("#no").onclick = () => act("/track/confirm", { resolved: false });
  }
  if ($("#moreBtn")) $("#moreBtn").onclick = () => act("/track/info", { message: $("#more").value });
}

$("#trackForm").addEventListener("submit", (e) => {
  e.preventDefault();
  $("#trackErr").textContent = "";
  const f = new FormData(e.target);
  creds = { reference: f.get("reference").trim(), access_code: f.get("access_code").trim() };
  showConcern();
});
