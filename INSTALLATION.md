# XHGui Installation Guide

Complete guide for setting up XHProf profiling with XHGui visualization for PHP applications.

## Table of Contents

- [Overview](#overview)
- [Prerequisites](#prerequisites)
- [Step 1: Install XHProf Extension](#step-1-install-xhprof-extension)
- [Step 2: Set Up XHGui (Visualization Layer)](#step-2-set-up-xhgui-visualization-layer)
- [Step 3: Instrument Your Application](#step-3-instrument-your-application-with-php-profiler)
- [Step 4: Enable Profiling via auto_prepend_file](#step-4-enable-profiling-via-auto_prepend_file)
- [Data Collection Strategies](#data-collection-strategies)
- [Docker Setup](#docker-compose-setup)
- [Using XHGui](#using-xhgui-to-find-bottlenecks)
- [Production Considerations](#production-considerations)
- [Troubleshooting](#troubleshooting)
- [Quick Reference](#quick-reference)

## Overview

This guide sets up a complete PHP profiling stack:

- **XHProf Extension**: Low-overhead profiler that collects performance data
- **XHGui**: Web interface for visualizing and analyzing profiles
- **php-profiler**: Modern library for integrating profiling into your application
- **MongoDB**: Storage backend for profiling data

### Why This Stack?

- ✅ **Cost**: Zero licensing fees. Run on a $5/month VPS
- ✅ **Flexibility**: Swap profilers without changing your UI
- ✅ **Ownership**: Your data stays on your infrastructure
- ✅ **Extensibility**: Build custom dashboards and integrations
- ✅ **Battle-tested**: Proven in production for years

## Prerequisites

- PHP 8.1 or later
- Composer
- MongoDB 3.2 or later
- Web server (Apache/Nginx) or PHP built-in server
- Root/sudo access for system-level installations

## Step 1: Install XHProf Extension

The XHProf extension must be installed on the server running your PHP application.

### Install via PECL (Recommended)

```bash
sudo pecl install xhprof
```

### Configure PHP

Create or edit the configuration file:

```bash
# For PHP 8.1
sudo nano /etc/php/8.1/mods-available/xhprof.ini
```

Add the following content:

```ini
extension=xhprof.so
xhprof.output_dir=/tmp/xhprof
```

### Enable the Extension

```bash
# For PHP-FPM
sudo ln -s /etc/php/8.1/mods-available/xhprof.ini /etc/php/8.1/fpm/conf.d/20-xhprof.ini
sudo systemctl restart php8.1-fpm

# For CLI
sudo ln -s /etc/php/8.1/mods-available/xhprof.ini /etc/php/8.1/cli/conf.d/20-xhprof.ini
```

### Verify Installation

```bash
php -m | grep xhprof
```

You should see `xhprof` in the output.

## Step 2: Set Up XHGui (Visualization Layer)

XHGui provides the web interface for browsing and comparing profiles.

### Clone and Install

```bash
cd /var/www
git clone https://github.com/ivoinov/xhgui.git
cd xhgui
composer install --no-dev
```

### Set Permissions

```bash
chmod -R 0777 cache
```

### Start MongoDB

Choose one of the following methods:

#### Option A: Docker (Recommended for Development)

```bash
docker run -d -p 27017:27017 --name xhgui-mongo mongo:6
```

#### Option B: System Package Manager

```bash
# Ubuntu/Debian
sudo apt-get install mongodb-org
sudo systemctl start mongod
sudo systemctl enable mongod

# Verify MongoDB is running
sudo systemctl status mongod
```

### Configure XHGui

Copy the default configuration:

```bash
cp src/config.default.php config/config.php
```

Edit `config/config.php`:

```php
<?php
return [
    'save.handler' => 'mongodb',
    'mongodb' => [
        'hostname' => '127.0.0.1',
        'port' => 27017,
        'database' => 'xhprof',
    ],
    // Optional: Set timezone
    'timezone' => 'UTC',
];
```

### Create MongoDB Indexes

Connect to MongoDB and create indexes for better performance:

```bash
mongosh
```

In the MongoDB shell:

```javascript
use xhprof
db.results.createIndex({ 'meta.SERVER.REQUEST_TIME': -1 })
db.results.createIndex({ 'profile.main().wt': -1 })
db.results.createIndex({ 'profile.main().mu': -1 })
db.results.createIndex({ 'profile.main().cpu': -1 })
db.results.createIndex({ 'meta.url': 1 })
db.results.createIndex({ 'meta.simple_url': 1 })
db.results.createIndex({ 'meta.SERVER.SERVER_NAME': 1 })
db.results.createIndex({ 'meta.request_ts': 1 })
```

### Run XHGui Web Interface

#### Option A: PHP Built-in Server (Development)

```bash
cd /var/www/xhgui
php -S 0.0.0.0:8080 -t webroot
```

#### Option B: Apache Configuration

```apache
<VirtualHost *:80>
    ServerName xhgui.local
    DocumentRoot /var/www/xhgui/webroot
    
    <Directory /var/www/xhgui/webroot>
        Options Indexes FollowSymLinks
        AllowOverride FileInfo
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/xhgui-error.log
    CustomLog ${APACHE_LOG_DIR}/xhgui-access.log combined
</VirtualHost>
```

#### Option C: Nginx Configuration

```nginx
server {
    listen 80;
    server_name xhgui.local;
    
    root /var/www/xhgui/webroot;
    index index.php;
    
    location / {
        try_files $uri $uri/ /index.php?$args;
    }
    
    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

### Verify XHGui is Running

Access XHGui at `http://localhost:8080` or your configured domain.

## Step 3: Instrument Your Application with php-profiler

### Install php-profiler Library

In your application directory:

```bash
cd /path/to/your/app
composer require perftools/php-profiler:^2.0
```

### Create Profiler Bootstrap File

Create `/var/www/myapp/profiler.php`:

```php
<?php
/**
 * XHProf Profiler Bootstrap
 * 
 * This file is loaded before your application via auto_prepend_file.
 * It initializes profiling and sends data to XHGui.
 */

use Xhgui\Profiler\Profiler;
use Xhgui\Profiler\ProfilingFlags;

// Load profiler's autoloader first to capture composer overhead
require __DIR__ . '/vendor/perftools/php-profiler/autoload.php';

try {
    $profiler = new Profiler([
        // Use XHProf extension
        'profiler' => Profiler::PROFILER_XHPROF,
        
        // Profiling flags
        'profiler.flags' => [
            ProfilingFlags::CPU,          // Track CPU time
            ProfilingFlags::MEMORY,       // Track memory usage
            ProfilingFlags::NO_BUILTINS,  // Exclude PHP internal functions
            ProfilingFlags::NO_SPANS,     // Disable span tracking
        ],
        
        // Choose your data collection method (see below)
        'save.handler' => Profiler::SAVER_UPLOAD,
        'save.handler.upload' => [
            'url' => 'http://127.0.0.1:8080/run/import',
            'timeout' => 3,
            'token' => '',  // Optional: Set if XHGui requires authentication
        ],
        
        // Profile selectively (important for production!)
        'profiler.enable' => function () {
            // Example 1: Sample 1% of requests
            return mt_rand(1, 100) === 1;
            
            // Example 2: Profile only when query parameter is present
            // return isset($_GET['_profile']);
            
            // Example 3: Profile based on environment
            // return getenv('APP_ENV') === 'development';
            
            // Example 4: Always profile (development only!)
            // return true;
        },
        
        // Normalize URLs to group similar requests
        'profiler.simple_url' => function ($url) {
            // Replace numeric IDs with placeholders
            $url = preg_replace('/=\d+/', '=*', $url);
            $url = preg_replace('/\/\d+/', '/*', $url);
            return $url;
        },
    ]);
    
    // Start profiling
    $profiler->start();
    
} catch (\Exception $e) {
    // Fail silently - don't break the application if profiling fails
    error_log('Profiler initialization failed: ' . $e->getMessage());
}

// Load your application's autoloader
require __DIR__ . '/vendor/autoload.php';
```

## Step 4: Enable Profiling via auto_prepend_file

To profile without modifying application code, use PHP's `auto_prepend_file` directive.

### Method A: PHP Configuration File

Create `/etc/php/8.1/fpm/conf.d/99-profiler.ini`:

```ini
auto_prepend_file = /var/www/myapp/profiler.php
```

Or add to your main `php.ini`:

```ini
auto_prepend_file = /var/www/myapp/profiler.php
```

Restart PHP-FPM:

```bash
sudo systemctl restart php8.1-fpm
```

### Method B: Apache Virtual Host

```apache
<VirtualHost *:80>
    ServerName myapp.local
    DocumentRoot /var/www/myapp/public
    
    php_admin_value auto_prepend_file "/var/www/myapp/profiler.php"
    
    <Directory /var/www/myapp/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Restart Apache:

```bash
sudo systemctl restart apache2
```

### Method C: Nginx with PHP-FPM

```nginx
server {
    listen 80;
    server_name myapp.local;
    root /var/www/myapp/public;
    index index.php;
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param PHP_VALUE "auto_prepend_file=/var/www/myapp/profiler.php";
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

Restart Nginx:

```bash
sudo systemctl restart nginx
```

### Method D: Per-Directory (.htaccess)

If your Apache configuration allows, add to `.htaccess`:

```apache
php_value auto_prepend_file "/var/www/myapp/profiler.php"
```

## Data Collection Strategies

Choose between two methods for sending profiling data to XHGui:

### Method A: File Saver (Batch Import)

**Best for**: Development environments or when you want manual control over data import.

**Pros**: No network overhead, can review data before importing
**Cons**: Requires manual import step, data not immediately visible

#### Configure Profiler

In your `profiler.php`:

```php
'save.handler' => Profiler::SAVER_FILE,
'save.handler.file' => [
    'filename' => '/tmp/xhgui.data.jsonl',
],
```

#### Import Data to XHGui

Single profile:

```bash
curl -X POST http://localhost:8080/run/import \
  -H 'Content-Type: application/json' \
  -d @/tmp/xhgui.data.jsonl
```

Multiple profiles (line-by-line):

```bash
while IFS= read -r line; do
  curl -s -X POST http://localhost:8080/run/import \
    -H 'Content-Type: application/json' \
    -d "$line"
done < /tmp/xhgui.data.jsonl
```

Or use the CLI import script:

```bash
php /var/www/xhgui/external/import.php -f /tmp/xhgui.data.jsonl
```

### Method B: Upload Saver (Real-time)

**Best for**: Production or when you want immediate visibility into performance issues.

**Pros**: Immediate data visibility, automatic upload
**Cons**: Adds small network overhead, requires network access to XHGui

#### Configure Profiler

In your `profiler.php`:

```php
'save.handler' => Profiler::SAVER_UPLOAD,
'save.handler.upload' => [
    'url' => 'http://xhgui.internal/run/import',
    'timeout' => 3,
    'token' => 'your-secret-token',  // Optional but recommended
],
```

**Requirements**: 
- `ext-curl` PHP extension
- Network access from your application server to XHGui server

**Security Note**: Use authentication tokens in production environments.

## Docker Compose Setup

For a complete containerized setup, create `docker-compose.yml`:

```yaml
version: '3.8'

services:
  app:
    build: .
    volumes:
      - ./profiler.php:/var/www/profiler.php
      - ./app:/var/www/app
    environment:
      PHP_VALUE: "auto_prepend_file=/var/www/profiler.php"
    ports:
      - "80:80"
    depends_on:
      - xhgui
  
  xhgui:
    image: edyan/xhgui:latest
    ports:
      - "8080:80"
    environment:
      MONGO_HOST: mongo
      MONGO_DB: xhprof
    depends_on:
      - mongo
  
  mongo:
    image: mongo:6
    ports:
      - "27017:27017"
    volumes:
      - mongo-data:/data/db
    command: --wiredTigerCacheSizeGB 1

volumes:
  mongo-data:
```

Start the stack:

```bash
docker-compose up -d
```

Access:
- Your application: `http://localhost`
- XHGui: `http://localhost:8080`

## Using XHGui to Find Bottlenecks

Once profiles are flowing into XHGui, you can:

### 1. Browse Recent Runs

- View at a glance: wall time, memory usage, and request counts
- Sort by different metrics to find the slowest requests
- Filter by URL patterns or date ranges

### 2. Compare Runs

- Select two profile runs to compare
- Identify performance regressions between deployments
- Spot differences in code paths

### 3. Drill into Call Graphs

- Visualize function hierarchies and call relationships
- See time spent in each function (inclusive vs. exclusive)
- Identify hotspots with color-coded call graphs

### 4. Filter by URL

- Group similar endpoints using simple_url normalization
- Track performance trends for specific routes
- Spot patterns across similar requests

### 5. Watch Metrics Over Time

- Track performance trends across days or weeks
- Set up watches for critical endpoints
- Get notified when thresholds are exceeded

### Key Metrics to Monitor

| Metric | Description | What to Watch For |
|--------|-------------|-------------------|
| **Wall Time (wt)** | Total elapsed time including I/O wait | High values indicate slow requests |
| **CPU Time (cpu)** | Actual CPU consumption | High CPU with low wall time = CPU-bound |
| **Memory (mu)** | Peak memory usage | Memory leaks, inefficient data structures |
| **Peak Memory (pmu)** | Maximum memory allocated | Memory spikes |
| **Call Count (ct)** | Number of function invocations | High counts often indicate N+1 problems |

### Common Issues and Solutions

| Symptom | Likely Cause | Solution |
|---------|--------------|----------|
| High call count for DB queries | N+1 query problem | Implement eager loading |
| High memory in data processing | Loading too much data | Implement pagination/streaming |
| High CPU in templates | Inefficient rendering | Cache rendered output |
| High wall time, low CPU | External API/DB latency | Add caching, optimize queries |

## Production Considerations

### Sampling Strategy

Profile only a subset of requests to minimize overhead:

```php
'profiler.enable' => function () {
    // Production: 0.1-5% of requests
    return mt_rand(1, 1000) <= 5; // 0.5% sampling
    
    // Or profile only slow requests
    // $threshold = 0.5; // 500ms
    // return (microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) > $threshold;
},
```

### Security

1. **Restrict XHGui Access**

   Apache example:
   ```apache
   <Location />
       Require ip 10.0.0.0/8
       Require ip 192.168.0.0/16
   </Location>
   ```

   Nginx example:
   ```nginx
   allow 10.0.0.0/8;
   allow 192.168.0.0/16;
   deny all;
   ```

2. **Use Authentication Tokens**

   In profiler.php:
   ```php
   'save.handler.upload' => [
       'token' => getenv('XHGUI_TOKEN'),
   ],
   ```

3. **Use HTTPS** for XHGui in production

4. **Secure MongoDB**
   - Enable authentication
   - Use firewall rules to restrict access
   - Regular backups

### Data Retention

MongoDB can grow quickly. Implement TTL indexes:

```javascript
// Delete profiles older than 7 days
db.results.createIndex(
    { "meta.request_ts": 1 }, 
    { expireAfterSeconds: 604800 }
)
```

Or periodic cleanup:

```bash
# Cron job to delete old profiles
0 2 * * * mongosh xhprof --eval 'db.results.deleteMany({"meta.request_ts": {$lt: new Date(Date.now() - 7*24*60*60*1000)}})'
```

### Infrastructure Recommendations

1. **Separate XHGui from Application Servers**
   - Run XHGui on dedicated instance
   - Reduces impact on application performance
   - Easier to scale independently

2. **MongoDB Sizing**
   - Plan for ~50-100KB per profile
   - 10,000 profiles/day = ~1GB/day
   - Size MongoDB server accordingly

3. **Monitoring**
   - Monitor MongoDB disk usage
   - Alert on XHGui import failures
   - Track profiling overhead

## Troubleshooting

### XHProf Extension Not Found

```bash
# Check if installed
php -m | grep xhprof

# If not found, verify installation
sudo pecl list | grep xhprof

# Check PHP config directory
php --ini | grep "Scan for additional"

# Verify .ini file exists
ls -la /etc/php/8.1/mods-available/xhprof.ini
```

### No Profiles Appearing in XHGui

1. **Check if profiling is enabled**
   ```php
   // Temporarily set to always profile
   'profiler.enable' => function () {
       return true;
   },
   ```

2. **Check XHGui logs**
   ```bash
   tail -f /var/log/nginx/error.log
   tail -f /var/log/apache2/error.log
   ```

3. **Verify MongoDB connection**
   ```bash
   mongosh
   use xhprof
   db.results.countDocuments()
   ```

4. **Test upload endpoint**
   ```bash
   curl -X POST http://localhost:8080/run/import \
     -H 'Content-Type: application/json' \
     -d '{"test":"data"}'
   ```

### High Profiling Overhead

1. **Reduce sampling rate**
   ```php
   return mt_rand(1, 1000) === 1; // 0.1% instead of 1%
   ```

2. **Enable NO_BUILTINS flag**
   ```php
   'profiler.flags' => [
       ProfilingFlags::NO_BUILTINS,  // Skip PHP internal functions
   ],
   ```

3. **Use async uploads** (if available)

### MongoDB Disk Space Issues

1. **Check current size**
   ```bash
   mongosh --eval "db.stats(1024*1024)" xhprof
   ```

2. **Enable TTL index** (see Data Retention above)

3. **Manual cleanup**
   ```javascript
   // Delete profiles older than 30 days
   db.results.deleteMany({
       "meta.request_ts": {
           $lt: new Date(Date.now() - 30*24*60*60*1000)
       }
   })
   ```

### Permission Issues

```bash
# XHGui cache directory
chmod -R 0777 /var/www/xhgui/cache

# Profiler output directory
chmod -R 0777 /tmp/xhprof
chown www-data:www-data /tmp/xhprof
```

## Quick Reference

### Essential Commands

```bash
# Install XHProf
sudo pecl install xhprof

# Verify XHProf
php -m | grep xhprof

# Clone XHGui
git clone https://github.com/ivoinov/xhgui.git

# Install dependencies
composer install

# Start MongoDB (Docker)
docker run -d -p 27017:27017 --name xhgui-mongo mongo:6

# Run XHGui dev server
php -S 0.0.0.0:8080 -t webroot

# Import profiles
php external/import.php -f /tmp/xhgui.data.jsonl

# Test XHGui is working
curl http://localhost:8080
```

### Key URLs

- **XHProf PECL**: https://pecl.php.net/package/xhprof
- **XHGui Repository**: https://github.com/ivoinov/xhgui
- **php-profiler**: https://github.com/perftools/php-profiler
- **MongoDB Documentation**: https://docs.mongodb.com/

### Configuration Files

- **PHP Extension**: `/etc/php/8.1/mods-available/xhprof.ini`
- **XHGui Config**: `/var/www/xhgui/config/config.php`
- **Profiler Bootstrap**: `/var/www/myapp/profiler.php`
- **PHP-FPM Config**: `/etc/php/8.1/fpm/conf.d/99-profiler.ini`

### Useful MongoDB Queries

```javascript
// Count total profiles
db.results.countDocuments()

// Find slowest requests
db.results.find().sort({"profile.main().wt": -1}).limit(10)

// Find memory-intensive requests
db.results.find().sort({"profile.main().mu": -1}).limit(10)

// Profiles for specific URL
db.results.find({"meta.simple_url": "/api/users/*"})

// Delete old profiles
db.results.deleteMany({
    "meta.request_ts": {$lt: new Date("2026-01-01")}
})
```

## Next Steps

1. ✅ Verify XHProf is installed and enabled
2. ✅ Confirm XHGui is accessible and MongoDB is connected
3. ✅ Generate some test profiles by visiting your application
4. ✅ View profiles in XHGui and explore the interface
5. ✅ Configure sampling rate for production
6. ✅ Set up MongoDB data retention policy
7. ✅ Configure alerts for performance degradation

## Support and Resources

- **XHGui Issues**: https://github.com/ivoinov/xhgui/issues
- **php-profiler Issues**: https://github.com/perftools/php-profiler/issues
- **Community**: PHP Performance discussions on Reddit, Stack Overflow

---

**Happy profiling! 🚀**

*Last updated: February 2, 2026*
