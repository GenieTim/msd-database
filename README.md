# msd-database

Symfony/Doctrine application to use to build a simple material safety data application with API.
The original use was to have a backend for [ChemWizard](https://github.com/BernhardWebstudio/ChemWizard).
Note that the data might be fetched by scraping other websites – make sure to get permissions first.

## Deploying with Coolify / Docker Compose

This repository is ready to deploy directly on **[Coolify](https://coolify.io)** (or any Docker Compose host):

1. **Add to Coolify**:
   - In Coolify, create a new **Resource** -> **Docker Compose** (or **Public / Private Git Repository**).
   - Select this repository and branch.
2. **Environment Variables**:
   Configure the following in the Coolify environment settings:
   - `APP_SECRET`: Generate a random 32-character hex string (e.g. `openssl rand -hex 16`).
   - `DATABASE_URL`: (Optional) Defaults to SQLite stored in the persistent volume (`sqlite:///%kernel.project_dir%/var/data/data.db`). If you prefer PostgreSQL or MariaDB, configure your database resource and pass its URL here.
   - `TRUSTED_PROXIES`: Defaults to `10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.1` so Coolify's Traefik reverse proxy headers are trusted for HTTPS and host resolution.
3. **Deploy**:
   - Click **Deploy**. Coolify builds the multi-stage image (compiles frontend assets with Webpack Encore, installs production PHP dependencies, warms up Symfony cache) and starts FrankenPHP.
   - On container boot, the entrypoint script automatically checks database connectivity, runs schema migrations, and imports the default H & P statements.
   - Persistent storage for SQLite and database files is managed via the `app_data` named volume (`/app/var/data`).

### Running Locally with Docker Compose

```bash
# Build and run with docker compose
docker compose up -d

# Open http://localhost:80 in your browser
```

---

## Manual Installation (Without Docker)

Please note: the following information might not be sufficient in case this is your first 
Symfony project – I recommend you check out some of their getting started documentation.
In case you have/had troubles, feel free to open an issue so I can help you 
or a PR to improve this README.

Well, well, well – as this is a full web application, you will need a webserver. 
On this webserver, you need a serving software, such as Apache, Nginx, or FrankenPHP. 
And you need a domain to access the site.
Alternatively, you use the local development servers offered by Symfony.

1. Clone this repository.
2. Install PHP dependencies using `composer install` (PHP >= 8.4 is required).
3. Install Node dependencies using `yarn install` and build assets with `yarn build`.
4. Copy `.env.dist` to `.env` and set your credentials.
5. Compile assets and clear cache with `./bin/update.sh`.

## Usage

If you've come this far, open your browser, point it to the domain where this app is installed, 
and use the app – it's not hard from here.

## Contributing

Yes, please!

There are quite a few things which could/should be done:

### Data Sources
- [x] PubChem (NCBI)
- [x] Wikidata
- [x] ECHA (European Chemicals Agency — REACH / CLP)
- [x] ChEBI (EMBL-EBI)
- [x] EPA CompTox Chemicals Dashboard
- [x] NIST Chemistry WebBook
- [x] GESTIS (DGUV)
- [x] Sigma-Aldrich

### TODO
- [ ] Improve search speed & stability
