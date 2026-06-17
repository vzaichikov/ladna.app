---
name: charm-crm-domain
description: Use for Charm CRM SaaS domain work involving platform/studio/customer roles, account tenancy, access permissions, subscriptions, class passes, schedules, trainers, customers, bookings, Charmpole demo data, or business-process decisions in this Laravel CRM.
---

# Charm CRM Domain

## Workflow

Before changing domain behavior, read `references/saas-structure.md`.

Use that reference to map business terms to code-level entities, permissions, and tenant boundaries. Keep the domain model operational and code-facing: when implementation details and business wording differ, preserve the business boundary first and then align code names consistently.

## Non-Negotiable Boundaries

- Preserve the separation between SaaS subscription and studio customer class pass.
- Keep studio-owned data scoped to one `Account`, usually through `account_id`.
- Never let studio owners create peer SaaS studio owners; platform admins create SaaS customer accounts.
- Treat trainer/admin CRM login as optional staff access linked to a trainer where applicable.
- Keep studio customers as `Customer` records, separate from CRM `User` records.
