---
name: maintain-verification-skill
description: 'Upkeep pass for a repo-local verification skill (`verify-<app>`) and its feature map: re-drives every feature file against the current app, detects drift (routes, commands, UI handles or levers that changed; features without a file; dead help-article links), fixes it, and ships at most one PR of proven corrections. Ends clean, changed or blocked. Invoke via /maintain-verification-skill, or from a scheduled local run. Use when the verify skill may be stale or the user asks to audit or refresh it.'
argument-hint: '[path to verify skill]'
disable-model-invocation: true
---

# Maintain a verification skill

A feature map rots as the app changes. This pass keeps a skill made by `/create-verification-skill` (or any repo-local verify skill with a feature map) true to the current app. The unit of rigor is the feature: every feature file gets a source read and a live drive, without proving every sentence.

Argument passed: `$ARGUMENTS`

The pass is built to run unattended. It never waits on a question. When it cannot decide safely, it ends `blocked` and says why.

## Outcomes

End with exactly one, and say which:

- **clean:** every feature was read from source and driven live, nothing to fix. No branch, no PR.
- **changed:** one PR of proven corrections to the verify skill.
- **blocked:** coverage could not finish, or a proven fix could not ship. Name the blocker and the coverage reached.

## Edit scope

Only the verify skill's own directory: its SKILL.md, `features/`, and helper scripts it owns. Never product code, including lever commands that live in the app. A lever that no longer works is a harness gap to report. A feature the app no longer performs as mapped is either map drift (fix the map) or a product regression (report it, never paper over it in the map).

## Pass

0. **Locate the target.** Use the argument, or find `verify-*` skills with a `features/` dir in the repo's skill home (read `AGENTS.md` / `CLAUDE.md` to find it). Several candidates and no argument: `blocked`, list them. None: `blocked`, point at `/create-verification-skill`.

1. **Start from a known base.** The working tree is clean and on the up-to-date default branch. Otherwise `blocked`: a pass on a feature branch would map unreleased behavior.

2. **Clean up, then doctor.** Run the repo's stale-run sweep first (the verify skill's Levers or `AGENTS.md` name it, e.g. `php artisan verify:cleanup --stale`), then the skill's doctor. A doctor failure caused by the environment (missing build, local services down, wrong keys) is `blocked`. One caused by the skill itself (a renamed command, a changed env key) is drift: fix it and retry once.

3. **Index hygiene.** Compare the feature README against the files in `features/`. Fix missing, extra, duplicate or dead entries. Check every help-article link resolves.

4. **Source read, one reader per feature.** Spawn one read-only subagent per feature file, in parallel where the agent tool supports it. Each reads the feature file and the current source, and returns: a one-paragraph summary of how the feature works now, its source entry points (routes, components, commands, jobs), drift with `file:line` citations (a renamed route, a changed button label, a moved menu, a new entry point, a lever flag that changed), and one live recipe. Readers never drive the app and never edit files.

5. **Coverage sweep.** Find user-facing features without a file: routes, navigation entries, commands, scheduled tasks, mail classes and help articles added since the map last changed (`git log` on `features/` gives the date). Also work down the README's `Unmapped` list. Call a feature missing only with a concrete source path.

6. **Live pass.** Required even when the source read found nothing. Follow the verify skill's own protocol: one scenario per run, evidence in its evidence dir under a `maintain` slug. Drive every feature file at least once, through each entry point it lists. Hold these for the whole pass:
    - Doctor again after any failed or surprising drive before driving anything else. Where doctor cannot see the problem (a wedged UI on a healthy server), reset to a fresh scenario.
    - Evidence captured so far survives every cleanup. Check it at its location.
    - Clean up what each failed attempt created before the next attempt.
    - A feature that cannot be reached is `blocked` for that feature only with the concrete prerequisite and the path attempted. If the map omits that prerequisite, that is drift.

7. **Triage.** For each finding:
    - **Map drift** (wrong path, label, command, precondition, help link): fix the feature file, then re-drive it.
    - **Missing feature:** write its file per the verify skill's feature contract, then drive it once.
    - **Harness gap** in a helper the skill owns: fix it, then re-drive every feature that uses it. A gap in an app-owned lever: report it.
    - **Product regression:** record it with evidence for the user or the repo's issue tracker. Keep it out of the PR.

8. **Final cleanup.** After the last drive, including re-drives, run the skill's cleanup and confirm the evidence dir survived.

9. **Ship or stop.** For `changed`, re-read every changed file, then open one PR with the fixes. Follow the repo's and the user's git rules for branches and commits. When they require confirmation that the invoking prompt did not give, leave the diff uncommitted and report `changed` with the files touched. For `clean` or `blocked`, no PR.

## Report

```
maintain-verification-skill: clean | changed | blocked
reason: <one line>
coverage: <n>/<m> features read, <n>/<m> driven, blocked features with prerequisite
drift fixed: <feature: what changed>
new feature files: <list>
harness gaps and product regressions: <list with evidence paths>
evidence: <evidence dir>
pr: <url, or uncommitted diff, or none>
```

Keep run notes in the evidence dir. Do not commit them.
