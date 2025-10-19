# Laravel Bill - Queue & Recurring Billing Configuration

## 📋 Table of Contents
- [Queue Configuration](#queue-configuration)
- [Recurring Billing](#recurring-billing)
- [Laravel Scheduler Setup](#laravel-scheduler-setup)
- [Environment Variables](#environment-variables)

## ⚙️ Queue Configuration

Larabill uses **Laravel Actions** to execute background processes. You can choose between synchronous (no queues) or asynchronous (with Redis/SQS/Beanstalkd) execution.

### Option 1: Synchronous (No Queues - Default)

```env
LARABILL_QUEUE_CONNECTION=sync
```

Processes run synchronously with the Laravel Scheduler. No queue workers required.

### Option 2: Redis (Recommended for Production)

```env
QUEUE_CONNECTION=redis
LARABILL_QUEUE_CONNECTION=redis
LARABILL_QUEUE_NAME=larabill

# Redis Configuration
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

**Start the queue worker:**
```bash
php artisan queue:work redis --queue=larabill --tries=3 --timeout=300
```

### Option 3: Amazon SQS

```env
LARABILL_QUEUE_CONNECTION=sqs
LARABILL_QUEUE_NAME=larabill-production

# AWS Configuration
AWS_ACCESS_KEY_ID=your-key
AWS_SECRET_ACCESS_KEY=your-secret
AWS_DEFAULT_REGION=us-east-1
SQS_PREFIX=https://sqs.us-east-1.amazonaws.com/your-account-id
```

### Option 4: Beanstalkd

```env
LARABILL_QUEUE_CONNECTION=beanstalkd
LARABILL_QUEUE_NAME=larabill

BEANSTALKD_HOST=127.0.0.1
BEANSTALKD_PORT=11300
```

## 🔄 Recurring Billing

### Configuration

Invoices for recurring services are generated **X days in advance** of the billing date.

#### Global Configuration (Default)

```env
# Days in advance to generate invoices (default: 7)
LARABILL_BILLING_DAYS_IN_ADVANCE=7

# Daily execution time (24h format)
LARABILL_BILLING_SCHEDULE_TIME=00:00

# Send email notifications after creating invoices
LARABILL_BILLING_SEND_NOTIFICATIONS=true

# Payment terms in days (for calculating due_date)
LARABILL_PAYMENT_TERMS_DAYS=15
```

#### Per-Article Override

You can override the global `days_in_advance` for specific articles:

```php
$article = Article::create([
    'name' => 'Premium Hosting',
    'base_price' => 9900, // €99.00
    'billing_frequency' => BillingFrequency::MONTHLY,
    'billing_days_in_advance' => 15, // Override: generate 15 days in advance
    // ... other fields
]);
```

### Manual Execution

#### Via Artisan Command

```bash
# Process now
php artisan larabill:process-recurring

# Dry-run (simulate without creating invoices)
php artisan larabill:process-recurring --dry-run

# Process for specific date
php artisan larabill:process-recurring --date="2024-01-15"
```

#### Programmatically (Direct Call)

```php
use AichaDigital\Larabill\Actions\ProcessRecurringBilling;

// Synchronous execution
$results = ProcessRecurringBilling::run();

// With specific date
$results = ProcessRecurringBilling::run(now()->addDay());

// Dry-run mode
$results = ProcessRecurringBilling::run(null, true);
```

#### Via Queue (If Configured)

```php
use AichaDigital\Larabill\Actions\ProcessRecurringBilling;

// Dispatch to queue
ProcessRecurringBilling::dispatch();

// With parameters
ProcessRecurringBilling::dispatch(now()->addDay());

// With delay
ProcessRecurringBilling::dispatch()->delay(now()->addHour());

// Specific queue
ProcessRecurringBilling::dispatch()->onQueue('billing');
```

## ⏰ Laravel Scheduler Setup

### Laravel 12 (Current)

The scheduler is configured in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;
use AichaDigital\Larabill\Actions\ProcessRecurringBilling;

// Recurring Billing - Generates invoices X days in advance
Schedule::call(ProcessRecurringBilling::class)
    ->dailyAt(config('larabill.recurring_billing.schedule_time', '00:00'))
    ->name('larabill:recurring-billing')
    ->onOneServer()
    ->withoutOverlapping();
```

### Single Cron Entry Required

Add this single cron entry to run all scheduled tasks:

```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

**Note:** This single cron job runs Laravel's scheduler every minute, which then executes your configured tasks at their specified times.

## 📝 Environment Variables Reference

```env
# ==========================================
# QUEUE CONFIGURATION
# ==========================================
# Queue connection (redis, sqs, beanstalkd, sync)
# If null, uses Laravel's default queue connection
LARABILL_QUEUE_CONNECTION=

# Specific queue name for Larabill jobs
LARABILL_QUEUE_NAME=default

# Number of times to retry failed jobs
LARABILL_QUEUE_TRIES=3

# Timeout in seconds
LARABILL_QUEUE_TIMEOUT=300

# ==========================================
# RECURRING BILLING CONFIGURATION
# ==========================================
# Days in advance to generate invoices (global default)
# Can be overridden per article via billing_days_in_advance field
LARABILL_BILLING_DAYS_IN_ADVANCE=7

# Daily execution time (24h format: HH:MM)
LARABILL_BILLING_SCHEDULE_TIME=00:00

# Send email notifications after creating invoices
LARABILL_BILLING_SEND_NOTIFICATIONS=true

# Payment terms in days (for calculating due_date)
LARABILL_PAYMENT_TERMS_DAYS=15

# ==========================================
# PAYMENT REMINDERS (Optional - Future)
# ==========================================
LARABILL_REMINDERS_ENABLED=true
LARABILL_REMINDERS_SCHEDULE_TIME=10:00
LARABILL_REMINDER_FIRST=7
LARABILL_REMINDER_SECOND=3
LARABILL_REMINDER_OVERDUE=1
LARABILL_REMINDER_SUSPENSION=7
```

## 🏗️ Architecture

### Service Layer

`RecurringBillingService` contains all business logic:
- Finding services due for billing
- Calculating billing periods using `addMonths`/`addYears`
- Generating invoices with comprehensive metadata
- Respecting `days_in_advance` configuration
- Error handling and logging

### Action Layer (Laravel Actions)

`ProcessRecurringBilling` provides multiple execution contexts:
- **Command:** `php artisan larabill:process-recurring`
- **Job:** `ProcessRecurringBilling::dispatch()`
- **Direct Call:** `ProcessRecurringBilling::run()`

**One class, multiple contexts!**

### Queue Agnostic

The package works **with or without queues**:
- **With queues:** Background processing via Redis/SQS/Beanstalkd
- **Without queues:** Synchronous processing via scheduler

## 📚 Additional Resources

- [Laravel Actions Documentation](https://laravelactions.com/)
- [Laravel Scheduler Documentation](https://laravel.com/docs/12.x/scheduling)
- [Laravel Queues Documentation](https://laravel.com/docs/12.x/queues)

---

**Package Version:** 0.3.3+  
**Laravel Version:** 12.x  
**PHP Version:** 8.3 | 8.4

