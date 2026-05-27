# Maintenance

## Updating Spec Kit

```bash
# Check for updates
uv tool upgrade specify-cli

# Or reinstall latest
uv tool install --force specify-cli --from git+https://github.com/github/spec-kit.git@v0.8.7
```

## Updating AGENTS.md and Memory

When you change standards in AGENTS.md:

```bash
# 1. Edit AGENTS.md
nano AGENTS.md

# 2. Update CONSTITUTION.md to match
nano .specify/memory/CONSTITUTION.md

# 3. Commit together
git add AGENTS.md .specify/memory/CONSTITUTION.md
git commit -m "chore: update standards (PHP 8.0)"
git push
```

## Reviewing Memory Files

Monthly, review memory files:

```bash
# Check if DECISIONS need updates
cat .specify/memory/DECISIONS.md

# Review common GOTCHAS
cat .specify/memory/GOTCHAS.md

# See if patterns need escalation to CONSTITUTION.md
```

## Learn More

- **Spec Kit Docs**: https://github.com/github/spec-kit
- **Specification-Driven Development**: https://github.com/github/spec-kit/blob/main/spec-driven.md
- **WPBoilerplate Docs**: See AGENTS.md in this repository
