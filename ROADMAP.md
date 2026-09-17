# ROADMAP — lavss monitor

## Текущие этапы monitoring

* **Stage 3.4 — Local Infrastructure Monitoring:** Location + LocalDevice, TCP проверки, подтверждение инцидентов через 2 минуты, Events/MAX и Dashboard. Завершён, задеплоен и проверен; см. [описание](docs/stage-3.4-local-infrastructure.md).
* **Stage 3.4.1 — Local Infrastructure live polling:** завершён, задеплоен и проверен (`0dbaf2a`).
* **Stage 3.5 — unified check history + uptime/statistics:** подготовлен для review: общая история, 30-day retention и sample-based uptime 24h/7d/30d для Website, VPS и LocalDevice. Без production migration/deploy; см. [описание](docs/stage-3.5-check-history.md).
* **Stage 3.6 — Monitoring & Notification Settings:** подготовлен для review: MAX-only policy, quiet hours/timezone, правила и задержки подтверждения, русский Settings UI; изменения со следующего цикла без restart. Без production migration/deploy; см. [описание](docs/stage-3.6-monitoring-settings.md).
* **Stage 3.7 — Location connectivity / WireGuard awareness:** подготовлен для review: TCP состояние площадки, подавление дочерних инцидентов, read-only WG diagnostics, history/uptime и location notification rule. Без production migration/deploy; см. [описание](docs/stage-3.7-location-connectivity.md).
* **Stage 3.8 — Proxmox API + Inventory:** подготовлен для review: отдельные connections/nodes/VM/LXC, encrypted API tokens, read-only sync, Location dependency и UI/Dashboard. Без production migration/deploy; см. [описание](docs/stage-3.8-proxmox-integration.md).
* **Stage 3.8.1 — Proxmox Monitoring & Correlation:** подготовлен для review: Connection/Node/Guest incidents, expected-state policy, parent suppression, Events/MAX, history и Dashboard. Guest monitoring по умолчанию OFF. Без production migration/deploy; см. [описание](docs/stage-3.8.1-proxmox-monitoring.md).
* **Позже:** detail pages/graphs и расширенный Proxmox monitoring.

Ниже сохранён первоначальный roadmap Alpha. Ограничения Windows/сетевых устройств относятся к специализированным метрикам: TCP доступность этих типов входит в Stage 3.4.

## Фаза 1: Alpha 0.1 (Текущий этап)

**Цель:** Получить первый работающий вертикальный сценарий мониторинга на `https://monitor.lavss.ru`.

* [x] **Подготовка архитектуры и документации**
  * Спецификация концепции, стека и границ Alpha 0.1.
  * Фиксация сущности `MonitoredObject` и связей родитель/потомок.
  * Утверждение визуального направления на базе `design/dashboard-reference-v0.1.png`.
* [ ] **Базовая инфраструктура приложения**
  * Развёртывание каркаса Laravel 13 + React Inertia + Tailwind CSS / shadcn/ui.
  * Настройка PostgreSQL и Redis.
  * Реализация сессионной аутентификации для владельца системы.
* [ ] **Мониторинг Linux VPS**
  * Сбор метрик через Node Exporter + Prometheus (CPU, RAM, Disk, Load, Network, Uptime).
* [ ] **Мониторинг сайтов**
  * HTTP/HTTPS проверочные сервисы (статус, время отклика, проверка SSL сертификатов).
* [ ] **Мониторинг Proxmox**
  * Интеграция с Proxmox API (статусы узлов Node, VM, LXC, ресурсоёмкость).
* [ ] **Dashboard & UI**
  * Реализация главного пульта управления в тёмном стиле.
  * Блоки: Общий статус, Сводные карточки, Требуют внимания, Последние события, Быстрые действия (`+ Добавить объект`).
  * Мобильная адаптивность с первого дня.
* [ ] **Уведомления**
  * Отправка алертов через Telegram бота.
* [ ] **Деплой Alpha**
  * Развёртывание на VM/LXC в PVE Home за WireGuard VPN.

---

## Фаза 2: Beta & Улучшения (Будущий этап)

* [ ] PWA (Progressive Web App) поддержка.
* [ ] Подключение дополнительных каналов уведомлений (MAX, Email).
* [ ] Группировка объектов по пользовательским тегам.
* [ ] Гибкая настройка порогов алертинга (Alert thresholds) по объектам.
* [ ] Расширенная история событий и фильтрация.

---

## Исключено из Alpha 0.1 (Планируется в будущих версиях)

* Windows Server monitoring
* MikroTik и Eltex сетевые устройства
* Kubernetes monitoring
* Docker container granular metrics
* Мультипользовательский режим и роли (Multi-user / Multi-tenant)
* Замена Grafana / Heartbeat
* Автоматическое управление серверами (Remotely trigger actions)
