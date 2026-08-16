# MVP — lavss monitor (Alpha 0.1)

## 1. Функциональные границы Alpha 0.1

### Мониторинг VPS (Linux)
* Доступность сервера (Ping/Up)
* Загрузка CPU (%)
* Использование RAM (%)
* Заполненность дисков (Disk Usage %)
* System Load Average (1m, 5m, 15m)
* Сетевой трафик (Network In/Out)
* Uptime

### Мониторинг Сайтов (Websites / WordPress)
* HTTP/HTTPS availability
* HTTP Status code (200 OK, 5xx error и др.)
* Время отклика (Response time, ms)
* Валидность SSL-сертификата
* Срок действия SSL-сертификата (дней до истечения)

### Мониторинг Proxmox
* Доступность кластера/узла Proxmox Node
* Статус Virtual Machines (VM)
* Статус LXC Containers
* Агрегированное потребление CPU, RAM, Storage

---

## 2. Экранные формы и элементы UI (Dashboard v0.1)

Главный Dashboard реализуется в соответствии с референсом [dashboard-reference-v0.1.png](file:///d:/seafile/lavss/My%20Libraries/Soft/Projects/lavss-monitor/design/dashboard-reference-v0.1.png):
* **Общий статус здоровья:** Баннер вверху экрана (`🟢 Все системы работают` или `🟡 Требуют внимания — X`).
* **Сводные карточки:** Сводка по VPS, Сайтам, Инфраструктуре Proxmox.
* **Секция «Требуют внимания»:** Список активных проблем и предупреждений.
* **Лента «Последние события»:** Журнал важных изменений и алертов.
* **Быстрые действия:** Кнопка `+ Добавить объект` с выпадающим выбором типов (VPS, Server, Proxmox, Container, Website, WordPress).

---

## 3. Вертикальные сценарии проверки (Критерии успеха Alpha)

Для сдачи Alpha 0.1 должны быть работоспособны 3 сквозных сценария:

1. **VPS Pipeline:**
   $$\text{VPS} \rightarrow \text{Node Exporter} \rightarrow \text{Prometheus} \rightarrow \text{lavss monitor} \rightarrow \text{Dashboard} \rightarrow \text{Event} \rightarrow \text{Telegram}$$

2. **Website Pipeline:**
   $$\text{Website} \rightarrow \text{HTTP/HTTPS check} \rightarrow \text{lavss monitor} \rightarrow \text{Dashboard} \rightarrow \text{Event} \rightarrow \text{Telegram}$$

3. **Proxmox Pipeline:**
   $$\text{Proxmox} \rightarrow \text{Proxmox API} \rightarrow \text{lavss monitor} \rightarrow \text{Dashboard}$$

---

## 4. Окружение развёртывания Alpha

* **Хост:** PVE Home (домашний Proxmox)
* **Тип:** Ubuntu 24.04 LTS (VM или LXC)
* **Ресурсы:** 2 vCPU, 3–4 GB RAM, 32–40 GB Storage
* **Развёртывание:** Docker Compose
