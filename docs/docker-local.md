# Local Docker environment and staging notes

## Scope and safety boundaries

`compose.yaml` is a local-only stack. It uses a new MySQL database named
`comercios_rd_docker_test`, a dedicated application account, and named Docker
volumes. It does not bind-mount project storage, host databases, `.env`, or
historical documents. MySQL, Redis, and PHP-FPM have no published host ports;
only Nginx is published, on `127.0.0.1:8080`.

The PHP entrypoint refuses to start if its local-only database, Redis, or
e-CF settings differ from the hard-coded safe values. The stack does not run
migrations, seeders, `storage:link`, or scheduled tasks automatically.
e-CF and portability backups are explicitly disabled. The optional scheduler
profile runs only one scheduler service; do not scale it above one replica.
Both Compose networks are internal, so the application and Nginx have no
outbound network access from this local stack.

The private storage volume is mounted only in Laravel services and is never
mounted in Nginx. Product images are persisted in the separate public volume;
Nginx reads that volume read-only and serves only image files beneath
`/storage/products/`. No historical `storage/app/public/ecf` documents are
copied into the build context or mounted in the stack.

## Start locally

1. Install Docker Engine and the Docker Compose v2 plugin. Do not install them
   automatically as part of this project setup.
2. Create a local-only environment file, which is ignored by Git:

   ```sh
   cp .env.docker.example .env.docker
   ```

   Set `COMERCIOS_DOCKER_APP_KEY` to a unique Laravel key (`base64:` plus
   base64-encoded 32 random bytes). Set both password variables to distinct,
   locally generated random values. Do not copy credentials from any existing
   `.env` file or deployment.
3. Check the rendered configuration and build the images:

   ```sh
   docker compose --env-file .env.docker config --quiet
   docker compose --env-file .env.docker build
   ```

4. Start only the isolated database and Redis first, then the application:

   ```sh
   docker compose --env-file .env.docker up -d mysql redis
   docker compose --env-file .env.docker up -d app worker nginx
   ```

   Confirm all required services are healthy. Before any schema-writing
   command, verify the application environment contains
   `APP_ENV=local`, `DB_HOST=mysql`, `DB_DATABASE=comercios_rd_docker_test`,
   `DB_USERNAME=comercios_docker_app`, an empty `DB_URL`, and
   `DOCKER_LOCAL_ISOLATION=1`. Do not print or share the password values.
   The entrypoint repeats the isolation check on every PHP service start.
5. Only after confirming the isolated database identity, a developer may
   initialize this disposable local database explicitly:

   ```sh
   docker compose --env-file .env.docker exec app php artisan migrate
   ```

   This step is intentionally manual. It does not connect to or modify
   `comercios_rd` or another existing database.
6. Visit `http://127.0.0.1:8080`. The Laravel `/up` endpoint is also used by
   the Nginx health check. To start the optional scheduler, first ensure the
   local schema and configuration are ready, then run:

   ```sh
   docker compose --env-file .env.docker --profile scheduler up -d scheduler
   ```

No e-CF integration, production mail, external database, historical-file
import, or automatic database migration is part of this local stack. Laravel
logs go to container output; inspect them with `docker compose logs`.

## Verify Nginx protections

Run these checks only after building and starting the stack. `nginx -T` is
important: the private-storage snippet must appear in the loaded configuration,
not merely exist on disk.

```sh
docker compose --env-file .env.docker exec nginx nginx -t
docker compose --env-file .env.docker exec nginx nginx -T
curl -i http://127.0.0.1:8080/up
curl -i http://127.0.0.1:8080/storage/ecf
curl -i http://127.0.0.1:8080/storage/ecf/
curl -i http://127.0.0.1:8080/storage/payables/invoices/example.pdf
curl -i http://127.0.0.1:8080/storage/prescriptions/example.png
curl -i http://127.0.0.1:8080/storage/imports/example.xlsx
curl -i http://127.0.0.1:8080/.env
curl -i http://127.0.0.1:8080/index.php/.env
curl -i http://127.0.0.1:8080/storage/products/example.php
curl --path-as-is -i 'http://127.0.0.1:8080/storage/ecf/%2e%2e/ecf/example.pdf'
curl --path-as-is -i 'http://127.0.0.1:8080/storage/%65cf/example.pdf'
```

