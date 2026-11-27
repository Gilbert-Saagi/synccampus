// System admin area (/admin/). Sets up and maintains the Guild cabinet's accounts.
// The admin never sees student concerns.

const ACADEMIC = "Academic issues, Examinations, Timetables, Registration";

// Suggested role, areas and campus for each cabinet position. The admin can change any
// of these before creating the link, and later on the staff accounts list.
function suggestFor(position) {
  const p = position.toLowerCase();
  const campus = p.includes("city campus") ? "City Campus" : "";
  const rules = [
    [/vice guild|vice president/, "vice_guild", "General"],
    [/guild president/, "president", "General"],
    [/ict/, "delegate", "ICT / Internet"],
    [/security/, "delegate", "Security, Campus facilities"],
    [/education/, "delegate", ACADEMIC],
    [/grc|speaker/, "delegate", ACADEMIC],
    [/gender|ethics/, "delegate", "Harassment / discrimination, Student welfare"],
    [/health/, "delegate", "Student welfare"],
    [/internal affairs/, "delegate", "Campus facilities, Student welfare, Administration"],
    [/general secretary/, "delegate", "Administration, Tuition / fees"],
    [/sports|culture|publicity|social affairs|mobiliz/, "delegate", "Student activities / events"],
    [/religious/, "delegate", "Student welfare, Student activities / events"],
  ];
  const [, role, categories] = rules.find(([pattern]) => pattern.test(p)) || [null, "delegate", "General"];
  return { role, categories, campus };
}

const same = (a, b) => (a || "").trim().toLowerCase() === (b || "").trim().toLowerCase();

const portal = initPortal({
  home: "/admin/",
  passwordLogin: false,
  render: async (account) => {
    if (account.role !== "admin") {
      wrongPortal("This page is only for the system administrator. Guild cabinet members sign in on the staff page.", "/staff/", "Go to the staff page");
      return;
    }
    await adminView();
  },
});

/* ---------- sign-in with an emailed link, bound to this device ---------- */

// A random key that stays in this browser. The emailed link only works when the
// same key is sent back, so a forwarded or intercepted link is useless elsewhere.
function deviceKey() {
  const KEY = "saagi_admin_device";
  let value = localStorage.getItem(KEY);
  if (!value) {
    const bytes = crypto.getRandomValues(new Uint8Array(32));
    value = [...bytes].map((b) => b.toString(16).padStart(2, "0")).join("");
    localStorage.setItem(KEY, value);
  }
  return value;
}

$("#adminLinkForm").addEventListener("submit", async (e) => {
  e.preventDefault();
  $("#loginErr").textContent = "";
  const button = e.target.querySelector("button");
  button.disabled = true;
  try {
    const result = await api("/auth/admin-link", { method: "POST", body: { email: new FormData(e.target).get("email"), device: deviceKey() } });
    e.target.classList.add("hide");
    $("#linkSent").innerHTML = `<div class="notice"><b>Check your email.</b> ${esc(result.message)}
        ${result.dev_link ? `<p class="small">Development server – no email is sent. <a class="more" href="${esc(result.dev_link)}">Open the sign-in link</a></p>` : ""}
        <p><button class="btn sm alt" id="again" type="button">Use a different address</button></p></div>`;
    $("#again").onclick = () => {
      $("#linkSent").innerHTML = "";
      e.target.classList.remove("hide");
    };
  } catch (error) {
    $("#loginErr").textContent = error.message;
  } finally {
    button.disabled = false;
  }
});

async function confirmLink(linkToken) {
  history.replaceState(null, "", location.pathname);
  $("#adminLinkForm").classList.add("hide");
  $("#linkSent").innerHTML = '<p class="muted">Signing you in…</p>';
  try {
    const session = await api("/auth/admin-link/confirm", { method: "POST", body: { token: linkToken, device: deviceKey() } });
    $("#linkSent").innerHTML = "";
    portal.signIn(session.access_token);
  } catch (error) {
    $("#linkSent").innerHTML = `<p class="err">${esc(error.message)}</p>`;
    $("#adminLinkForm").classList.remove("hide");
  }
}

const linkToken = new URLSearchParams(location.hash.slice(1)).get("login");
if (linkToken) confirmLink(linkToken);

async function adminView() {
  const [staff, invitations, cabinet] = await Promise.all([call("/admin/staff"), call("/admin/invitations"), loadCabinet().catch(() => [])]);

  $("#app").innerHTML = `
    <div class="card"><h1>Administration</h1>
      <p class="sub">Create and look after the Guild cabinet's accounts. Each person gets a one-use sign-up link, sets their own password,
      then signs in at <b>${esc(location.origin)}/staff/</b>. For student privacy, the admin account cannot open concerns.</p></div>
    ${cabinetCard(cabinet, staff, invitations)}
    ${invitePanel({ title: "Create an invitation", intro: "Use “Prepare invite” above, or fill this in for anyone not on the list. " })}
    ${staffCard(staff)}
    ${invitationsCard(invitations)}
    ${accountCard({ password: false })}`;

  bindInviteForm(() => setTimeout(adminView, 4000));
  bindAccountCard();

  $$("[data-prepare]").forEach((button) => {
    button.onclick = () => {
      const person = cabinet.find((p) => String(p.id) === button.dataset.prepare);
      prefillInvite({ name: person.name, portfolio: person.position, ...suggestFor(person.position) });
    };
  });
  $$("[data-toggle]").forEach((button) => {
    button.onclick = async () => {
      await call(`/admin/staff/${button.dataset.toggle}`, { method: "PATCH", body: { is_active: button.dataset.active !== "1" } });
      adminView();
    };
  });
  $$("form[data-edit]").forEach((form) => {
    form.onsubmit = async (e) => {
      e.preventDefault();
      const f = new FormData(form);
      const status = $(".formStatus", form);
      try {
        await call(`/admin/staff/${form.dataset.edit}`, { method: "PATCH", body: {
          portfolio: f.get("portfolio"), categories_handled: f.get("categories_handled"),
          campus: f.get("campus"), max_open_load: Number(f.get("max_open_load")),
        } });
        status.className = "formStatus ok";
        status.textContent = "Saved";
      } catch (error) {
        status.className = "formStatus err";
        status.textContent = error.message;
      }
    };
  });
  $$("[data-revoke]").forEach((button) => {
    button.onclick = async () => {
      await call(`/admin/invitations/${button.dataset.revoke}/revoke`, { method: "POST" });
      adminView();
    };
  });
}

