# PHP + PostgreSQL Sample

This workspace contains a minimal PHP app served by Nginx and PHP-FPM with a PostgreSQL database using Docker Compose.

Quick start:

```bash
# Build and start services
docker compose up --build

# Open in browser
# Visit http://localhost:8080
```

Default DB credentials (set in `docker-compose.yml`):
- POSTGRES_USER: `user`
- POSTGRES_PASSWORD: `password`
- POSTGRES_DB: `appdb`

Notes:
- PHP files are in `src/`.
- `php/Dockerfile` installs `pdo_pgsql`.
- Exposed ports: PHP site on `8080`, Postgres on `5432`.

If you want to override DB credentials, create a `.env` file or set environment variables before bringing up the stack.
