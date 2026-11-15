# MRC — Modern ERP, CRM & POS SaaS Platform

[🇹🇷 Türkçe Dokümantasyon](README.tr.md) | [📖 Kullanıcı Kılavuzu](docs/kilavuz/README.md)

An enterprise-ready, multi-tenant cloud platform built for inventory management, multi-currency invoicing, point-of-sale operations, customer relationship tracking, and financial accounting.

---

## 🌟 Overview

MRC is a full-featured web-based business management system engineered for high performance and strict multi-tenant isolation. Developed with **Laravel 12**, **Vue 3**, **Vite**, and **Tailwind CSS**, it delivers a desktop-grade single-page application (SPA) backed by a hardened REST API.

### Core Capabilities

- **Multi-Tenant Architecture**: Complete data isolation per organization with role-based permissions (Admin, Manager, Staff, Accountant).
- **Sales & Purchase Workflow**: End-to-end processing from quotation to dispatch, delivery notes, and tax-compliant invoicing.
- **Point of Sale (POS)**: High-speed barcode-driven checkout interface with thermal receipt printing (58mm, 80mm, A5) and direct hardware printer bridges.
- **Multi-Warehouse Inventory**: Live stock levels, transfer manifests, cycle counting reconciliations, and automatic Weighted Average Cost (WAC) calculations.
- **Dynamic Multi-Currency & Price Classes**: Configure unique sales and purchase prices per currency (TRY, USD, EUR, GBP) with automatic central bank exchange rate synchronization.
- **Accounts & Financials**: Real-time accounts receivable/payable (AR/AP), aging reports, cash flow analysis, bank accounts, and debt settlement tracking.
- **Visual Print Template & Label Designer**: Drag-and-drop canvas for designing thermal barcode labels and PDF templates with pixel-perfect SVG/GD rendering.
- **Online Payment Gateways**: Automated payment link generation and payment capture with PayTR and iyzico webhook integration.
- **Security & Compliance**: Built-in TOTP two-factor authentication (RFC 6238), active device session management, and immutable audit trails.
- **Automated Backups**: Native scheduled database dumps and compressed offsite file backups.

---

## 🏗️ Technology Stack

| Layer | Technology | Details |
|---|---|---|
| **Backend** | PHP 8.2+ / Laravel 12 | RESTful API (`/api/v1`), Sanctum token & cookie authentication |
| **Frontend** | Vue 3 / Vite 7 / Tailwind CSS | Single Page Application (SPA), Pinia state management |
| **Database** | MySQL 8.0+ / MariaDB 10.6+ | `utf8mb4_unicode_ci`, strictly indexed tenant foreign keys |
| **Document Engine** | DomPDF / Native GD | High-resolution PDF generation and thermal vector graphics |
| **Hardware Bridge** | Native ESC/POS Bridge | Local thermal and label printer spooling protocol |

---

## 📁 Repository Structure

```
├── docs/
│   └── kilavuz/             # End-user visual documentation and manuals
├── mrcweb/                  # Primary application (Laravel 12 backend + Vue 3 SPA)
│   ├── app/                 # Application controllers, models, and domain services
│   ├── config/              # Modular application configuration
│   ├── database/            # Database migrations, seeders, and factories
│   ├── resources/           # Vue 3 SPA components, stores, and styles
│   ├── routes/              # API and web routing declarations
│   └── tests/               # Comprehensive automated test suite
├── README.md                # Project documentation (English)
├── README.tr.md             # Project documentation (Turkish)
└── .gitignore
```

---

## 🚀 Quick Start (Development)

### Prerequisites

- PHP 8.2 or higher (with `pdo_mysql`, `gd`, `bcmath`, `mbstring`, `zip`, `intl` extensions)
- Composer 2.x
- Node.js 20.x or higher & npm
- MySQL 8.0+ or MariaDB 10.6+

### Installation Steps

1. **Clone the repository:**
   ```bash
   git clone https://github.com/lepotane/mrc-crm-erp-saas-system.git
   cd mrc-crm-erp-saas-system/mrcweb
   ```

2. **Install dependencies:**
   ```bash
   composer install
   npm ci
   ```

3. **Configure environment:**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
   Update `.env` with your database credentials:
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=mrc_database
   DB_USERNAME=your_db_user
   DB_PASSWORD=your_db_password
   ```

4. **Run migrations and bootstrap administrator:**
   ```bash
   php artisan migrate
   php artisan mrc:create-admin --email=admin@example.com --company="Acme Corp" --password=Secret123!
   ```

5. **Start development servers:**
   ```bash
   # Terminal 1: Laravel backend API
   php artisan serve

   # Terminal 2: Vite SPA server (Hot Module Reloading)
   npm run dev
   ```

---

## 🧪 Testing & Code Quality

The application includes an extensive suite of automated unit, integration, and feature tests ensuring strict tenant isolation and mathematical precision:

```bash
cd mrcweb

# Run automated test suite
php artisan test

# Run frontend build verification
npm run build
```

---

## ⚙️ Production Deployment

### 1. Production Build & Optimization

```bash
cd mrcweb
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 2. Cron Scheduler

Add the Laravel scheduler to your system crontab:

```crontab
* * * * * cd /path-to-app/mrcweb && php artisan schedule:run >> /dev/null 2>&1
```

Scheduled jobs automatically handle:
- Daily database backup exports
- Central bank foreign currency rate fetching
- Inactive printer queue and token cleanup
- Monthly subscription and quota reconciliations

---

## 🤝 Contributing

Contributions, bug reports, and pull requests are welcome.
1. Fork the repository
2. Create your feature branch (`git checkout -b feature/new-feature`)
3. Commit your changes (`git commit -m 'feat: add new feature'`)
4. Push to the branch (`git push origin feature/new-feature`)
5. Open a Pull Request

---

## 📄 License

This project is licensed under the **GNU General Public License v3.0 (GPLv3)**.
See the [LICENSE](LICENSE) file for full license terms and conditions.
