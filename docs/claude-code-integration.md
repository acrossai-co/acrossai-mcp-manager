# Claude Code Integration

## Slash Commands Available

Once Spec Kit is set up, Claude Code has these commands:

```
/speckit.specify          Create feature specification
/speckit.clarify          Clarify and refine specs
/speckit.analyze          Analyze specs for gaps
/speckit.plan             Create implementation plan
/speckit.tasks            Generate task breakdown
/speckit.implement        Execute tasks
/speckit.review           Review implementation
/speckit.archive.run      Archive merged features
```

## How Claude Code Uses Memory

Claude Code automatically:
1. Reads `.specify/memory/CONSTITUTION.md` before working
2. Knows your standards without you explaining them
3. Suggests code aligned with your DECISIONS.md
4. Avoids issues documented in GOTCHAS.md
5. References AGENTS.md for complete specifications

**Result:** Claude Code suggestions match your exact project standards.
