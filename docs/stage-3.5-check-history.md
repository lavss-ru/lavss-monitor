# Stage 3.5 — история проверок и uptime

Реализация подготовлена для review в `stage-3.5`, base `0dbaf2a`. Production migration/deploy не выполнялись.

## Данные и срок хранения

`monitor_checks`: одна строка на фактически вызванный health checker. Поля:

| Поле | Семантика |
| --- | --- |
| id | bigint primary key |
| monitor_type | vps / website / local_device |
| monitor_id | bigint, без foreign key |
| origin | scheduled / manual / manual_batch |
| status | online / offline / unknown |
| checked_at | timestamp завершения checker, обычная timezone/секундная точность Laravel |
| response_ms | nullable integer |
| http_status | nullable small integer; только Website |

Допустимые значения проверяет recorder; migration использует Laravel enum, который для PostgreSQL/SQLite создаёт строковое поле с CHECK constraint. Модель имеет immutable datetime cast, timestamps отключены. Код только добавляет samples и удаляет просроченные; обновления результатов, MAX/incident state, Event IDs и секретов нет. DB trigger для запрета UPDATE не вводится.

Удаление монитора оставляет orphan samples до retention cleanup. List pages запрашивают только IDs существующих объектов. При изменении host/URL история того же ID сохраняется; это uptime объекта за окно, которое может включать его прежний endpoint. Версионирование конфигурации пока не реализовано.

**История и uptime начинают накапливаться после установки Stage 3.5.** Backfill из статусов, Events или логов отсутствует.

## Путь записи

Commands/endpoints явно передают origin. `scheduled` — scheduler command, `manual` — кнопка одного объекта, `manual_batch` — check-all. Origin обязателен в API services, без default; все callers/tests передают его явно.

Каждый monitoring service вызывает `MonitorCheckRecorder::observe`, передавая операцию monitoring. Observer предоставляет callback, которым обёрнут ровно фактический `healthCheck->check`. Он запоминает sample в памяти. `finally` записывает его после завершения monitoring operation, включая закрытие Website/LocalDevice transaction. Low-level checkers не записывают историю повторно и не изменены.

VPS сохраняет прежние transition Events/MAX. Website сохраняет aggregate lock, 10-minute confirmation и aggregate fingerprint logic. LocalDevice сохраняет row lock, проверку effective enabled, 2-minute confirmation, MAX retry/recovery_pending и diagnostic semantics. Disabled/paused объекты, пропущенные до checker, не создают samples. Admin edits, resets, Events/MAX retry и загрузка страниц не являются checks.

Unexpected checker exception создаёт UNKNOWN с NULL metrics; исходное исключение продолжает передаваться прежнему caller. Website/LocalDevice сохраняют прежний factual unknown handling, VPS — прежнее распространение ошибки. Ошибка incident/notification после успешного checker не меняет измеренный sample на UNKNOWN.

History INSERT выполняется в собственной короткой transaction (при внешней transaction — savepoint). SQL/recorder errors передаются `report()` и не меняют factual health, Events или MAX. Нет retry INSERT и нет повторного health check. История best-effort: сбой БД или аварийное завершение процесса между check и INSERT может оставить пропуск. Если будущий caller обернёт весь monitoring в свою transaction, rollback этого caller также откатит sample; существующие production entry points этого не делают.

## Статистика

`MonitorCheckStatisticsService::forMonitors(type, ids)` возвращает map ID → `24h`, `7d`, `30d`. Только `origin = scheduled`, `checked_at >= cutoff` и `checked_at <= now`. Все три cutoffs вычисляются из одного now, с обычной секундной точностью записей. Граница включена; будущие samples исключены.

`uptime_percent = online / (online + offline) * 100`, округление до двух знаков, numeric JSON. Если denominator нулевой — NULL. UNKNOWN считается отдельно. Manual/manual_batch не входят ни в uptime, ни в latency.

Каждый период содержит `online_count`, `offline_count`, `unknown_count`, `measured_count`, `total_count`, `uptime_percent`, `average_response_ms`. Average — AVG только ONLINE scheduled response_ms IS NOT NULL; 0 ms является допустимым измерением и включается. Offline timeout не является online latency.

