# CleanManage deployment checklist

## 1. Prepare the hosting environment
- Use PHP 8.2+ with Apache or Nginx
- Enable PDO, mysqli, OpenSSL, and file uploads
- Ensure the web root points to the project folder
- Set document root to the project root or public folder if you add one

## 2. Database setup
- Create the MySQL database: cleanmanage_db
- Import the schema from database.sql if needed
- Ensure the DB user has full privileges to that database

## 3. Security configuration
- Use strong passwords and never leave default credentials in production
- Set APP_ENV=production and APP_DEBUG=false
- Demo role accounts are seeded only outside production; create production users with unique credentials
- Set SESSION_SECURE=true when HTTPS is enabled
- Restrict file uploads and keep uploads outside the web root when possible

## 4. Email and WhatsApp
- Fill in SMTP credentials for real email delivery
- Configure a WhatsApp Business API provider for automated notifications

## 5. Final deployment checks
- Verify login works
- Confirm admin role access
- Confirm employee/customer role restrictions work
- Verify dashboard totals are correct
- Test booking creation, payments, and invoice generation
- Test upload flows for proof photos

## 6. Production launch
- Keep Apache/Nginx behind HTTPS
- Use a real domain and valid SSL certificate
- Disable debug output and error display in production
- Review logs and backups regularly
