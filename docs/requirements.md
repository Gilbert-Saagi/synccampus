# Requirements traceability

How each question in the requirements-gathering questionnaire is answered by Saagi SyncCampus.
Where a question offered choices, the system follows the most common response. All numeric
settings derived from the questionnaire live in `backend/src/Engine/Rules.php`, so they can be
updated if the final tallies change.

## Section A – Respondent information (Q1–Q3)

Used to describe the sample. The roles listed (students, class representatives, cabinet
members/delegates, Guild President, administrative staff) map onto the system's users:
anonymous students, `delegate`, `president` / `vice_guild` and `admin` accounts.

## Section B – Current handling of concerns (Q4–Q8)

| Q | Finding | How the system responds |
| --- | --- | --- |
| 4b | Concerns arrive through WhatsApp, calls, face-to-face, class reps | One submission form for all students, no account needed, works on a phone and installs as an app |
| 5 | Common concern types | The twelve categories on the submit form, plus **Other** |
| 6 | Slow response, no feedback, unclear responsibility, lost concerns, repeats, poor tracking, no records, loss at handover | Priority queue, private replies on the tracking page, delegate recommendation, related-report detection, status tracking, audit log, handover records |
| 7 | Resolution should be recorded, confirmed by the student and reopened if it persists | A delegate must write an update to resolve; the student confirms or reopens; reopened concerns return to the same delegate |
| 8 | Progress is hard to track | Every concern has a status (new → assigned → in progress → resolved → closed) visible to the student and the leaders |

## Section C – Baseline timelines (Q9–Q14)

These questions give the "before" picture for the project report. The system measures the same
things from its own records, so the two can be compared in the evaluation chapter:

| Q | Measure | Measured by the system as |
| --- | --- | --- |
| 9 | Time to identify the responsible person | Instant: a delegate is recommended when the concern is opened |
| 10 | Time to assign | Submission → first assignment (`avg_assign_hrs`) |
| 11 | Time to first response | Submission → first reply the student can see (`avg_first_response_hrs`) |
| 12 | Time to resolve | Submission → marked resolved (`avg_resolution_hrs`) |
| 13 | Share of concerns resolved | `resolution_rate` |
| 14 | Satisfaction | Students rate 1–5 when confirming a fix (`avg_satisfaction`) |

## Section D – Prioritisation (Q15–Q17)

- **Q15:** all eight factors are used. Severity comes from the category, urgency and number
  affected from the form, duration from the time open, and repeated reports from related-report
  detection. Safety risk, academic impact and financial impact are checkboxes, and the last two
  are pre-ticked for academic and fee categories.
- **Q16:** the ranking sets the weights. Safety risk ranked first, so it works as an override and a
  safety concern is never below Critical. The other seven factors get rank-sum weights
  `w = (8 − rank) / 28`: severity .25, urgency .21, students affected .18, academic impact .14,
  repeated reports .11, duration .07, financial impact .04.
- **Q17:** there was no formal classification before. The system uses Critical ≥ 80, High ≥ 60,
  Medium ≥ 40, Low below 40, and leaders can open a breakdown of any score.

## Section E – Assignment and follow-up (Q18–Q20)

- **Q18:** a recommendation weighs portfolio/category match (45%), spare capacity (25%),
  experience with the category (15%) and campus/location (15%). Delegates marked as away, or
  already at their maximum load, are not recommended. Delegates set their own availability.
- **Q19:** the leader can accept the recommendation, choose someone else, assign manually, see
  the reasons and score, and see every delegate's workload. Overrides are recorded.
- **Q20:** assignments appear on the delegate's dashboard flagged **New** until opened. Because
  WhatsApp is how delegates are told today, the leader also gets a **Notify on WhatsApp** button
  with a ready message (reference and title only, no student details).

## Section F – Escalation (Q21–Q25)

- **Q21–Q22:** escalation limits follow the most common answers: Critical within 6 hours, High
  within 24 hours, Medium within 3 days, Low within 1 week. A reminder is sent halfway there.
- **Q23:** baseline only.
- **Q24:** when a limit passes, the system records a reminder or escalation, marks the concern
  overdue, shows it to the Guild President, and posts a delay notice the student sees on their
  tracking page. The leader can reassign.
- **Q25:** escalations go to the Guild President and Vice, who acknowledge them on the dashboard.

## Section G – Performance indicators (Q26–Q28)

- **Q26–Q27:** performance is computed from recorded data. For each delegate: concerns assigned,
  open, resolved, completion rate, overdue, average first response, average resolution time,
  escalations, reassignments and student satisfaction. Meeting attendance is not recorded in this
  version.
- **Q28:** the Guild President and Vice see every delegate. Each delegate sees only their own figures.

## Section H – Related concerns and decision support (Q29–Q30)

- **Q29:** similar reports are detected (TF-IDF + cosine similarity) and linked, but each report
  stays separate. The queue shows how many reports are linked and how many students they affect
  in total. Priority rises with repeats, and the delegate holding a linked concern gets an
  internal note when another report arrives.
- **Q30:** the dashboards show unresolved counts, top categories, response and resolution times,
  related reports, monthly and campus trends, workload and delegate performance.

## Section I – Handover (Q31–Q35)

- **Q31–Q32, Q34:** before the system there was no reliable written record.
- **Q33:** a handover record saves unresolved concerns with their latest notes, active projects
  and pending tasks, the history of resolved concerns, delegate roles and responsibilities,
  delegate performance, and upcoming meetings and events. Contacts and financial records are left
  to a later version.
- **Q35:** records are created by the President or Vice and can be read by all incoming leaders and
  cabinet members in the Guild workspace.

## Section J – Features (Q36–Q38)

Everything marked Critical or Important is in this version: concern submission and tracking,
priority scoring, related-report detection, delegate recommendation, workload monitoring, automatic
reminders and escalation, response and resolution reporting, analytics and trends, handover
records, project tracking, and a calendar of upcoming meetings, events and due dates in the
workspace.
