# Spec Kit Workflow Documentation
## AcrossAI Abilities Manager WordPress Plugin

---

## Overview

This document explains the complete Spec Kit workflow for building the AcrossAI Abilities Manager plugin. The workflow has **10 main steps** that take you from project principles to a fully implemented, tested, and documented plugin.

---

## Step-by-Step Workflow

### 1. `/speckit.constitution`

**What it does:** Creates your project's foundational principles and governance guidelines.

**Purpose:** Establishes the "constitution" - core values, coding standards, security requirements, and quality gates that will guide all development decisions. This becomes the reference for AI agents throughout the project.

**Creates:** `.specify/memory/constitution.md` - Your project's rulebook that agents follow during /specify, /plan, and /implement.

**Example output:** Principles like "All PHP code must be WordPress-strict PHPCS compliant", "All REST endpoints require manage_options capability", "BerlinDB Query class mandatory for all DB operations"

---

### 2. `/speckit.memory-md.create-context-memo`

**What it does:** Documents your initial project context and team knowledge.

**Purpose:** Captures why this project exists, who's involved, what problems it solves, and key decisions. This creates a searchable record that helps onboard new team members and maintains context over time.

**Creates:** `.specify/memory/project-context.md` - A narrative document explaining the "why" behind the plugin and important team knowledge.

**Example content:** "We're building this because WordPress admins need centralized control over plugin abilities. Key constraint: must work with WordPress 6.9+. Team: 1 lead dev, 2 junior devs."

---

### 3. `/speckit.specify`

**What it does:** Writes the functional specification - what the plugin should do from a user's perspective.

**Purpose:** Translates your vision into concrete requirements. Describes features, user stories, acceptance criteria, and constraints WITHOUT specifying tech choices. Focus on the "what" and "why", not the "how".

**Creates:** `specs/sitewide-ability-management/spec.md` - Your functional requirements document that Architecture Guard and Security Review will validate.

**Example content:** "Admins can view all abilities, edit settings (allowed/readonly/destructive flags), override database defaults, reset to original values, control REST API visibility, and manage MCP server access per ability."

---

### 4. `/speckit.clarify` (If Needed - Optional)

**What it does:** Clarifies ambiguous requirements or asks follow-up questions about the specification.

**Purpose:** Surfaces gaps, contradictions, or unclear parts of your spec. Useful when your initial `/specify` is vague or when stakeholders disagree on requirements. Helps refine the spec before moving to planning.

**Usage:** Run this ONLY if your spec needs clarification. You'll be prompted with questions to answer, which improve the spec.

**Example questions:** "Should admins be able to override abilities from core WordPress? Should there be an audit log of changes? Can deleted overrides be recovered?"

---

### 5. `/speckit.plan`

**What it does:** Creates the technical implementation plan based on your architecture and tech stack decisions.

**Purpose:** Translates the functional spec into a technical roadmap. Specifies technology choices (React, BerlinDB, REST API), data models, API contracts, module structure, and implementation phases. Defines the "how".

**Creates:** `specs/sitewide-ability-management/plan.md` - Your technical blueprint with architecture decisions, database schema, API endpoints, component structure.

**Example content:** "Use BerlinDB Query class for all database operations. React with @wordpress/dataviews for UI. REST API endpoints require manage_options. All override data stored in wp_acrossai_abilities_overwrite table."

---

### 6. Extension: `Memory MD` - Index Project

**What it does:** Auto-generates a map of your project structure, modules, and dependencies.

**Purpose:** Creates `.specify/memory/module-map.md` showing which components depend on which others, what each module does, and how they interconnect. Helps visualize architecture at a glance.

**Output:** Visual module hierarchy, dependency graph, integration points between React UI / REST API / BerlinDB layers.

**Use after:** `/speckit.plan` (when you have a complete technical plan)

---

### 7. Extension: `Architecture Guard` - Validate Plan

**What it does:** Reviews your technical plan for architectural consistency and pattern adherence.

