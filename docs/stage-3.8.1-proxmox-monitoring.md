# Stage 3.8.1 — Proxmox Monitoring & Correlation

Подготовлено для review на базе `442b956`, ветка `stage-3.8.1`.
Миграции production не выполнялись. Proxmox API остаётся GET/read-only.

## Архитектура и defaults

Иерархия: **Location → Proxmox Connection → Node → VM/LXC Guest**.
`ProxmoxSyncService` сохраняет существующий revision guard и connection lock.
`ProxmoxMonitoringService` применяет существующий паттерн Location/LocalDevice:
`failure_started_at`, `incident_confirmed_at`, `incident_notified_at`,
`recovery_pending_at`, warning/info Events и отдельную фазу MAX после сохранения.
Используются общие `NotificationPolicyService`, `MaxNotifier`,
`MonitorCheckRecorder`, `MonitorCheckStatisticsService`; очереди и новая
независимая incident infrastructure не добавлены.

Connection и Node: `monitoring_enabled=true`. Guest: **false**, expected `running`.
Миграция применяет эти defaults и к существующим строкам. Все восемь гостей
PVE Home останутся с выключенным мониторингом, включая stopped mail/haos.
Connection `enabled=false` запрещает API; `monitoring_enabled=false` выключает
его incidents/history, но сохраняет inventory sync и независимые child policies.
Выключение мониторинга Node также не отключает factual dependency gate гостей.

## Семантика состояния

| Объект | Online/healthy | Offline/failure | Unknown |
| --- | --- | --- | --- |
| Connection | API-запрос успешен | network / HTTP failure | auth, payload, internal, disabled, Location unavailable |
| Node | factual online | factual offline | неизвестное состояние, stale, недоступен родитель |
| Guest expected running | running | stopped / paused | неизвестное состояние, stale, недоступен родитель |
| Guest expected stopped | stopped | running / paused | неизвестное состояние, stale, недоступен родитель |

`ignore` или monitoring OFF исключают гостя из incidents, новых samples и
monitored summary. Stopped не является аварией само по себе. Paused — обычный
mismatch, отдельной категории alarm нет. Identity: connection + guest_type + vmid.
Rename/move/status/metrics sync не сбрасывают monitoring policy или evidence.
Templates также не включаются автоматически; оператор может явно выбрать policy.

**Выбранная политика auth/payload:** UI остаётся UNKNOWN с безопасным
`last_error_code` и пояснением. Эти ошибки не подтверждают DOWN и не закрывают
существующий инцидент. MAX об auth/payload в этом этапе не отправляется.
Это операционная неопределённость, которую оператор видит на странице Proxmox;
данная модель не выдаёт её за сетевой OFFLINE. Internal имеет ту же семантику.

## Подтверждение, suppression и recovery

По умолчанию задержка всех трёх типов — 120 секунд, настраивается отдельно.
Первый failure запускает grace без Event/MAX. Повторный factual failure после
задержки подтверждает инцидент, создаёт один warning Event. Повторные failures
не создают новых Events. UNKNOWN сбрасывает только неподтверждённый таймер:
интервал неопределённости не считается непрерывным сбоем.

Location gate использует `Location::blocksChildren()`. При confirmed offline,
unknown или disabled Location API не вызывается, samples не создаются. В grace
Location existing pattern разрешает fetch, но все Proxmox incident transitions
и alerts ждут online Location; child monitoring state UNKNOWN.

Любой non-online Connection подавляет детей уже в grace. Confirmed Connection
также блокирует их. Node offline/unknown/stale подавляет гостей этого узла уже
в grace; гости другого online Node продолжают мониториться. Отключённый child
не превращается в problem в summary.

Confirmed child evidence сохраняется при любых parent failures и stale.
UI показывает UNKNOWN и сохранённую отметку confirmed. Warning Event не
закрывается, а Dashboard временно исключает его из активного списка внимания.
При восстановлении сначала принимается новый inventory; только factual healthy
ребёнок закрывает свой инцидент. Still mismatch сохраняет прежний инцидент.
Так исключены ложные Recovery и каскад зелёных сообщений.

