# PGClient

A vanilla PHP web client for PostgreSQL (dashboard, SQL editor, schema explorer, table
viewer, CSV import, multiple connections), served by Nginx and PHP-FPM with a PostgreSQL
database using Docker Compose.

Quick start:

```bash
# Build and start services
docker compose up --build

# Open in browser
# Visit http://localhost:8080
```

On first visit the setup wizard asks for an admin login and a database connection. For the
bundled database use host `db`, port `5432`, database `appdb`, user `user`, password `password`
(set in `docker-compose.yml`).

Notes:
- PHP files are in `src/`.
- `php/Dockerfile` installs `pdo_pgsql`.
- Exposed ports: PHP site on `8080`, Postgres on `5432`.
- PGClient's login and connections are stored in `pgclient.json` in the `pgclient_config`
  volume (`PGCLIENT_CONFIG_DIR`), outside the web root.

## Deploy to a Google Cloud VM

`deploy/gcp-deploy.sh` creates a Compute Engine VM (Debian 12, `e2-small`) with a static IP,
opens port 80, installs Docker, uploads the app and starts it with `docker-compose.prod.yml`.
Re-run it to deploy code changes; the database and generated credentials are kept.

Requirements: a Google Cloud project with billing enabled, and `gcloud` logged in.
[Cloud Shell](https://shell.cloud.google.com) has everything already:

```bash
git clone <this repo> && cd deploy-to-gCloud
PROJECT_ID=your-project-id ./deploy/gcp-deploy.sh
```

Optional: `ZONE` (default `us-central1-a`), `VM_NAME` (default `php-postgres-app`),
`MACHINE_TYPE` (default `e2-small`). If a VM with that name already exists, the script
deploys onto it.

The script prints the URL and the PGClient login. The production setup differs from local dev:
- There is no setup wizard: the deploy writes PGClient's admin login and a "Local PostgreSQL"
  connection to the bundled database. More connections can be added in the Connections page.
- Postgres is not exposed outside the VM, and its password is random.
- Credentials live in `/opt/app/.env` on the VM. To change the PGClient login, edit
  `ADMIN_USER`/`ADMIN_PASSWORD` there and re-run the script.
- Uploads are allowed up to 20 MB.

The site is served over plain HTTP, so the login is sent unencrypted. For HTTPS, point a
domain at the VM's IP and put a TLS-terminating proxy (e.g. Caddy) or a Google Cloud load
balancer in front of it.

Useful commands:

```bash
gcloud compute ssh php-postgres-app --zone us-central1-a        # shell on the VM
sudo docker compose -f /opt/app/docker-compose.prod.yml logs -f  # (on the VM) logs
sudo docker compose -f /opt/app/docker-compose.prod.yml exec db \
  pg_dump -U app appdb > backup.sql                              # (on the VM) DB backup
```

To remove everything (this deletes the database):

```bash
gcloud compute instances delete php-postgres-app --zone us-central1-a
gcloud compute addresses delete php-postgres-app-ip --region us-central1
gcloud compute firewall-rules delete default-allow-http  # only if nothing else uses it
```
