# FSL (Friends StarCraft League) Web Application

A web application for managing StarCraft 2 team league operations, including player management, match scheduling, statistics, and draft functionality.

## Features

- Player profiles and statistics tracking
- Team management and roster tracking
- Match scheduling and score reporting
- Draft system for team league seasons
- Spider chart voting system for player attributes
- Admin panel for managing content
- Public-facing pages for teams, players, and matches

## Requirements

- PHP 7.4 or higher
- MySQL/MariaDB database
- Apache or Nginx web server
- PDO MySQL extension

## Installation

1. Clone the repository
2. Copy `config.php.example` to `config.php` and fill in your database credentials
3. Import the database schema from `schema.sql`
4. Set proper file permissions for `uploads/` and `draft/data/` directories
5. Configure your web server to point to this directory

## Security Notes

⚠️ **IMPORTANT**: Before deploying or committing:

1. **Never commit `config.php`** - It contains sensitive database credentials and API tokens
2. **Remove or restrict access to `phpinfo.php`** - It exposes server configuration
3. **Review `.gitignore`** - Ensure sensitive files are excluded
4. **Change default passwords** - Update all default credentials
5. **Set proper file permissions** - Restrict access to sensitive files

## Directory Structure

- `/admin` - Admin interface files
- `/ajax` - AJAX endpoints
- `/css` - Stylesheets
- `/draft` - Draft system (admin, public, team views)
- `/includes` - Shared PHP includes (database, functions)
- `/images` - Image assets
- `/js` - JavaScript files
- `/uploads` - User-uploaded files

## Draft System

The draft system (`/draft`) is a self-contained module that:
- Uses JSON files for data storage (no database required)
- Supports snake draft with timer functionality
- Provides separate views for admin, public spectators, and team captains
- Implements file locking for concurrent access

## License

[Your License Here]
