---
name: implement-spec
description: "Implement the result of /to-spec and /to-tickets in code."
disable-model-invocation: true
---

You have been provided a spec. This spec should have tickets associated with it, describing how to implement the spec.

The issue tracker should have been provided to you. If not, tell the user to run `/setup-matt-pocock-skills`.

The goal is the entire spec implemented on a single **integration branch** only when the parent spec is an explicitly approved single change. Otherwise, each issue gets its own branch from `main`. In either case, `main` remains the latest approved version and is the only branch promoted for release.

The tickets are not a list of steps. They are a **task graph** with blocking relationships between them. This means there is always a **frontier** of tickets which are ready to be grabbed.

Communication to and from subagents should be sparse. Communicate primarily through **context pointers**: to the spec, tickets, research notes, and previous commits. Don't duplicate information already available via pointers.

**Implementer subagents** should be run in the background where possible for maximum concurrency.

## Steps

1. Read the spec and tickets to understand the task graph.

2. (optional) Use an **exploration subagent** to conduct any exploration required by the tickets - relevant codebase files or external documentation. Ensure the exploration subagent can save files - it should save its markdown notes in a directory outside the repo, accessible by all future subagents. This lets **implementer subagents** focus on implementation rather than exploration.

3. Inspect `git status --short`, `git branch --show-current`, and `git rev-parse main` before creating work. Treat existing working-tree changes as user-owned: do not include them in implementation commits. If switching branches requires it, preserve them in a clearly named stash and restore them on the original branch before reporting completion.

   Create the implementation branch from the current `main`. Use `codex/issue-<number>-<short-description>` for a single issue. Use `codex/issue-<parent>-<short-description>` for an integration branch only when the user explicitly approves the parent spec as one change; the child tickets still work in their own branches/worktrees.

   If the issue tracker closes work through PRs, or the user asks for one, open a draft PR after the first merge in step 5 (a branch with no commits ahead of main can't open one), marked as closing the spec and tickets.

4. Use **implementer subagents** to implement each ticket, each in its own worktree on its own branch. Each implementer subagent:
   - confirms its worktree is based on the integration branch before starting, and resets onto it if not;
   - calls the Skill tool with `tdd` to build the ticket;
   - merges the integration branch tip into its own branch before reporting done

5. Once an **implementer subagent** completes, merge its work to the integration branch with a **merger subagent**.

6. If this changes the **frontier** of available tickets, kick off more **implementer subagents** to work on the new tickets. This allows for maximum concurrency.

7. Once all tickets are complete, call the Skill tool with `code-review` while the candidate branch is still identifiable. Pass the fixed point `main` and the explicit candidate ref, and review `git diff main...<candidate>`; never rely on an ambient `HEAD` after switching to `main`. Fix all issues raised by the code review in a single **implementer subagent**, then rerun the relevant tests.

8. After review approval, stop treating issue closure as proof of promotion. Prepare the release audit first:
   - confirm the candidate ref, review result, and relevant tests are recorded;
   - keep issues open when any acceptance criterion is manual, environment-dependent, or still incomplete;
   - if a draft PR exists, mark it ready for review only after the candidate is approved.

9. Promote only from a clean, approved candidate:
   - refresh local `main` from its remote with a fast-forward-only update, preserving unrelated user work;
   - create an immutable annotated tag on the exact pre-change `main` commit named `backup/main-before-<issue-or-change>-<YYYY-MM-DD>`;
   - include every issue number and the functionality covered by that backup in the tag annotation;
   - merge the approved candidate into `main` with an explicit merge commit, never by rewriting shared history;
   - rerun the relevant tests on `main` and verify the merge commit contains the reviewed candidate.

10. Push the approved `main` merge and its backup tag. Only after both pushes succeed, audit that every issue being closed is represented by an ancestor commit on `main` and that the backup tag points to the pre-merge commit.

11. Close only the issues that the audit proves complete. Leave blocked or partially verified issues open and add a concise issue comment explaining the remaining evidence or environment requirement.

12. Clean up all **implementer subagent** worktrees and report the candidate commit, merge commit, backup tag annotation, pushed refs, test evidence, and issue state.