Recovery Event возможен только после confirmed incident. MAX Recovery возможен
только если DOWN был успешно отправлен и включён recovery toggle. При quiet
hours/global disable/MAX disable отправка откладывается. Deferred DOWN
отправляется лишь при продолжающемся подтверждённом failure. Pending Recovery
сохраняется до успешной доставки или отключения recovery rule; новый failure
отменяет устаревший pending Recovery. Удаление Connection локальное, без Recovery;
физического удаления stale rows нет. Изменение monitoring policy начинает новую
эпоху наблюдения, снимает старое evidence и закрывает warning без Recovery Event/MAX.

## Sync, manual, scheduler и locking

Scheduler запускает `monitor:local-devices` (Location прежде устройств) до
`monitor:proxmox`; Proxmox every minute, `withoutOverlapping(10)`. Gate повторно
проверяется под shared Location lock после fetch и непосредственно перед MAX.
Если предыдущая Location-команда пропущена/неуспешна, используется последнее
сохранённое состояние площадки, как в существующем correlation pattern.

Цикл Connection:

1. Снимок notification policy и revision, Location gate.
2. GET inventory вне DB transaction, существующая строгая валидация node/qemu/lxc.
3. Connection lock, проверка revision, shared Location lock и повторный gate.
4. Атомарно Connection factual state, inventory (без policy overwrite).
5. Connection → Nodes → Guests transitions, history и Events в той же transaction.
6. Отдельная transaction: тот же connection lock, revision/Location gate, delivery
   parent → children, сохранение delivery evidence. Нет transaction retries вокруг MAX.

Overlap или policy edit повышает revision: устаревший fetch не создаёт ни samples,
ни Events, ни MAX. Policy editor блокирует Connection перед child. Superseded
notify пропускается; следующий цикл доставляет всё ещё актуальный инцидент.
При ошибке сохранения inventory rollback атомарен; best-effort UNKNOWN сбрасывает
неподтверждённые таймеры. Если сама БД недоступна, запись состояния невозможна.

`Проверить подключение`: только GET /version, Connection sample с origin manual,
Connection failure/grace; нет Node/Guest transitions или samples. Если Connection
был недоступен, старый snapshot не становится factual после успешного /version.
Confirmed Connection Recovery ждёт **полного sync**. Неопределённость при ручном
тесте также сбрасывает неподтверждённые child timers, не создавая child incidents.

`Синхронизировать`: полный цикл, origin manual. Scheduler: scheduled.
Service поддерживает manual_batch для существующей общей схемы origins;
нового Proxmox check-all UI нет.

`last_synced_at=null` после ошибки/изменения endpoint означает, что cached inventory
не подтверждён для текущего подключения. Метрики и row `last_seen_at` сохраняются;
успешный полный sync заново устанавливает timestamp. Stale row означает отсутствие
в успешном текущем snapshot, а не DOWN.

**Ограничение доставки:** DB locks устраняют дубли при обычных overlap/retry,
но, как в существующем MAX pattern, timeout после принятия сообщения внешним
сервером или crash между отправкой и DB commit не дают математической гарантии
exactly-once. MAX idempotency/outbox в этом этапе не добавлены. PostgreSQL lock
semantics reviewed по коду; реальные конкурентные PostgreSQL процессы не запускались.

## History и availability

Новые types: `proxmox_connection`, `proxmox_node`, `proxmox_guest` в
`monitor_checks` и `notification_rules`. Connection history хранит factual
online/offline/unknown. Node history — correlated online/offline/unknown.
Guest `online` означает **соответствие expected состоянию на момент проверки**,
`offline` — factual mismatch, `unknown` — uncertainty/stale/parent unavailable.
Это не uptime процесса VM: expected stopped + stopped = online sample.

Skipped disabled/Location dependency/superseded не создают fake samples.
При неуспешном API есть только Connection sample; cached children не измерены.
В успешном inventory cycle stale/unknown и дети offline Node получают UNKNOWN.
История записывается только для monitoring-enabled объектов (Guest не ignore).

