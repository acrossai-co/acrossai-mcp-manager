# Copilot Instructions

This repository is built to work with VS Code Copilot agents memory.

For any non-trivial task, memory is part of the workflow, not optional documentation.

## Memory Layers

- Constitution / principles:
  Read the current constitution or project principles first.
  Store only stable operating principles there. Never store feature-specific notes there.
- Durable project memory:
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
- Read constitution, durable project memory, and any closely related bug or decision entries.
- Produce or refresh a compact `memory-synthesis.md` section for constraints, reused decisions, bug patterns, boundaries, conflicts, assumptions, and watchpoints.

Before `/plan` and `/tasks`:
- Read the active spec plus `memory.md` and `memory-synthesis.md`.
- Do not proceed if there is an unresolved hard conflict with project memory or architecture boundaries.

Before `/implement`:
- Re-read `memory-synthesis.md`.
- Treat implementation and verification watchpoints as requirements, not suggestions.

After `/implement` and after `/verify`:
- Review the diff, task completion, tests, and findings.
- Update durable memory only when the lesson is durable, evidenced, reusable, and non-obvious.
- Refuse changelog-style or speculative memory updates.

Treat docs/memory as the repository memory layer.
Keep entries concise, durable, and reviewable in Git.
Do not assume hidden state outside the repository.

A task is not fully complete until memory has been reviewed.
