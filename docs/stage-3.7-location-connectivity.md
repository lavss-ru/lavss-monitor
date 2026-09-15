# Stage 3.7 — Location connectivity / WireGuard awareness

Реализация для review в `stage-3.7`, база `9347f3c`. Production migration,
deploy и ручная проверка настоящих площадок в этом этапе не выполняются.

## Архитектура и defaults

Location получает собственные `LocationHealthCheckService` и
`LocationMonitoringService`. Первый выполняет один TCP connect с timeout 3 секунды,
второй сохраняет состояние, подтверждает инцидент, создаёт Event и отдельно
пытается доставить MAX. Используются существующие `NotificationPolicyService`,
`MaxNotifier`, `MonitorCheckRecorder` и `MonitorCheckStatisticsService`.

Новые typed поля:

| Поля | Назначение / default |
| --- | --- |
| monitoring_enabled | false; собственная проверка площадки включается явно |
| status | online / offline / unknown; default unknown |
| probe_type | tcp, единственный поддержанный протокол |
| probe_host / probe_port | nullable hostname/IPv4/IPv6 и порт 1..65535 |
| last_checked_at / last_response_ms | время последнего измерения и успешного соединения |
| failure_started_at | начало наблюдаемого сбоя |
| incident_confirmed_at | подтверждение сбоя |
| incident_notified_at | свидетельство успешной доставки DOWN |
| recovery_pending_at | ожидающая доставки коррелированная Recovery |
| wireguard_interface | nullable, до 15 символов |
| wireguard_peer_public_key | nullable, канонический base64 публичного ключа из 32 байт |
| wireguard_diagnostic_state | unknown / never / fresh / stale, default unknown |
| wireguard_last_handshake_at | nullable время handshake выбранного peer |
| wireguard_rx_bytes / wireguard_tx_bytes | nullable счётчики выбранного peer |

`enabled` сохраняет прежний смысл: выключение площадки приостанавливает всех детей.
`monitoring_enabled` включает только собственный connectivity probe. У существующих
площадок он выключен, поэтому «Дом» и прежние WireGuard-площадки продолжают проверять
устройства как раньше. Наличие WG metadata само по себе не включает мониторинг.
При включении собственного мониторинга для любого connection_type обязательны
оба поля TCP endpoint. Local-площадка не требует WG metadata.

## State machine

Успешный TCP connect даёт online. Выполненный неуспешный connect даёт offline сразу,
ещё до подтверждения. Исключение checker даёт unknown и history sample unknown;
неподтверждённый таймер сбрасывается, подтверждённый инцидент сохраняется.
Непроверяемая площадка остаётся unknown, а пропуск не создаёт history.

Первый offline начинает `failure_started_at`. Подтверждение происходит на первой
последующей offline-проверке с elapsed >= `location.confirmation_seconds`.
Default 120 секунд; разрешён диапазон 0..86400. Значение 0 подтверждает первую
неуспешную проверку. Стабильный offline не создаёт повторных Events.
Unknown не считается непрерывным доказанным сбоем.

Online после grace тихо очищает таймер. Online после confirmed incident создаёт
один info Event и разрешает warning Event. MAX Recovery становится pending только
при наличии успешного DOWN. Unknown после confirmed offline сохраняет инцидент
до следующего фактического результата, не создавая Recovery.

Изменение enabled, monitoring_enabled, connection_type, probe_type, endpoint или WG
metadata тихо сбрасывает состояние Location и диагностику, разрешает её warnings.
Переименование и описание инцидент не сбрасывают. Выключение enabled также сохраняет
прежний тихий reset детей. Удаление площадки с детьми запрещено; удаление пустой
площадки разрешает её warnings. Нет очередей retry, которые переживают удаление.

## Дочерние устройства

| Состояние контролируемой площадки | Проверки детей | Инциденты детей |
| --- | --- | --- |
| online | выполняются | обычная state machine |
| offline, grace | выполняются, фактические samples сохраняются | переходы и MAX заморожены |
| offline, confirmed | пропускаются | status unknown, без новых таймеров, Events и MAX |
| unknown | пропускаются консервативно | то же, без утверждения о DOWN площадки |
| собственный monitoring выключен | прежние проверки | прежняя state machine |
| enabled выключен | batch пропускает | прежний silent pause/reset |

