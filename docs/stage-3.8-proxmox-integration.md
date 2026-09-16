# Stage 3.8 — Proxmox API + Inventory

Подготовлено для review на базе `d32f369`, ветка `stage-3.8`.
Никаких production migrations, deploy или реальных API calls в ходе разработки.

## Архитектура

```text
Location
└── ProxmoxConnection (отдельный API endpoint/token)
    ├── ProxmoxNode
    │   ├── ProxmoxGuest qemu (VM)
    │   └── ProxmoxGuest lxc
    └── cached stale inventory
```

Legacy `Infrastructure`, его Dashboard list и существующие LocalDevice сохранены.
Тип LocalDevice `proxmox` остаётся обычной TCP-проверкой. «PVE Home» не удаляется
и не конвертируется. Одна connection описывает standalone node или точку доступа
к cluster; nodes/guests принадлежат connection, не LocalDevice.

## Модель данных и миграция

Одна reversible migration создаёт три таблицы в порядке parent → children;
rollback удаляет children → parent. Типизированные колонки, без generic JSON.

- `proxmox_connections`: location FK с RESTRICT, name, host, port integer default
  8006, scheme https/http (default https), verify_tls default true, api_user,
  api_token_id, encrypted text api_token_secret, enabled, online/offline/unknown,
  last_checked_at, last_response_ms, safe last_error_code, version,
  last_synced_at и revision.
- `proxmox_nodes`: connection FK CASCADE, unique(connection,node_name),
  online/offline/unknown, cpu_usage double, memory_used/total bigint,
  uptime_seconds bigint, max_cpu integer, nullable version, stale, last_seen_at.
  Version node зарезервирована; версия API endpoint записывается на connection
  при Test и не приписывается всем nodes смешанного cluster.
- `proxmox_guests`: connection FK CASCADE, nullable node FK SET NULL,
  unique(connection,guest_type,vmid), qemu/lxc, vmid integer, nullable name,
  running/stopped/paused/unknown, numeric metrics, template, stale, last_seen_at.
- Индексы: location_id, node FK, status, last_seen_at; составные unique также
  обслуживают выборку inventory по connection.

Laravel enum создаёт CHECK в PostgreSQL/SQLite. Port намеренно integer:
PostgreSQL smallint не покрывает 65535. Bigint bytes не форматируются в DB.
SQLite up/down и ограничения тестируются в памяти; PostgreSQL проверен
генерацией DDL с PDO mock (prepare/exec запрещены), без соединения.
Настоящая PostgreSQL migration/concurrency acceptance остаётся ручной.

Удаление connection удаляет только локальный cache каскадом, без HTTP.
Location с Proxmox connections нельзя удалить до переноса/удаления connections.

## API и authentication

Используется Laravel HTTP client, только GET:

| Action | Endpoints |
| --- | --- |
| Test | /api2/json/version |
| Sync | /api2/json/nodes, /api2/json/cluster/resources |

`/cluster/resources` — основной bulk inventory. `/nodes` проверяет, что snapshot
содержит ожидаемые nodes. `/cluster/status` не нужен для Stage 3.8:
cluster name/quorum не собираются. Нет запросов на каждый guest.

Header собирается только backend:
`PVEAPIToken=USER@REALM!TOKENID=SECRET`.
api_user включает realm, token ID хранится отдельно; пароль не запрашивается.
Host допускает IPv4, IPv6 без скобок и hostname без scheme/path/port.
URL builder добавляет скобки IPv6. User/token ID запрещают header delimiters
и CR/LF; secret — непустые печатные ASCII без пробелов и CR/LF.

Timeout 10 s, connect timeout 3 s (`config/proxmox.php`), без retry.
Redirects запрещены, чтобы credential header не уходил на другой endpoint.
verify_tls=true по умолчанию; выключение явно отмечается менее безопасным
режимом. HTTP тоже явно отмечен как передача token без шифрования.
Для постоянной настройки предпочтителен HTTPS с доверенным сертификатом.

API contracts реализованы по заданным endpoints и fixture payloads.
Интернет и live API не использовались; особенности установленной PVE версии
нужно проверить при ручной приёмке.

## Секреты и безопасные ошибки

Encrypted cast использует Laravel APP_KEY; ciphertext в text column,
`$hidden` исключает secret и revision из сериализации. APP_KEY должен
сохраняться вместе с защищёнными backup: потеря ключа делает token нечитаемым.
Backend расшифровывает secret только для HTTP header.

Create требует secret; update без secret или с пустым полем сохраняет старый.
Явная непустая замена шифруется заново. Поле UI всегда начинается пустым,
type=password, autocomplete=new-password, без useForm remember key,
очищается после завершения отправки. Secret не приходит в props/HTML.
`dontFlash(['api_token_secret'])` исключает old input при любых validation errors;
сообщение валидации secret фиксированное, без подстановки значения.

