# Project Memory Setup

Project memory stores team standards, decisions, and lessons learned. This helps:
- New developers understand your practices
- Claude Code suggest code aligned with your standards
- Team remember why decisions were made
- Prevent repeating mistakes

## Adding Memory Files

Ask Claude Code to create memory files:

**Prompt for Claude Code:**
```
Please create project memory files in .specify/memory/ with:
1. CONSTITUTION.md - Quick reference for team standards
2. DECISIONS.md - Record of architectural decisions
3. GOTCHAS.md - Lessons learned
4. README.md - Explanation of memory files

The content should reference AGENTS.md as source of truth.
Commit all files with message "chore: setup project memory infrastructure"
```

## Memory File Templates

### `.specify/memory/CONSTITUTION.md`

```markdown
# Constitution

All standards, rules, and requirements are defined in AGENTS.md (source of truth).

## Quick Reference (See AGENTS.md for details)

### Environment
- PHP: 7.4+
- WordPress: 6.9+
- Node: 18.0+
- npm: 9.0+
- Composer: 2.0+

### Standards
- Naming prefix: `acrossai_mcp_manager_`
- Coding: WPCS strict
- Analysis: PHPStan level 8
- Linting: ESLint

### Security (Non-negotiable)
- Nonces on all forms/AJAX
- Capability checks on admin actions
- Sanitize input at entry
- Escape output at display
- Use $wpdb->prepare() for SQL
- File upload validation required

### Code Quality
- PHPCS must pass
- PHPStan level 8 must pass
- ESLint must pass
- Unit tests required

See AGENTS.md for complete specification.
```

### `.specify/memory/DECISIONS.md`

```markdown
# Decisions Record

This document records all major decisions made during plugin development.

## Decision 001: PHP 7.4 Minimum

**Date**: 2026-05-10
**Status**: Locked
**Decision**: Use PHP 7.4 as minimum version

**Rationale**: Broader hosting compatibility, modern features available

**Impact**: Cannot use PHP 8.0+ syntax

---

[Add new decisions as you make them]
```

### `.specify/memory/GOTCHAS.md`

```markdown
# Gotchas & Lessons Learned

This document captures problems encountered so we can prevent them in the future.

## Template

### Gotcha: [Short Name]

**Discovered**: Date
**Severity**: Critical/High/Medium/Low
**Status**: Resolved/Monitoring

**The Problem**: What broke?

**The Solution**: How we fixed it?

**Prevention**: How to avoid next time?

---

[Add gotchas as you work]
```

### `.specify/memory/README.md`

```markdown
# Project Memory

This folder contains institutional knowledge for the plugin.

## Files

- **CONSTITUTION.md** - Quick reference for team standards
- **DECISIONS.md** - Record of architectural decisions
- **GOTCHAS.md** - Lessons learned from problems

## How to Use

### When Starting Work
1. Read CONSTITUTION.md (2 minutes)
2. Read DECISIONS.md (5 minutes)
3. Skim GOTCHAS.md (3 minutes)

### When You Discover Something
1. Found a problem? → Add to GOTCHAS.md
2. Made a decision? → Add to DECISIONS.md
3. Changed standards? → Update AGENTS.md + CONSTITUTION.md

### When Onboarding New Developer
1. Have them read CONSTITUTION.md
2. Have them read DECISIONS.md
3. Reference GOTCHAS.md while coding

## Single Source of Truth

**AGENTS.md is the source of truth for all standards.**

Update standards only in AGENTS.md, then sync CONSTITUTION.md.
```
