// Shared by the two signed-in areas: /staff/ (Guild cabinet) and /admin/ (system admin).
// Handles sign-in, sign-out, invitation links, password changes and installing the app.

const TOKEN_KEY = "saagi_token";
const ROLE_LABELS = { admin: "System admin", president: "Guild President", vice_guild: "Guild Vice President", delegate: "Minister / GRC" };
const INVITABLE = { admin: ["president", "vice_guild", "delegate"] };
const inviteToken = new URLSearchParams(location.hash.slice(1)).get("invite");

let token = sessionStorage.getItem(TOKEN_KEY);
let me = null;
let installPrompt = null;

const call = (path, options = {}) => api(path, { ...options, token });

/**
 * Wires up the page. `render(me)` draws the signed-in view; `home` is where
 * sign-out returns to. The admin page brings its own sign-in form, so it
 * passes `passwordLogin: false` and calls the returned `signIn` itself.
 */
function initPortal({ home, render, passwordLogin = true }) {
  if (passwordLogin) $("#loginForm").addEventListener("submit", async (e) => {
    e.preventDefault();
    $("#loginErr").textContent = "";
    const f = new FormData(e.target);
    try {
      const session = await api("/auth/login", { method: "POST", form: { username: f.get("email"), password: f.get("password") } });
      signIn(session.access_token);
    } catch (error) {
      $("#loginErr").textContent = error.message;
    }
  });

  $("#logout").onclick = async () => {
    await call("/auth/logout", { method: "POST" }).catch(() => {});
    sessionStorage.removeItem(TOKEN_KEY);
    location.replace(home);
  };

  async function start() {
    try {
      me = await call("/auth/me");
    } catch {
      sessionStorage.removeItem(TOKEN_KEY);
      return;
    }
    $("#loginBox").classList.add("hide");
    $("#invitationBox")?.classList.add("hide");
    $("#app").classList.remove("hide");
    $("#who").textContent = `${me.name} · ${ROLE_LABELS[me.role] || me.role}`;
    $("#who").classList.remove("hide");
    $("#logout").classList.remove("hide");
    try {
      await render(me);
    } catch (error) {
      $("#app").innerHTML = `<div class="card"><p class="err">${esc(error.message)}</p></div>`;
    }
  }

  function signIn(newToken) {
    token = newToken;
    sessionStorage.setItem(TOKEN_KEY, token);
    start();
  }

  if (inviteToken && $("#invitationBox")) showInvitation(signIn);
  else if (token) start();
  return { signIn };
}

