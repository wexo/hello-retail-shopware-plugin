#! /bin/bash
# Every HR_*_HOST is a BARE HOSTNAME, and nothing here normalises one. HR_CORE_HOST and HR_DASHBOARD_HOST
# also reach docker-compose's extra_hosts, which interpolates the raw shell value into the container's
# /etc/hosts before this script ever runs. Compose cannot strip anything, so any tolerance in here would
# apply to the SDK hosts and NOT to /etc/hosts, and the two would disagree. A trailing slash is the case
# that bites: the daemon accepts `example.test/:host-gateway`, the container comes up with a bogus hosts
# entry, a normalising entrypoint writes the slash-free name into the template, and that name then resolves
# nowhere -- a failure with nothing to report it. A scheme, by contrast, is already rejected loudly at
# container-create. Refusing anything but a bare hostname is the only treatment that is identical on both
# paths, and it is the only guard HR_CDN_HOST gets at all: it has no extra_hosts entry, and an unnoticed
# scheme there produces cdn_host=https://https://... and 502s every asset in the browser.
#
# An IP address is the same silent class as the trailing slash, and the hostname regex below would otherwise
# take one: digits and dots satisfy every label. Measured, not assumed. `extra_hosts: "192.168.1.50:host-
# gateway"` comes up clean and puts `192.168.5.2  192.168.1.50` in the container /etc/hosts -- an entry whose
# NAME is an address. Nothing ever reads it: getaddrinfo, php gethostbyname and curl all take the literal and
# never consult the hosts file, so the entry does not redirect that address either. It is simply inert. What
# is left is a value pasted into an https:// URL that no certificate here covers, so the browser rejects
# every SDK call, and the extra_hosts mechanism the whole design rests on has quietly done nothing.
hr_require_hostname() {
    local name="$1" value="$2"
    if [[ ! $value =~ ^[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)*$ ]]; then
        echo "entrypoint: ERROR - $name must be a bare hostname, got \"$value\"." >&2
        echo "entrypoint:   No scheme, no port, no path, no trailing slash. All three variables are pasted" >&2
        echo "entrypoint:   straight into an https:// URL for the SDK; HR_CORE_HOST and HR_DASHBOARD_HOST also" >&2
        echo "entrypoint:   go into the container /etc/hosts verbatim, where anything else cannot resolve." >&2
        echo "entrypoint:   HR_CDN_HOST has no /etc/hosts entry at all, so this check is the only guard it gets." >&2
        exit 1
    fi
    if [[ $value =~ ^[0-9]+(\.[0-9]+)*$ ]]; then
        echo "entrypoint: ERROR - $name must be a hostname, not an IP address, got \"$value\"." >&2
        echo "entrypoint:   The dev hosts are names: https://$value has no certificate the browser will accept," >&2
        echo "entrypoint:   and for HR_CORE_HOST / HR_DASHBOARD_HOST the extra_hosts entry the daemon writes for" >&2
        echo "entrypoint:   an address is never read -- a resolver does not look an address up -- so it points" >&2
        echo "entrypoint:   nowhere." >&2
        exit 1
    fi
}

# The local Hello Retail app's hosts. Override them if you run the app somewhere else; the defaults are
# the primary local instance from docker-dev-env.
# Apply a change with `docker compose up -d`, which RECREATES the container. `docker compose restart` does
# not: it stops and starts the container that already exists, and a container's environment is fixed when it
# is created, so the new value never arrives and this script reports `already current` while the storefront
# keeps serving the old host.
HR_CORE_HOST="${HR_CORE_HOST:-core.dev.helloretail.com}"
HR_DASHBOARD_HOST="${HR_DASHBOARD_HOST:-my.dev.helloretail.com}"
hr_require_hostname HR_CORE_HOST "$HR_CORE_HOST"
hr_require_hostname HR_DASHBOARD_HOST "$HR_DASHBOARD_HOST"
# The SDK's asset CDN. Deliberately has NO default: leave it unset and the SDK keeps loading assets from
# production, which works. The dev-env has no nginx server block for helloretailcdn.test (nor for the old
# d1pna5l3xsntoj.cloudfront.test), so both fall through to the default 443 block, get proxied to
# host.docker.internal:8080 and answer 502 -- dead when the developer runs on 8081-8084, and the wrong
# instance even when 8080 is up. cdn_host feeds slick/swiper, ppBusiness.js, feed-loader.gif and the partner
# stylesheets, so defaulting it to a .test name turns working asset loads into 502s. Set it only when you
# are actually serving the CDN locally.
# Unset and empty both mean "leave the SDK on the production CDN", so only a non-empty value is validated.
HR_CDN_HOST="${HR_CDN_HOST:-}"
if [ -n "$HR_CDN_HOST" ]; then
    hr_require_hostname HR_CDN_HOST "$HR_CDN_HOST"
