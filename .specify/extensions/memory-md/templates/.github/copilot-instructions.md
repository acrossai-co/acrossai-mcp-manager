# Copilot Instructions

This repository is built to work with VS Code Copilot agents memory.

For any non-trivial task, memory is part of the workflow, not optional documentation.

## Memory Layers

- Constitution / principles:
  Read the current constitution or project principles first.
  Store only stable operating principles there. Never store feature-specific notes there.
- Durable project memory:
  `docs/memory/INDEX.md` is the compact routing map.
  `docs/memory/PROJECT_CONTEXT.md`
  `docs/memory/ARCHITECTURE.md`
  `docs/memory/DECISIONS.md`
  `docs/memory/BUGS.md`
  `docs/memory/WORKLOG.md`
- Active feature memory:
  `specs/<feature>/memory.md`
  `specs/<feature>/memory-synthesis.md`
- Ephemeral run context:
  Use the current prompt, diff, terminal output, and temporary notes only. Do not commit them to durable memory.

## Required Workflow

These requirements are enforced in this repository by prompts, shared instructions, and review expectations.
They are not yet backed by separate `/tasks` or `/verify` extension commands.

Before `/specify`:
- Read constitution or principles only if present and small.
- Read `docs/memory/INDEX.md` first, then only selected source sections when needed.
- Do not load all durable memory files during `/specify`.
- Produce or refresh a compact `memory-synthesis.md` section for constraints, reused decisions, bug patterns, boundaries, conflicts, assumptions, and watchpoints.

Before `/plan` and `/tasks`:
- Read the active spec plus `memory.md` and `memory-synthesis.md`.
- Normal downstream flow should consume `memory-synthesis.md`, not the whole memory folder.
- Do not proceed if there is an unresolved hard conflict with project memory or architecture boundaries.

Before `/implement`:
- Re-read `memory-synthesis.md`.
- Treat implementation and verification watchpoints as requirements, not suggestions.

After `/implement` and after `/verify`:
- Review the diff, task completion, tests, and findings.
- Propose durable memory and `INDEX.md` updates first.
- Update durable memory only after explicit approval and only when the lesson is durable, evidenced, reusable, and non-obvious.
- Refuse changelog-style or speculative memory updates.

Treat docs/memory as the repository memory layer.
Keep entries concise, durable, and reviewable in Git.
Do not assume hidden state outside the repository.
Keep `memory-synthesis.md` under the configured retrieval word budget.

A task is not fully complete until memory has been reviewed.
