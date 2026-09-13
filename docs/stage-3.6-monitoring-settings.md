# Stage 3.6 — Monitoring & Notification Settings

Статус: реализация подготовлена для review в `stage-3.6`. Production migration и deploy не выполнялись.

## Интерфейс и доступ

`GET /settings/notifications` — русская страница Inertia; `PUT /settings/notifications` — сохранение.
Оба маршрута находятся под `auth`, штатная web/CSRF middleware сохранена.
Разделы: Общие, MAX, Тихие часы, Правила VPS, Правила сайтов, Правила локальной инфраструктуры.
Настройки доступны из Sidebar. Есть ошибки полей, состояние сохранения, блокировка повторного submit и сообщение об успехе.

Единственный канал — MAX. В UI доступны общий выключатель, MAX выключатель и один получатель.
Токен берётся только из `config('services.max.bot_token')` / существующего `MAX_BOT_TOKEN`.
Он не является полем БД, не входит в Inertia props и не сохраняется из запроса. Интерфейс показывает только статус конфигурации.

## Schema и defaults

Миграция `2026_09_13_000000_create_notification_settings.php` создаёт:

| Таблица / поле | Default / назначение |
| --- | --- |
| notification_settings.id | singleton, id = 1 |
| notifications_enabled | true |
| max_enabled | true |
| max_recipient_id | nullable string(255), null |
| timezone | Europe/Moscow |
| quiet_hours_enabled | false |
| quiet_hours_start / quiet_hours_end | nullable time, null |
| notification_rules.monitor_type | unique enum: vps / website / local_device |
| down_enabled / recovery_enabled | true для каждого типа |
| confirmation_seconds | VPS 0, Website 600, LocalDevice 120 |

Обе таблицы имеют timestamps. Миграция заполняет singleton и три правила.
`2026_09_13_000001_add_vps_incident_state.php` добавляет nullable timestamps к VPS:
`failure_started_at`, `incident_confirmed_at`, `incident_notified_at`, `recovery_pending_at`.
Данные о доставке старых VPS не выдумываются и не backfill-ятся из Events.
Если старый offline VPS остаётся offline, он может получить текущий DOWN после подтверждения;
если он сразу восстановился, остаётся recovery Event, но MAX Recovery без delivery evidence не отправляется.
Миграции обратимы; rollback уничтожает новые настройки/состояние доставки. Проверены только на SQLite :memory:.
Stage 3.5 schema и retention не менялись.

## Validation и atomic save

Допускаются IANA timezone из `timezone_identifiers_list()`, HH:MM, целая задержка 0..86400,
Laravel boolean значения (true/false, 0/1, "0"/"1"), nullable строка получателя до 255 символов.
При включённых тихих часах оба времени обязательны и не могут совпадать.
Полный набор трёх правил обязателен; неизвестные ключи внутри rules запрещены.
Settings и rules сохраняются в одной DB transaction: сначала singleton, затем три upsert правил.
Ошибка в любом правиле откатывает всё сохранение; это проверяется SQLite trigger, принудительно ломающим второе правило.

## NotificationPolicyService и live updates

Policy — неизменяемый снимок операции без static cache / singleton cache.
На входе в каждую monitoring command / batch загружается новый снимок, который передаётся всем checks и website aggregate.
Одиночные проверки загружают policy при вызове. Повторное использование объекта сервиса или команды не сохраняет старую policy.
Поэтому сохранение применяется со следующего monitoring cycle без restart `schedule:work`.
Текущая пачка завершается на своём снимке.

Чтение settings + rules выполняется в короткой transaction с shared lock singleton.
Поскольку writer первым обновляет singleton, чтение не смешивает старые settings с новыми rules
при PostgreSQL READ COMMITTED. Lock отпускается до health checks / MAX HTTP.
Конкуренция PostgreSQL не запускалась: все тесты изолированы в SQLite.

