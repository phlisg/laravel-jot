## MANDATORY: Use td for Task Management

You must run td usage --new-session at conversation start (or after /clear) to see current work.
Use td usage -q for subsequent reads.

## Writing documentation

Load the `writing` skill before editing README.md, docs/ or CHANGELOG.md, also in subagents. Repo conventions (where facts live, synced copies, heading names that src/ points to) are in docs/development.md, "Documentation".

- Each section tells the developer what to do, answer first. A section that only explains how something works needs the action added.
- Settings, options and comparisons go in tables. A bullet list of `key: long explanation` is too dense.
- Check every claim about behaviour in src/ before writing it. Leave out numbers that were never measured.
- For a large doc change, use subagents to keep context small: fresh readers in parallel (junior installing Jot, lead evaluating it, on-call developer debugging an error, prose editor), then one editor agent applies the merged findings.
- After a subagent edits docs, check its claims against src/ and remove what it could not verify.
