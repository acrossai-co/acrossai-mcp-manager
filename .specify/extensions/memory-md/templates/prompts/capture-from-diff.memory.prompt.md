Inspect the diff and identify only high-signal findings.

Capture is manual and human-approved. Show proposed durable entries and matching `{memory_root}/INDEX.md` rows first, then ask for approval before writing.

For each candidate memory update:
- verify evidence in the diff, tasks, tests, review feedback, or incident context
- classify it as decision, architecture constraint, bug pattern, or short durable worklog lesson
- prepare a compact routing row for `{memory_root}/INDEX.md` that points to the source entry
- reject it if it is obvious, transient, or feature-local

Summarize accepted entries with the durability, prevention value, evidence, and next place to inspect.