Получатель: непустой `max_recipient_id` после trim, иначе существующий `config('services.max.user_id')` / `MAX_USER_ID`.
Он URL-encoded при формировании одного `user_id` параметра. При отсутствии токена или получателя HTTP не вызывается.
Смена ENV credentials подчиняется существующему Laravel config lifecycle; обещание live update относится к настройкам БД.

## Quiet hours и timezone

Quiet interval: начало включительно, конец исключительно. Для 09:00–17:00 блокируется `[09:00,17:00)`;
для 22:00–07:00 — время >=22:00 либо <07:00. Сравнивается локальное время выбранной IANA зоны.
На осеннем переводе часов повторяющееся время также входит в интервал; на весеннем пропущенное время не возникает.
Время в VPS/LocalDevice сообщениях отображается в выбранной зоне; timestamp хранения и интервалы подтверждения не переводятся.
Тихие часы не останавливают мониторинг или Events/history.

## Policy semantics и correlation

| Условие | Поведение |
| --- | --- |
| global off / MAX off / quiet hours | deferred; HTTP не вызывается |
| down_enabled false | suppressed; HTTP не вызывается; текущий продолжающийся подтверждённый DOWN может отправиться после включения |
| recovery_enabled false | terminal suppression; pending Recovery очищается и позже не воспроизводится |
| всё разрешено | попытка доставки только по актуальному состоянию инцидента |
| HTTP / application error / exception | доставка не отмечается, retry следующего цикла |

Events создаются при подтверждении и восстановлении независимо от policy; каждый выполненный health check остаётся в `monitor_checks`.
Задержка измеряется от первого наблюдаемого failure; подтверждение на первой offline проверке с elapsed >= delay.
Изменение delay влияет на ещё не подтверждённый инцидент; подтверждённый инцидент обратно в grace не переводится.

VPS и LocalDevice сначала сохраняют factual state и Events. Затем повторно блокируют запись и проверяют её актуальное состояние для MAX.
`incident_notified_at` выставляется только после успешного DOWN. Recovery pending создаётся только при наличии этой delivery evidence.
В тихие часы/при выключенных global/MAX pending сохраняется; при новой недоступности stale pending green удаляется.
Если подавленный/не доставленный DOWN закончился до разрешения доставки, не будет ни старого красного, ни orphan green.
Recovery Event при этом остаётся.
Успешная доставка не повторяется на стабильных циклах. Transport failure не создаёт повторный Event.
HTTP не оборачивается в автоматический transaction retry, поэтому внутри одного вызова скрытых повторов нет.

Website сохраняет существующую aggregate модель:
первый DOWN требует хотя бы одного confirmed offline сайта, сообщение содержит весь текущий offline список, включая grace members.
Fingerprint основан на ID+URL, без elapsed/HTTP code/confirmation. Изменения списка дают update;
подтверждение grace member без изменения списка не дублирует сообщение.
Policy проверяется до transport; snapshot/published_at обновляются только после успешной отправки.
Aggregate green возможен только после опубликованного red и успешного полного подтверждения online состояния enabled сайтов.
Unknown/неполная batch не разрешает green. Пустой monitored set сбрасывает aggregate без ложного Recovery.
Подавленные промежуточные списки не воспроизводятся: после quiet публикуется актуальный список.

MAX сохраняет Authorization header, существующий HTTPS URL и TLS verification, timeout 5 секунд.
Успех требует 2xx без явного application error (`success=false`, `error`, `code`); теперь это одинаково для VPS и остальных типов.
Policy suppression/defer не вызывает transport и не логируется как transport failure.

## Проверки

Baseline пользователя: 337 passed / 2328 assertions.
Финальный полный suite: **448 passed / 2808 assertions** (111 дополнительных тестовых случаев).
Три старых fixture/expectation обновлены согласно Stage 3.6: delivered VPS DOWN явно задан для dedup,
legacy VPS и LocalDevice Recovery без доставленного DOWN больше не ожидают orphan green. Events assertions сохранены.

