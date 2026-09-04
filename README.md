# Classroom Management System (CRMS)

A web-based classroom and room management system for Bangladesh Agricultural University,
built for the System & Software Engineering laboratory course.

The system replaces manual, notice-board-based room allocation with a centralised
platform where teachers, class representatives (CRs) and administrators can view
live room availability, book rooms, manage recurring course schedules and receive
automated notifications.

---

## Features

- **Live room availability** — real-time status of every room across buildings and floors
- **Room booking** — conflict-checked, one-off bookings with purpose tracking
- **Recurring course schedules** — weekly timetables with per-instance cancellation
  and exception handling
- **Role-based dashboards** — separate interfaces for Admin, Teacher, CR and Faculty
- **Notifications** — in-app alerts plus automated daily email reminders
- **Account security** — CSRF protection, login-attempt throttling, account lockout
  with OTP unlock, secure "remember me" tokens and email-based password reset
- **Audit trail** — activity logging and an admin audit log for accountability

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8 (PDO, prepared statements) |
| Database | MySQL / MariaDB |
| Frontend | HTML5, CSS3, vanilla JavaScript |
| Scheduled jobs | cron (PHP CLI) |
| Mail | PHP `mail()` (PHPMailer-ready) |

---

## Project Structure

```
.
├── includes/                    # Core application logic
│   ├── functions.php            # Data layer: bookings, availability, notifications
│   ├── business_functions.php   # Analytics: utilisation, stats, peak usage
│   ├── schedule_validation.php  # Recurring-schedule conflict checks
│   ├── auth.php                 # Sessions, lockout, OTP, remember-tokens
│   ├── csrf.php                 # CSRF token generation & verification
│   ├── password_reset.php       # Password reset flow
│   └── email_helper.php         # Transactional email templates
├── cron/                        # Scheduled background jobs
│   ├── send_daily_reminders.php
│   ├── mark_past_bookings.php
│   └── cleanup_notifications.php
├── css/                         # Stylesheets
├── images/                      # Static assets
├── db_schema.sql                # Full database schema
├── database_migration.sql       # Incremental schema migrations
├── db_config.example.php        # Configuration template
├── index.php                    # Public landing page
├── login.php / register.php     # Authentication entry points
├── admin_dashboard.php          # Administrative console
├── teacher_dashboard.php        # Teacher interface
├── cr_dashboard.php             # Class representative interface
├── f_dashboard.php              # Faculty interface
├── room_status.php              # Live room availability
└── view-classroom.php           # Individual room detail view
```

---

## Database Design

The schema is normalised around an institutional hierarchy:

```
faculties → departments → degree_programs → semesters
buildings → floors → rooms
```

Scheduling is modelled in two complementary tables: `course_schedule` holds
**recurring** weekly entries, while `room_bookings` holds **one-off** reservations.
`schedule_exceptions` records cancellations of individual instances of a recurring
schedule without deleting the schedule itself.

Supporting tables cover notifications, activity logging, login attempts, booking
attempts, remember-me tokens and an administrative audit log.

---

## Setup

```bash
# 1. Clone the repository
git clone <repository-url>
cd crms

# 2. Create the database and import the schema
mysql -u <user> -p <database_name> < db_schema.sql

# 3. Apply migrations
mysql -u <user> -p <database_name> < database_migration.sql

# 4. Configure the application
cp db_config.example.php db_config.php
#    then edit db_config.php with your database credentials

# 5. Serve the application (e.g. Apache/Nginx document root, or)
php -S localhost:8000
```

### Scheduled jobs

Add the following to crontab for automated reminders and cleanup:

```cron
0  7 * * * php /path/to/cron/send_daily_reminders.php
*/15 * * * * php /path/to/cron/mark_past_bookings.php
0  2 * * * php /path/to/cron/cleanup_notifications.php
```

---

## Configuration

`db_config.php` holds all environment-specific settings and is **excluded from
version control**. Never commit real credentials — copy `db_config.example.php`
and fill it in locally.

---

## Team

| Member | Role |
|---|---|
| Afif | System architecture & database design |
| Rabbi | Authentication, security & booking workflow |
| Fahad | Frontend, dashboards & user interface |

---

## Course

Developed for the System & Software Engineering laboratory course,
Department of Bioinformatics Engineering, Bangladesh Agricultural University.

---

## License

This project was developed for Bangladesh Agricultural University coursework and
internal use. It is not licensed for public use, redistribution or modification.
All rights reserved by the authors.
