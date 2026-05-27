# Specs

Use this folder for active feature delivery artifacts.

Suggested structure:

- specs/001-feature-name/spec.md
- specs/001-feature-name/plan.md
- specs/001-feature-name/tasks.md
- specs/001-feature-name/memory.md
- specs/001-feature-name/memory-synthesis.md

Use `memory.md` for active feature context only:

- spec clarifications
- scoped constraints
- open questions
- short-lived notes that matter until the feature ships

Use `memory-synthesis.md` as the compact AI-facing summary before planning, tasks, implementation, and verification.
Normal downstream commands should consume `memory-synthesis.md`, not the whole durable memory folder.
This is a workflow requirement enforced by prompts and shared instructions rather than a separate CLI command.

Do not copy long-term decisions or bug patterns into feature memory unless they are directly relevant.
Do not use this folder as durable project memory storage.
