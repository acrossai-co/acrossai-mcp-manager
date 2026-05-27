

<!-- Extension: memory-md -->
<!-- Config: .specify/extensions/memory-md/ -->
# Capture

Reflect on completed work and update durable memory only if needed.

Resolve configuration first. Use `.specify/extensions/memory-md/config.yml` when present; otherwise default to `memory_root: docs/memory` and `specs_root: specs`.

Capture is manual and human-approved. Do not write durable memory unless the user explicitly ran this command and approves the proposed updates.

Inputs to review:
- active spec / plan / tasks
- final implementation diff or summary
- tests or validation results
- review findings (Architecture Guard, Security Review, etc.), if any
- incident or bug-fix context, if any

For each candidate lesson, require all of these:
- reusable
- non-obvious
- likely to prevent future mistakes
- evidenced by the diff, tests, review feedback, or incident analysis
- correctly scoped to durable memory rather than feature-local notes

Every new entry must answer:
- why this is durable
- what future mistake it prevents
- what evidence supports it
- where maintainers should look next

Candidate files:
- `{memory_root}/DECISIONS.md`
- `{memory_root}/ARCHITECTURE.md`
- `{memory_root}/BUGS.md`
- `{memory_root}/WORKLOG.md`
- `{memory_root}/INDEX.md`

Rules:
- Prefer `DECISIONS.md` for still-active cross-feature choices and tradeoffs.
- Prefer `ARCHITECTURE.md` for durable boundaries or constraints.
- Prefer `BUGS.md` for repeatable failure modes and prevention guidance.
- Use `WORKLOG.md` only for short, durable lessons that do not fit the other two files.
- When adding durable memory to `DECISIONS.md`, `ARCHITECTURE.md`, `BUGS.md`, or `WORKLOG.md`, also add or update one compact routing row in `INDEX.md`.
- Keep `INDEX.md` short. It points to source entries; it does not duplicate full lessons.
- Refuse routine implementation detail, feature narrative, or speculative lessons.

Approval flow:
1. Show proposed durable memory entries and matching `INDEX.md` rows first.
2. Ask for approval before writing.
3. If approval is not explicit, stop after the proposal.
