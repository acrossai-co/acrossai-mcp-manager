# Quick Start

## Prerequisites

- **uv** package manager (or pipx)
- **Claude Code** (or other AI coding agent)
- Python 3.7+

## Installation

### 1. Install Spec Kit CLI

```bash
# Using uv (recommended)
uv tool install specify-cli --from git+https://github.com/github/spec-kit.git@v0.8.7

# Or using pipx
pip install pipx
pipx install specify-cli --from git+https://github.com/github/spec-kit.git@v0.8.7
```

### 2. Initialize Spec Kit in Your Plugin

Navigate to your plugin root directory:

```bash
cd your-plugin-directory

# Initialize Spec Kit
specify init --here --integration claude-code
```

When prompted:
- **Which agent?** → Select `claude-code`
- **Continue with setup?** → Select `yes`

### 3. Verify Installation

```bash
# Check that .specify folder was created
ls -la .specify/

# You should see:
# .specify/
# ├── memory/
# │   └── constitution.md
# ├── scripts/
# ├── templates/
# ├── .specifyrc.json
# └── ...
```

### 4. Commit to Git

```bash
git add .specify/
git commit -m "chore: add spec kit v0.8.7 infrastructure"
git push
```

## Checklist: Getting Started

- [ ] Install Spec Kit CLI: `uv tool install specify-cli --from git+https://github.com/github/spec-kit.git@v0.8.7`
- [ ] Initialize in plugin: `specify init --here --integration claude-code`
- [ ] Create memory files: CONSTITUTION.md, DECISIONS.md, GOTCHAS.md
- [ ] Commit: `git add .specify/ && git commit -m "chore: add spec kit"`
- [ ] Read this guide once more
- [ ] Try first spec: `/speckit.specify` in Claude Code
- [ ] Ask Claude Code to implement: `/speckit.implement`
