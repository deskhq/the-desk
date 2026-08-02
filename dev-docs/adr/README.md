# Architecture Decision Records

Short records of load-bearing architecture decisions. Format and rationale:
[ADR-0001](0001-record-architecture-decisions.md). Shared vocabulary and the named
seams live in [`CONTEXT.md`](../../CONTEXT.md).

| ADR | Decision |
| --- | --- |
| [0001](0001-record-architecture-decisions.md) | Record architecture decisions as ADRs |
| [0002](0002-message-eager-load-set-in-one-scope.md) | Own the message eager-load set in one scope |
| [0003](0003-single-visible-channels-acl-scope.md) | One scope for the visible-channels ACL |
| [0004](0004-timeline-window-as-read-model.md) | Resolve the channel timeline window in a read-model |
| [0005](0005-domain-event-recording-seam.md) | Record audit & security events via event→listener |
| [0006](0006-realtime-lives-in-composables.md) | Realtime subscription lifecycles live in composables |
| [0007](0007-in-process-browser-realtime-harness.md) | Browser E2E realtime tests run against the app served in-process |
| [0008](0008-workspace-shell-read-model.md) | The shared-props middleware is glue; the workspace shell is a read-model |
| [0009](0009-message-action-context-is-provided.md) | The message-action context is provided, not drilled |
| [0010](0010-channel-traffic-predicate-has-one-home-per-language.md) | The channel-traffic predicate has one home per language |
| [0011](0011-read-model-dto-takes-its-viewer.md) | A read-model DTO takes its viewer, and batches its roster |
| [0012](0012-three-test-suites.md) | Three test suites: pure, database, HTTP contract |
