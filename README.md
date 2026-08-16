# lavss monitor

**lavss monitor** — единый веб-пульт управления и наблюдения за личной IT-инфраструктурой (`https://monitor.lavss.ru`).

---

## 1. Философия и цель продукта

Главная цель — дать владельцу инфраструктуры возможность открывать дашборд и за несколько секунд получать исчерпывающее понимание текущей ситуации:
* Вся ли система работает нормально?
* Где возникла проблема или аномалия?
* Что требует немедленного внимания?

### Основной принцип
Не перегружать пользователя лишними метриками. Показывать:
1. Общий статус здоровья системы.
2. Проблемы и предупреждения.
3. Важные события.
4. Ключевые показатели.
5. Подробности — только при переходе к конкретному объекту.

---

## 2. Объём Alpha 0.1

Первая версия фокусируется на базовом наборе объектов инфраструктуры:

* **VPS (Linux):** доступность, CPU, RAM, Disk, Load, Network, Uptime.
* **Websites (WordPress / HTTP):** HTTP/HTTPS availability, HTTP status, response time, SSL validity и срок действия сертификата.
* **Proxmox (PVE Home / ЦБО):** статус Node, VM, LXC, CPU, RAM, Storage.
* **Monitored Object Entity:** абстрактная расширяемая сущность объекта с поддержкой связей «родитель-потомок» (например, VPS $\rightarrow$ Сайт или Proxmox $\rightarrow$ VM/LXC).
* **Telegram Alerts:** уведомления о событиях и проблемах через Telegram.

---

## 3. Утверждённый технологический стек

* **Backend:** Laravel 13
* **Frontend:** React + TypeScript + Inertia.js
* **UI:** Tailwind CSS + shadcn/ui (собственная дизайн-система `lavss monitor` на основе dark UI референса)
* **Database:** PostgreSQL
* **Queue / Cache:** Redis
* **Metrics:** Prometheus + Node Exporter (для Linux VPS)
* **Proxmox Integration:** Proxmox API
* **Website Checks:** Встроенные HTTP/HTTPS проверки
* **Notifications:** Telegram Channel
* **Security & Network:** WireGuard VPN + Reverse Proxy

---

## 4. Визуальный направление

Главный экран строится на основе утверждённого визуального макета [dashboard-reference-v0.1.png](file:///d:/seafile/lavss/My%20Libraries/Soft/Projects/lavss-monitor/design/dashboard-reference-v0.1.png):
* Dark UI с глубоким тёмным фоном.
* Понятная цветовая индикация статусов (🟢 Всё работает / 🟡 Требуют внимания / 🔴 Авария).
* Выразительные сводные карточки и информационная иерархия.
* Полная адаптивность для мобильных устройств, планшетов и десктопов.

---

## 5. Структура репозитория

```text
lavss-monitor/
├── backend/          # Laravel 13 приложение (заглушка для следующего этапа)
├── frontend/         # React + Inertia фронтенд (заглушка для следующего этапа)
├── infrastructure/   # Конфигурация окружения
├── docs/             # Архитектурная документация и спецификации Alpha 0.1
│   ├── VISION.md
│   ├── ARCHITECTURE.md
│   ├── MVP.md
│   └── DECISIONS.md
├── design/           # Дизайн-референсы и макеты
├── README.md
├── ROADMAP.md
├── SECURITY.md
└── CHANGELOG.md
```