fi

# Everything this script reports goes to stderr, progress included. Docker captures stdout and stderr as two
# separate streams and `docker logs` merges them by arrival, so a mixed script prints out of order: the
# warnings and errors below would appear ABOVE the per-file lines they summarise. One stream, one order.
echo "Starting entrypoint" >&2

# The deployed plugin, not the repo's ./src: docker-compose mounts the `shopware` named volume over
# /usr/app/src and does not bind-mount the plugin source, so this is the copy the storefront renders.
# Patching it therefore cannot reach the developer's git checkout.
PLUGIN_DIR=/usr/app/src/custom/plugins/HelloRetail

# The hosts are not a plugin setting. config.xml declares no field for them, and the storefront's
# Resources/views/storefront/layout/meta.html.twig calls `hrq.push(['init'])` with no configuration object
# at all, so the SDK falls back to its built-in production hosts. Point it at the local app by writing the
# override object into that init call.
#
# Key spelling is the SDK's, not the old fragment's: helloretail.js merges the object straight onto its conf
# (`Object.assign(_.conf, conf)`), and conf only ever reads cdn_host / core_host / dashboard_host. The old
# sed's `server_host` is dropped -- it is in neither SDK, and the legacy loader's fragment parser has an
# explicit key allowlist that never contained it, so it was always discarded.
echo "Pointing the storefront SDK at the local app" >&2

HR_CDN_HOST="$HR_CDN_HOST" HR_CORE_HOST="$HR_CORE_HOST" HR_DASHBOARD_HOST="$HR_DASHBOARD_HOST" \
PLUGIN_DIR="$PLUGIN_DIR" php -r '
$hosts = array(
    "core_host" => "https://" . getenv("HR_CORE_HOST"),
    "dashboard_host" => "https://" . getenv("HR_DASHBOARD_HOST"),
);
$cdn = getenv("HR_CDN_HOST");
if ($cdn !== false && $cdn !== "") {
    $hosts["cdn_host"] = "https://" . $cdn;
}

// cdn_host is managed either way: written when HR_CDN_HOST is set, and removed when it is not. Only adding
// it would let a value an earlier start wrote outlive the variable and keep 502ing forever on a volume that
// survives -- the same freeze that left the old sed pinning core.helloretail.test.
$apply = function (array $current) use ($hosts) {
    $merged = array_merge($current, $hosts);
    if (!isset($hosts["cdn_host"])) {
        unset($merged["cdn_host"]);
    }
    return $merged;
};
$dir = getenv("PLUGIN_DIR");

// present:    templates that exist in the deployed plugin.
// patched:    templates that now carry our hosts (freshly written, or already correct).
// unmatched:  templates that exist but whose init call we did not recognise.
// left_alone: templates holding an object we refuse to guess at.
// rendering_unmatched: of the unmatched ones, those we know reach the browser (see below).
// Counting these is the point: a rewrite that quietly matches nothing is precisely the failure this script
// exists to end, so "no error" must never be mistaken for "the hosts were applied".
//
// Know what patched does NOT prove: it counts a file on disk carrying our hosts, never that the storefront
// RENDERS that file. Full reachability is not checked here, and could not be without rendering a page.
// On the current image the two candidates are not equal in that respect. The template that actually reaches
// the browser is component/hello-retail-tracking.html.twig, and it gets there through the plugin copy of
// storefront/base.html.twig, which sw_includes it inside the storefront block `base_script_csrf`. The
// plugin layout/meta.html.twig sw_includes the very same component from `layout_head_javascript_csrf` -- a
// block the vendor meta.html.twig nests inside a feature(FEATURE_NEXT_15917) condition that is off -- so
// that override is inert: it compiles, it is patched, and it never renders. Confirmed live with injected
// markers: a marker in meta.html.twig does not appear in the page, one in base.html.twig does.
//
// So a plain "patched > 0" gate is too weak: the inert meta override alone can satisfy it while the
// component that does render is unmatched, which is a storefront on the PRODUCTION hosts with exit 0.
// The obvious tightening -- demand that EVERY present candidate be resolved -- is wrong in the other
// direction: on this very image meta.html.twig is present and carries no init call at all, so that rule
// fails the normal, working case. What the two candidates differ in is not presence but render authority,
// and that we do know: $renders below marks the candidate verified to reach the browser where it exists.
// An unmatched call in THAT file is fatal even when a sibling patched, because the patched sibling cannot
// stand in for it. The residual, and it stays a residual: a future plugin that carries the init call only
// in meta.html.twig, in a block that likewise does not render, still scores patched = 1 and exits 0. That
// one needs a rendered page to catch, and this gate does not render one.
$state = array("present" => 0, "patched" => 0, "unmatched" => 0, "left_alone" => 0, "rendering_unmatched" => 0);