function cabinetCard(cabinet, staff, invitations) {
  if (!cabinet.length) return "";
  const rows = cabinet.map((person) => {
    const account = staff.find((u) => same(u.name, person.name));
    const invite = invitations.find((i) => i.status === "pending" && same(i.invitee_name, person.name));
    const state = account
      ? `<span class="pill good">account created</span>${account.last_login ? "" : ' <span class="muted small">never signed in</span>'}`
      : invite ? `<span class="pill">invited · expires ${new Date(invite.expires_at).toLocaleDateString()}</span>` : '<span class="pill over">not invited</span>';
    return `<tr><td><b>${esc(person.name)}</b></td><td>${esc(person.position)}</td><td>${state}</td>
      <td>${account ? "" : `<button class="btn sm${invite ? " alt" : ""}" data-prepare="${person.id}">${invite ? "Invite again" : "Prepare invite"}</button>`}</td></tr>`;
  }).join("");
  const done = cabinet.filter((p) => staff.some((u) => same(u.name, p.name))).length;
  return `<div class="card"><h2>Guild cabinet</h2>
    <p class="sub">${done} of ${cabinet.length} cabinet members have an account. Start with the Guild President and Vice.</p>
    <div class="tablewrap"><table><thead><tr><th>Name</th><th>Position</th><th>Account</th><th></th></tr></thead><tbody>${rows}</tbody></table></div></div>`;
}

function staffCard(staff) {
  const campusOptions = (value) => ["", "Main Campus", "City Campus"]
    .map((c) => `<option value="${c}"${c === (value || "") ? " selected" : ""}>${c || "Both campuses"}</option>`).join("");
  const rows = staff.map((u) => `<tr class="${u.is_active ? "" : "muted"}">
      <td><b>${esc(u.name)}</b><br><span class="muted">${esc(u.email)}</span></td>
      <td>${esc(ROLE_LABELS[u.role] || u.role)}<br><span class="muted">${esc(u.portfolio || "")}</span></td>
      <td>${u.is_active ? (u.is_available === false ? "away" : "active") : "switched off"}<br>
        <span class="muted small">${u.last_login ? "last sign-in " + fmtDate(u.last_login) : "never signed in"}</span></td>
      <td>${u.role === "admin" ? "" : `<details><summary>Edit</summary>
          <form data-edit="${u.id}">
            <label>Portfolio</label><input name="portfolio" value="${esc(u.portfolio || "")}" required maxlength="120">
            <label>Categories handled</label><input name="categories_handled" value="${esc(u.categories_handled || "")}" maxlength="300">
            <div class="row"><div><label>Campus</label><select name="campus">${campusOptions(u.campus)}</select></div>
              <div><label>Max open</label><input name="max_open_load" type="number" min="1" max="50" value="${u.max_open_load ?? 5}"></div></div>
            <button class="btn sm">Save</button> <span class="formStatus"></span>
          </form></details>`}
        ${u.id === me.id ? "" : `<button class="btn sm alt" data-toggle="${u.id}" data-active="${u.is_active ? 1 : 0}">${u.is_active ? "Switch off" : "Switch on"}</button>`}</td>
    </tr>`).join("");
  return `<div class="card"><h2>Staff accounts</h2>
    <p class="sub">Switch off the account of anyone who leaves the cabinet; concerns they held go back to the President's queue.</p>
    <div class="tablewrap"><table><thead><tr><th>Person</th><th>Role</th><th>Status</th><th></th></tr></thead><tbody>${rows}</tbody></table></div></div>`;
}

function invitationsCard(invitations) {
  const rows = invitations.slice(0, 30).map((i) => `<tr>
      <td>${esc(i.invitee_name || "–")}<br><span class="muted">${esc(i.invitee_email || "")}</span></td>
      <td>${esc(i.portfolio)}</td><td>${esc(i.created_by)}<br><span class="muted small">${fmtDate(i.created_at)}</span></td>
      <td><span class="pill${i.status === "used" ? " good" : i.status === "pending" ? "" : " over"}">${i.status}</span></td>
      <td>${i.status === "pending" ? `<button class="btn sm alt" data-revoke="${i.id}">Cancel link</button>` : ""}</td></tr>`).join("");
  return `<div class="card"><h2>Invitation links</h2>
    <p class="sub">Links created by you and by the Guild leaders. Cancel any link sent to the wrong person.</p>
    ${rows ? `<div class="tablewrap"><table><thead><tr><th>For</th><th>Position</th><th>Created by</th><th>Status</th><th></th></tr></thead><tbody>${rows}</tbody></table></div>` : '<p class="muted">No invitations yet.</p>'}</div>`;
}