Это доля успешных **имеющихся check samples**, не time-weighted SLA и не доказательство полной истории за 30 дней. Пропущенные scheduler ticks не синтезируются в DOWN/UNKNOWN.

## SQL и производительность

Индексы: `(monitor_type, monitor_id, origin, checked_at)` для statistics и `(checked_at)` для retention; дополнительно только primary key. Один INSERT на check attempt, ноль на skip. Без индексов status/origin отдельно.

Один GROUP BY monitor_id с условными SUM(CASE...) / AVG(CASE...) считает сразу три окна для всех IDs данного type. SQL переносим на SQLite/PostgreSQL, история не загружается в PHP. Память PHP пропорциональна числу мониторов.

Каждая непустая list page выполняет ровно **один aggregation query**: VPS, Websites, Local Infrastructure. Пустой набор — ноль. Local Infrastructure `localDeviceStats` — отдельный closure prop. `locationsPolling.ts` по-прежнему запрашивает только `locations` каждые 15 секунд: **ноль statistics queries**. Обычный full visit обновляет statistics.

За 30 дней один VPS/LocalDevice даёт примерно 43 200 scheduled rows, Website — 8 640. Aggregation всё ещё читает подходящие samples за 30 дней: для большого количества объектов full visit требует измерения latency/EXPLAIN на PostgreSQL. Материализованные rollups/cache/partitioning сейчас не добавляются. После удаления строк PostgreSQL autovacuum должен обслуживать dead tuples; размер файла таблицы не обязан немедленно уменьшаться.

## Retention

`php artisan monitor:prune-check-history` фиксирует cutoff `now - 30 days`, удаляет только `checked_at < cutoff`. Ровно 30 дней остаются. Повторяет SELECT старых IDs с LIMIT 2000 и DELETE WHERE id IN (...), без OFFSET и общей долгой transaction. Выводит `Deleted N check history rows.`, SUCCESS даже при N=0.

30 дней и batch 2000 — constants команды; отдельные Settings/config/.env не нужны для фиксированного этапа. Scheduler: ежедневно 03:15 application timezone, `withoutOverlapping(120)`, foreground. Существующие monitoring schedules не изменены. Физически просроченные строки могут оставаться до следующего ежедневного cleanup; statistics уже исключает всё старше 30 дней.

## UI и проверки

Общий `MonitorUptime` показывает `24ч / 7д / 30д`, NULL → `—`, 100 → `100%`, максимум два знака. Видна подпись «по имеющимся автопроверкам»; title/accessible label содержит measured/unknown counts и объяснение неполного покрытия. Raw samples и HTTP distribution не передаются.

Тесты используют SQLite :memory:, frozen time, mocked TCP/checkers/HTTP, network_mode none и пустые MAX credentials в контейнере (отдельные существующие MAX tests используют только fake credentials и HTTP mocks). Проверены origins/statuses всех типов, multiple batches, границы, формула, source isolation, один aggregation на страницу и ноль при polling, failed INSERT и сохранение incidents. Production PostgreSQL/browser acceptance остаётся отдельным ручным шагом после review.

## Ручная приёмка после review

1. Проверить migration на PostgreSQL, schema/CHECK constraints/indexes; применить только после отдельного решения владельца.
2. Убедиться, что прежние scheduler jobs продолжают работать и дают по одному sample с scheduled.
3. Проверить single manual/manual_batch origins и отсутствие их влияния на uptime.
4. Проверить UNKNOWN/NULL и UI без истории, затем появление процентов на трёх страницах.
5. Проверить Local Infrastructure polling, forms и scroll; в Network только locations, в SQL логах polling нет aggregate query.
6. Проверить disabled device/location и ручную диагностику.
7. Для pruning сначала read-only inspect count/min checked_at старше cutoff и план индекса, затем отдельно запустить command. Команда не имеет dry-run флага.
8. Проверить прежнее MAX incident/recovery поведение без отправки искусственных сообщений из этой сессии.
9. Оценить latency/EXPLAIN statistics на реальном объёме и autovacuum после retention.

## Будущее

Общая таблица и batch statistics — основа detail pages, графиков и incident analytics. Сейчас не добавлены detail pages, charts/sparklines, raw history UI, CSV, SLA/custom retention settings, новые notifications, Dashboard aggregate uptime или новые checker protocols.