// $build returns the replacement text, or null to mean "do not touch this one".
// $renders says this candidate is known to reach the browser wherever it is deployed.
$rewrite = function ($file, $pattern, $build, $renders) use (&$state) {
    $name = basename($file);
    if (!is_file($file)) {
        fwrite(STDERR, "entrypoint: " . $name . " is not deployed, skipping\n");
        return;
    }
    $state["present"]++;

    $before = file_get_contents($file);
    if ($before === false) {
        fwrite(STDERR, "entrypoint: ERROR - cannot read " . $name . "\n");
        exit(1);
    }

    $left_alone = false;
    $recognised = 0;
    $after = preg_replace_callback(
        $pattern,
        function ($m) use ($build, &$left_alone, &$recognised) {
            $replacement = $build($m);
            if ($replacement === null) {
                $left_alone = true;
                return $m[0];
            }
            $recognised++;
            return $replacement;
        },
        $before,
        -1,
        $count
    );

    if ($after === null) {
        fwrite(STDERR, "entrypoint: ERROR - pattern failed on " . $name . ": " . preg_last_error_msg() . "\n");
        exit(1);
    }
    if ($count === 0) {
        // Deployed, but the init call does not look the way we expect: a refactor, an added Twig filter, or
        // the call having moved into the compiled storefront bundle. Report it rather than passing silently.
        $state["unmatched"]++;
        if ($renders) {
            $state["rendering_unmatched"]++;
        }
        fwrite(STDERR, "entrypoint: " . $name . " carries no init call we recognise\n");
        return;
    }
    if ($left_alone) {
        // Drives the warning only. A file can hold more than one init call -- a second one behind a Twig
        // condition, say -- and refusing to guess at one of them must not throw away a sibling we did
        // recognise. That rewrite still has to reach disk, or the call we understood perfectly well would
        // keep its production hosts with nothing but a warning to show for it.
        $state["left_alone"]++;
        fwrite(STDERR, "entrypoint: left the hand-written init object in " . $name . " alone\n");
    }
    if ($recognised === 0) {
        // Every call in this file is one we refuse to guess at, so there is nothing of ours to write and
        // nothing to count as patched: the hosts are whatever those objects already say.
        return;
    }
    if ($after !== $before) {
        // An unchecked write would print "updated" even on a read-only or wrong-owner file, which is the
        // same silent-success trap as a non-matching pattern. A short write is a worse case than a rejected
        // one: file_put_contents can return a byte count smaller than strlen($after) -- a filesystem filling
        // mid-write, say -- instead of false, and this gate would then report the template as updated while
        // a truncated file sits where the storefront reads it. That is worse than an unpatched template: an
        // unpatched one still renders. Writing to a temp file in the same directory, checking its byte count
        // there, and renaming over the original only once that count matches keeps a short write off the
        // path the storefront actually serves; the original is untouched until the rename, and rename() on
        // the same filesystem is atomic, so no reader ever observes a half-written file under the real name.
        $tmp = $file . "." . getmypid() . ".tmp";
        $written = file_put_contents($tmp, $after);
        if ($written !== strlen($after)) {
            @unlink($tmp);
            fwrite(STDERR, "entrypoint: ERROR - short write to " . $name . " ("
                . var_export($written, true) . " of " . strlen($after) . " bytes); left the original untouched\n");
            exit(1);
        }
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            fwrite(STDERR, "entrypoint: ERROR - cannot replace " . $name . "\n");
            exit(1);
        }
        fwrite(STDERR, "entrypoint: updated " . $name . "\n");
    } else {
        fwrite(STDERR, "entrypoint: already current " . $name . "\n");
    }
    $state["patched"]++;
};

