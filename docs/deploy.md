Deploy runbook — ComerciosRD
=============================

Purpose
-------
This document is a step-by-step checklist to deploy ComerciosRD to production safely, plus preflight checks, health checks, and a rollback plan.

Before you start
----------------
- Do not store secrets in the repository. Use a secure vault or environment variables in your hosting platform.
- Prepare a production `.env` using `.env.production.example` (replace placeholders with real values).
- Ensure you have an automatic DB backup and at least one tested restore snapshot.
- Have access to the application server, supervisor/systemd (for queues), and the logs.
- For the 10,000-concurrent-user target, follow `docs/scale-readiness-10000.md` and pass its staging load test before choosing production capacity.

1) Preflight (on staging that mirrors production)
------------------------------------------------
1. Copy `.env.production.example` → `.env` on staging and fill placeholders with staging credentials.
2. Run basic caching and checks:
   - php artisan config:cache
   - php artisan route:cache
   - php artisan view:cache
3. Run security preflight strictly:
   - php artisan security:preflight --strict
     - Fix any issues reported (CSP, session fingerprint, missing TLS, 2FA policy, etc.).
4. Dry-run migrations:
   - php artisan migrate --pretend
   - Review SQL statements. If destructive, schedule maintenance window.
5. Run test suite (feature & smoke):
   - `php artisan test --testsuite=Feature` (PHPUnit forces the local `comercios_rd_storage_test` database at `127.0.0.1`; provision and migrate only that test database first. It never uses `comercios_rd` or a remote DB host.)
6. Verify UI and POS flows in staging (login, POS sale, printing labels, retail reports, e-CF sandbox if applicable).

2) Deployment steps (production)
--------------------------------
Run these commands from your release directory (or via your CI/CD runner). Use maintenance mode for downtime-sensitive migrations.

- Pull new release / upload artifacts
  - git fetch && git checkout <release-tag> (or CI artifact copy)

- Install dependencies
  - composer install --no-dev --prefer-dist --optimize-autoloader
  - npm ci && npm run build (only if assets changed)

- Ensure storage symlink (one-time)
  - php artisan storage:link
- Ensure private document storage is persistent and inaccessible to the web server:
  - `PRIVATE_FILESYSTEM_DRIVER=local` keeps sensitive files in `storage/app/private`.
  - Mount a persistent volume at that directory; do not put it under `public/`.
  - For Nginx, include `deploy/nginx/private-storage.conf` in the HTTPS server block before generic static-file regex locations, then reload Nginx.
  - Apache deployments use the deny rules in `public/.htaccess`.
  - Do not remove existing public copies until following `docs/private-storage-migration.md`.
- DigitalOcean Spaces is supported by `PRIVATE_FILESYSTEM_DRIVER=s3` with a private bucket and `DO_SPACES_*` values; use a dedicated Spaces access key and do not configure a public CDN URL for this disk.

- Put app into maintenance mode (recommended for DB migrations)
  - php artisan down --message="Deploy in progress"

- Database migrations
  - php artisan migrate --force
  - If you have seeders for non-production data, do not run them in prod.

- Cache & optimization
  - php artisan config:cache
  - php artisan route:cache
  - php artisan view:cache

- Queue workers
  - php artisan queue:restart
  - Then ensure supervisor/systemd reloads workers (example):
    - supervisorctl reread && supervisorctl update && supervisorctl restart all
    - or systemctl restart your-worker.service

- Bring app up
  - php artisan up

- Post-deploy warm-up
  - curl -sSf https://your-production-domain.com/health || true
  - php artisan queue:failed (verify none immediately)
  - tail -n 200 storage/logs/laravel.log | grep -i "exception\|error" || true

3) Post-deploy verification (first 30 minutes)
--------------------------------------------
- Verify HTTPS and headers:
  - curl -I https://your-production-domain.com
  - Check response headers include HSTS and CSP as configured.
- Login as owner/admin and verify 2FA flow if required.
- Run a POS sale (test product/variant):
  - Create a quick sale on POS and confirm stock decremented by correct variant.
  - Check InventoryMovement entries for variant_id.
