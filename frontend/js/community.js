// Guild updates page: public announcements, surveys and anonymous suggestions.

// A random id kept in this browser so the same person can't answer a survey twice.
// Only a hash of it reaches the server.
const SURVEY_CLIENT_KEY = "saagi_survey_client_v1";
let surveyClientId;
try {
  surveyClientId = localStorage.getItem(SURVEY_CLIENT_KEY) || crypto.randomUUID();
  localStorage.setItem(SURVEY_CLIENT_KEY, surveyClientId);
} catch {
  surveyClientId = crypto.randomUUID();
}

function itemHtml(item) {
  const when = item.starts_at ? `<small>${fmtDate(item.starts_at)}</small>` : "";
  const where = item.location ? ` · ${esc(item.location)}` : "";
  const link = item.url ? ` · <a href="${esc(item.url)}" target="_blank" rel="noopener">Open link</a>` : "";
  return `<div class="upd"><b>${esc(item.title)}</b> <span class="pill">${esc(item.kind)}</span><p>${esc(item.description)}</p>${when}${where}${link}</div>`;
}

function surveyHtml(survey) {
  const options = survey.options.map((option, i) =>
    `<label class="check"><input type="radio" name="answer" value="${esc(option)}"${i === 0 ? " required" : ""}> ${esc(option)}</label>`).join("");
  return `<form class="surveyForm" data-survey="${survey.id}">
      <h3>${esc(survey.title)}</h3><p>${esc(survey.question)}</p>${options}
      <label>Optional comment</label><textarea name="comment" maxlength="2000"></textarea>
      <button class="btn sm">Send response</button> <span class="surveyResult muted"></span>
    </form><hr class="sep">`;
}

async function loadCommunity() {
  let items, surveys;
  try {
    [items, surveys] = await Promise.all([api("/public/workspace"), api("/public/surveys")]);
  } catch (error) {
    $("#guildUpdates").innerHTML = `<h2>Announcements, events and media</h2><p class="err">${esc(error.message)}</p>`;
    $("#surveys").innerHTML = `<h2>Open surveys</h2><p class="err">${esc(error.message)}</p>`;
    return;
  }

  $("#guildUpdates").innerHTML = "<h2>Announcements, events and media</h2>" +
    (items.map(itemHtml).join("") || '<p class="muted">No public updates yet.</p>');
  $("#surveys").innerHTML = "<h2>Open surveys</h2>" +
    (surveys.map(surveyHtml).join("") || '<p class="muted">No open surveys right now.</p>');

  $$(".surveyForm").forEach((form) => {
    form.onsubmit = async (e) => {
      e.preventDefault();
      const data = new FormData(form);
      const status = $(".surveyResult", form);
      try {
        await api(`/public/surveys/${form.dataset.survey}/responses`, { method: "POST", body: {
          respondent_token: `${surveyClientId}:${form.dataset.survey}`,
          answer: data.get("answer"),
          comment: data.get("comment"),
        } });
        status.textContent = "Response recorded. Thank you.";
        form.querySelector("button").disabled = true;
      } catch (error) {
        status.textContent = error.message;
      }
    };
  });
}

$("#suggestForm").onsubmit = async (e) => {
  e.preventDefault();
  try {
    const result = await api("/suggestions", { method: "POST", body: Object.fromEntries(new FormData(e.target)) });
    $("#suggestResult").innerHTML = `<div class="notice"><b>Suggestion submitted.</b>
      <p>Reference: <b>${esc(result.reference)}</b><br>Access code:</p><div class="code">${esc(result.access_code)}</div>
      <p>${esc(result.note)}</p></div>`;
    e.target.reset();
  } catch (error) {
    $("#suggestResult").innerHTML = `<p class="err">${esc(error.message)}</p>`;
  }
};

$("#trackSuggestion").onsubmit = async (e) => {
  e.preventDefault();
  const creds = Object.fromEntries(new FormData(e.target));
  const out = $("#suggestTrackResult");
  try {
    const s = await api("/suggestions/track", { method: "POST", body: creds });
    out.innerHTML = `<div class="upd"><b>${esc(s.title)}</b> <span class="pill">${esc(s.status)}</span><p>${esc(s.description)}</p>
      ${s.updates.map((u) => `<p><b>${esc(u.from)}</b>: ${esc(u.message)}</p>`).join("")}
      <form id="suggestReply"><label>Reply to the Guild</label><textarea name="message" minlength="2" required></textarea>
        <button class="btn sm">Send reply</button> <span id="replyStatus"></span></form></div>`;
    $("#suggestReply").onsubmit = async (replyEvent) => {
      replyEvent.preventDefault();
      const message = new FormData(replyEvent.target).get("message");
      try {
        await api("/suggestions/track/reply", { method: "POST", body: { ...creds, message } });
        replyEvent.target.innerHTML = '<span class="ok">Reply sent.</span>';
      } catch (error) {
        $("#replyStatus").innerHTML = `<span class="err">${esc(error.message)}</span>`;
      }
    };
  } catch (error) {
    out.innerHTML = `<p class="err">${esc(error.message)}</p>`;
  }
};

loadCommunity();