HTTP exceptions/response bodies не логируются и не добавляются как previous
exception. Сервис использует whitelist safe codes: auth, network, http,
payload, internal, disabled, location_unavailable, superseded.
401/403 → «Ошибка авторизации API». Raw HTTP headers/body, SQL exceptions,
request dumps не отправляются в Event/notification/JSON. DB failure ловится
на границе записи, inventory transaction откатывается. Если DB ещё доступна,
connection переводится в internal/unknown; иначе сохранить факт невозможно.

Интеграция не включает HTTP debug middleware или tracing. При добавлении
APM/request logging в будущем требуется redaction Authorization и
api_token_secret; encrypted storage не защищает от администратора с APP_KEY.
Реальных credentials в fixtures нет.

## Sync и целостность

1. Перечитать connection и Location; запомнить revision.
2. Disabled connection/Location или Location::blocksChildren() → skip HTTP,
   derived/persisted unknown с безопасной причиной; last_checked_at,
   last_seen_at и stale inventory не переносятся.
3. Получить оба payload до DB transaction.
4. Проверить data list, type/node/vmid/status, duplicate identities, node
   references и numeric metrics. Отсутствующие optional metrics → null.
   Числовые строки API допускаются, отрицательные/бесконечные/переполненные
   и дробные integer metrics отклоняются, CPU ограничен 0..1.
   Неизвестные непустые status нормализуются в unknown.
   Известные неиспользуемые types storage/pool/sdn пропускаются;
   неизвестные resource types отклоняются до отдельной поддержки.
5. Требовать непустой /nodes и точное совпадение node sets с resources.
   Malformed/явно partial payload abort: старые записи полностью сохраняются.
6. Открыть transaction, lock connection, проверить revision, взять shared lock
   текущей Location и повторить availability gate.
7. Отметить cached rows stale/unknown, затем updateOrCreate всех полученных
   nodes/guests с stale=false и текущим last_seen_at. Обновить connection
   online, last_checked_at, last_synced_at, response_ms и revision.
8. Любой DB exception откатывает все inventory writes. Сетевые вызовы никогда
   не выполняются под собственной DB transaction.

Revision увеличивается при CRUD update и при завершении operation. Поэтому
результат параллельного fetch после изменения настроек или более раннего
завершённого operation не перезаписывает актуальные данные. Изменение host,
credentials, Location, TLS или enabled также инвалидирует cache (stale/unknown).
Автоматических повторов нет. Это optimistic concurrency, не очередь:
два одновременных manual/scheduled fetch могут выполнить HTTP, но второй
устаревший результат будет отклонён. PostgreSQL race cases требуют приёмки.

Guest identity не включает node: migration между nodes обновляет node FK
существующей guest row. Исчезновение не вызывает delete, last_seen_at сохраняется;
повторное появление оживляет ту же запись. Stale хранится бессрочно на Stage 3.8,
retention можно добавить позже.

Полностью валидный, но ACL-filtered response невозможно отличить от реального
исчезновения guest. Уменьшение node set в обоих endpoints также может быть
реальным изменением cluster. Поэтому нужны полные read-only права inventory,
а stale записи никогда не удаляются автоматически. Два API запроса не являются
атомарным Proxmox snapshot; изменение cluster между ними может отклонить один cycle.

## Location и статусы

Используется существующий Stage 3.7 `blocksChildren()` без собственной state machine:

- monitored unknown и confirmed offline блокируют;
- grace offline без confirmation допускает API;
- unmonitored enabled Location допускает API;
- disabled Location/connection всегда блокирует, включая manual Test/Sync.

Page вычисляет derived unknown по текущей Location даже до scheduler cycle.
При network/http failure connection offline; auth/payload/internal, disabled,
location_unavailable и superseded означают unknown. 401/403 и malformed payload
не доказывают сетевую недоступность PVE. Safe last_error_code сохраняет причину.
Успешный валидный Test/Sync → online, никогда не создаётся Event/MAX.

Node: online/offline, всё остальное unknown.
Guest: running/stopped/paused, всё остальное unknown.
Stopped и templates не считаются incidents. При недоступной connection UI
показывает guest/node unknown, но сохраняет подписанные snapshot metrics.

## Metrics

| API | DB | UI |
| --- | --- | --- |
| cpu | cpu_usage | fraction × 100% |
| mem / maxmem | memory_used / memory_total | binary bytes + percent |
| maxcpu | max_cpu | raw count |
| disk / maxdisk | disk_used / disk_total (guest) | binary bytes |
| uptime | uptime_seconds | дни, часы, минуты |
| template | template boolean | template badge |

Disk из bulk endpoint может быть 0/null либо иметь разную семантику VM/LXC;
это данные Proxmox, не измерение занятого места внутри guest filesystem.
Нет guest agent calls и storage alerting.

## Scheduler, Dashboard и UI

