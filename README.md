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
* **Database:** PostgreSQL 16
* **Cache / Queue:** Redis 7
* **Metrics:** Prometheus + Node Exporter (для Linux VPS)
* **Proxmox Integration:** Proxmox API
* **Website Checks:** Встроенные HTTP/HTTPS проверки
* **Notifications:** Telegram Channel
* **Security & Network:** WireGuard VPN + Reverse Proxy

---

## 4. Local Development (Быстрый старт)

Для локального запуска проекта используются Docker Compose (PostgreSQL 16, Redis 7, PHP 8.3 App, Node.js Vite).

### Запуск через Docker Compose:

1. **Скопируйте конфигурацию окружения:**
   ```bash
   cp .env.example .env
   ```

2. **Запустите контейнеры:**
   ```bash
   docker compose up -d
   ```

3. **Откройте приложение в браузере:**
   ```text
   http://localhost:8000
   ```

4. **Данные для входа (Single Owner):**
   * **Email:** `lavss@lavss.ru`
   * **Password:** `password`

5. **Остановка окружения:**
   ```bash
   docker compose down
   ```

---

## 5. Структура проекта

```text
lavss-monitor/
├── docker-compose.yml          # Dev-окружение (PostgreSQL 16, Redis 7, App, Vite)
├── infrastructure/             # Dockerfiles для развёртывания
│   └── docker/app.Dockerfile
├── app/                        # Laravel 13 Backend (Controllers, Models)
├── database/                   # Миграции и сид единственного владельца
├── resources/                  # React + Inertia + Tailwind CSS Frontend
│   ├── js/
│   │   ├── Components/Dashboard/
│   │   ├── Layouts/
│   │   ├── Pages/Auth/Login.tsx
│   │   ├── Pages/Dashboard.tsx
│   │   └── types/dashboard.ts
│   └── css/app.css
├── routes/                     # web.php, auth.php
├── tests/                      # Feature & Auth тесты (Pest)
├── docs/                       # Спецификации и ADR
└── design/
    └── dashboard-reference-v0.1.png
```
