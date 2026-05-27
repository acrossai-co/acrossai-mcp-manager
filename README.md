# AcrossAI MCP Manager

A specification-driven WordPress plugin boilerplate that integrates **Spec Kit** and **Claude Code** to help you build plugins using clear specs before writing a single line of code.

Write specifications. Let AI implement. Ship with confidence.

---

## Documentation

| Section | Description |
|---|---|
| [What is Spec Kit?](docs/what-is-spec-kit.md) | Overview of Spec Kit and spec-driven development |
| [Quick Start](docs/quick-start.md) | Installation, initialization, and getting-started checklist |
| [Project Memory](docs/project-memory.md) | Setting up CONSTITUTION.md, DECISIONS.md, and GOTCHAS.md |
| [Spec Kit Workflow](docs/spec-kit-workflow.md) | Step-by-step workflow with a full payment feature example |
| [Project Structure](docs/project-structure.md) | Full directory layout after Spec Kit setup |
| [Claude Code Integration](docs/claude-code-integration.md) | Available slash commands and how Claude Code uses memory |
| [Best Practices](docs/best-practices.md) | Tips for specs, memory files, and team collaboration |
| [Maintenance](docs/maintenance.md) | Updating Spec Kit, syncing AGENTS.md, monthly review guide |

---

## Quick Install

```bash
uv tool install specify-cli --from git+https://github.com/github/spec-kit.git@v0.8.7
specify init --here --integration claude-code
```

See [Quick Start](docs/quick-start.md) for the full setup guide.
