---
description: Capture durable knowledge and architecture decisions from current or
  provided diffs.
scripts:
  sh: .specify/scripts/bash/detect-changed-files.sh
  ps: .specify/scripts/powershell/detect-changed-files.ps1
---


<!-- Extension: memory-md -->
<!-- Config: .specify/extensions/memory-md/ -->
# Capture From Diff

You are capturing durable knowledge for `memory-hub` by analyzing code changes.

Resolve configuration first. Use `.specify/extensions/memory-md/config.yml` when present; otherwise default to `memory_root: docs/memory` and `specs_root: specs`.

Capture is manual and human-approved. Do not write durable memory unless the user explicitly ran this command and approves the proposed updates.

## Determine Review Scope

1. **Identify Changed Files**:
   - If the user provided a diff or explicit instructions, follow them.
   - Otherwise, you **MUST** execute the `.specify/scripts/bash/detect-changed-files.sh` with `--json` to detect changed files since the merge-base or in the working directory.
   - Use the `changed_files` list as the primary set for knowledge extraction.

## Capture Process

1. **Inspect Changes**: Analyze the diff of the identified files.
2. **Identify High-Signal Knowledge**:
   - **Architecture Decisions**: New boundaries, patterns, or choices.
   - **Integration Gotchas**: Non-obvious failure modes or hidden dependencies.
   - **Recurring Patterns**: Bug patterns to prevent or conventions to follow.
   - **Tradeoffs**: Conscious decisions to prefer one quality over another.
3. **Verify Evidence**: Ensure every finding is backed by:
   - The actual diff content.
   - Successful tests or verification results.
   - Explicit task completion in `tasks.md`.
4. **Categorize and Route**:
   - `DECISIONS.md`: Durable architectural or technical choices.
   - `ARCHITECTURE.md`: Durable boundaries or constraints.
   - `BUGS.md`: Lessons from fixed bugs and prevention rules.
   - `WORKLOG.md`: High-value project milestones.
   - `INDEX.md`: Compact routing rows for every durable entry added or changed.
5. **Filter Noise**: Reject entries that are obvious, transient, feature-local, or weakly evidenced.

## Output Format

1. **Proposed Memory Updates**
   - **File**: [Target memory file]
   - **Category**: [Decision / Bug Pattern / Milestone]
   - **Signal**: [Actionable lesson captured]
   - **Evidence**: [Supporting code snippet or task ID]
   - **Index Row**: [Compact row to add/update in `{memory_root}/INDEX.md`]

2. **Action Plan**
   - **Durable Step**: Why this update prevents future drift or repeats.
   - **Approval Needed**: Ask whether to apply the durable memory and index updates.

Only write after explicit approval. If approval is not explicit, stop after the proposal.

---
## Capture Principles
- **Concise**: 1-2 sentences of durable guidance.
- **Actionable**: Tells a future developer exactly what to do or avoid.
- **Durable**: Remains relevant long after the current feature is shipped.