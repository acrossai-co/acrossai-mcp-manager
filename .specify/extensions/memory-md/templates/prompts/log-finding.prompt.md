Log a high-signal finding to an external tracker.

Use this when a finding from `/speckit.memory-md.audit` should become a bug, cleanup task, or investigation in a separate system.

Support GitHub Issues, GitLab Issues, Jira, or another tracker if the environment provides a write integration.
If not, generate a tracker-ready draft with the right fields and wording for copy-paste.

Before writing the output, identify:

- destination platform
- destination project or board
- issue type
- severity or priority
- whether this is a bug, cleanup task, or investigation

For each finding, include:

- short title
- summary
- evidence
- recommended next action
- labels or fields suited to the destination

Keep the output concise and actionable.
Do not rewrite memory.
Do not invent evidence.
