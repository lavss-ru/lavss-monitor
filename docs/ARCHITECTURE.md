# ARCHITECTURE — lavss monitor

## 1. Стек технологий (Alpha 0.1)

* **Backend:** Laravel 13 (PHP 8.3+)
* **Frontend:** React + TypeScript + Inertia.js
* **Styling & Components:** Tailwind CSS + shadcn/ui (кастомная тема `lavss monitor`)
* **Database:** PostgreSQL
* **Cache & Queues:** Redis
* **Metrics Ingestion:** Prometheus (сбор с Node Exporter)
* **API Integration:** Proxmox API
* **Web Probes:** Встроенный HTTP/HTTPS клиент Laravel/Guzzle
* **Alerting:** Telegram Bot API
* **Network & Security:** WireGuard VPN + Reverse Proxy

---

## 2. Ключевая сущность: `MonitoredObject`

Все мониторируемые ресурсы в системе представлены единой абстрактной моделью `MonitoredObject`.

### Базовая схема сущности
* `id` (UUID / Primary Key)
* `name` (string) — Название объекта (например, `VPS-01`, `PVE Home`, `site.ru`)
* `type` (enum/string) — `vps`, `server`, `proxmox`, `container`, `website`, `wordpress`
* `status` (enum) — `ok` (🟢), `warning` (🟡), `critical` (🔴), `unknown` (⚪)
* `enabled` (boolean) — Активен ли мониторинг
* `description` (text, optional)
* `tags` (jsonb/array) — Теги для фильтрации
* `parent_id` (foreign key, nullable) — Ссылка на родительский `MonitoredObject`
* `created_at` (timestamp)
* `updated_at` (timestamp)

### Иерархия связей (Parent-Child)
Поддерживаются произвольные вложенности, например:
```text
Proxmox (PVE Home)
├── LXC (Home Backup)
└── LXC (Home Seafile)

VPS-03
├── Website (site1.ru)
├── Website (site2.ru)
└── Website (site3.ru)
```
*Важное правило:* Иерархия используется для группировки и детализации, но главный Dashboard не превращается в гигантское дерево. Объекты могут отображаться в сводках независимо, сохраняя ссылку на родителя.

---

## 3. Сбор метрик и источников данных

1. **Linux VPS:**
   * На серверах устанавливается `Node Exporter`.
   * `Prometheus` собирает метрики (CPU, RAM, Disk, Load Average, Network, Uptime) по защищённой сети WireGuard.
   * `lavss monitor` опрашивает Prometheus API для вычисления статусов и сохранения аномалий.
2. **Proxmox Virtual Environment:**
   * `lavss monitor` опрашивает Proxmox API напрямую (Node status, VM/LXC statuses, CPU/RAM/Storage allocations).
3. **Websites & WordPress:**
   * `lavss monitor` выполняет периодические HTTP/HTTPS запросы (HTTP status code, response time, SSL expiration date, cert validation).

---

## 4. Архитектура уведомлений (Notification Pipeline)

Архитектура отчуждаема от одного конкретного провайдера:

```text
Event / Threshold Trigger
         ↓
       Alert
         ↓
    Notification
         ↓
Notification Channel (Interface)
   ├── TelegramChannel (Alpha 0.1)
   ├── MaxChannel (Future)
   └── EmailChannel (Future)
```

---

## 5. Топология сети

```text
Пользователь (Браузер / Мобильный)
              │
    https://monitor.lavss.ru
              │
        Reverse Proxy
              │
          WireGuard
              │
  PVE Home (lavss monitor)
    ├── PostgreSQL / Redis
    └── Prometheus
         │ (через WireGuard)
         ├── VPS 1 (Node Exporter)
         ├── VPS 2 (Node Exporter)
         └── VPS 3 (Node Exporter)
```
