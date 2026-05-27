# Local Optimizer Roadmap

This roadmap documents an optional Node.js + SQLite optimizer for projects that want to reduce token usage by searching local caches before the AI reads context.

The optimizer is an enhancement, not a requirement. Basic markdown-only usage remains the default.

## Trust Model

Markdown, Spec Kit artifacts, and source code remain the source of truth.
SQLite is a rebuildable cache for discovery and retrieval.
`memory-synthesis.md` remains the compact AI-facing package used during normal planning and implementation.
The LLM should read synthesis or search results first, not all files.

## Phase 1: Cache Durable Memory

Scope:

- `docs/memory/INDEX.md`
- `docs/memory/PROJECT_CONTEXT.md`
- `docs/memory/ARCHITECTURE.md`
- `docs/memory/DECISIONS.md`
- `docs/memory/BUGS.md`
- `docs/memory/WORKLOG.md`

Purpose:

- Reduce token usage during memory-aware planning.
- Search local SQLite first, then generate a compact `memory-synthesis.md`.

Behavior:

1. User manually captures durable memory.
2. Markdown memory files remain authoritative.
3. Node.js indexes approved memory files into SQLite.
4. Search returns only relevant memory entries.
5. The optimizer generates `specs/<feature>/memory-synthesis.md`.
6. Normal planning reads only `memory-synthesis.md`.

Commands:

```text
npx speckit-memory index-memory
npx speckit-memory search-memory "query"
npx speckit-memory synthesize --feature specs/<feature>
npx speckit-memory audit-memory
npx speckit-memory refresh-memory
```

Rules:

- Durable capture remains manual and human-approved.
- SQLite may refresh automatically after approved markdown changes.
- The AI should not read all durable memory files by default.
- Full memory audit may be expensive.

## Phase 2: Cache Development Docs

Scope:

- `constitution.md`
- `.specify/memory/*.md` if present
- `docs/**/*.md`
- `specs/**/spec.md`
- `specs/**/plan.md`
- `specs/**/tasks.md`
- `specs/**/research.md`
- `specs/**/data-model.md`
- `specs/**/contracts/*`
- `README.md`
- architecture docs
- security docs
- bug docs

Purpose:

- Avoid making the AI read every spec, plan, task, architecture, or bug note repeatedly.

Behavior:

1. Node.js chunks development docs.
2. Each chunk is stored with metadata such as source path, artifact type, feature id, heading, status, tags, hash, and updated time.
3. Search retrieves relevant docs before the AI opens files.
4. Direct file read is used only for selected authoritative files.

Retrieval order:

```text
1. Search SQLite cache
2. Read top relevant snippets or synthesis
3. Directly open only selected source files when needed
4. Avoid scanning all docs by default
```

Commands:

```text
npx speckit-memory index-docs
npx speckit-memory search-docs "query"
npx speckit-memory synthesize-docs --feature specs/<feature>
npx speckit-memory audit-docs
npx speckit-memory refresh-docs
```

Source files remain authoritative.

## Phase 3: Cache Code Symbols

Scope:

- files
- functions
- classes
- methods
- interfaces and types
- exports
- imports
- routes
- commands
- config keys
- tests

Purpose:

- Help the AI find existing code before creating new functions or classes.
- Reduce duplicate implementation.

Behavior:

1. Node.js scans source files.
2. It extracts symbol metadata such as symbol name, symbol type, file path, line range, signature, doc comment, exports, imports, tags, and hash.
3. The AI searches symbols before implementing.
4. The AI opens only selected source files or line ranges.

Do not store full source code in SQLite by default. Store signatures, doc comments, small snippets, and line ranges.

Commands:

```text
npx speckit-memory index-code
npx speckit-memory search-code "query"
npx speckit-memory find-duplicates
npx speckit-memory code-context --feature specs/<feature>
npx speckit-memory audit-code
npx speckit-memory refresh-code
```

Duplicate-prevention flow:

```text
User asks to implement feature
↓
Optimizer searches existing symbols
↓
Returns matching helpers/classes/routes
↓
AI reads only selected files or line ranges
↓
AI reuses or extends existing code instead of duplicating
```

## Cross-Phase Commands

```text
npx speckit-memory audit
npx speckit-memory refresh
npx speckit-memory rebuild
npx speckit-memory doctor
```

Audit is read-only. Refresh is incremental. Rebuild is a full cache regeneration. Doctor validates environment and configuration.

## Configuration

Use `.spec-kit-memory/` for the SQLite cache and add it to `.gitignore`.

## Is this like Claude-Mem?

Yes, conceptually.

Claude-Mem-style flow:

```text
capture -> compress -> retrieve -> inject
```

Spec Kit Memory Hub optimizer flow:

```text
source artifacts -> local SQLite index -> retrieve relevant context -> generate memory-synthesis.md -> Spec Kit commands read compact context
```

Difference:

- designed for VS Code and Spec Kit
- markdown/spec/code remain source of truth
- SQLite is only a rebuildable cache
- durable memory capture remains manual and approved
- retrieval is project-aware, feature-aware, and spec-aware
