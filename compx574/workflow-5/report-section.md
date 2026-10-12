# Workflow 5: parallel multi-agent rewrite with a shared skeleton

Evidence links point to the group branch. `[log]` is [`compx574/workflow-5/log.md`](https://github.com/xiaofang2016/mealie/blob/mealie-next-backend-rewrite/compx574/workflow-5/log.md); commit hashes link to the commit.

## Approach

**Tool.** Claude Code (CLI) with Claude Opus 5.5. One orchestrating session started the sub-agents with Claude Code's `Agent` tool. All agents used the same model.

**Decomposition.** The backend was split by Python router package into four areas, and each area had its own sub-agent:

1. auth, users, app, validators: 32 routes
2. recipe, organizers, unit_and_foods, comments, parser: 86 routes
3. households, media, shared: 74 routes
4. groups, admin, explore: 80 routes

The work ran in four phases:

- **Phase 0:** the orchestrator built a shared Laravel 13 skeleton. It contained the database connection, JWT handling, the auth middleware, and helpers for ids, dates, errors and pagination ([`a8642d6ef`](https://github.com/xiaofang2016/mealie/commit/a8642d6ef)).
- **Phase A:** the area split document, which lists for each area the Python files it covers, the PHP files it may create, and the files no area may touch ([`workflow-5-split.md`](https://github.com/xiaofang2016/mealie/blob/mealie-next-backend-rewrite/compx574/workflow-5-split.md), [`ef25c3f2a`](https://github.com/xiaofang2016/mealie/commit/ef25c3f2a)).
- **Phase B:** four sub-agents ran in parallel. Each first wrote a contract for its area, covering for every endpoint the Python source, the schema file, the id format, the permission check and anything not implemented. It then wrote the code ([contracts](https://github.com/xiaofang2016/mealie/tree/mealie-next-backend-rewrite/compx574/workflow-5-contracts), [`72720e4f8`](https://github.com/xiaofang2016/mealie/commit/72720e4f8)).
- **Phase C:** after a merge-conflict review (Gate B), a single fresh agent removed the duplicated helpers that the review had marked ([`workflow-5-merge.md`](https://github.com/xiaofang2016/mealie/blob/mealie-next-backend-rewrite/compx574/workflow-5-merge.md), [`58427d58e`](https://github.com/xiaofang2016/mealie/commit/58427d58e)).

**Context given to agents.** All four sub-agents got the same prompt template, with only the area number, port and paths changed ([`prompts/phase-b-subagent.md`](https://github.com/xiaofang2016/mealie/blob/mealie-next-backend-rewrite/compx574/workflow-5/prompts/phase-b-subagent.md)). Each one was told to read only the shared sections of the split and its own area's section. It was told to copy every method and path from its Python routers, and never to register a route that only returns a fixed error. It could not read W1's code or the shared Hurl file.

The agents could not talk to each other. The shared skeleton was read-only for them, so a missing helper had to be written inside the area's own namespace and listed in its contract.

**Verification.** Sub-agents checked their own work by sending the same request to the Python backend (:9000) and to their own PHP server, and comparing status and body. The shared acceptance suite, `parity.hurl`, was deliberately **held out**: agents were told not to read it or run it. The orchestrator ran it only at phase boundaries, through [`measure.sh`](https://github.com/xiaofang2016/mealie/blob/mealie-next-backend-rewrite/compx574/workflow-5/measure.sh), which scores each endpoint and each area. Alongside it the orchestrator ran three more checks:

- the group's response-body comparison (`accuracy_compare`);
- [`perm_compare.py`](https://github.com/xiaofang2016/mealie/blob/mealie-next-backend-rewrite/compx574/workflow-5/perm_compare.py): status codes as an admin, as a non-admin and with no token;
- [`shape_compare.py`](https://github.com/xiaofang2016/mealie/blob/mealie-next-backend-rewrite/compx574/workflow-5/shape_compare.py): JSON key sets and id format.

The work ran inside a sandbox container with no credentials ([`sandbox/Dockerfile`](https://github.com/xiaofang2016/mealie/blob/mealie-next-backend-rewrite/compx574/workflow-5/sandbox/Dockerfile)). The database was restored from a snapshot before every scoring run.

**Changes from the group's planned W5.** The group's prompt text and the seven changes made to it are in [log] under "Deviations" (D1–D7). The two that matter most are:

- **Phase 0 was added**, because `run.sh` needs a particular JWT class and nobody owned the shared files.
- **The Hurl file was held out from the agents**, because it only asserts status codes and so can be passed with stubs.

Both review gates were run by the orchestrator, not a person, because the operator delegated them. W5 therefore measures *multi-agent with agent-run gates*.

## Results

Measured on the shared database (`compx574/mealie.db`) after Phase C ([`results/p3-*`](https://github.com/xiaofang2016/mealie/tree/mealie-next-backend-rewrite/compx574/workflow-5/results)):

| Measure | Result |
|---|---|
| Python routes re-implemented | 188 of 272 (69%): 26/32, 50/86, 48/74, 64/80 by area |
| `parity.hurl` status checks | 189/265. The skeleton alone, with no area code, scores 12/265 |
| Hurl failures on implemented routes | 0. All 76 failures are routes that were not implemented |
| Response-body match (group's 40 GET paths) | 37/40 |
| Status match on implemented GETs, as admin / non-admin / no token | 105/105, 105/105, 106/106 |
| JSON key-set or id-format differences on GETs where both return 200 | 0 of 64 (non-admin), 0 of 77 (admin) |
| PHP written (excluding the Laravel scaffold) | 73 files, 10,334 lines |
| Cost (whole session, API prices) | USD 69.66; 2 h 3 min of API time |
| Wall clock | about 1 h 10 min of work, plus a 10 h stop at the usage limit |

For comparison, W1 measured with the same scripts on the same database scored 263/265 on Hurl but 15/40 on response bodies. Without its global middleware the Hurl score falls to 110/265 ([log], last sections; [`16ab779bd`](https://github.com/xiaofang2016/mealie/commit/16ab779bd)).

## What happened and why

**1. The shared layer was a single point of failure, and parallel agents copied around it instead of reporting it.** In Phase 0 the orchestrator wrote a catch-all exception renderer that ran before Laravel's own handling of HTTP responses. As a result, the shared auth middleware returned 500 instead of 401/403. Areas 1, 2 and 3 each found this on their own and each wrote a separate wrapper middleware. Area 4 kept using the broken one ([log] 12:51; [`reports/`](https://github.com/xiaofang2016/mealie/tree/mealie-next-backend-rewrite/compx574/workflow-5/reports)).

The cause was structural. The shared code was frozen for the agents, they had no channel back to its owner, and the code had never been exercised through the middleware path before the agents started. Every agent that hit a defect in the shared code paid for it separately.

**2. The shared helpers were not enough, so each area wrote its own.** The shared paginator was written from a single sampled response before any area's needs were known. All three areas that needed paging found the same gaps, among them `perPage=-1`, a 400 for an unknown `orderBy`, and search. Each area wrote its own paginator; none used the shared one. The final inventory found:

- 4 auth wrappers
- 3 paginators
- 3–4 slugify functions
- 3 "not found" helpers
- 2 recipe-summary serializers
- 4 request validators

([`workflow-5-merge.md`](https://github.com/xiaofang2016/mealie/blob/mealie-next-backend-rewrite/compx574/workflow-5-merge.md) section 1)

Removing them was not free. The Phase C agent deleted 1,113 lines and added 460, and took 16 minutes. It also changed 385 of the 5,215 query-variant responses it had recorded before starting, even though its instruction said no response may change. One paginator cannot keep three areas' different edge-case behaviour, so it chose Python's behaviour each time. It reported this openly ([`reports/phase-c.md`](https://github.com/xiaofang2016/mealie/blob/mealie-next-backend-rewrite/compx574/workflow-5/reports/phase-c.md)).

**3. The API stayed consistent even though the agents never coordinated.** The group's Phase B prompt asked for five kinds of conflict to be listed: duplicated helpers, JSON key style, route prefixes, id formats and permission rules. Only the first showed up. The other four did not appear in any API output (zero key-set, id or permission-status differences above).

The reason is that every agent checked itself against the same running Python oracle. The oracle, not communication between agents, kept the output consistent. All the conflicts were internal: duplicated code that a reviewer would have to read and maintain.

**4. Holding out the acceptance test kept the score honest.** No implemented route failed a Hurl check. The 84 routes that weren't implemented were left unregistered, each listed with a reason in its area's contract: outside services, Python-only libraries, or not enough time. None was stubbed to pass.

W1 tuned against the same file instead. 153 of its 263 passes came from a global middleware that returns the expected status before routing happens. The difference follows from what each workflow let its agents see: an agent optimises the signal it is shown.

**5. Running agents in parallel spent rate budget, not time.** The four sub-agents started at 02:16 and all stopped at about 02:35, when the 5-hour session usage limit ran out (HTTP 429). Nothing could continue until the limit reset 10 hours later ([log] ~02:35).

The resumed runs alone used 1.27 million tokens. Claude Code's usage breakdown attributed 63% of the day's usage to sub-agents, and 84% to requests with more than 150k tokens of context. On a subscription plan, parallel agents meet the burst limit long before they save any wall-clock time.

**6. The agents' own reports overstated their results.** Each overstatement was caught only by a mechanical check:

- Area 3 reported 52 routes; `route:list` showed 48.
- Area 4 said it had "restored" group preferences; a snapshot comparison showed one timestamp had changed.
- Area 3 also printed one development token, and deleted test rows directly with SQL. A row-count comparison against the snapshot confirmed no existing data was lost.

([log] 12:53–13:14). The pattern matches the "premature claims of completion" failure mode in the brief. A sub-agent's report should be treated as a claim that still needs checking.

**7. Delegating review reduced what we can say about human judgement.** Both gates were run by the orchestrator. It did find real problems: the Hurl parse error, the test token belonging to a non-admin, and the over-reported route count. But W5 can't show how much a human reviewer would have caught.

## What I would change

- **Test the shared layer before fanning out.** Run a small contract test over the middleware, error bodies and pagination against Python before any sub-agent starts. Give agents a way to propose changes to shared code, through the orchestrator, instead of forbidding all changes. This addresses causes 1 and 2.
- **Build shared helpers from the union of needs.** Have each agent list its helper needs in its contract first. The orchestrator then builds those helpers once, before any area code is written. This turns Phase C's clean-up into up-front design.
- **Budget for parallelism.** Stagger the agents or cap how many run at once. Use a cheaper model for areas that are mostly CRUD, and balance the areas: area 2 had 86 routes against area 1's 32. Record usage per agent at launch, because the first runs' usage was lost when they hit the limit.
- **Strengthen the acceptance test before relying on it.** Add body or schema assertions, an admin token alongside the non-admin one, and keep a held-out portion even from the people tuning workflows.
- **Keep a human at Gate B.** The orchestrator produced the merge table and Gate B marks in a few minutes ([log] 12:58–12:59). A person reviewing the same table, with the time recorded, would give direct evidence for the synthesis.

## Appendix: group prompts and translations

The Phase A, B and C prompts were agreed in the group chat in Chinese. The full Chinese text is in [log] under "Workflow as specified by the group". English translations:

**Phase A (split only).** "Rewrite Mealie's FastAPI backend (`mealie/`) in PHP in a new directory `php-w5`. Leave the Python backend in place. Use the same `/api` paths and the existing SQLite database `dev/data/mealie.db`. Do not run `php artisan migrate` against this database. Do not modify `frontend/app/lib/api/types/`, or any locale file other than en-US. Do not print secrets. Python keeps running at http://127.0.0.1:9000; this PHP app runs at http://127.0.0.1:9001. Commit only when this prompt explicitly asks, with messages starting `workflow-5:`. Do not read or copy `php-w1/`. Do not read `compx574/workflow-1-single-instruction.md`. This is workflow 5. Parallel sub-agents will be used later; do not implement any API behaviour in this round. Write the split to `compx574/workflow-5-split.md`. Split the backend into the following four areas; every Python package under `mealie/routes/` except spa must be assigned: (1) auth, users, app, validators; (2) recipe, organizers, unit_and_foods, comments, parser; (3) households, media, shared; (4) groups, admin, explore. For each area state: its Python files, the PHP files it may create, and the files no area may change. Each area may register routes only in its own `routes/<area>.php`. No area may change another area's files, or `mealie/`, `frontend/` or `tests/`. spa is out of scope. List anything else you will not implement under 'Out of scope'. Stop after writing the split."

**Phase B.** "The split is approved. Start one sub-agent per area and run them in parallel. Do not implement the areas yourself. Each sub-agent receives only its own area from `compx574/workflow-5-split.md` and these rules: change only the PHP files assigned to that area; copy every HTTP method and full path from the assigned Python router; leave the Python backend in place. Before writing route code, each sub-agent appends a section to `compx574/workflow-5-contracts.md`. For every endpoint it plans to implement it records: method and full path with its Python source; the schema file the JSON field names come from; how ids are stored and returned; who may call it, based on which Python permission check; and what is not implemented. After the sub-agents return, do not resolve differences. Write the conflicts to `compx574/workflow-5-merge.md`, listing only: duplicated helper code, different JSON key styles, different route prefixes, different id formats, different permission rules. Commit with a message starting `workflow-5:`. Then stop."

**Phase C.** "Handle only the conflicts I have marked below. Do not add endpoints, and do not reopen areas without conflicts. <the marked conflict table> Add the changes to `compx574/workflow-5-merge.md`. Commit with a message starting `workflow-5:`. Then stop."

**Operator's delegation of the review gates.** "按照你认为合适的去完成 并记录就行" = "Complete it however you think is appropriate, and just record it."
