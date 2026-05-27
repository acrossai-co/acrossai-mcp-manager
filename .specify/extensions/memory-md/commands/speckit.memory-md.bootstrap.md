# Bootstrap

Set up this repository to use the layered Spec Kit Memory workflow.

Tasks:

1. Read `config-template.yml` at the extension root for default values.
   If the project has `.specify/extensions/memory-md/config.yml`, use those values instead.
   Fall back to defaults: `memory_root: docs/memory`, `specs_root: specs`.
2. Ensure these folders exist:
   - `{memory_root}` (default: docs/memory)
   - `{specs_root}` (default: specs)
   - .github
3. Create missing durable memory files from the extension templates:
   - `{memory_root}/INDEX.md`
   - `{memory_root}/PROJECT_CONTEXT.md`
   - `{memory_root}/ARCHITECTURE.md`
   - `{memory_root}/DECISIONS.md`
   - `{memory_root}/BUGS.md`
   - `{memory_root}/WORKLOG.md`
4. Create or update spec starter files so every feature folder can contain:
   - spec.md
   - plan.md
   - tasks.md
   - `{feature_memory_filename}` (default: memory.md)
   - `{memory_synthesis_filename}` (default: memory-synthesis.md)
5. Create or update `.github/copilot-instructions.md` so memory is required before planning and implementation.
6. If `.specify/extensions/memory-md/config.yml` does not exist, create it from `config-template.yml` with default values.
7. Summarize the memory model:
   - constitution / principles = stable operating rules
   - durable project memory = reusable cross-feature knowledge
   - active feature memory = feature-local constraints, open questions, and carry-forward context
   - memory index = compact routing map for selecting relevant durable entries
   - ephemeral run context = temporary prompt or terminal state that must not be committed
8. List the first customization steps:
   - fill in project context and architecture
   - migrate any durable lessons into decisions or bugs
   - stop using worklog as a changelog
   - use feature memory plus synthesis on the next spec
   - review config.yml and adjust paths if needed

Prioritize preserving existing project files.
Never overwrite project-specific memory without explicit approval.