// The template the plugin ships today. The optional `, {...}` is an object an earlier start wrote, or one
// the developer added by hand; matching it as well as the bare form is what makes this idempotent. The
// object never contains a `]`, so stopping at the first one is enough to find the end of the argument.
$rewrite(
    $dir . "/src/Resources/views/storefront/layout/meta.html.twig",
    "/hrq\.push\(\s*\[\s*([\x27\"])init\\1\s*(,[^\]]*)?\]\s*\)/",
    function ($m) use ($apply) {
        $current = array();
        if (isset($m[2])) {
            $argument = trim(substr($m[2], 1));
            $decoded = json_decode($argument, true);
            if (is_array($decoded)) {
                $current = $decoded;
            } elseif ($argument !== "") {
                // Something we did not write and cannot parse -- a Twig expression, or a variable reference.
                // Merging would mean guessing, so leave it and let the caller report it as unresolved.
                return null;
            }
        }
        // Merge, so a websiteUuid / trackingOptOut the developer put there survives and only the hosts move.
        return "hrq.push([\x27init\x27, " . json_encode($apply($current), JSON_UNESCAPED_SLASHES) . "])";
    },
    // Not marked as rendering: on the current image this override sits in a block that never renders, and
    // on a plugin where it does render there is no second candidate to mistake it for.
    false
);

// A volume from before the plugin moved to helloretail.js still serves the legacy loader, whose hosts ride
// in the script src fragment as a comma-separated key=value list. The image bakes an already-patched copy of
// it -- patched with the hosts of whichever start captured the image -- which is why the old sed, written to
// match only the pristine line, never fired again and left those hosts frozen. Match the patched form too.
$rewrite(
    $dir . "/src/Resources/views/storefront/component/hello-retail-tracking.html.twig",
    "/(awAddGift\.js#\{\{\s*addWishPartnerId\s*\}\})([^\x27\"]*)/",
    function ($m) use ($apply) {
        $pairs = array();
        foreach (explode(",", $m[2]) as $pair) {
            if (strpos($pair, "=") === false) {
                continue;
            }
            list($k, $v) = explode("=", $pair, 2);
            // server_host is litter from the old sed: the fragment parser in the legacy loader matches
            // against a fixed key allowlist that never contained it, so it has never done anything.
            // Drop it rather than carry it forward.
            if (trim($k) !== "" && trim($k) !== "server_host") {
                $pairs[trim($k)] = $v;
            }
        }
        $fragment = "";
        foreach ($apply($pairs) as $k => $v) {
            $fragment .= "," . $k . "=" . $v;
        }
        return $m[1] . $fragment;
    },
    // Marked as rendering: wherever this component is deployed it is pulled into the page by the plugin
    // base.html.twig, from a block that does render. Verified with injected markers.
    true
);

// The plugin directory, not the template count, is what says whether the integration is deployed. Reading
// present === 0 as "not deployed" would fold two very different states into one: a volume with no plugin,
// which is harmless, and a deployed plugin whose init template has been renamed or moved, which is the
// storefront talking to the PRODUCTION hosts. Only the first may exit 0.
if (!is_dir($dir)) {
    // No Hello Retail plugin at all, so there is no integration to misdirect. Worth saying, not failing.
    fwrite(STDERR, "entrypoint: WARNING - the HelloRetail plugin is not deployed; nothing to point at\n");
    exit(0);
}

if ($state["present"] === 0) {
    fwrite(STDERR, "entrypoint: ERROR - the HelloRetail plugin is deployed at " . $dir . " but none of the\n");
    fwrite(STDERR, "entrypoint:   templates this script patches exist there; they have been renamed, moved or\n");
    fwrite(STDERR, "entrypoint:   dropped. The storefront would use the PRODUCTION Hello Retail hosts.\n");
    exit(1);
}

