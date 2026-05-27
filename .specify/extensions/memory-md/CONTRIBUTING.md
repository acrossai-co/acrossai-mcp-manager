# Contributing to Spec Kit Memory Hub

Thank you for your interest in contributing!

## Getting Started

1. Fork and clone this repository.
2. Read the [README.md](README.md) for project context.
3. Read `docs/governed-memory-workflow.md` for the full workflow design.

## Repository Structure

```
extension.yml          ← extension manifest (canonical)
config-template.yml    ← configuration reference
commands/              ← Spec Kit command definitions
templates/             ← starter files installed into target projects
  docs/memory/         ← durable memory templates
  specs/               ← feature folder templates
  prompts/             ← AI prompt templates
  .github/             ← copilot instructions template
scripts/               ← install, sync, check, and test scripts
docs/                  ← project documentation
```

## Development Workflow

### Running tests

```bash
./scripts/test-install.sh
```

This runs smoke tests that verify:

- Hub structure is valid
- Install script creates expected files
- Re-install preserves existing files
- Sync does not overwrite project memory
- Memory check passes on installed projects

### Testing changes locally

```bash
# Install into a test project
./scripts/install-into-project.sh . /tmp/test-project

# Verify the test project
./scripts/check-memory.sh /tmp/test-project

# Clean up
rm -rf /tmp/test-project
```

### Using with Spec Kit CLI

```bash
specify extension add --dev /path/to/your/fork
```

## What to Contribute

### High-value contributions

- Bug fixes in install or sync scripts
- Improvements to memory templates (better examples, clearer boundaries)
- New prompt templates that improve AI behavior
- CI integration examples
- Documentation improvements

### Before you start

- Check existing issues and pull requests.
- For significant changes, open an issue first to discuss the approach.
- Keep memory templates concise — the value of this project is in signal quality, not volume.

## Guidelines

### File edits

- **Commands**: Instructions for AI agents. Keep them clear, specific, and actionable.
- **Templates**: Starter files for target projects. Include "Keep Here" and "Never Store Here" boundaries.
- **Prompts**: AI prompt fragments. Keep them compact and directly usable.
- **Scripts**: Bash scripts. Use `set -euo pipefail`, check arguments, and print clear output.

### Code style

- Shell scripts: follow existing patterns (`set -euo pipefail`, argument checks, colored output).
- Markdown: use ATX-style headers (`#`), keep lines readable, prefer lists over paragraphs.
- YAML: use single quotes for strings, keep keys lowercase with underscores.

### Commit messages

Use clear, descriptive commit messages:

```
fix: correct audit command missing cleanup rules
feat: add check-memory.sh for CI integration
docs: add lifecycle documentation to DECISIONS template
```

### Pull requests

- One logical change per PR.
- Run `./scripts/test-install.sh` before submitting.
- Describe what changed and why.

## License

By contributing, you agree that your contributions will be licensed under the [MIT License](LICENSE).
