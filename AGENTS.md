---
name: "AcrossAI MCP Manager"
description: "Agency standards for professional AcrossAI MCP Manager"
version: "1.0.0"
---

# Agency Standards

## Environment

```yaml
php_min_version: "7.4"
wordpress_min_version: "6.9"
node_version: "18.0"
npm_version: "9.0"
composer_version: "2.0"
```

## Plugin Configuration

```yaml
naming_prefix: "acrossai_mcp_manager_"
coding_standard: "wpcs-strict"
multisite_support: false
```

## Security Requirements

```yaml
enforce_nonces: true
enforce_capabilities: true
sanitize_input: true
escape_output: true
sql_prepared_statements: true
file_upload_validation: true
```

## Code Quality

```yaml
phpcs_enabled: true
phpstan_level: 8
eslint_enabled: true
```

## Plugin Boilerplate Reference

All plugin development MUST follow the `wp-plugin-development` skill: `.agents/skills/wp-plugin-development/SKILL.md`

## Package Strategy

```yaml
prefer_wordpress_packages: true
validation_script: "npm run validate-packages"
package_hierarchy:
  tier_1: "@wordpress/* packages (official, always first)"
  tier_2: "npm packages (lodash, date-fns, etc.)"
  tier_3: "external frameworks (avoid duplicating React, Vue, etc.)"
```

## Before Commit Checklist

- [ ] PHPCS pass
- [ ] PHPStan pass
- [ ] All functions prefixed with "acrossai_mcp_manager_"
- [ ] Nonces on all forms/AJAX
- [ ] Capabilities checked
- [ ] Input sanitized, output escaped
- [ ] No deprecated functions
- [ ] Package validation pass (npm run validate-packages)

---

# AI Engineering Rules

## Core Rules

- Follow WordPress Coding Standards
- Never perform broad refactors
- Never modify unrelated systems
- Execute one task at a time
- Read specs before implementation
- Update reports after implementation
- Update memory when new learnings are discovered
- Always run validation before completion

---

# Workflow

1. Read `.specify/memory/CONSTITUTION.md`
2. Read current feature spec
3. Read related memory
4. Read current task
5. Plan implementation
6. Execute scoped changes only
7. Run PHPCS
8. Run security validation
9. Run unit tests
10. Generate feature report
11. Update memory
12. Mark task complete

---

# WordPress Rules

- Escape output
- Sanitize input
- Use nonce validation
- Use capability checks
- Use wp_remote_get()
- Use wp_remote_post()
- Prefer Action Scheduler
- Avoid direct SQL unless required

---

# Testing Rules

Feature is NOT complete without:
- PHPCS validation
- Security review
- Unit tests

---

# Submodule Rules

Never modify files inside:

`.agents/tools/`

unless explicitly requested.

These repositories are external dependencies and must remain isolated from plugin implementation.

---

# Code Organization & Module Structure

Architecture and module structure are governed by the Constitution.
Read `.specify/memory/CONSTITUTION.md` for the canonical rules. Key rules summarised here:

**Directory layout**: `admin/Partials/` (admin classes), `includes/Utilities/` (shared logic), `includes/Modules/` (feature modules). There is NO `includes/Base/` directory and NO abstract module base class.

**Singleton pattern (plugin-wide convention)**: Every feature class MUST implement:
```php
protected static $_instance = null;
public static function instance(): self {
    if ( null === self::$_instance ) { self::$_instance = new self(); }
    return self::$_instance;
}
private function __construct() { /* dependencies via OtherClass::instance() only */ }
```

**Hook registration**: `includes/Main.php` is the ONLY file that calls `$this->loader->add_action()` / `$this->loader->add_filter()`. All hooks wire directly in `define_admin_hooks()` or `define_public_hooks()` using singleton instances — e.g. `$this->loader->add_action( 'rest_api_init', MyClass::instance(), 'register_routes' )`. There is NO `register_hooks( Loader $loader )` delegation pattern and NO module orchestrator class.

**Admin Partials Rule**: Classes that call `add_menu_page()`, enqueue assets, or render HTML live in `admin/Partials/`. Asset enqueue (`wp_enqueue_script/style`) MUST be in `Admin\Main::enqueue_scripts()/enqueue_styles()` only — never in Partials page classes or module classes.

**UI Contract**: `@wordpress/dataforms` for all admin forms; `@wordpress/dataviews` for all admin tables/lists.

See `.agents/skills/wp-plugin-development/SKILL.md` and `.agents/skills/wp-plugin-development/references/boot-flow.md` for full examples and anti-patterns.