- Export a small report and verify it runs within acceptable time.
- Confirm worker jobs run (emails, background jobs).
- Check your monitoring dashboards (Sentry/Prometheus/CloudWatch).

4) Health checks & monitoring (basic list)
-----------------------------------------
- Application health endpoint `/health` should return 200 and useful metrics.
- Queue length should be minimal and failing jobs should be alerted.
- Error rate (5xx) should be within baseline.
- Logs stream to central logging and errors create alerts.

5) Rollback plan
----------------
- Keep previous release artifacts on disk (releases/<timestamp>), served by symlink.
- If rollback is needed and migrations were non-destructive:
  1. Switch symlink back to previous release.
  2. php artisan queue:restart
  3. Check logs and worker queue.
- If migration was destructive, rollback requires DB restore from backup:
  - Restore from most recent pre-deploy backup.
  - Restore app release symlink to previous.
  - Restart workers & services.
- Practice the rollback in staging before relying on it in production.

6) CI / Pipeline (recommended minimal outline)
--------------------------------------------
- Pipeline jobs: test → build → deploy (manual protection on deploy to prod)

Example GitHub Actions minimal flow (conceptual):

name: CI
on: [push]

jobs:
  tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.2
      - run: composer install --no-interaction --prefer-dist
      - run: cp .env.ci .env
      - run: php artisan key:generate
      - run: php artisan migrate --env=testing --force
      - run: php artisan test --testsuite=Feature

  build:
    needs: tests
    runs-on: ubuntu-latest
    steps:
      - run: composer install --no-dev --optimize-autoloader
      - run: npm ci && npm run build

  deploy:
    needs: build
    if: github.ref == 'refs/heads/main' && github.event_name == 'push'
    runs-on: ubuntu-latest
    steps:
      - name: Deploy to host
        run: ./deploy-scripts/deploy-prod.sh
        # deploy script should run the commands from this doc on the server

7) Quick commands & checks
--------------------------
- Security preflight (must pass on prod):
  - php artisan security:preflight --strict
- Cache commands:
  - php artisan config:cache
  - php artisan route:cache
  - php artisan view:cache
- Queue management:
  - php artisan queue:restart
  - php artisan queue:work --tries=3 --timeout=120 (for supervisor config)
- Troubleshooting logs:
  - tail -f storage/logs/laravel.log
  - php artisan tinker (debug small checks)

8) Notes & recommendations
--------------------------
- Replace any `TIME=480` generic variable. Use explicit names: SESSION_LIFETIME and MASTER_SESSION_LIFETIME.
- Keep APP_KEY unchanged across deploys; rotating it invalidates cookies/sessions.
- Use Redis for session & cache if you run in containers or ephemeral filesystems.
- Enforce 2FA for admin/owner roles in production.
- Regularly test DB backups and rollbacks.
- Automate as much as possible in CI; manual deploy should be rare and protected by approvals.
- e-CF remains disabled with `ECF_ENABLED=false`. Changing the environment flag alone
  cannot enable it: a reviewed code change must approve the completed XAdES implementation
  and DGII certification tests first. Existing e-CF records are retained; ordinary sales,
  refunds, inventory, accounting and reports do not depend on this feature.

Contact & Runbook
-----------------
- Document on-call contacts and escalation steps in a separate runbook (ops/team contact list).

If you want, next tasks I can do for you:
- Create `docs/deploy.sh` deploy script template to run the exact commands in order.
- Add GitHub Actions workflow file in `.github/workflows/ci.yml` (example).
- Create the minimal feature tests (POS sale / report smoke) scaffolding.

Deploy script included
----------------------
- Script path: `deploy-scripts/deploy-prod.sh`
- Run:
  - `bash deploy-scripts/deploy-prod.sh`
- Optional flags (environment variables):
  - `SKIP_NPM=1 bash deploy-scripts/deploy-prod.sh`
  - `SKIP_DOWN=1 bash deploy-scripts/deploy-prod.sh`
  - `SKIP_SECURITY_PREFLIGHT=1 bash deploy-scripts/deploy-prod.sh`
