# XHGui
A graphical interface for XHProf profiling data that can store the results in MongoDB or PDO database.
Application is profiled and the profiling data is transferred to XHGui, which takes that information, saves it in MongoDB (or PDO database), and provides a convenient GUI for working with it.
This project is the GUI for showing profiling results. To profile your application, use the specific minimal library:
-  [perftools/php-profiler](#profiling-a-web-request-or-cli-script)
[![Build Status](https://travis-ci.org/perftools/xhgui.svg?branch=master)](https://travis-ci.org/perftools/xhgui)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/perftools/xhgui/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/perftools/xhgui/?branch=master)
[![Code Coverage](https://scrutinizer-ci.com/g/perftools/xhgui/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/perftools/xhgui/?branch=master)
# System Requirements
XHGui has the following requirements:
- **PHP 8.1 or later** (PHP 8.1, 8.2, 8.3 are supported)
- If using MongoDB storage, see [MongoDB](#mongodb) requirements
- If using PDO storage, see [PDO](#pdo) requirements
- To profile an application, one of the profiling PHP extensions is required.
  See [Profiling a Web Request or CLI script](#profiling-a-web-request-or-cli-script).
  The extension is not needed to run XHGui itself.
If you need to decide which backend to use, you can check the [compatibility matrix](#compatibility-matrix) to see what features are implemented or missing per backend.
## MongoDB
The default installation uses MongoDB database. Most of the documentation speaks about MongoDB.
- **[MongoDB Extension][ext-mongodb]** (ext-mongodb) version **2.0 or later**: `pecl install mongodb`
- **[mongodb/mongodb][mongodb-library]** PHP library version **2.1 or later** (installed via Composer)
- **[MongoDB Server][mongodb]** version **3.2 or later**
[ext-mongodb]: https://pecl.php.net/package/mongodb
[mongodb-library]: https://packagist.org/packages/mongodb/mongodb
[mongodb]: https://www.mongodb.com/
## PDO
- [PDO][ext-pdo] PHP extension
Any of the drivers and an accompanying database:
- [SQLite (PDO)][ext-pdo_sqlite]
- [MySQL (PDO)][ext-pdo_mysql]
- [PostgreSQL (PDO)][ext-pdo_pgsql]
NOTE: PDO may not support all the features of XHGui, see [#320].
[ext-pdo]: https://www.php.net/manual/en/book.pdo.php
[ext-pdo_sqlite]: https://www.php.net/manual/en/ref.pdo-sqlite.php
[ext-pdo_mysql]: https://www.php.net/manual/en/ref.pdo-mysql.php
[ext-pdo_pgsql]: https://www.php.net/manual/en/ref.pdo-pgsql.php
[#320]: https://github.com/perftools/xhgui/issues/320
# Installation from source
## Prerequisites
1. **Install PHP 8.1 or later** with required extensions:
   ```bash
   # On Ubuntu/Debian
   sudo apt-get install php8.1 php8.1-cli php8.1-mongodb php8.1-json php8.1-mbstring
   # Or install ext-mongodb via PECL
   sudo pecl install mongodb
   ```
2. **Install MongoDB Server** (version 3.2 or later):
   ```bash
   # On Ubuntu/Debian - install MongoDB 6.0 or later
   # Follow official guide: https://www.mongodb.com/docs/manual/installation/
   # Start MongoDB service
   sudo systemctl start mongod
   sudo systemctl enable mongod
   ```
3. **Install Composer** (if not already installed):
   ```bash
   curl -sS https://getcomposer.org/installer | php
   sudo mv composer.phar /usr/local/bin/composer
   ```
## Installation Steps
1. **Clone or download `xhgui` from GitHub:**
   ```bash
   git clone https://github.com/perftools/xhgui.git
   cd xhgui
   ```
2. **Install PHP dependencies with Composer:**
   ```bash
   composer install --no-dev
   ```
   For development (includes testing tools):
   ```bash
   composer install
   ```
3. **Set the permissions on the `cache` directory:**
   ```bash
   chmod -R 0777 cache
   ```
4. **Configure MongoDB connection** (if not using defaults):
   Copy the default configuration file and customize it:
   ```bash
   cp src/config.default.php config/config.php
   ```
   Edit `config/config.php` to update MongoDB connection settings if your MongoDB:
   - Uses authentication
   - Runs on a non-default port
   - Runs on a different hostname
5. **Create MongoDB indexes** (recommended for performance):
   XHGui stores profiling information in a `results` collection in the `xhgui` database. Adding indexes improves query performance.
   Connect to MongoDB and create indexes:
   ```bash
   mongosh
   ```
   Then in the MongoDB shell:
   ```javascript
   use xhgui
   db.results.createIndex({ 'meta.SERVER.REQUEST_TIME': -1 })
   db.results.createIndex({ 'profile.main().wt': -1 })
   db.results.createIndex({ 'profile.main().mu': -1 })
   db.results.createIndex({ 'profile.main().cpu': -1 })
   db.results.createIndex({ 'meta.url': 1 })
   db.results.createIndex({ 'meta.simple_url': 1 })
   db.results.createIndex({ 'meta.SERVER.SERVER_NAME': 1 })
   db.results.createIndex({ 'meta.request_ts': 1 })
   ```
6. **Point your webserver to the `webroot` directory.**
   See the [Configuration section](#configuration) below for webserver setup examples.
## Verify Installation
Run the test suite to verify everything is working:
```bash
composer test
```
All tests should pass (some may be marked as incomplete or skipped depending on your setup).
# Installation with Docker
This setup uses [docker-compose] to orchestrate docker containers. The Docker setup includes:
- PHP 8.1+ with ext-mongodb 2.x
- MongoDB 6.0+
- Nginx web server
## Quick Start
1. **Copy example [`docker-compose.yml`][docker-compose.yml] from this project**
2. **Startup the containers:**
   ```bash
   docker-compose up -d
   ```
3. **Access the application:**
   - Open your browser at http://xhgui.127.0.0.1.nip.io:8142
   - Or just http://localhost:8142
   - Or type at terminal: `composer openurl`
4. **Customize configuration (optional):**
   Copy [`src/config.default.php`][src/config.default.php] to `config/config.php` and edit that file.
   ```bash
   cp src/config.default.php config/config.php
   ```
[docker-compose]: https://docs.docker.com/compose/
[docker-compose.yml]: https://github.com/perftools/xhgui/raw/HEAD/docker-compose.yml
[src/config.default.php]: https://github.com/perftools/xhgui/raw/HEAD/src/config.default.php
# Configuration
## Configure Webserver Re-Write Rules
XHGui prefers to have URL rewriting enabled, but will work without it.
For Apache, you can do the following to enable URL rewriting:
1. Make sure that an .htaccess override is allowed and that AllowOverride
   has the directive FileInfo set for the correct DocumentRoot.
   Example configuration for Apache 2.4:
   ```apache
   <Directory /var/www/xhgui/>
       Options Indexes FollowSymLinks
       AllowOverride FileInfo
       Require all granted
   </Directory>
   ```
2. Make sure you are loading up mod_rewrite correctly.
   You should see something like:
   ```apache
   LoadModule rewrite_module libexec/apache2/mod_rewrite.so
   ```
3. XHGui comes with a `.htaccess` file to enable the remaining rewrite rules.
For nginx and fast-cgi, you can use the following snippet as a start:
```nginx
server {
    listen   80;
    server_name example.com;
    # root directive should be global
    root   /var/www/example.com/public/xhgui/webroot/;
    index  index.php;
    location / {
        try_files $uri $uri/ /index.php?$args;
    }
    location ~ \.php$ {
        try_files $uri =404;
        include /etc/nginx/fastcgi_params;
        fastcgi_pass    127.0.0.1:9000;
        fastcgi_index   index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```
# Profiling a Web Request or CLI script
The supported way to profile an application is to use [perftools/php-profiler]
package.
You can use that package to collect data from your web application or a CLI script.
This data is then pushed into XHGui database where it can be viewed with XHGui application.
The `php-profiler` package offers submitting data directly to XHGui instance once the profiling is complete at the end of the request.
If the application cannot directly connect to XHGui instance, the package offers solution to capture profiling data to a file which you can import later using the [import][import-jsonl-files] script.
**Warning**: Importing the same file twice will create duplicate profiles.
## Importing Profiling Data

If your application cannot directly connect to XHGui, you can capture profiling data to files and import them later.

### CLI Import (Recommended)

Use the command-line import script for batch imports:

```bash
php external/import.php -f /path/to/profiles.jsonl
```

**Note**: `import.php` is a CLI-only script. Do NOT include it in your web server configuration.

### Web-Based Import

For importing via HTTP, use the web endpoint:

```bash
# Upload a file
curl -X POST -F "file=@profiles.jsonl" http://your-xhgui-server/external/web-import.php

# Or send raw data
curl -X POST --data-binary @profiles.jsonl http://your-xhgui-server/external/web-import.php
```

⚠️ **Security**: The web import endpoint should be protected with authentication or IP restrictions. See `external/README.md` for security configuration examples.

**Warning**: Importing the same file multiple times will create duplicate profiles.

For more details, see [external/README.md](external/README.md).

[perftools/php-profiler]: https://github.com/perftools/php-profiler
[import-jsonl-files]: https://github.com/perftools/php-profiler#import-jsonl-files
## Limiting MongoDB Disk Usage
Disk usage can grow quickly, especially when profiling applications with large code bases or that use larger frameworks.
To keep the growth in check, configure MongoDB to automatically delete profiling documents once they have reached a certain age by creating a [TTL index](http://docs.mongodb.org/manual/core/index-ttl/).
Decide on a maximum profile document age in seconds: you may wish to choose a lower value in development (where you profile everything), than production (where you profile only a selection of documents). The following command instructs MongoDB to delete documents over 5 days (432000 seconds) old.
```bash
mongosh
```
Then in the MongoDB shell:
```javascript
use xhgui
db.results.createIndex({ "meta.request_ts": 1 }, { expireAfterSeconds: 432000 })
```
## Waterfall Display
The goal of XHGui's waterfall display is to recognize that concurrent requests can affect each other. Concurrent database requests, CPU-intensive activities and even locks on session files can become relevant. With an Ajax-heavy application, understanding the page build is far more complex than a single load: hopefully the waterfall can help. Remember, if you're only profiling a sample of requests, the waterfall fills you with impolite lies.
Some Notes:
- There should probably be more indexes on MongoDB for this to be performant.
- The waterfall display introduces storage of a new `request_ts_micro` value, as second level granularity doesn't work well with waterfalls.
- The waterfall display is still very much in alpha.
- Feedback and pull requests are welcome :)
# Development
## Running Tests
XHGui includes a comprehensive test suite. To run the tests:
```bash
# Install development dependencies (if not already installed)
composer install
# Run the test suite
composer test
# Run tests with coverage
composer cover
```
## Code Quality
Check code style:
```bash
composer check-cs
```
Fix code style automatically:
```bash
composer fix-cs
```
## Requirements for Development
- PHP 8.1 or later
- ext-mongodb 2.0 or later
- MongoDB Server 3.2 or later (for integration tests)
- Composer
# Monitoring
[Prometheus](https://prometheus.io) metrics suitable for monitoring service health are exposed on `/metrics`.  (This currently only works if using PDO for storage.)
# Compatibility matrix
| Feature                         | MongoDB  | PDO      |
|---------------------------------|----------|----------|
| Prometheus exporter             | ✗        | ✓ [#305] |
| Searcher::latest()              | ✓        | ✓        |
| Searcher::query()               | ✓        | ✗ [#384] |
| Searcher::get()                 | ✓        | ✓        |
| Searcher::getForUrl()           | ✓        | ✓ [#436] |
| Searcher::getPercentileForUrl() | ✓        | ✓ [#436] |
| Searcher::getAvgsForUrl()       | ✓        | ✗ [#384] |
| Searcher::getAll(sort)          | ✓        | ✓ [#436] |
| Searcher::getAll(direction)     | ✓        | ✓ [#436] |
| Searcher::delete()              | ✓        | ✓        |
| Searcher::truncate()            | ✓        | ✓        |
| Searcher::saveWatch()           | ✓        | ✓ [#435] |
| Searcher::getAllWatches()       | ✓        | ✓ [#435] |
| Searcher::truncateWatches()     | ✓        | ✓ [#435] |
| Searcher::stats()               | ✗ [#305] | ✓        |
| Searcher::getAllServerNames()   | ✓ [#460] | ✗        |
[#305]: https://github.com/perftools/xhgui/pull/305
[#384]: https://github.com/perftools/xhgui/pull/384
[#435]: https://github.com/perftools/xhgui/pull/435
[#436]: https://github.com/perftools/xhgui/pull/436
[#460]: https://github.com/perftools/xhgui/pull/460
# Releases / Changelog
See the [releases](https://github.com/perftools/xhgui/releases) for changelogs and release information.
# License
Copyright (c) 2013 Mark Story & Paul Reinheimer
Permission is hereby granted, free of charge, to any person obtaining a
copy of this software and associated documentation files (the
"Software"), to deal in the Software without restriction, including
without limitation the rights to use, copy, modify, merge, publish,
distribute, sublicense, and/or sell copies of the Software, and to
permit persons to whom the Software is furnished to do so, subject to
the following conditions:
The above copyright notice and this permission notice shall be included
in all copies or substantial portions of the Software.
THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS
OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY
CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT,
TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE
SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
