# Initiatives module

The Initiatives domain adds the strategic/governance layer above operational
Tickets. An initiative may exist before execution work and may link to many
tickets; linked ticket time entries feed actual internal cost.

## Architecture

Routes remain thin (`pages/initiatives.php`, `pages/initiative.php`,
`pages/initiative-form.php`). Business behavior lives under
`includes/modules/initiatives/`:

- `initiative-schema.php`: normalized, idempotent DDL and neutral workflow defaults;
- `initiative-permissions.php`: granular backend capabilities and initiative visibility;
- `initiative-service.php`: CRUD, read models, costs, benefits, ticket links and events;
- `initiative-workflow.php`: transition validation, required fields and checkpoints;
- `initiative-financial.php`: annualization, ROI, payback, discounted payback, NPV, IRR and TCO;
- `initiative-governance.php`: triage, approvals, measurements, committee scoring, conflicts, homologation and rollout;
- `initiative-portfolio.php`: filters, KPIs, deviations, Work queues and global search;
- `initiative-settings.php`: administrator-managed reference and governance data.

Fresh installs execute the domain DDL immediately after `includes/schema.sql`.
Existing installations use the same statements through `upgrade.php`, and the
runtime ensure is an idempotent rolling-upgrade fallback.

## Configurable data

Administrators can manage initiative types, required fields, financial model,
categories, areas, units, cost centers, display statuses, stable status groups,
allowed transitions, transition capabilities, justification requirements,
currency, discount rate, analysis horizon, measurement checkpoints, approval
rules, committees, members, minimum participation, divergence threshold,
weighted criteria, custom numeric scales, and per-user capabilities.

No customer name, person, organizational unit, approver, financial threshold,
criterion weight or tool price is embedded in product logic.

## Financial scenarios

Every cost and benefit belongs to `forecast`, `validated` or `actual`.
Snapshots expose annual benefit, initial investment, annual recurring cost,
total cost, net benefit, ROI, simple/discounted payback, NPV and IRR. Hours-saved
benefits account for before/after duration, frequency, people, adoption and
hourly cost. Risk benefits use probability reduction times financial impact.
Incremental revenue can apply contribution margin. Actual snapshots add costed
hours from every linked ticket.

## API

Authenticated and scoped endpoints are registered in `includes/api/router.php`:
list/get/create/update, transition, triage, approval, evaluation, measurement,
homologation, rollout, portfolio and CSV export. All writes enforce backend
capabilities and browser writes require CSRF.

