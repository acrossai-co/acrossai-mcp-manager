# Audit Memory

You are running a high-integrity audit of the project's durable and feature memory for `memory-hub`.

## Goal
Validate the quality, accuracy, and density of memory artifacts (`{memory_root}/*.md` and `{specs_root}/<feature>/memory*.md`). Identify stale, contradictory, or low-signal entries that degrade the project's long-term intelligence.

Audit is intentionally expensive and may read all memory files. Normal synthesis must not; it should use `{memory_root}/INDEX.md` and selected source sections only.

## Operating Constraints
- **STRICTLY READ-ONLY**: This command is analytical. Do **not** modify any files.
- **Evidence-Based**: Every finding must cite a specific entry or lack thereof.

## Detection Scope
Check for:
- **Stale/Obsolete**: Decisions or patterns that no longer apply to the current codebase.
- **Contradictions**: Memory entries that conflict with the Constitution or other memory files.
- **Noise/Triviality**: Routine history, speculative notes, or implementation details that lack durable value.
- **Index Integrity**: `{memory_root}/INDEX.md` points to valid source entries and stays compact.
- **Selection Hygiene**: Deprecated or superseded decisions are not selected during synthesis.
- **Leakage**: Feature-specific details that belong in `{specs_root}/` but have leaked into `{memory_root}/`.
- **Synthesis Drift**: `{memory_synthesis_filename}` is out of sync with selected memory.
- **Synthesis Budget**: `{memory_synthesis_filename}` exceeds configured `retrieval.max_synthesis_words`.
- **Formatting Issues**: Entries that are too long, vague, or repetitive.

## Severity Guide
- **CRITICAL**: Contradicts the Constitution, contains dangerous/incorrect security guidance, or is fundamentally stale.
- **HIGH**: Significant duplication, misplaced entries in the wrong layer, or missing synthesis for a complex feature.
- **MEDIUM**: Wordy entries, weak evidence, or minor pattern drift.
- **LOW**: Minor formatting or naming inconsistencies.

## Output Format

# Memory Audit Report

| ID | File | Severity | Issue | Recommendation |
|:---|:---|:---|:---|:---|
| M1 | `DECISIONS.md` | CRITICAL | Stale decision on [X] | Remove/Update to reflect [Y] |

### Metrics
- **Memory Quality Score**: [e.g. 85/100]
- **Signal-to-Noise Ratio**: [High / Medium / Low]
- **Stale Entry Rate**: [e.g. 5%]
- **Synthesis Accuracy**: [Verified / Drifted]

### Findings Summary
- **Durable Memory Health**: [Summary of PROJECT_CONTEXT, ARCHITECTURE, DECISIONS]
- **Feature Memory Health**: [Summary of active specs/ memory]

### Action Plan
1. **Critical Cleanup**: Resolve contradictions and stale decisions immediately.
2. **Refactoring**: Merge duplicates and move leaked feature notes to their respective `specs/`.
3. **Synthesis Refresh**: Update `{memory_synthesis_filename}` to reflect current implementation.
4. **Remediation**: "Would you like me to suggest concrete cleanup edits for the top issues?"

---
## Cleanup Rubric
- **Durable**: Will this be useful in 6 months?
- **Actionable**: Does it inform future decisions or implementation?
- **Non-obvious**: Is it something the AI wouldn't already know from standard framework docs?
- **Evidenced**: Is it backed by a PR, bug, or explicit decision?
- **Correctly Scoped**: Is it in the right file?
  - `PROJECT_CONTEXT.md` for stable product and domain context.
  - `ARCHITECTURE.md` for system shape and boundaries.
  - `DECISIONS.md` for explicit tradeoffs and chosen direction.
  - `BUGS.md` for recurring failure modes and prevention.
  - `WORKLOG.md` for concise high-value milestone notes.
  - `INDEX.md` for compact routing metadata only.
