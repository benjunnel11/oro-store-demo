# Oro Store POS System

A production multi-device Point of Sale system built with PHP, MySQL, and JavaScript. Deployed across 2 retail stores handling real daily transactions.

## Features

- **Multi-device sync** — Cloud stock sync via TiDB Cloud, device-to-device HTTP API, offline queue with auto-flush
- **Role-based access** — Admin, Manager, Cashier, Kiosk roles with 20+ database tables
- **GCash integration** — Cash in/out, send, bank transfer with fee calculation
- **Kiosk self-service** — Customer-facing product ordering with priority queue
- **Thermal printing** — ESC/POS receipt printing via RawBT
- **Credit/Delivery/Angkat tracking** — Full transaction lifecycle management
- **Real-time stock sync** — Cross-device inventory updates via cloud database
- **Caching** — Client-side (localStorage + TTL) and server-side (session) caching
- **Security** — Rate limiting, env-based credentials, activity logging

## Tech Stack

- **Backend:** PHP, MySQL/MariaDB
- **Frontend:** Vanilla JavaScript, HTML, CSS
- **Cloud:** TiDB Cloud Serverless (stock sync)
- **Networking:** ZeroTier VPN (device-to-device)
- **Printing:** RawBT ESC/POS thermal printing
- **Tools:** XAMPP, Git, Syncthing

## Setup (Demo)

1. Install XAMPP (PHP + MySQL)
2. Clone this repo to `htdocs/oro-store`
3. Import the database schema (auto-creates tables on first run)
4. Open `http://localhost/oro-store/` — auto-logs in as Demo Admin

## Author

**Ben Junnel Oro** — [Portfolio](https://resume-benjunnel.vercel.app) | [GitHub](https://github.com/benjunnel11)