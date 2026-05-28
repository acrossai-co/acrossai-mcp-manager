# Specs

This directory holds one folder per feature, named `NNN-feature-name` (e.g., `001-mcp-server-management`).

## Feature Folder Structure

Each feature folder contains:

```
specs/NNN-feature-name/
├── spec.md               # Feature specification (created by /speckit.specify)
├── plan.md               # Implementation plan (created by /speckit.plan)
├── tasks.md              # Task list (created by /speckit.tasks)
├── memory.md             # Feature-local constraints, open questions, watchlist
└── memory-synthesis.md   # Synthesized memory snapshot used before planning/implementation
```

## Workflow

1. `/speckit.specify` — write `spec.md`
2. `/speckit.plan` — write `plan.md` (reads memory before planning)
3. `/speckit.tasks` — write `tasks.md`
4. `/speckit.implement` — execute tasks
5. `/speckit.memory-md.capture-from-diff` — capture durable lessons to `docs/memory/`

See `.specify/memory/constitution.md` for quality gates that must pass before a feature is complete.
