# Plan With Memory

Before planning the feature, resolve configuration. If `.specify/extensions/memory-md/config.yml` exists, read it for `memory_root`, `specs_root`, `feature_memory_filename`, `memory_synthesis_filename`, `require_memory_synthesis_before_plan`, and `retrieval`.
Otherwise use defaults: `memory_root: docs/memory`, `specs_root: specs`, `feature_memory_filename: memory.md`, `memory_synthesis_filename: memory-synthesis.md`, `require_memory_synthesis_before_plan: true`, and the retrieval defaults below.
If `require_memory_synthesis_before_plan` is `false`, skip the synthesis gate but still produce a synthesis when possible.

## Retrieval Order

1. Read config.
2. Read constitution or project principles only if present and small.
3. Read the active feature spec.
4. Read `{specs_root}/<feature>/{feature_memory_filename}` if present.
5. Read `{memory_root}/INDEX.md`.
6. Select relevant index entries by feature scope, affected modules, named technologies, security/data boundaries, known bug patterns, and active decisions.
7. Only then read the smallest necessary source sections from durable memory files.
8. Create or refresh `{specs_root}/<feature>/{memory_synthesis_filename}`.

Do not read or paste entire durable memory files unless the index is missing, incomplete, or the user explicitly requests a full audit.

## Semantic Modeling

Before planning, build internal representations:
1. **Constraint Map**: Identify MUST/SHOULD rules from small principles files and selected architecture entries.
2. **Pattern Inventory**: Identify preferred implementation patterns from selected active decisions.
3. **Anti-Pattern Guard**: Identify selected recurring bug patterns that apply to this scope.
4. **Deviation Log**: Identify any `accepted-deviations` that relax standard rules.

## Retrieval Selection & Budget
Do not dump the entire repository memory into the synthesis. Use configured retrieval limits, defaulting to:
- Max 20 index entries considered
- Max 5 active decisions
- Max 5 architecture constraints
- Max 3 accepted deviations
- Max 3 security constraints
- Max 3 bug patterns
- Max 2 worklog items
- Max 900 synthesis words
- Full durable memory read allowed: false

If the budget is exceeded, summarize and prioritize the highest-impact entries instead of loading more memory.

### Phase-Aware Retrieval
Adapt synthesis based on the Spec Kit phase:
- **Specify/Plan**: Prioritize boundary definitions, module ownership, and architectural drift risks.
- **Tasks/Implement**: Prioritize migration patterns, security constraints, and known implementation risks.

### Decision State & Conflict Resolution
Treat memory as stateful.
- Supported states: `active`, `deprecated`, `superseded`, `experimental`, `accepted-deviation`.
- Prefer newer accepted decisions.
- Explicitly exclude `deprecated` or `superseded` memory.
- If an unresolved conflict exists, explicitly surface it in the "Conflict Warnings" section, preferring the current active standard.

### Required Synthesis Structure
Create or refresh `{specs_root}/<feature>/{memory_synthesis_filename}` matching exactly this structure and keep it within `retrieval.max_synthesis_words`:

```markdown
# Memory Synthesis

## Current Scope
[Brief description of feature scope and affected modules]

## Relevant Decisions
- [Decision] (Reason Included: [X], Status: [Y], Source: [Z])

## Active Architecture Constraints
- [Constraint] (Reason Included: [X], Source: [Z])

## Accepted Deviations
- [Deviation] (Reason Included: [X], Status: Accepted-Deviation)

## Relevant Security Constraints
- [Constraint] (Reason Included: [X], Source: security-constraints.md)

## Related Historical Lessons
- [Lesson] (Reason Included: [X])

## Conflict Warnings
- [Explicit conflicts between old and new memory]

## Retrieval Notes
- [Index entries considered, source sections read, budget status]
```

Conflict rules:
- Hard conflict: block progress when the spec or plan violates constitution rules, an explicit architecture boundary, a still-valid decision, or a known safety / data integrity bug prevention rule.
- Soft conflict: warn when memory suggests a preferred approach but the spec can still proceed with a justified alternative.
- Ask for clarification when the spec cannot satisfy memory without changing scope, requirements, or an existing durable decision.

Output:
- a concise planning synthesis
- Include only selected summaries in the plan.
- Do not continue to task breakdown or implementation with unresolved hard conflicts.
