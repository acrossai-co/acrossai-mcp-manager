# Log Finding

Turn a high-signal audit finding into a tracker-ready follow-up.

Use this when:

- an audit finding should be tracked outside memory
- the team wants a bug, cleanup task, or follow-up item in GitHub Issues, GitLab Issues, Jira, or another tracker
- the finding is worth action but not worth storing as durable memory on its own

Before creating anything, identify:

- target platform
- target project, repository, or board
- issue type or category
- priority or severity
- whether the item is a bug, cleanup task, or investigation

For each logged finding:

- preserve the evidence
- keep the title short and actionable
- include a concise summary of the problem
- include the memory or diff evidence that supports it
- include the recommended next action
- include labels or fields that match the destination platform

If a direct write integration exists in the current environment, create the issue there.
If no write integration exists, produce a tracker-ready draft that can be pasted into the chosen platform.

Do not rewrite memory here.
Do not create noisy or speculative follow-up items.
