# Changelog

All notable changes to dbAPI are documented here. Version numbers follow [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-06-12

- First public release


## [1.0.1] - 2026-06-12

- Publish docker image to Docker Hub


## [1.1.0] - 2026-06-14

- Implement access control enhancements and update documentation


## [1.2.0] - 2026-06-14

- Implement access control enhancements and update documentation


## [1.2.1] - 2026-06-15

- Release v1.2.1.


## [1.2.2] - 2026-06-15

- Update management API for single mode


## [1.2.3] - 2026-06-15

- Update management API for single mode


## [1.2.4] - 2026-06-16

- Enhance management API with single deployment mode support


## [1.2.5] - 2026-06-16

- Update Docker publish workflow to dynamically set image name


## [1.2.6] - 2026-06-16

- Enhance Docker setup for consumer projects. Refactor webhooks dispatcher in Docker setup


## [1.3.0] - 2026-06-29

- Implement sub-relation handling in API


## [1.4.0] - 2026-07-20

- Upgrade to PHP 8.3 


## [1.4.1] - 2026-07-20

- Update testing configurations and enhance error handling in DataPlane tests


## [1.4.2] - 2026-08-31

- In single-mode Docker, overlay `DB_*` and `CONFIG_API_SECRET` at request time so regenerated `connection.php` / `admin_config.php` keep working.
- Keep PHP `display_errors` off in FPM (`php_admin_flag`) and in CodeIgniter development mode.
- CSV import across API endpoints.
- Pagination enhancements.
- Sparse fieldsets on `include`d resources honor `fields[{type}]` (JSON:API) and path keys `fields[{parent}/{rel}]`; outbound FK columns stay selected so relationship linkages and include hydration are not dropped when omitted from `fields`.
- CSV/XLS export uses explicit sparse fieldsets (`exportFields`) so auto-added PK/FK columns for query hydration are not exported as extra columns.
- CSV/XLS export flattens outbound (1:1) `include` relations into columns (`rel.field`); inbound (1:n) includes are skipped.
- Remove PHP resource hooks (`hooks/<entity>/before.insert.php`, etc.); use Redis webhooks for side effects.


## [1.4.3] - 2026-09-01

- Fix PHP-FPM OPcache for PHP 8.3: load settings from conf.d (opcache.enable is startup-only; fast_shutdown was removed) and drop public/.user.ini that disabled it.


## [1.4.4] - 2026-09-02

- Add unauthenticated `GET /health` liveness probe; Docker `HEALTHCHECK` uses it.


## [1.5.0] - 2026-09-08

- dbAuth refresh tokens: optional `refresh_validity` issues an opaque rotating `refresh_token` (hashed in `dbapi_refresh_tokens`, skipped on introspect). `POST .../auth/refresh` rotates; reuse of the old token returns 401. `POST .../auth/logout` revokes.


## [1.5.1] - 2026-09-18

- Release v1.5.1.


## [Unreleased]