Неподтверждённые child таймеры сбрасываются также при проверках в grace площадки,
чтобы не переносить недоказанный непрерывный сбой через этот интервал.
Заморозка переходов уже в grace преднамеренна: child confirmation может быть 0,
а Location delay 120 или больше. Без этого дети успели бы создать каскад раньше
подтверждения площадки. Сетевые измерения в grace продолжаются; uptime отражает
их фактические результаты. Подтверждённое отключение площадки прекращает эти попытки.

При пропуске `last_checked_at` ребёнка не переносится на текущее время. Response
очищается, unconfirmed failure timer сбрасывается. Confirmed timestamps,
delivery evidence, pending recovery и существующий warning Event сохраняются.
После восстановления площадки ребёнок снова проверяется: реальный online завершает
старый инцидент, реальный offline продолжает подтверждённый или начинает новый grace.
Нет искусственного Recovery/повторного DOWN за интервал без child measurement.

UI и Dashboard дополнительно выводят derived unknown из текущей Location, даже
если после одиночной проверки площадки batch детей ещё не выполнялся. Это
преобразование ответа не записывает новые факты в БД. Подпись различает
«Площадка недоступна» и «Доступность площадки неизвестна».

## Scheduler, manual и блокировки

Существующая `monitor:local-devices` остаётся раз в минуту с прежним
withoutOverlapping. Внутри сначала выполняются все включённые Location probes,
затем дети. Новый отдельный scheduler job не добавлен. `checked` теперь считает
все фактически выполненные Location + LocalDevice probes, `errors` — ошибки
обеих групп. MAX transport errors не останавливают проверки.

Один новый immutable policy snapshot загружается на batch и передаётся всем
площадкам и устройствам. Settings/rules перечитываются на следующем cycle, в том
числе при повторном использовании того же объекта сервиса. Static cache нет.

`POST /locations/{location}/check` — одиночная проверка, origin manual.
Выключенная/ненастроенная площадка пропускается. Кнопка «Проверить включённые»
использует прежний `/local-devices/check-all`, origin manual_batch, с новым порядком.
Одиночный manual child check сначала проверяет его Location, затем уважает
suppression; оба фактических samples имеют origin manual. Все маршруты под auth/CSRF.

Location monitor блокирует свою строку. Child monitor и MAX delivery блокируют
сначала device, затем берут shared lock Location. Location CRUD сохраняет порядок
device-first → location. Location monitor не берёт device locks, что избегает
обратного порядка. MAX отправляется после сохранения фактов, с повторной проверкой
актуального состояния под блокировкой и без автоматического transaction retry.
Настоящая PostgreSQL конкурентность требует отдельной приёмки.

## MAX и Settings Stage 3.6

`notification_rules.monitor_type=location`: down_enabled=true,
recovery_enabled=true, confirmation_seconds=120. Settings UI содержит отдельные
«Правила площадок». Сохранение требует все четыре typed правила; неизвестные
ключи запрещены. Отдельная JSON-система settings не добавляется.

Global off / MAX off / quiet hours откладывают доставку, но не Events/history.
После разрешения доставляется только актуальный продолжающийся DOWN либо pending
Recovery. down_enabled=false не доставляет DOWN; включение может разрешить ещё
продолжающийся инцидент. recovery_enabled=false терминально подавляет Recovery.
Новая недоступность удаляет obsolete pending green. HTTP/application error/exception
оставляет попытку retryable, без нового Event. Orphan green без доставленного red нет.

Сообщения содержат имя площадки, тип подключения, контрольный endpoint и время в
настроенном notification timezone. Тихие часы используют прежнюю Stage 3.6 логику,
включая переход через полночь. Токен берётся из существующей конфигурации MAX.
Exactly-once через внешний HTTP не гарантируется: timeout после принятия запроса
или crash до сохранения delivery marker может привести к повторной доставке.

## WireGuard диагностика и безопасность

Исполняются только массивы аргументов Laravel Process API:

```text
['wg', 'show', validated_interface, 'latest-handshakes']
['wg', 'show', validated_interface, 'transfer']
```