Общий statistics service использует scheduled samples за 24h/7d/30d;
UNKNOWN исключён из denominator, manual/manual_batch не входят в uptime.
Guest UI: «Соответствие», Connection/Node: «Доступность».
Исторические samples не переопределяются после смены guest policy: это
соответствие политике, действовавшей при измерении. Retention остаётся общим.

## UI, Events, MAX и безопасность

Settings → Notifications содержит Connection/Node/Guest DOWN/Recovery toggles
и delay. Guest dialog явно задаёт monitoring и expected running/stopped/ignore;
Node toggle и Connection monitoring checkbox доступны на Proxmox page.
Редактирование использует существующий authenticated/CSRF-protected PUT Connection
с typed monitoring_target и scoped child id. Новых application routes нет.

UI показывает factual actual, expected, monitoring state, grace/confirmed,
last check и 24h/7d/30d availability. Dashboard отдельно считает PVE connections,
Nodes и monitored Guests: online/healthy, offline/problem, unknown. Ignored
и disabled monitoring не входят в monitored counters.

Events используют type/source_id, warning при подтверждении, info при recovery,
resolved_at для прежнего warning. MAX использует общую policy/quiet hours/timezone
и recipient fallback. Guest text содержит connection, node, VM/LXC, vmid, имя,
expected/actual. Raw API body, token, Authorization, exception stack в messages
не передаются. Proxmox MAX transport logs редактируют raw errors/bodies.
Encrypted secret, hidden serialization и безопасные API codes сохранены.

## Migration и проверка

`2026_09_16_000000_add_proxmox_monitoring.php` добавляет boolean/timestamp поля,
Guest expected enum; расширяет monitor_type CHECKs по существующему паттерну.
PostgreSQL: named CHECK replacement. SQLite: rebuild с повторным объявлением
origin/status enum, сохранением indexes. Down удаляет только новые типы history/rules
и новые monitoring columns, сохраняет inventory. Это потеря новых monitoring
данных при rollback, поэтому rollback выполняется только осознанно после backup.

SQLite up/down и существующие 8 гостей проверяются тестом. PostgreSQL DDL
компилируется через pretend + PDO double, без соединения и SQL execution.
Изоляция: `/tmp/stage-3.8.1-test.compose.yml`, `network_mode: none`, SQLite :memory:,
Http::fake/fake MAX, array cache/session, существующие локальные Docker images.
Полный отчёт и точные результаты: `/tmp/stage-3.8.1-report.txt`.

## Production acceptance — только после review/deploy оператором

1. Сделать backup и применить миграцию, проверить PVE Home / Дом / 192.168.31.2:8006,
   PVE 9.1.4, узел proxmox и все 8 существующих гостей.
2. Убедиться, что monitoring всех Guests OFF. Включить expected running только:
   cloud 100, lxc-debian-torrserv 102, lxc-debian-samba0 105, vikunja 110,
   backup 300, lavss-monitor 500. Mail 103 и haos 200 оставить OFF или явно
   выбрать expected stopped.
3. Проверить обычный scheduler, rules/delays, samples и availability.
4. Оператору остановить выбранный безопасный тестовый Guest: grace → один DOWN
   Event и MAX. Запустить Guest: один Recovery. Не останавливать VM с самим monitor.
5. Контролируемо нарушить доступ к API: один Connection incident; дети UNKNOWN,
   без alert flood. Восстановить API и выполнить factual sync перед Recovery.
6. Повторить с уже confirmed Guest: его evidence сохраняется; mismatch после
   восстановления не даёт Recovery, factual healthy даёт один Recovery.
7. При безопасной возможности проверить Location failure и scoped node failure.
8. Проверить quiet hours, включение/выключение правил и отсутствие дублей.

Никакие acceptance воздействия этим этапом разработки не выполняются.

## Исключённый scope

CPU/RAM/Disk thresholds, storage, backup monitoring, Ceph, HA, quorum,
replication, task log ingestion, guest IP discovery, QEMU agent, SSH, SNMP,
Prometheus и любые VM/node control actions: start/stop/reboot/shutdown,
suspend/resume/migrate/snapshot/clone/delete/backup/config mutate/node command.