Новые тесты: `NotificationSettingsTest.php`, `NotificationPolicyMonitoringTest.php`.
Покрыты defaults, 0/1/37/86400 и точные границы; ordinary/cross-midnight/timezone/DST quiet;
quiet/transient/persistent DOWN; deferred Recovery; global/MAX/down/recovery выключатели;
live updates на том же сервисе и объекте команды; recipient fallback/override; rollback/validation/auth;
history при различных policy; HTTP/application/exception retries; latest aggregate membership и grace semantics.
Полный suite дополнительно проверяет существующие Website aggregate, LocalDevice, Stage 3.5 history/uptime и TLS/auth сценарии.

Изоляция: SQLite :memory:, array cache/session, sync queue, пустой .env mount, пустые реальные MAX credentials,
`Http::fake` и mocked health checks, Docker `network_mode: none`, без PostgreSQL, Redis, Internet и реальных targets.
Использованы уже существующие локальные test/node images, без pull/download.

Точные финальные команды из worktree `/home/lavs/lavss-monitor-stage-3.6`:

```bash
docker compose --env-file /dev/null -p lavss-stage36-tests -f /tmp/stage-3.6-test.compose.yml run --rm --no-deps test php artisan test > /tmp/stage-3.6-full.log 2>&1

docker run --rm --network none --entrypoint sh -v /home/lavs/lavss-monitor-stage-3.6:/var/www/html -v /var/www/html/node_modules -v /tmp/stage-3.6-empty.env:/var/www/html/.env:ro lavss-monitor-node:latest -c 'node --test tests/frontend/locationsPolling.test.mjs && node_modules/.bin/tsc --noEmit && npm run build' > /tmp/stage-3.6-frontend.log 2>&1
```

Frontend polling: **5 passed / 0 failed**. `tsc --noEmit`: passed. `npm run build`: passed, Vite build 8.42s.
PHP изменённых файлов отформатирован Pint. Полный отчёт и git checks: `/tmp/stage-3.6-report.txt`.

## Ограничения и manual acceptance

Абсолютный exactly-once для внешнего HTTP недостижим без MAX idempotency: MAX мог принять запрос перед timeout,
или процесс мог завершиться после HTTP success, но до записи delivery marker. В этих случаях retry потенциально дублирует сообщение.
DB locks защищают от штатных параллельных попыток, но не устраняют этот внешний crash window.
Корреляция хранится на уровне инцидента/aggregate; изменение получателя направляет следующие разрешённые сообщения новому получателю.
Реальные PostgreSQL lock semantics и визуальный браузерный smoke не проверялись в этом offline прогоне.

План ручной приёмки в отдельно разрешённом тестовом окружении:

1. Применить две миграции; открыть `/settings/notifications` под авторизованным пользователем и проверить redirect без сессии.
2. Проверить шесть русских секций на desktop/mobile, меню, клавиатурный доступ, inline errors, сохранение и повторную загрузку значений.
3. Проверить IANA timezone, пустые/одинаковые quiet times, delay -1/86401, допустимые 0/86400; убедиться в отсутствии частичных изменений.
4. На тестовых targets и тестовом MAX получателе проверить VPS default immediate, Website 600s, LocalDevice 120s.
5. Включить quiet и получить persistent/transient incidents; после окончания проверить актуальный DOWN либо отсутствие stale red/green.
6. Доставить DOWN, восстановить в quiet, затем проверить единственный Recovery после quiet; отдельно выключить recovery rule.
7. Без restart schedule:work изменить delay/toggles/recipient; проверить следующую batch, fallback при очистке recipient, Events и history во всех состояниях.
8. Симулировать MAX failure и восстановление transport; проверить один retry за цикл, dedup после успеха и отсутствие повторных Events.
9. Проверить website aggregate с confirmed+grace members, сменой списка, unknown и восстановлением всех enabled сайтов.

Это план, реальные migrations/MAX/targets/deploy в рамках Stage 3.6 не выполнялись.