Каждый процесс имеет timeout 2 секунды. Shell interpolation, sudo и mutation
commands отсутствуют. `dump` не используется: его вывод содержит ключевой материал.
Private keys и preshared keys не запрашиваются, не хранятся и не показываются.
Interface допускает `^[A-Za-z0-9_.-]{1,15}$` с дополнительным запретом начального `-`.
Hostname проверяется существующим TcpHost, IPv6 передаётся в bracketed TCP address.

При одном peer он выбирается автоматически, при нескольких требуется явный
публичный ключ. Неизвестный peer, неправильный вывод, отсутствие интерфейса,
нет прав, нет wg binary или ошибка процесса дают diagnostic unknown.
Timestamp 0 — never, возраст до 180 секунд — fresh, больше — stale.
Ни stale, ни diagnostic unknown не меняют результат connectivity probe.
RX/TX — накопительные счётчики, не скорость. Две команды не образуют атомарный snapshot.

WG diagnostics — необязательные диагностические metadata. Единственный authoritative
source connectivity — TCP probe; diagnostic unknown не является неисправностью
Location и не влияет на status, Events, MAX или child suppression.

По ручной проверке владельца production host содержит `/usr/bin/wg` и видит
`wg-shki`, но APP и SCHEDULER контейнеры не содержат wg binary и не видят этот
host interface. Текущий production deployment не предоставляет контейнерам host
WG namespace, поэтому diagnostic unknown в нём ожидаем и нормален.

UI скрывает весь WG diagnostic блок, если interface не настроен. При настроенном
interface и unknown показывает нейтральное «WG-диагностика недоступна.
Доступность площадки определяется TCP-проверкой.» без красного alarm оформления.
Старые handshake/counters при unavailable не показываются.

Диагностика видит интерфейсы и права окружения процесса приложения. В обычном
Docker namespace host WG может быть невидим: UI покажет unknown, TCP продолжит
работать при настроенной внешним администратором маршрутизации. Этап не устанавливает
wg, не расширяет привилегии контейнера и не меняет host networking.

## History, uptime и миграции

Каждая настоящая Location TCP attempt даёт ровно одну `monitor_checks` строку:
location + ID + scheduled/manual/manual_batch + online/offline/unknown.
WG inspection не создаёт отдельные строки. Пропущенные дети и площадки не создают
фиктивные samples. Unknown response_ms null; неуспешный TCP response_ms null по
существующей TCP семантике. HTTP status для Location всегда null.

Uptime за 24ч/7д/30д считается существующим bulk aggregation только по scheduled:
online/(online+offline), unknown исключается. Нет измерений — «—».
На полной странице два bulk statistics запроса: один по всем device IDs и один
по всем location IDs. Locations-only 15s polling не делает statistics queries,
сохраняет форму/scroll и обновляет Location/device statuses. Uptime обновляется
при полной загрузке, как существующая Stage 3.5 статистика устройств.
Retention использует прежнюю 30-day команду без изменений.

Миграции:

1. `2026_09_14_000000_add_location_monitoring_state.php` — typed поля Location.
2. `2026_09_14_000001_extend_location_monitor_types.php` — monitor_checks и
   notification_rules принимают location; вставляется правило с default 120.

PostgreSQL enum Laravel фактически varchar + CHECK. Миграция явно заменяет
`monitor_checks_monitor_type_check` и `notification_rules_monitor_type_check`,
сохраняя прежние типы. Не используется PostgreSQL native ENUM ALTER TYPE.
SQLite ветка использует Laravel enum change с rebuild таблиц и явно повторно
объявляет CHECK для origin/status: introspection иначе теряет ограничения
неизменённых enum колонок. Это отдельно покрыто regression tests. Tests проверяют
данные, indexes, unique и CHECK после rollback/up. Исходные миграции не меняются.
Rollback удаляет только новые location samples/rule перед сужением CHECK, затем
удаляет Location monitoring columns; эта потеря новых данных неизбежна для старой schema.

На PostgreSQL ALTER CHECK требует table locks и проверки имеющихся строк; для
большой history нужен согласованный deployment window. Реальное исполнение,
имена constraints на установленной БД и время блокировки проверяются отдельно.
Offline SQL-generation тест не заменяет миграционную приёмку PostgreSQL.