`monitor:proxmox`: каждую минуту, foreground, withoutOverlapping(10).
Sequential enabled connections; одна ошибка не прерывает остальные.
Summary checked/errors/skipped, ненулевой exit при errors; секретов в output нет.
Duration двух fetch ограничена примерно 20 сек на connection; большой парк
увеличивает длительность цикла. Не запускается отдельный polling transport.

`/proxmox`: connection CRUD, Test, Sync, node cards, grouped VM/LXC table,
snapshot timestamp, CPU/RAM/Disk/uptime, template/stale, TLS mode.
Открытая страница обновляет данные после manual actions или reload; фонового
page polling на Stage 3.8 нет. Scheduler обновляет локальную БД.

Page: eager load connections/location/nodes/guests + список Location,
без N+1 и HTTP. Группировка на клиенте через Map/Set за O(nodes+guests).
Dashboard: constant bulk queries, `dashboard.proxmox` содержит connections,
nodes, vm/lxc total/running/stopped. Legacy Infrastructure list сохранён,
placeholder PVE/Servers/LXC подпись заменена реальными PVE/VM/LXC.
Sidebar ведёт на /proxmox, фиктивные PVE/containers counts и WG OK убраны.

Totals: enabled connections в enabled Location; nodes/guests stale исключены.
Templates исключены из guest totals и running/stopped, но видны в таблице.
Running/stopped считаются по cached inventory только для online connection,
которую не блокирует Location. При unavailable totals сохраняются, running/stopped
обнуляются (это unknown, не автоматически stopped). Node count — inventory count,
не число online nodes. Недавность snapshot видна на странице.

## Auth и read-only scope

Все шесть routes под web/auth, mutations под стандартным Laravel 13
PreventRequestForgery (CSRF/origin verification). Публичного API нет.
Модель доступа общая для authenticated пользователей, как существующая система.

Единственные outbound verbs — GET. Нет SSH, pvesh, password auth, scraping,
start/stop/reboot/migrate/snapshot/delete/backup/storage mutations.
Удаление в Lavss Monitor не меняет Proxmox.

## Тесты и изоляция

Http::fake / preventStrayRequests; backend SQLite :memory:, array cache/session,
sync queue. Docker image pull_policy=never, network_mode:none, пустой .env,
отдельные временные volumes vendor/storage/bootstrap cache.
Frontend использует локальный node image, --network none и временный build volume.

Проверяются API header/TLS/timeouts/errors/payloads, encrypted storage,
old input/JSON omission, CRUD/auth/CSRF, inventory/moves/templates/stale,
rollback/revision/Location gates, bounded page query count, Dashboard,
scheduler, SQLite constraints и offline PostgreSQL DDL.
Frontend SSR tests проверяют render/grouping, secret initialization/clear,
nullable metrics; прежние polling/WG tests сохранены.
Итог: 652 backend tests / 3971 assertions (baseline 555 / 3573; +97 tests / +398 assertions).
Targeted: 99 / 400, включая два существующих LocalDevice cases с типом proxmox.
Frontend: 15/15, TypeScript и build прошли.
Точные команды и окончательные результаты: /tmp/stage-3.8-report.txt.

## Ручная приёмка после review

1. Отдельно согласовать и применить production migrations, проверить APP_KEY/backup.
2. Создать read-only API user/token PVE (например PVEAuditor на / с propagation).
   При privilege separation проверить права и user, и token: видимость должна
   включать все ожидаемые nodes/VM/LXC. Не использовать root password.
3. В существующей Location «Дом» добавить отдельную connection «PVE Home»,
   указать фактический host/API port, user@realm, token ID и secret.
   LocalDevice «PVE Home» оставить. Адреса acceptance не зашиты в defaults.
4. Test: online/version, inventory не меняется; проверить TLS verification.
5. Sync: реальные nodes/VM/LXC, node association, running/stopped/paused,
   template badge и raw CPU/RAM/uptime сравнить с PVE.
6. Проверить Dashboard totals/running и поведение templates.
7. Отозвать token: auth/unknown, inventory остаётся, secret нигде не выводится.
8. Восстановить/заменить token, проверить пустой secret при обычном update.
9. Сделать Location unavailable: no API calls, unknown с причиной площадки,
   no stale timestamp changes и false auth errors. Восстановить Location и sync.
10. Проверить move/stale/reappearance, mobile/desktop UI, scheduler cadence,
    PostgreSQL locking и удаление только локального cache.
11. Проверить HTML/Inertia payload, session/errors/logs на отсутствие secret.

## Ограничения и следующий этап

monitor_checks, MonitorCheckRecorder/Statistics и NotificationPolicyService
не расширяются: inventory metrics не являются connectivity history/uptime.
Stage 3.8.1 — connection/node/guest incidents, confirmation/correlation/MAX,
history и uptime. Сейчас нет per-guest notifications, CPU/RAM thresholds,
storage/backup/Ceph/HA/quorum/replication alerts, task logs, guest agent/IP discovery,
SNMP, SSH, Prometheus и управления гостями.
