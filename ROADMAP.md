# ROADMAP — lavss monitor

## Текущие этапы monitoring

* **Stage 3.4 — Local Infrastructure Monitoring:** Location + LocalDevice, TCP проверки, подтверждение инцидентов через 2 минуты, Events/MAX и Dashboard. Реализация подготовлена для review; см. [описание](docs/stage-3.4-local-infrastructure.md).
* **Stage 3.5 — unified check history + uptime/statistics:** общая история и статистика Website, VPS и LocalDevice. Прежняя идея Stage 3.4 перенесена сюда.
* **Позже:** detail pages/graphs, Location/gateway correlation, Proxmox API monitoring.

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