## Dashboard и интерфейс

Карточка «Инфраструктура» включает реальные активные площадки и сохраняет прежние
Infrastructure/Proxmox/Server/LXC счётчики. Подпись показывает online/offline/unknown
площадок и число устройств. `dashboard.locations` и `dashboard.localDevices`
содержат раздельные status counters; total относится к enabled объектам.
Существующие attention/recent Events принимают location как инфраструктурный source.

Location header показывает статус, grace/confirmed, endpoint, время проверки,
response, uptime, а для WireGuard — interface, diagnostic state, handshake age,
RX/TX. Формы позволяют включить собственный probe отдельно от всей площадки.
Настройки и формы редактирования не сбрасываются фоновым 15s polling.

## Автоматические проверки

Итог: **555 passed / 3573 assertions**, baseline 448 / 2808. Дополнительно 107
тестовых случаев. Targeted Location/WG/policy suite: **181 passed / 1104 assertions**.
Frontend polling: **5/5**, WG UI render contract: **4/4**, `tsc --noEmit` и `npm run build` прошли. Build-артефакты
изолированы во временном Docker volume, удаляемом вместе с test container. `git diff --check` чистый.

Использованы локальные Docker images без pull, `network_mode: none`, SQLite
`:memory:`, array cache/session, sync queue, пустой `.env` mount, fake MAX credentials
только внутри тестов. TCP transport замокан, Http::fake/Process::fake исключают
реальные вызовы. PostgreSQL проверяется только генерацией SQL с PDO mock, который
не может prepare/exec; реального соединения нет. Браузерная визуальная приёмка
и PostgreSQL concurrency/migration acceptance не выполнялись.

Точные команды и полный результат сохранены в `/tmp/stage-3.7-report.txt`.

## Ручная приёмка после review

Только после отдельного разрешения владельца:

1. Проверить и применить миграции на целевой PostgreSQL, убедиться в constraints/indexes.
2. Для ШКИ включить Location monitoring; выбрать действительно открытый TCP сервис
   внутри VPN, например на WG IP MikroTik `192.168.180.1`, и его реальный порт.
   Указать диагностический `wg-shki`, при необходимости public peer key.
   Значения acceptance не входят в runtime defaults приложения.
3. Убедиться: площадка online, Eltex/MikroTik/Acronis получают фактические checks.
4. Администратор ОС отключает tunnel/peer: сначала grace, затем confirmed offline,
   один Location warning, один MAX DOWN; children unknown без каскада.
5. Пока tunnel отсутствует, проверить отсутствие новых child checks/Events/MAX.
   Предварительно существующий device incident должен оставаться сохранённым.
6. Администратор восстанавливает VPN: один Location recovery; дети проверяются
   снова, реально неисправный ребёнок продолжает/начинает собственный инцидент.
7. Проверить scheduled/manual/manual_batch history, uptime и отсутствие fake rows.
8. На работающем schedule:work изменить Location delay/toggles, quiet hours,
   убедиться в применении со следующего cycle и red/green correlation.
9. Проверить desktop/mobile, открытые edit forms во время polling, errors,
   disabled/local площадку и Dashboard counters. Сравнить diagnostic namespace
   с размещением wg-shki; diagnostic unknown сам по себе не является offline.

## Ограничения и исключённый scope

Один TCP endpoint — свидетельство доступности выбранного сервиса, а не доказательство
исправности всех маршрутов площадки. Его собственный отказ тоже подавит детей;
нужен надёжный контрольный сервис. DNS resolution подчиняется системному resolver и
может занимать дольше socket timeout; IP устраняет эту зависимость. Schedule cadence,
число endpoints, TCP/WG/MAX timeouts влияют на реальную задержку обнаружения.

Нет WireGuard configuration/key provisioning, route/firewall management, topology
discovery, SNMP, Proxmox API, VM/LXC inventory, multi-probe, agents, email/Telegram/
webhooks, maintenance windows и escalation. Реальные targets, MAX, WireGuard,
PostgreSQL и Redis в автоматических тестах не используются.
