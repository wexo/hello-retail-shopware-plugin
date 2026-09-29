# Shopware 6 Hello Retail extension

## Local development

Needs `docker-dev-env` running (nginx and the `dev-env` network) with the dev hostnames in `/etc/hosts` — see its readme.

    docker compose up -d

Storefront: https://shopware.hrdev.test/

Compose does not mount `./src` into the container: the `shopware` service runs the plugin baked into its image, not the code in this repo. Editing `src/` will not change what the storefront serves.

Point it at another local app instance:

    HR_CORE_HOST=core.dev1.helloretail.com HR_DASHBOARD_HOST=my.dev1.helloretail.com docker compose up -d

| Variable | Default |
| --- | --- |
| `HR_CORE_HOST` | `core.dev.helloretail.com` |
| `HR_DASHBOARD_HOST` | `my.dev.helloretail.com` |
| `HR_CDN_HOST` | unset — leave it unless you host the CDN locally; `helloretailcdn.test` 502s |

Bare hostnames only — no scheme, port, path, trailing slash or IP. The entrypoint refuses anything else rather than starting against production hosts.

Use `up -d`, not `restart`: `restart` reuses the container and does not re-read the environment, so the shop keeps the old hosts.

Check it worked: view source and look for `core_host=https://core.dev…` in the `awAddGift.js` script tag's src fragment. This image serves the legacy loader fragment, not an SDK init object.