**Purpose:** Checks that your plan follows best practices: BerlinDB usage patterns, module isolation, REST security, React component hierarchy. Catches architectural issues BEFORE implementation.

**Output:** Report with findings like "✅ BerlinDB integration correct", "⚠️ REST endpoint missing nonce verification", "❌ UI component coupling too tight"

**Use after:** `/speckit.plan` (validate your architecture before tasks)

---

### 8. Extension: `Security Review` - Full Audit

**What it does:** Performs a comprehensive security analysis of your specification and plan.

**Purpose:** Identifies potential vulnerabilities: missing input validation, output escaping gaps, capability checks, authentication issues, data exposure risks. Ensures secure-by-design before implementation starts.

**Output:** Security findings organized by severity (Critical/High/Medium/Low) with remediation steps. Example: "Missing sanitization on admin input fields", "REST endpoint missing nonce check"

**Use after:** `/speckit.plan` (identify security issues early when fixes are cheaper)

---

### 9. `/speckit.tasks`

**What it does:** Breaks down your plan into an ordered list of implementation tasks.

**Purpose:** Creates a task list that developers can execute sequentially. Each task is actionable, has dependencies defined, and can be assigned to a developer. Tasks are ordered so dependencies complete first.

**Creates:** `specs/sitewide-ability-management/tasks.md` - Numbered list of implementation tasks with descriptions, dependencies, estimated effort.

**Example tasks:** 
- Task 1: Create BerlinDB Query class for abilities_overwrite table
- Task 2: Build REST API endpoints for GET /abilities
- Task 3: Create React AbilityTable component using DataViews
- Task 4: Implement edit modal with nonce validation

---

### 10. Extension: `Architecture Guard` - Validate Tasks

**What it does:** Reviews your task list to ensure it respects architectural patterns and dependencies.

**Purpose:** Catches issues like "Task 5 depends on Task 8, but Task 8 is listed later" or "This task violates module isolation rules". Ensures task order is logical before implementation begins.

**Output:** Task validation report with sequencing issues or architectural concerns.

**Use after:** `/speckit.tasks` (validate task sequence before developers start)

---

### 11. `/speckit.implement`

**What it does:** Generates the actual code from your tasks and plan.

**Purpose:** AI creates working PHP/JavaScript/CSS code based on your specifications. Output is code files ready to run (though it may need human review/refinement).

**Creates:** Actual WordPress plugin files:
- PHP classes (Query, REST controller, Feature class)
- React components (AbilityTable, EditModal, etc.)
- Database migrations
- CSS files
- Tests (if configured)

**Output:** A functioning WordPress plugin that matches your specification and plan.

---

### 12. `/speckit.analyze`

**What it does:** Reviews your implementation against the original specification and plan to check for consistency and completeness.

**Purpose:** Detects if code matches spec ("Did we implement everything that was spec'd?"), if plan was followed ("Did we use the architecture we planned?"), and identifies drift ("Did scope creep happen?").

**Output:** Consistency report with findings like "✅ All abilities endpoint implemented", "⚠️ Edit validation logic missing nonce check", "❌ MCP integration only partially complete"

**Use after:** `/speckit.implement` (before going to production)

---

### 13. Post-Implementation: `Architecture Guard` - Drift Analysis

**What it does:** Detects if the implemented code has drifted from the planned architecture.

**Purpose:** Identifies tangled modules, violated patterns, code that doesn't follow decisions made in the plan. Proposes refactoring to restore architectural integrity.

**Output:** Drift report with issues and refactoring suggestions. Example: "UI components importing directly from database layer instead of through REST API"

**Use after:** `/speckit.analyze` (ensure code quality before final review)

---

### 14. Post-Implementation: `Security Review` - Final Audit

**What it does:** Comprehensive security audit of the entire implemented codebase.

**Purpose:** Final check for security vulnerabilities: input validation, output escaping, nonce verification, capability checks, SQL injection risks, XSS risks, authentication/authorization issues.

**Output:** Security findings report with severity levels and remediation steps. Must fix Critical/High before release.

**Use after:** `/speckit.implement` (catch security issues before deployment)

---

