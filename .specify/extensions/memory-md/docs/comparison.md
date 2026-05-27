# Comparison

## Spec Kit core
Provides structured feature workflow and extensibility.
Does not provide a full default durable memory bank for decisions, bug patterns, and worklog.

## Personal agent memory tools like `claude-mem`
Useful for:
- personal continuity across sessions
- agent-local preferences and working style
- temporary working context for a single developer or agent

Limits for team workflows:
- important knowledge may stay hidden from the rest of the team
- memory quality and visibility depend on one user or one tool instance
- it is harder to review, version, and correct shared project knowledge

## Community DB-style memory
Can offer more automatic capture and search.
Usually adds more complexity and less Git visibility.

## This hub
Uses Markdown memory in the repo.
Optimized for:
- VS Code
- Copilot
- Git review
- explicit, selective memory capture

It is intended to complement personal agent memory, not replace it.

Recommended split:
- use `claude-mem` or similar tools for personal and agent-local recall
- use this extension for durable project memory that the whole team should be able to inspect, review, and reuse

Simple rule:
- if only one person or agent needs it temporarily, personal memory is fine
- if the team may need it later, it belongs in the repository