// Pasting a sign-up or sign-in link into a tab that already shows this page only
// changes the #fragment, which does not reload the page. Reload so the link is read.
window.addEventListener("hashchange", () => {
  if (/(^|[#&])(invite|login)=/.test(location.hash)) location.reload();
});

/** Shown when someone signs in at the wrong door. */
function wrongPortal(text, href, label) {
  $("#app").innerHTML = `<div class="card narrow"><h2>Wrong sign-in page</h2><p>${text}</p><a class="btn" href="${href}">${label}</a></div>`;
}

/* ---------- installing the app on a phone ---------- */

window.addEventListener("beforeinstallprompt", (event) => {
  event.preventDefault();
  installPrompt = event;
});

document.addEventListener("click", async (event) => {
  if (event.target.id !== "installApp") return;
  if (!installPrompt) {
    event.target.insertAdjacentHTML("afterend", '<p class="muted small">Open your browser menu and choose "Install app" or "Add to Home screen".</p>');
    event.target.remove();
    return;
  }
  installPrompt.prompt();
  await installPrompt.userChoice;
  installPrompt = null;
});

/* ---------- invitations ---------- */

function invitePanel({ title = "Invite staff", intro = "" } = {}) {
  const options = (INVITABLE[me.role] || []).map((role) => `<option value="${role}">${ROLE_LABELS[role]}</option>`).join("");
  return `<div class="card" id="invitePanel"><h2>${title}</h2>
    <p class="sub">${intro}Creates a one-use sign-up link that expires in 7 days. Copy it and send it to the person directly, for example on WhatsApp.</p>
    <form id="inviteForm">
      <div class="row">
        <div><label>Name</label><input name="invitee_name" id="inviteName" maxlength="120" placeholder="Their full name"></div>
        <div><label>Account role</label><select id="inviteRole" name="role">${options}</select></div>
      </div>
      <label>Portfolio / position</label><input name="portfolio" id="invitePortfolio" maxlength="120" placeholder="e.g. Minister of ICT, or GRC – BMIT">
      <label>Categories this person handles (comma separated)</label><input name="categories_handled" id="inviteCategories" maxlength="300" placeholder="e.g. ICT / Internet, Registration">
      <div class="row">
        <div><label>Campus covered</label><select name="campus" id="inviteCampus"><option value="">Both campuses</option><option>Main Campus</option><option>City Campus</option></select></div>
        <div><label>Maximum open assignments</label><input name="max_open_load" type="number" min="1" max="50" value="5"></div>
      </div>
      <label>Email (optional – only this address can use the link)</label><input name="invitee_email" type="email" maxlength="160" placeholder="name@example.com">
      <button class="btn" type="submit">Create invitation link</button>
      <div class="err" id="inviteErr"></div>
    </form>
    <div id="inviteResult"></div>
  </div>`;
}

/** Fills the invite form, e.g. from the cabinet list on the admin page. */
function prefillInvite({ name, role, portfolio, categories, campus }) {
  $("#inviteName").value = name || "";
  if ([...$("#inviteRole").options].some((o) => o.value === role)) $("#inviteRole").value = role;
  $("#invitePortfolio").value = portfolio || "";
  $("#inviteCategories").value = categories || "";
  $("#inviteCampus").value = campus || "";
  $("#inviteResult").innerHTML = "";
  $("#invitePanel").scrollIntoView({ behavior: "smooth" });
}

function bindInviteForm(onCreated) {
  const form = $("#inviteForm");
  const role = $("#inviteRole");
  const portfolio = $("#invitePortfolio");
  role.addEventListener("change", () => {
    if (role.value === "president") portfolio.value = "Guild President";
    else if (role.value === "vice_guild") portfolio.value = "Guild Vice President";
  });

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    $("#inviteErr").textContent = "";
    const f = new FormData(form);
    try {
      const invitation = await call("/staff/invitations", { method: "POST", body: {
        role: f.get("role"),
        invitee_name: f.get("invitee_name"),
        invitee_email: f.get("invitee_email") || null,
        portfolio: f.get("portfolio"),
        categories_handled: f.get("categories_handled"),
        max_open_load: Number(f.get("max_open_load")) || 5,
        campus: f.get("campus"),
      } });
      const link = `${location.origin}/staff/#invite=${encodeURIComponent(invitation.token)}`;
      const who = f.get("invitee_name") || ROLE_LABELS[invitation.role];
      const message = `Hello ${f.get("invitee_name") || ""}, here is your SyncCampus sign-up link for ${invitation.portfolio}. It works once and expires in 7 days: ${link}`;
      $("#inviteResult").innerHTML = `<div class="notice">
          <b>Invitation for ${esc(who)} created.</b> It works once and expires ${fmtDate(invitation.expires_at)}.
          <input id="inviteLink" readonly value="${esc(link)}" aria-label="Invitation link">
          <div class="actions"><button class="btn sm alt" id="copyInvite" type="button">Copy link</button>
          <a class="btn sm alt" target="_blank" rel="noopener" href="https://wa.me/?text=${encodeURIComponent(message)}">Send on WhatsApp</a>
          <span class="ok" id="copyStatus"></span></div>
        </div>`;
      $("#copyInvite").onclick = async () => {
        try {
          await navigator.clipboard.writeText(link);
          $("#copyStatus").textContent = "Copied";
        } catch {
          $("#inviteLink").select();
          $("#copyStatus").textContent = "Select the link and copy it";
        }
      };
      form.reset();
      if (onCreated) onCreated(invitation);
    } catch (error) {
      $("#inviteErr").textContent = error.message;
    }
  });
}

async function showInvitation(signIn) {
  $("#loginBox").classList.add("hide");
  const box = $("#invitationBox");
  box.classList.remove("hide");
  box.innerHTML = "<h2>Checking invitation…</h2>";

  let info;
  try {
    info = await api("/staff/invitations/inspect", { method: "POST", body: { token: inviteToken } });
  } catch (error) {
    box.innerHTML = `<h2>Invitation unavailable</h2><p class="err">${esc(error.message)}</p><p class="sub">Ask the system admin for a new link.</p>`;
    return;
  }

  box.innerHTML = `<h2>Welcome${info.invitee_name ? ", " + esc(info.invitee_name) : ""}</h2>
    <p class="sub">Create your SyncCampus account for <b>${esc(info.portfolio)}</b>. This link expires ${new Date(info.expires_at).toLocaleDateString()}.</p>
    <form id="registerForm">
      <label>Full name</label><input name="full_name" required minlength="2" maxlength="120" autocomplete="name" value="${esc(info.invitee_name || "")}">
      <label>Email (you will sign in with this)</label><input name="email" type="email" required maxlength="160" autocomplete="email" value="${esc(info.invitee_email || "")}"${info.invitee_email ? " readonly" : ""}>
      <label>Password (at least 12 characters)</label><input name="password" type="password" required minlength="12" maxlength="128" autocomplete="new-password">
      <label>Confirm password</label><input name="confirm_password" type="password" required minlength="12" maxlength="128" autocomplete="new-password">
      <button class="btn" type="submit">Create account</button>
      <div class="err" id="registerErr"></div>
    </form>`;

  $("#registerForm").addEventListener("submit", async (event) => {
    event.preventDefault();
    $("#registerErr").textContent = "";
    const f = new FormData(event.target);
    if (f.get("password") !== f.get("confirm_password")) {
      $("#registerErr").textContent = "Passwords do not match";
      return;
    }
    try {
      const session = await api("/staff/invitations/redeem", { method: "POST", body: {
        token: inviteToken, full_name: f.get("full_name"), email: f.get("email"), password: f.get("password"),
      } });
      history.replaceState(null, "", location.pathname);
      signIn(session.access_token);
    } catch (error) {
      $("#registerErr").textContent = error.message;
    }
  });
}

/* ---------- account settings ---------- */

function accountCard({ password = true } = {}) {
  const passwordForm = password
    ? `<form id="passwordForm">
      <div class="row">
        <div><label>Current password</label><input type="password" name="current_password" required autocomplete="current-password"></div>
        <div><label>New password (at least 12 characters)</label><input type="password" name="new_password" required minlength="12" maxlength="128" autocomplete="new-password"></div>
      </div>
      <button class="btn alt" type="submit">Change password</button> <span id="passwordResult"></span>
      <p class="muted small">Changing your password signs you out on every other device.</p>
    </form>`
    : "";
  return `<div class="card"><h2>My account</h2>
    ${passwordForm}
    <h3>Signed-in devices</h3>
    <div id="devices"><p class="muted">Loading…</p></div>
    <p class="muted small">You can install SyncCampus on your phone like an app.</p>
    <button class="btn alt sm" id="installApp" type="button">Install app</button>
  </div>`;
}

async function renderDevices() {
  const list = $("#devices");
  if (!list) return;
  try {
    const sessions = await call("/auth/sessions");
    list.innerHTML = sessions.map((s) => `<div class="upd"><b>${esc(s.label)}</b>${s.current ? ' <span class="pill good">this device</span>' : ""}<br>
        <small>Signed in ${fmtDate(s.signed_in_at)} · last active ${fmtDate(s.last_seen_at)}</small>
        ${s.current ? "" : ` <button class="btn sm alt" data-signout="${s.id}">Sign out</button>`}</div>`).join("")
      + (sessions.length > 1 ? '<button class="btn sm" id="signOutOthers">Sign out all other devices</button>' : "");
    list.querySelectorAll("[data-signout]").forEach((button) => {
      button.onclick = async () => {
        await call(`/auth/sessions/${button.dataset.signout}/revoke`, { method: "POST" });
        renderDevices();
      };
    });
    if ($("#signOutOthers")) {
      $("#signOutOthers").onclick = async () => {
        await call("/auth/sessions/revoke-others", { method: "POST" });
        renderDevices();
      };
    }
  } catch (error) {
    list.innerHTML = `<p class="err">${esc(error.message)}</p>`;
  }
}

function bindAccountCard() {
  renderDevices();
  const form = $("#passwordForm");
  if (!form) return;
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const f = new FormData(form);
    const out = $("#passwordResult");
    try {
      await call("/auth/change-password", { method: "POST", body: { current_password: f.get("current_password"), new_password: f.get("new_password") } });
      out.className = "ok";
      out.textContent = "Password changed. Other devices have been signed out.";
      form.reset();
      renderDevices();
    } catch (error) {
      out.className = "err";
      out.textContent = error.message;
    }
  });
}
