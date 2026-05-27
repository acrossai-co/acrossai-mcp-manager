Before writing or revising the feature spec:

Read:
- config from `.specify/extensions/memory-md/config.yml` when present; otherwise use `memory_root: docs/memory` and `specs_root: specs`
- constitution or project principles only if present and small
- `{memory_root}/INDEX.md` when present
- any nearby feature memory from related unfinished work when clearly relevant

Select only relevant index entries by feature scope, affected modules, named technologies, security/data boundaries, active decisions, and known bug patterns. Read only the selected source sections when needed.
Respect configured retrieval budgets. If the budget is exceeded, summarize and prioritize instead of reading more memory.

Then:
- extract only the constraints, reused decisions, bug patterns, and architecture boundaries relevant to this feature
- write or refresh `{specs_root}/<feature>/memory.md` with feature-local notes and open questions
- write or refresh `{specs_root}/<feature>/memory-synthesis.md` with a compact summary for planning and implementation, within `retrieval.max_synthesis_words` defaulting to 900 words
- call out conflicts between the requested feature and existing durable memory
- separate durable project memory from transient feature context

Do not load all durable memory files during `/specify`.
Include only selected summaries in the spec.
Do not store transient feature notes in durable memory.
