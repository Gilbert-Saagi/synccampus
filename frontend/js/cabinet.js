(async () => {
  try {
    const people = await loadCabinet();
    $("#people").innerHTML = people.map((p) => personCard(p, { details: true })).join("") || '<p class="muted">No cabinet members listed yet.</p>';
  } catch {
    $("#people").textContent = "Could not load the cabinet list.";
  }
})();