### 15. Post-Implementation: `Memory MD` - Merge Features

**What it does:** Archives implementation decisions, learnings, and known issues into project memory.

**Purpose:** Captures what was learned during implementation, decisions made to overcome obstacles, known limitations, and technical debt for future reference.

**Creates:** Updates to `.specify/memory/` files:
- Adds actual implementation decisions to architecture-decisions.md
- Records known issues/bugs in known-issues.md
- Updates project-context.md with new learnings

**Use after:** `/speckit.implement` (document what was learned before moving on)

---

### 16. Git Auto-Commit (Hook)

**What it does:** Automatically commits all changes to git after `/analyze` completes.

**Purpose:** Creates a permanent record of the completed feature in version control. Triggered automatically by the git extension hook.

**Output:** New git commit with message like "feat: sitewide ability management complete" + all changes staged and committed.

**Automatic:** Runs automatically after `/speckit.analyze` (no manual action needed)

---

## Complete Workflow Sequence

```
Step 1:  /constitution
         ↓
Step 2:  /memory-md.create-context-memo (Extension)
         ↓
Step 3:  /specify
         ↓
Step 4:  /clarify (OPTIONAL - if spec needs clarification)
         ↓
Step 5:  /plan
         ↓
Step 6:  /memory-md.index-project (Extension)
         ↓
Step 7:  /architecture-guard.governed-plan (Extension)
         ↓
Step 8:  /security-review.full (Extension)
         ↓
Step 9:  /tasks
         ↓
Step 10: /architecture-guard.governed-tasks (Extension)
         ↓
Step 11: /implement
         ↓
Step 12: /analyze
         ↓
Step 13: /architecture-guard.drift-analysis (Extension)
         ↓
Step 14: /security-review.full (Extension)
         ↓
Step 15: /memory-md.merge-features (Extension)
         ↓
Step 16: Git auto-commit (Automatic hook)
         ✅ Done!
```

---

## When to Skip Steps

### `/clarify` - SKIP if:
- Your specification is already clear and complete
- All stakeholders agree on requirements
- There are no ambiguous requirements

### `/clarify` - RUN if:
- Your `/specify` output feels vague or incomplete
- Stakeholders are disagreeing on requirements
- You have unanswered questions about scope

---

## Time Estimates

- `/constitution` - 5-10 minutes
- `/specify` - 10-15 minutes
- `/clarify` - 5-10 minutes (if needed)
- `/plan` - 10-15 minutes
- Extensions (Memory, Architecture, Security) - Automatic (1-2 min each)
- `/tasks` - 5 minutes (auto-generated)
- `/implement` - 10-30 minutes (depends on complexity)
- `/analyze` - 2-5 minutes
- Post-impl extensions - 5-10 minutes each
- Git commit - Automatic

**Total time: 60-120 minutes for a feature like "Sitewide Ability Management"**

---

## Key Principles

1. **Constitution First** - Principles guide all decisions
2. **Spec Before Plan** - Define "what" before "how"
3. **Validate Early** - Architecture & Security checks BEFORE implementation
4. **Document Always** - Memory files keep knowledge for the team
5. **Analyze Last** - Final consistency check before release

---

## Files This Workflow Creates

```
.specify/memory/
├── constitution.md
├── project-context.md
├── architecture-decisions.md
├── known-issues.md
├── module-map.md
└── governance/
    ├── architecture-findings.md
    └── security-findings.md

specs/sitewide-ability-management/
├── spec.md
├── plan.md
└── tasks.md

Plugin files (generated):
├── includes/features/sitewide/
│   ├── class-feature.php
│   ├── class-query.php
│   ├── class-rest-controller.php
│   ├── src/
│   │   ├── components/
│   │   ├── store/
│   │   └── api/
│   └── templates/
```

---

## Your Next Action

Ready to start? Run in your Claude chat:

```
/speckit.constitution Create principles focused on code quality, testing standards, WordPress security, REST API design, and BerlinDB patterns
```

Then follow the workflow sequence above. Each step builds on the previous one. 🚀
