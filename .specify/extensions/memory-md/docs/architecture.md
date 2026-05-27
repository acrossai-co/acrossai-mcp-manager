# Architecture

## Layers

### 1. Spec Kit execution layer

- constitution
- feature specs
- plans
- tasks
- implementation work
- **governed orchestration** (via Architecture Guard)

### 2. Durable project memory layer

- compact index
- project context
- architecture notes
- decisions
- recurring bugs
- worklog

### 3. Active feature memory layer

- feature-local memory
- memory synthesis
- open questions and watchpoints for the active feature

### 4. Editor/runtime behavior layer

- Copilot instructions
- prompt files
- local repo conventions

These files make the repository usable by VS Code Copilot agents memory without requiring hidden state.

## Initial Release Boundary

This project is a repository-first memory workflow, not a dynamic memory runtime.

It supports the repository-side conventions used by Copilot agents memory, but it does not provision the GitHub or VS Code feature flags for you.

The initial release intentionally relies on:

- explicit Markdown files
- index-first retrieval
- shared folder conventions
- command-driven usage
- governed orchestration

The initial release intentionally does not include:

- automatic hierarchical memory loading
- database-backed retrieval
- hidden memory state outside the repository

Copilot Memory remains an external, opt-in feature in GitHub and VS Code settings.

## Principle

Specifications are for active delivery.
Memory is for durable learning.

Do not overload feature specs with cross-feature memory.
Do not overload durable memory with routine implementation detail or feature-local notes.
Use feature memory plus synthesis to keep active context close to execution without turning the repo into a knowledge base.

## Optional Local Optimizer

An optional Node.js + SQLite optimizer can sit alongside the markdown workflow for projects that want faster retrieval at larger scale.

The optimizer does not replace source files. Markdown/spec/code remain authoritative, and SQLite stays a rebuildable cache that helps discovery.

The intended flow is:

1. Search local cache.
2. Read selected snippets or synthesized context.
3. Open authoritative source files only when needed.
4. Keep `memory-synthesis.md` as the small AI-readable package used in normal planning.

The optimizer can be introduced in phases:

1. Cache durable memory only.
2. Cache development docs and Spec Kit artifacts.
3. Cache code symbols to reduce duplication.

This keeps the core workflow lightweight while leaving room for projects that outgrow pure markdown retrieval.