if ($state["patched"] === 0) {
    // The integration IS deployed but none of it carries our hosts, so the storefront would talk to the
    // production Hello Retail. Refuse to start rather than let that happen unnoticed -- silently serving
    // production from a dev box is the exact bug this script was rewritten to end.
    fwrite(STDERR, "entrypoint: ERROR - the HelloRetail plugin is deployed but no template now carries the\n");
    fwrite(STDERR, "entrypoint:   local hosts (" . $state["unmatched"] . " unrecognised, "
        . $state["left_alone"] . " left alone). The storefront would use the PRODUCTION Hello Retail hosts.\n");
    exit(1);
}

if ($state["rendering_unmatched"] > 0) {
    // Something IS patched, but not the candidate we know reaches the browser, and a patched file that
    // never renders proves nothing about the one that does. Treating the sibling as a stand-in is exactly
    // how this gate would wave through a storefront on the production hosts.
    fwrite(STDERR, "entrypoint: ERROR - " . $state["rendering_unmatched"] . " template(s) that the storefront\n");
    fwrite(STDERR, "entrypoint:   actually renders carry no init call we recognise. Another template was\n");
    fwrite(STDERR, "entrypoint:   patched, but it cannot stand in for them: the rendered page would use the\n");
    fwrite(STDERR, "entrypoint:   PRODUCTION Hello Retail hosts.\n");
    exit(1);
}

if ($state["left_alone"] > 0) {
    fwrite(STDERR, "entrypoint: WARNING - " . $state["left_alone"] . " template(s) hold a hand-written init\n");
    fwrite(STDERR, "entrypoint:   object that was left untouched; their hosts are whatever that object says.\n");
}
' || exit 1

# Twig compiles templates to PHP under var/cache/<env>_<hash>/twig, and the image ships a warm cache built
# from the templates as they were when it was captured. Under APP_ENV=dev Twig auto-reloads on mtime, so this
# is redundant there, but the image's own .env sets APP_ENV=prod, where it is required. It is cheap either way.
#
# The status matters. On a fresh volume neither directory is there, the glob stays unexpanded, and `rm -rf`
# on a path that does not exist succeeds -- that must keep being a success. A genuine removal failure is the
# opposite case: root-owned leftovers from an older image, or a read-only mount. Then the stale compiled
# template and the cached page survive, the storefront keeps serving the hosts they were built with, and a
# container that started anyway would reproduce exactly the misdirection the gate above just refused.
echo "Clearing the compiled twig templates" >&2
rm -rf /usr/app/src/var/cache/*/twig || {
    echo "entrypoint: ERROR - could not clear the compiled twig templates; Twig would keep serving the" >&2
    echo "entrypoint:   templates as they were, with their old Hello Retail hosts." >&2
    exit 1
}

# The same .env sets SHOPWARE_HTTP_CACHE_ENABLED=1 with SHOPWARE_HTTP_DEFAULT_TTL=7200, and that flag is read
# straight from the environment (shopware.http.cache.enabled), NOT gated on APP_ENV -- so whole anonymous page
# responses are cached with the old hosts baked into the body. Without this, a developer who repoints
# HR_CORE_HOST, runs `docker compose up -d` and opens the homepage anonymously keeps seeing the previous host
# for up to two hours; only logging in or filling the cart bypasses it.
# cache.http is backed by the cache.app filesystem pool (framework.yaml: cache.http -> adapter: cache.app), so
# it lives in pools/app. Dropping that directory also drops cache.object/tags/rate_limiter, which rebuild on
# demand; pools/system (container metadata) is kept, and the theme is NOT recompiled -- theme:compile only
# builds SCSS and JS, which a template edit cannot affect, and it is far too slow to run on every start.
# `bin/console cache:pool:clear cache.http` would be surgical, but it boots the kernel and needs the database,
# and compose declares no depends_on for shopwaredb, so it cannot be relied on at start.
echo "Clearing the cached HTTP responses" >&2
rm -rf /usr/app/src/var/cache/*/pools/app || {
    echo "entrypoint: ERROR - could not clear the cached HTTP responses; anonymous pages would keep being" >&2
    echo "entrypoint:   served from cache, with their old Hello Retail hosts, for up to the 7200s TTL." >&2
    exit 1
}

exec $@
