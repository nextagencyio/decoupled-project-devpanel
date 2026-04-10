# Decoupled.io — DevPanel Template

Drupal 11 headless CMS template for [DevPanel](https://devpanel.com) hosting. Same `dc_core` install profile and custom modules that power [Decoupled.io](https://decoupled.io), adapted to run on DevPanel's container platform.

## What's Included

- **Drupal 11** with PHP 8.3
- **`dc_core` install profile** with 12 custom modules:
  - `dc_admin` — Gin admin theme + toolbar
  - `dc_api` — GraphQL + JSON:API + OAuth
  - `dc_config` — Configuration helper + OAuth credentials page
  - `dc_config_import` — REST API for importing Drupal config as YAML
  - `dc_fields` — Base text formats, CKEditor5, tags vocabulary
  - `dc_import` — REST API for importing content model + data as JSON
  - `dc_login` — One-time login URL generation
  - `dc_mail` — Resend HTTP API mail handler
  - `dc_puck` — Visual page builder (Puck editor) integration
  - `dc_revalidate` — Next.js on-demand revalidation
  - `dc_usage` — Usage tracking + limits
  - `dc_user_redirect` — User access handling
- **GraphQL** via GraphQL Compose (auto-generated types from schema)
- **JSON:API** with write support for content CRUD
- **OAuth 2.0** for frontend authentication (Next.js, Astro, etc.)
- **Paragraphs** for flexible content composition

## Quick Start

### DevPanel (Production)

1. Create a new DevPanel project
2. Connect this repository as the source
3. Push to `main` — DevPanel runs `.devpanel/init.sh` automatically
4. Site installs with `dc_core` profile
5. Visit `/dc-config` for OAuth credentials and frontend setup

### DDEV (Local Development)

```bash
ddev start
# Wait for init.sh to complete (installs Drupal + dc_core profile)
ddev drush uli  # Get a login link
```

### VS Code Dev Container

1. Open the project in VS Code
2. "Reopen in Container" when prompted
3. The `updateContentCommand` runs `.devpanel/init.sh` automatically

## Environment Variables

DevPanel injects these automatically. For DDEV they're set in `.ddev/config.yaml`:

| Variable | Purpose |
|---|---|
| `DB_HOST` | Database hostname |
| `DB_PORT` | Database port (3306) |
| `DB_USER` | Database username |
| `DB_PASSWORD` | Database password |
| `DB_NAME` | Database name |
| `DB_DRIVER` | Database driver (mysql) |
| `DP_APP_ID` | DevPanel application identifier |
| `DP_HOSTNAME` | Site hostname (for trusted_host_patterns) |

### Optional

| Variable | Purpose |
|---|---|
| `RESEND_API_KEY` | Email sending via Resend HTTP API (dc_mail) |

## DevPanel Scripts

| Script | When | What |
|---|---|---|
| `.devpanel/init.sh` | First deploy / DDEV start | Composer install, site install with dc_core, cache warm |
| `.devpanel/init-container.sh` | Container restart | DB import, updatedb, cache warm |
| `.devpanel/re-config.sh` | Re-deploy | Composer install, settings patch, files import |
| `.devpanel/custom_package_installer.sh` | Container creation | Install npm, APCu, AVIF, uploadprogress |
| `.devpanel/create_quickstart.sh` | Manual | Export DB + files for Docker image |
| `.devpanel/warm` | After install/update | PHP-level page cache warming |

## API Endpoints

After installation, the following endpoints are available:

| Endpoint | Method | Purpose |
|---|---|---|
| `/graphql` | POST | GraphQL API (auto-generated schema) |
| `/jsonapi` | GET/POST/PATCH/DELETE | JSON:API for content CRUD |
| `/oauth/token` | POST | OAuth 2.0 token endpoint |
| `/dc-config` | GET | Configuration page with OAuth credentials |
| `/api/dc-import/import` | POST | Import content model + data as JSON |
| `/api/dc-config-import` | POST | Import Drupal config as YAML |

## Relationship to Decoupled.io

This template is a **DevPanel port** of the canonical `drupal-project` that powers the Decoupled.io platform. The Drupal code (composer.json, dc_core profile, custom modules) is identical — only the hosting integration layer (`.devpanel/` instead of Docker/Kubernetes) differs.

To keep in sync with upstream, periodically pull changes from the canonical template:

```bash
# Compare with canonical
diff -rq web/profiles/dc_core/ /path/to/decoupled-docker/drupal-project/web/profiles/dc_core/

# Copy updated files
cp /path/to/decoupled-docker/drupal-project/composer.json .
cp /path/to/decoupled-docker/drupal-project/composer.lock .
cp -R /path/to/decoupled-docker/drupal-project/web/profiles/dc_core/ web/profiles/dc_core/
```
