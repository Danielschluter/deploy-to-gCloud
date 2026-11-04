# PHP + PostgreSQL Data Tools

A small PHP app, served by nginx and PHP-FPM, for loading CSV files into
PostgreSQL and browsing the resulting tables. It runs locally with Docker
Compose and deploys to Google Cloud Run with Cloud SQL.

- **CSV Import** (`/csv-import.php`): upload a CSV and create or append to a table.
  Column types are inferred from every row: `bigint`, `numeric` (exact) or `text`.
  Numbers with leading zeros, such as ZIP codes, stay text. Each import is all or
  nothing.
- **View Tables** (`/view-tables.php`): browse tables 20 rows at a time, delete
  rows, and clear or drop tables.

Both pages can change or delete data. They require an admin login, and every
change needs a CSRF token.

```
src/              web root
  index.php, csv-import.php, view-tables.php, healthz.php
  lib/            shared code (never served)
docker/           nginx, PHP-FPM and PHP config for the image
tests/            CSV helper tests and fixtures
Dockerfile        one image: nginx + PHP-FPM, listens on $PORT
```

## Configuration

| Variable | Default | |
|---|---|---|
| `DB_SOCKET` | | Unix socket directory, e.g. `/cloudsql/PROJECT:REGION:INSTANCE` on Cloud Run. Overrides `DB_HOST`/`DB_PORT`. |
| `DB_HOST`, `DB_PORT` | `db`, `5432` | |
| `DB_NAME` | `appdb` | Falls back to `POSTGRES_DB`. |
| `DB_USER`, `DB_PASSWORD` | | Required. Fall back to `POSTGRES_USER`/`POSTGRES_PASSWORD`. |
| `DB_SSLMODE` | | e.g. `require` when connecting over a public IP. |
| `ADMIN_USER` | `admin` | Login for the admin pages (HTTP Basic auth). |
| `ADMIN_PASSWORD_HASH` | | Preferred. Generate with `php -r 'echo password_hash("...", PASSWORD_DEFAULT), "\n";'` |
| `ADMIN_PASSWORD` | | Plain-text alternative to the hash. |
| `APP_ENV` | `production` | `local` leaves the admin pages open when no admin password is set. In production they stay closed until one is. |
| `FPM_MAX_CHILDREN` | `8` | PHP workers per container. |

## Run locally

```bash
cp .env.example .env
```

```bash
docker compose up --build
```

Open http://localhost:8080. `src/` is mounted into the container, so edits show
up without a rebuild. Postgres listens on `127.0.0.1:5432` for local clients
only.

## Tests

```bash
php tests/csv_test.php
```

CI (`.github/workflows/ci.yml`) also starts the full stack in production mode and
checks login, CSRF, an import (including that ZIP codes and decimals come through
intact), row deletion, and that internal files are never served.

## Deploy to Google Cloud Run + Cloud SQL

Set the names once:

```bash
PROJECT=your-project REGION=us-central1 INSTANCE=app-db
```

Create the database (skip if you have one):

```bash
gcloud sql instances create $INSTANCE --project $PROJECT --region $REGION --database-version POSTGRES_15 --tier db-f1-micro
```

```bash
gcloud sql databases create appdb --instance $INSTANCE --project $PROJECT
```

Create the database user with a generated password, and keep that password in
Secret Manager:

```bash
DB_PASSWORD=$(openssl rand -base64 24)
```

```bash
gcloud sql users create app --instance $INSTANCE --project $PROJECT --password "$DB_PASSWORD"
```

```bash
printf '%s' "$DB_PASSWORD" | gcloud secrets create db-password --project $PROJECT --data-file=-
```

Store a hash of the admin password the same way:

```bash
php -r 'echo password_hash("THE-ADMIN-PASSWORD", PASSWORD_DEFAULT);' | gcloud secrets create admin-password-hash --project $PROJECT --data-file=-
```

The Cloud Run service account needs `roles/cloudsql.client` and
`roles/secretmanager.secretAccessor`. Then deploy:

```bash
gcloud run deploy data-tools \
  --project $PROJECT \
  --region $REGION \
  --source . \
  --allow-unauthenticated \
  --add-cloudsql-instances $PROJECT:$REGION:$INSTANCE \
  --set-env-vars "DB_SOCKET=/cloudsql/$PROJECT:$REGION:$INSTANCE,DB_NAME=appdb,DB_USER=app" \
  --set-secrets "DB_PASSWORD=db-password:latest,ADMIN_PASSWORD_HASH=admin-password-hash:latest"
```

`--source .` builds the `Dockerfile` with Cloud Build. To keep the whole app
private, drop `--allow-unauthenticated` and grant `roles/run.invoker` to the
people who need it, or put it behind IAP.

## Notes

- Uploads are limited to 20 MB (`docker/php.ini` and `client_max_body_size` in
  `docker/nginx.conf`).
- New tables get an `id bigserial` primary key. A CSV column named `id` is
  imported as `csv_id`.
- Rows can only be deleted one at a time in tables that have an `id` column.
  "Clear table" and "Delete table" don't cascade, so they fail rather than empty
  tables that reference this one.
