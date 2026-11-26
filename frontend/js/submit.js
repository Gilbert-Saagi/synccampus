let categories = [];

async function loadCategories() {
  try {
    categories = await api("/categories");
    $("#category").innerHTML = categories.map((c) => `<option value="${c.id}">${esc(c.name)}</option>`).join("");
    $("#category").disabled = false;
    syncSensitive({ presetImpact: true });
  } catch (error) {
    $("#category").innerHTML = "<option>Categories could not be loaded</option>";
    $("#submitErr").textContent = error.message;
  }
}

// Sensitive categories (harassment, discrimination) can never be made public.
// Academic and fee categories pre-tick the matching impact box; the student can untick it.
function syncSensitive({ presetImpact = false } = {}) {
  const category = categories.find((c) => String(c.id) === $("#category").value);
  if (presetImpact && category) {
    $("#academicImpact").checked = category.academic;
    $("#financialImpact").checked = category.financial;
  }
  const sensitive = Boolean(category && category.is_sensitive);
  $("#isPublic").disabled = sensitive;
  if (sensitive) $("#isPublic").checked = false;
  $("#sensNote").classList.toggle("hide", !sensitive);
}

function showReceipt(form, r) {
  form.classList.add("hide");
  const box = $("#result");
  box.classList.remove("hide");
  box.innerHTML = `<h2>Concern received</h2>
    <p>Reference: <b>${esc(r.reference)}</b></p>
    <p>Your private access code:</p><div class="code">${esc(r.access_code)}</div>
    <div class="notice"><b>Save this code now.</b> It is shown only once and nobody, including the Guild, can recover it. Never share it.</div>
    ${r.similar_reports_found ? `<p class="muted">${r.similar_reports_found} similar report(s) were found and linked, which raises the priority for everyone affected.</p>` : ""}
    <div class="actions"><button class="btn alt" id="copyBtn">Copy reference and code</button>
    <a class="btn" href="/track.html">Go to tracking</a>
    <button class="btn alt" id="againBtn">Submit another</button></div>`;

  $("#copyBtn").onclick = async () => {
    try {
      await navigator.clipboard.writeText(`${r.reference} ${r.access_code}`);
      $("#copyBtn").textContent = "Copied";
    } catch {
      $("#copyBtn").textContent = "Copy failed – write it down";
    }
  };
  $("#againBtn").onclick = () => {
    box.classList.add("hide");
    form.reset();
    form.classList.remove("hide");
    syncSensitive({ presetImpact: true });
  };
}

$("#category").addEventListener("change", () => syncSensitive({ presetImpact: true }));

$("#submitForm").addEventListener("submit", async (e) => {
  e.preventDefault();
  $("#submitErr").textContent = "";
  const f = new FormData(e.target);
  const button = e.target.querySelector("button[type=submit]");
  button.disabled = true;
  try {
    const receipt = await api("/concerns", { method: "POST", body: {
      title: f.get("title"),
      description: f.get("description"),
      category_id: Number(f.get("category_id")),
      campus: f.get("campus"),
      location: f.get("location") || "",
      urgency: Number(f.get("urgency")),
      students_affected: Number(f.get("students_affected")) || 1,
      safety_flag: f.get("safety_flag") === "on",
      academic_impact: f.get("academic_impact") === "on",
      financial_impact: f.get("financial_impact") === "on",
      is_public: f.get("is_public") === "on",
    } });
    rememberReference(receipt.reference);
    showReceipt(e.target, receipt);
  } catch (error) {
    $("#submitErr").textContent = error.message;
  } finally {
    button.disabled = false;
  }
});

loadCategories();
