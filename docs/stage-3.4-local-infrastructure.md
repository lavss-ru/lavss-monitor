# Stage 3.4 — Local Infrastructure Monitoring

Stage 3.4 adds Location and LocalDevice. The older check-history/uptime proposal is Stage 3.5, shared across Website, VPS and LocalDevice.

## Scope and routing

`Infrastructure` remains the existing legacy dashboard inventory. `Location` groups new `LocalDevice` records and has no measured health. `connection_type` (`local`, `wireguard`, `vpn`, `other`) is metadata. Routing, DNS and VPN connectivity are provided by the operating system. No tunnel, firewall, keys, shell commands, HTTP fetching or Proxmox API are involved.

Device types: `proxmox`, `linux_server`, `windows_server`, `router`, `vm`, `network_device`, `other`. All use the configured TCP port. A TCP connection proves port availability, not application correctness.

## CRUD and control

Authenticated single-owner routes follow the existing application (no public registration, no new roles). `/local-infrastructure` and `/locations` display the grouped page. Location and device forms validate on the server. Hosts accept private/public IPv4, raw IPv6 and ASCII DNS hostnames including single-label names. Punycode hostnames are accepted; Unicode conversion, IPv6 zone IDs, URLs, ports in host and bracketed input are not supported. No DNS/network lookup occurs during validation.

A Location with children cannot be deleted; the FK also uses RESTRICT. Device deletion preserves Event history, resolving its active warnings. Editing host, port, Location, or enabled state resets health to unknown, clears incident and pending notification state, and resolves old warnings without emitting recovery. Metadata-only changes preserve health.

Location.enabled pauses all its children. Changing it resets child health/incident state and resolves warnings; it never claims that Location is DOWN or recovered. Device.enabled remains independent. Check-all and scheduler require both enabled flags. Manual single check can diagnose a disabled device or a device in a paused Location: factual health changes, but no incident, Event or MAX is generated.

## TCP and scheduling

`LocalDeviceHealthCheckService` uses `stream_socket_client`, 3-second connect timeout, monotonic elapsed timing and closes the successful socket. Offline response time is null; online records integer milliseconds. Reuses `VpsHealthCheckService::buildAddress` for IPv6 brackets without modifying the VPS checker. Protected `connect` provides a no-network testing seam.

`monitor:local-devices` runs every minute with `withoutOverlapping(10)` and no background execution. Sequential check-all isolates Throwable per device and returns checked/error counts. Command returns 1 if any checker failed unexpectedly. Network refusal/timeout is ordinary offline, not a command error. Existing VPS everyMinute and Website everyFiveMinutes are unchanged. An unexpected checker exception records unknown and clears an unconfirmed failure timer; a previously confirmed incident survives uncertainty.

## Persisted state machine

- First factual offline, including unknown -> offline: set failure_started_at; no Event/MAX.
- At elapsed 1:59: remain in grace, no Event/MAX.
- At elapsed >=2:00 on an offline check: set incident_confirmed_at; create one warning Event and attempt red MAX DOWN.
- Further offline: no new Event. Retry DOWN only if incident_notified_at is null. Successful delivery sets it.
- Online before confirmation: silently clear failure timer; initial unknown -> online is silent.
- Online after confirmation: resolve warning, create one info Event, clear failure/confirmation/DOWN-notified fields and set recovery_pending_at. Attempt green recovery.
- Successful recovery delivery clears recovery_pending_at. Failed recovery remains pending and retries on subsequent online checks without another Event.
- Recovery is reported for a confirmed incident even if its DOWN could never be delivered. This can produce a standalone green recovery, intentionally documenting a real confirmed incident.
- A new offline result discards obsolete pending recovery and starts a fresh grace window; it never sends green while offline.
- Administrative reset/delete cancels pending delivery; it is not measured recovery.

Fields are persisted in DB; process restart does not restart grace or lose retry state. Per-device row locks serialize check, edit, delete and delivery. Health + Event state commits before MAX delivery, so notification errors cannot roll back factual state. Delivery uses a separate transaction and never auto-retries that transaction.

## MAX and delivery limits

Uses existing MaxNotifier transport/config, raw Authorization, user_id query, 5-second timeout and default TLS verification. No credentials or endpoints are introduced. A non-2xx response, application error (`success:false`, `error`, `code`) or exception leaves delivery pending. Unconfigured credentials also do not count as delivery.

Successful normal delivery is deduplicated. Exactly-once external delivery cannot be guaranteed after an ambiguous timeout or a crash between MAX accepting the request and DB recording success: the existing MAX API integration provides no idempotency key. Retrying may duplicate a message in that crash window. Errors on one device never stop the rest.

No Location correlation/suppression: a real site-wide outage can produce one confirmed DOWN per device. Large inventories run sequentially; connect and MAX latency can extend the interval beyond one minute. PHP DNS resolution can additionally depend on OS resolver timeouts.

## Dashboard and UI

The new navigation item and grouped page provide location/device forms, pause/resume, single diagnostics and check-all. Factual OFFLINE and grace/confirmed incident labels are distinct. Dashboard localDevices counters (total/online/offline/unknown) include only effectively monitored devices. Existing counters are unchanged. Local warning Events use the existing infrastructure attention path and resolve on recovery or administrative reset.

## Verification and next stages

Run backend tests with a separate Docker project, source at the Stage 3.4 worktree, SQLite :memory:, anonymous vendor volume, empty overlaid .env, empty MAX credentials and network_mode:none. Tests fake HTTP and TCP. Never run the application image default command (it migrates before serving) for tests. TypeScript/build use an isolated node container with no network and an anonymous node_modules volume.

SQLite tests exercise migrations and FK restriction. PostgreSQL row-lock concurrency is reviewed but not integration-tested against a PostgreSQL instance in this stage. No real devices, Internet or MAX are used in verification.

Stage 3.5: unified check history + uptime/statistics for Website, VPS and LocalDevice. Later: detail pages/graphs, Location/gateway correlation, Proxmox API monitoring.
