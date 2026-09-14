# Lab HIS System

A healthcare and laboratory management system built for a medical center / clinic workflow. The application is a PHP-based HIS dashboard with patient management, appointments, staff access, pharmacy, accounting, and laboratory operations integrated into a single management portal.

## Project Overview

This project provides a modular hospital information system (HIS) experience for:

- Admin and staff authentication
- Patient registration and tracking
- Appointments and clinics management
- Pharmacy / medicine management
- Lab requests and billing flows
- Accounting and revenue monitoring
- Roles and permissions for different staff levels
- Arabic and English interface support

## Tech Stack

- PHP
- MySQL
- JavaScript / jQuery
- Bootstrap and custom CSS
- Apache / Nginx compatible layout

## Project Structure

- `index.php` — login entry point
- `session_init.php` — session configuration
- `pos/` — admin, cashier, accountant, and related modules
- `style.css` — global styling
- `js_main.js`, `js_check.js` — front-end logic

## Database Configuration

The application is configured to connect to a local MySQL database with the following defaults:

- Host: `localhost`
- Database: `alwahat`
- Username: `root`
- Password: empty

Update the values in `pos/admin/config/config.php` if you are deploying to another environment.

## Local Setup

1. Install PHP and MySQL.
2. Create a MySQL database named `alwahat`.
3. Import your database schema if available.
4. Configure `localhost` credentials in `pos/admin/config/config.php`.
5. Start a local PHP server:

```bash
php -S localhost:8888
```

6. Open the app in your browser:

```text
http://localhost:8888
```

## Login

The project includes a login page at the root entry point. Use your configured admin or staff credentials from the database.

## Notes

This repository is intended as a medical management system starter for clinic and hospital workflow automation. Some modules and database objects may depend on your own database import or custom data setup.

## License

This project is provided as a source code project for educational and internal business use. Please review and confirm your licensing obligations before production deployment.
