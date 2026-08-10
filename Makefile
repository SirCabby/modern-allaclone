# modern-allaclone — containerised item/quest browser pointed at the live `peq`
# database. See DEPLOY.md for what each piece actually does.
#
# Every target drives the RUNNING container, so nothing here needs PHP on the host.
# `make` with no target prints the list below.

CONTAINER ?= modern-allaclone
COMPOSE   ?= docker compose
ARTISAN    = docker exec $(CONTAINER) php artisan

.DEFAULT_GOAL := help

.PHONY: help up down restart rebuild logs shell \
        refresh index-quests index-eras index-lists cache-clear \
        migrate verify-db require-container wait-ready

##@ General

help: ## Show this help
	@printf '\nmodern-allaclone — usage: make [target]\n'
	@awk 'BEGIN { FS = ":[^#]*## " } \
	     /^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5); next } \
	     /^[a-zA-Z0-9_-]+:[^#]*## / { printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2 }' \
	     $(MAKEFILE_LIST)
	@printf '\n'

##@ Container

up: ## Start the container (http://<host>:8081)
	$(COMPOSE) up -d

down: ## Stop and remove the container
	$(COMPOSE) down

# `docker compose restart` keeps the previous env — env_file values are injected at
# create time, so a credential change needs the container recreated, not restarted.
restart: ## Recreate the container so .env changes take effect
	$(COMPOSE) up -d --force-recreate

# opcache.validate_timestamps=0, so a code change is invisible until the image is rebuilt.
rebuild: ## Rebuild the image and recreate (required for any code change)
	$(COMPOSE) build
	$(COMPOSE) up -d --force-recreate

logs: ## Follow container logs
	$(COMPOSE) logs -f

shell: require-container ## Shell into the container
	docker exec -it $(CONTAINER) sh

##@ Site data

# Everything between an edit and what a browser is served. Each stage is here
# because leaving it out fails silently -- the site keeps serving the old thing
# and says nothing -- which is the whole reason this is one target:
#   1. rebuild           — opcache.validate_timestamps=0, so the code baked into
#      the image is all php ever reads. Editing the checkout changes nothing on
#      its own, and neither does restarting.
#   2. migrate           — the allaclone-data volume mounts OVER database/, which
#      hides the migrations the image just baked; `make migrate` copies them in.
#   3. quests:index      — quest_scripts + item/npc/task cross-references
#   4. items:index-eras  — reads quest rewards, so it must follow quests:index
#   5. items:index-lists — resolves resources/item-lists/*.txt to item ids, and
#      settles same-named items by which one the era index reached, so it must
#      follow items:index-eras
#   6. cache:clear       — last, because rendered page data (TTLs from a day to
#      forever) lives in the sqlite `cache` table and survives the rebuild, so
#      pages cached before it read the new indexes only after this drops them.
#
# Stages 3-6 are also on their own as index-quests / index-eras / index-lists /
# cache-clear, for when you know only the data moved.
refresh: ## Ship everything: rebuild the image, migrate, reindex, drop caches
	@printf '>> [1/6] rebuilding image and recreating container...\n'
	@$(MAKE) --no-print-directory rebuild
	@$(MAKE) --no-print-directory wait-ready
	@printf '>> [2/6] applying migrations...\n'
	@$(MAKE) --no-print-directory migrate
	@printf '>> [3/6] indexing quest scripts...\n'
	$(ARTISAN) quests:index --no-interaction
	@printf '>> [4/6] indexing item eras (~48k items — this is the slow one)...\n'
	$(ARTISAN) items:index-eras --no-interaction
	@printf '>> [5/6] indexing item lists...\n'
	$(ARTISAN) items:index-lists --no-interaction
	@printf '>> [6/6] clearing cached pages...\n'
	$(ARTISAN) cache:clear --no-interaction
	@printf '>> refresh complete.\n'

# `up -d` returns once the container exists, but the entrypoint still has
# package:discover, migrate and the config/route/view caches to get through
# before it execs supervisord. Driving artisan inside that window races the
# config cache, so wait for nginx to answer first. Any HTTP status will do --
# this is asking whether the stack is up, not whether the page is healthy.
wait-ready: require-container ## Block until the container is serving
	@i=0; until docker exec $(CONTAINER) curl -s -o /dev/null http://127.0.0.1:8080/ 2>/dev/null; do \
	    i=$$((i + 1)); \
	    if [ $$i -ge 60 ]; then printf '>> [%s] did not start serving within 60s\n' '$(CONTAINER)'; exit 1; fi; \
	    sleep 1; \
	done

index-quests: require-container ## Reindex quest scripts only
	$(ARTISAN) quests:index --no-interaction

index-eras: require-container ## Rebuild the item era index only (wants a current quest index)
	$(ARTISAN) items:index-eras --no-interaction

index-lists: require-container ## Rebuild the item list index only (wants a current era index)
	$(ARTISAN) items:index-lists --no-interaction

cache-clear: require-container ## Drop cached page data only
	$(ARTISAN) cache:clear --no-interaction

##@ Maintenance

# The allaclone-data volume mounts OVER /var/www/html/database, so migration files
# baked into the image are invisible to a volume that already exists. Copying is
# idempotent; a fresh volume is seeded from the image and needs none of this.
migrate: require-container ## Apply Laravel migrations (copies them into the volume first)
	@for f in database/migrations/*.php; do docker cp "$$f" $(CONTAINER):/var/www/html/database/migrations/; done
	$(ARTISAN) migrate --force --no-interaction

# Read-only. Do NOT check this by attempting a write: if the account is not actually
# read-only the write succeeds and leaves an artifact in peq.
verify-db: require-container ## Show which peq account the app connects as, and its grants
	@$(ARTISAN) tinker --execute='$$c = DB::connection("eqemu"); echo $$c->select("SELECT CURRENT_USER() u")[0]->u."\n"; foreach ($$c->select("SHOW GRANTS FOR CURRENT_USER()") as $$r) { echo array_values((array)$$r)[0]."\n"; }'

require-container:
	@docker ps --format '{{.Names}}' | grep -qx '$(CONTAINER)' || { \
	  printf '>> [%s] is not running — run `make up` first.\n' '$(CONTAINER)'; exit 1; }