Sensitive paths and arbitrary PHP paths must not return file contents. Nginx
returns `404` for blocked legacy paths and unsupported files beneath
`/storage/`. Test encoded and normalized path variants as well as the plain
paths. To verify the public-image allowlist, upload a disposable test image
through the application after the local schema is initialized, request its
exact `/storage/products/...` URL and confirm `200` with the expected image
content type, then remove only that explicitly identified test object. A `404`
for a nonexistent image alone does not prove that valid product images work.

The container images and Compose configuration have not been runtime-tested
on this host if Docker or Nginx is unavailable. Do not describe the Nginx
rules as verified until `nginx -t`, `nginx -T`, and the HTTP checks above pass.

## Storage, backups, and operations

- MySQL, Redis, public uploads, private files, Laravel framework runtime files,
  and application logs use separate named volumes. The public upload mount is
  read-only inside Nginx.
- Back up MySQL and persistent private/public files to a separately secured
  destination. A Docker volume is persistence, not a backup. Test restoration
  into a separate isolated stack before relying on it.
- Do not use `docker compose down -v` if any local volume must be retained; it
  deletes the named volumes. Remove only an explicitly identified disposable
  volume after confirming its contents and obtaining the appropriate approval.
- `FILESYSTEM_DISK=local` and `PRIVATE_FILESYSTEM_DRIVER=local` are deliberate
  local defaults. The existing private disk can use the S3-compatible
  DigitalOcean Spaces driver with `PRIVATE_FILESYSTEM_DRIVER=s3` and
  `DO_SPACES_*` secrets supplied by a secret manager. No Spaces credentials
  belong in this repository or image. Product images currently use the local
  public disk; moving them to Spaces requires a separately planned public-disk
  configuration and an Nginx/CDN delivery design.
- Keep `PORTABILITY_BACKUP_ENABLED=false` until external backup storage and
  restore procedures have been explicitly implemented and tested.

## DigitalOcean staging prerequisites

This Compose file intentionally refuses external database and Redis
connections. Do not reuse it as a production deployment manifest. Before
staging, prepare a separate deployment definition with a private VPC path to
managed MySQL/Redis or isolated database containers, explicit outbound access
for required services, and the local-isolation guard disabled only for that
reviewed deployment.

Build and publish images through a controlled CI pipeline. Pin PHP, Composer,
Node, Nginx, MySQL, and Redis base images by immutable digest for releases;
the Dockerfile's default tags are convenient version tags, not a guarantee of
bit-for-bit reproducible builds. Retain the Composer and npm lockfiles, scan
images and dependencies, and generate/retain build provenance and an SBOM.
Provide `APP_KEY`, database/Redis credentials, and any Spaces credentials from
DigitalOcean's secret store at runtime; never bake them into an image.

Terminate TLS at a properly configured DigitalOcean load balancer or a
separately managed HTTPS proxy, restrict inbound traffic with cloud firewalls,
and configure Laravel to trust only the actual proxy addresses. Add HTTPS
redirects and HSTS at the TLS termination layer after verifying proxy
behavior. Keep MySQL, Redis, and PHP-FPM unreachable from the public network.
Run the web, worker, and exactly one scheduler as separate least-privilege
services. Configure external monitoring, log retention, alerting, encrypted
off-site backups, and a tested restoration procedure before opening staging to
users.

The historical-document migration remains a separate gated operation. Follow
[`private-storage-migration.md`](private-storage-migration.md) only after a
backup, integrity checks, authorization tests, and explicit approval; this
Compose stack neither mounts nor migrates those documents.
