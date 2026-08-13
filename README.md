# My Portfolio

A personal portfolio website and custom Content Management System (CMS) built with PHP, MySQL, HTML, CSS, and vanilla JavaScript.

## Features
- **Public Portfolio**: Showcases projects, experience, and contact details with modern web aesthetics.
- **Secure Admin Panel**: A private backend (`/admin-03-22-25/`) for managing content, viewing messages, and handling resume requests.
- **Dynamic Content**: Uses a MySQL database to serve dynamic projects and content.
- **Security-First**: Includes prepared statements, secure session management, CSRF tokens, and security headers.

## Local Setup

1. **Requirements**: 
   - A local server environment like XAMPP or MAMP.
   - PHP 8.1+
   - MySQL

2. **Database Configuration**:
   - Create a MySQL database named `wrndll_db_final`.
   - Import the `wrndll_db_final.sql` database dump.
   - (Optional) Configure database credentials in `private/config.local.php` if your local MySQL uses a password.

3. **Running the Site**:
   - Place this project in your local server's document root (e.g., `htdocs/HelloWrandell`).
   - Navigate to `http://localhost/HelloWrandell` to view the public site.
   - Navigate to `http://localhost/HelloWrandell/admin-03-22-25/login.php` to access the CMS.

## Author
**Wrandell I. Almeda**
