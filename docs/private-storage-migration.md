# Private document storage migration

## Current storage boundaries

- Public: product photographs, product image URLs, and approved business logos.
- Private: supplier invoices, prescription scans, e-CF PDFs/XML/signatures/certificates, and spreadsheet imports.
- Receipts and quotes are generated on authenticated routes; they are not persisted as public files.
- The `private` disk defaults to local `storage/app/private`. Keep this directory on persistent storage and outside the web root.

New private attachments are served through authenticated, tenant-scoped routes. For backward compatibility, those routes can read old supplier-invoice and prescription paths from the public disk after authorization. Those legacy files are still directly retrievable by their old public URLs unless the web-server deny rules are active or the files have been migrated.

## Inventory and exposure review

Do this on the deployed host with a read-only database account and secure output storage. Do not expose the inventory or path manifest in a public ticket.

1. Count database references without selecting customer data:

   ```sql
   SELECT 'payables.invoice_file' AS reference_type, COUNT(*) AS references_count
   FROM payables WHERE invoice_file IS NOT NULL AND invoice_file <> ''
   UNION ALL
   SELECT 'prescriptions.image_path', COUNT(*)
   FROM prescriptions WHERE image_path IS NOT NULL AND image_path <> ''
   UNION ALL
   SELECT 'electronic_invoices.pdf_path', COUNT(*)
   FROM electronic_invoices WHERE pdf_path IS NOT NULL AND pdf_path <> ''
   UNION ALL
   SELECT 'electronic_invoices.signed_xml_path', COUNT(*)
   FROM electronic_invoices WHERE signed_xml_path IS NOT NULL AND signed_xml_path <> ''
   UNION ALL
   SELECT 'ecf_settings.certificate_path', COUNT(*)
   FROM ecf_settings WHERE certificate_path IS NOT NULL AND certificate_path <> '';
   ```

2. Inventory files on each configured storage backend (local public/private roots and any configured S3/Spaces buckets). Record relative paths, byte sizes, and SHA-256 digests in an access-restricted manifest. Also compare the inventory to database references and report unreferenced objects.
3. Review web-server access logs, CDN/cache logs, bucket ACL/policy, and historical public URLs for `/storage/payables/invoices/`, `/storage/ecf/`, `/storage/prescriptions/`, and `/storage/imports/`. Source code cannot establish whether a deployed symlink, web server, bucket, CDN, or prior cached URL exposed a file.
4. Apply the Apache rules in `public/.htaccess` or include `deploy/nginx/private-storage.conf` in the production Nginx server block. Confirm direct requests to every sensitive legacy path return 404 from the public hostname. The Nginx snippet is not active until included and reloaded.

## Copy and cutover (manual, gated)

Do not execute this procedure until the owner approves a maintenance window and the backups below have been verified.

1. Pause uploads and background writers. Capture a database snapshot and an archive of every relevant storage root/bucket. Keep both immutable and access-restricted; record hashes and restore-test the snapshots in an isolated environment.
2. Produce a mapping from each database reference to its source object, tenant, destination private path, size, and SHA-256. Do not infer ownership from a browser-supplied ID or filename.
3. Copy—not move—the files to `storage/app/private` or the private Spaces bucket. Preserve source files and references. Set local ownership/permissions so the application can read/write and the web-server account cannot serve the directory.
4. Compare source/destination counts, sizes, and SHA-256 values. Check private-disk access using the application and verify unauthenticated, wrong-role, and cross-business requests are denied. No DB reference changes before these checks pass.
5. Back up the affected reference values again. Update references in small transactions to the new relative private paths using a reviewed migration script. Verify records and downloads in staging, then production.
6. Rollback: restore the saved reference values or database snapshot, switch to the previous release if needed, and keep the source files untouched. Do not delete copied objects or public source files during rollback.
7. After an agreed retention period, inspect access logs and references again, invalidate/purge any CDN caches, then remove public copies only under a separately approved cleanup. If public exposure is confirmed, treat copies and cached URLs as disclosed; moving files does not revoke copies already downloaded or cached.

Never run broad `mv`, wildcard deletion, or bulk reference rewrites as part of deployment.

## DigitalOcean Spaces

Provision a dedicated bucket with private access and a least-privilege Spaces key. Configure `PRIVATE_FILESYSTEM_DRIVER=s3`, `DO_SPACES_KEY`, `DO_SPACES_SECRET`, `DO_SPACES_REGION`, `DO_SPACES_BUCKET`, and `DO_SPACES_ENDPOINT` using the region endpoint (for example `https://nyc3.digitaloceanspaces.com`). Keep `DO_SPACES_USE_PATH_STYLE_ENDPOINT=false` unless the chosen endpoint requires otherwise. Do not set a public URL/CDN for the private disk.

The S3 Flysystem adapter is included as a Composer dependency. Test private writes, reads, deletes, streamed downloads, and cross-tenant denial against a non-production bucket before cutover. Public product images must remain on the separate public disk; do not make the private bucket public to support image display.

`php artisan portability:backup` archives local `storage/app/private`; it does not export a Spaces bucket. Before using Spaces in production, provision a separate backup/versioning/replication policy, test object restoration, and verify it covers the private bucket. `portability:check` warns when the private disk uses S3, but that warning is not proof that a backup exists.
