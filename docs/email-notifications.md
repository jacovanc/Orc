# Workflow attention email

Orc can email the workflow owner when a run enters any human-controlled stage, including Human Review, Development Blocked Review, QA Blocked Review, and Merge Blocked Review. It does not email for agent or terminal stages and never takes the human action automatically.

## Delivery model

The central workflow engine creates one `workflow_attention_deliveries` record for each human StageRun while it holds the run transaction. A unique `(stage_run_id, channel)` constraint prevents duplicate scheduling when a completion or command is replayed. The mail job is dispatched only after commit and uses bounded retries on the `workflow-notifications` queue.

Before sending, the job confirms that the attempt is still the active waiting stage. It records a stale, cancelled, moved, or already-completed attempt as `skipped` rather than sending an obsolete notification. Successful and exhausted deliveries are recorded as `sent` or `failed`; provider errors are sanitized and no API key or response body is stored.

Application-side deduplication prevents repeated commands from scheduling repeated mail. Like ordinary email delivery, an ambiguous provider response can theoretically result in a duplicate message after a retry; email transport does not provide the same end-to-end idempotency guarantee as Orc's workflow callbacks.

## Mailgun configuration

Install these values as Laravel Cloud environment variables. Treat `MAILGUN_SECRET` as a secret and never paste it into an issue, chat, command, log, or tracked file.

```dotenv
MAIL_MAILER=mailgun
MAILGUN_DOMAIN=mg.example.com
MAILGUN_SECRET=<Laravel Cloud secret>
MAILGUN_ENDPOINT=api.mailgun.net
MAIL_FROM_ADDRESS=orc@mg.example.com
MAIL_FROM_NAME=Orc
WORKFLOW_EMAIL_NOTIFICATIONS_ENABLED=true
WORKFLOW_EMAIL_QUEUE=workflow-notifications
```

Use `api.eu.mailgun.net` instead of `api.mailgun.net` for a Mailgun EU-region domain. The sending domain must be verified in Mailgun and the From address should use that domain.

The supervised database worker must consume both queues:

```bash
php artisan queue:work database --queue=amp-launches,workflow-notifications --tries=4 --backoff=5 --timeout=30
```

Deploy the application and migrate before enabling notifications. Enabling applies only to human stages entered afterward; Orc does not retroactively email for stages that were already waiting while notifications were disabled.

## Safe activation check

1. Configure the verified Mailgun domain, endpoint, From identity, and secret in Laravel Cloud.
2. Update the existing supervised worker to consume `amp-launches,workflow-notifications`.
3. Set `WORKFLOW_EMAIL_NOTIFICATIONS_ENABLED=true` last and deploy/restart the application and worker.
4. Enter a new Human Review or blocked attempt through a test workflow.
5. Confirm one delivery row reaches `sent`, one message arrives at the workflow owner's account email, and its Orc/GitHub links target the correct run.

Do not use a real workflow transition merely to test mail without authorization. Mailgun configuration can first be checked with a separately addressed application-level test message.
