# Changelog

All notable changes to the Xophz Compass Fresh Mints plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [2026-10-07]

### Fixed
- **Public Registry Data Integrity ([class-freshmints-api.php](file:///home/xopher/www/x/Xophz-COMPASS/wp-content/plugins/xophz-compass-fresh-mints/includes/class-freshmints-api.php))**: Removed fabricated PHP `uniqid()` license numbers and default city fallbacks in NY Socrata, NPPES, and FINRA branches. Incomplete records lacking valid license identifiers are now cleanly skipped, double "NY-NY-" prefixing is eliminated, and missing cities remain unpopulated instead of defaulting to New York City.

## [2026-09-18]

### Fixed
- **Dedicated Subdomain Routing ([class-xophz-compass-freshmints-public.php](file:///home/xopher/www/x/Xophz-COMPASS/wp-content/plugins/xophz-compass-fresh-mints/public/class-xophz-compass-freshmints-public.php))**: Added automatic detection for `freshmints.*` domains, dynamically resolving `load_mode` to `homepage` and mounting `appBase` to `/` so apex and subpath requests cleanly load the Fresh Mints dashboard.
- **Hook Priority Elevation ([xophz-compass-fresh-mints.php](file:///home/xopher/www/x/Xophz-COMPASS/wp-content/plugins/xophz-compass-fresh-mints/xophz-compass-fresh-mints.php))**: Adjusted `template_redirect` hook priority to 5 to ensure early routing resolution.
