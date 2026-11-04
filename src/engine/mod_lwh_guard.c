/* Keep distributed rewrite/handler rules inside their assigned FPM identity.
 * Runs after mod_rewrite's per-directory fixups. No .htaccess directives are
 * registered by this module; only root-controlled vhost configuration applies.
 */
#include "httpd.h"
#include "http_config.h"
#include "http_request.h"
#include "http_log.h"
#include "apr_strings.h"
#include <string.h>

module AP_MODULE_DECLARE_DATA lwh_guard_module;
typedef struct {
    const char *site_handler;
    const char *panel_handler;
} lwh_guard_config;

static void *create_config(apr_pool_t *pool, server_rec *server)
{
    (void)server;
    return apr_pcalloc(pool, sizeof(lwh_guard_config));
}

static const char *set_site(cmd_parms *cmd, void *unused, const char *value)
{
    lwh_guard_config *cfg = ap_get_module_config(cmd->server->module_config,
                                                &lwh_guard_module);
    (void)unused;
    cfg->site_handler = apr_pstrdup(cmd->pool, value);
    return NULL;
}

static const char *set_panel(cmd_parms *cmd, void *unused, const char *value)
{
    lwh_guard_config *cfg = ap_get_module_config(cmd->server->module_config,
                                                &lwh_guard_module);
    (void)unused;
    cfg->panel_handler = apr_pstrdup(cmd->pool, value);
    return NULL;
}

static int guard_request(request_rec *request)
{
    const lwh_guard_config *cfg = ap_get_module_config(
        request->server->module_config, &lwh_guard_module);
    const char *expected;
    const char *forced;
    if (!cfg || !cfg->site_handler) return DECLINED;

    /* [P] rewrites become proxy requests at fixups; normal SetHandler FPM
     * requests do not become proxies until their content handler executes. */
    if (request->proxyreq != PROXYREQ_NONE ||
        (request->filename && !strncmp(request->filename, "proxy:", 6))) {
        ap_log_rerror(APLOG_MARK, APLOG_WARNING, 0, request,
                      "lwh_guard: distributed proxy request denied");
        return HTTP_FORBIDDEN;
    }
    expected = cfg->site_handler;
    if (request->filename &&
        (!strncmp(request->filename, "/var/www/admin_panel/phpmyadmin/", sizeof("/var/www/admin_panel/phpmyadmin/") - 1) ||
         !strncmp(request->filename, "/var/www/admin_panel/dbadmin/", sizeof("/var/www/admin_panel/dbadmin/") - 1)))
        expected = cfg->panel_handler;
    /* mod_rewrite applies H from this note in its own late fixup. Check the
     * pending value as well as the current handler, independent of hook ties. */
    forced = apr_table_get(request->notes, "rewrite-forced-handler");
    if (forced && !strncmp(forced, "proxy:", 6) &&
        (!expected || strcmp(forced, expected)))
        return HTTP_FORBIDDEN;
    if (!request->handler || strncmp(request->handler, "proxy:", 6))
        return DECLINED;
    if (!expected || strcmp(request->handler, expected)) {
        ap_log_rerror(APLOG_MARK, APLOG_WARNING, 0, request,
                      "lwh_guard: foreign FPM handler denied");
        return HTTP_FORBIDDEN;
    }
    return DECLINED;
}

static const command_rec commands[] = {
    AP_INIT_TAKE1("LwhGuardSiteHandler", set_site, NULL, RSRC_CONF,
                  "The FPM handler assigned to this vhost"),
    AP_INIT_TAKE1("LwhGuardPanelHandler", set_panel, NULL, RSRC_CONF,
                  "The FPM handler for the root-managed database application"),
    { NULL }
};

static void register_hooks(apr_pool_t *pool)
{
    static const char * const predecessors[] = { "mod_rewrite.c", NULL };
    (void)pool;
    ap_hook_fixups(guard_request, predecessors, NULL, APR_HOOK_REALLY_LAST);
}

module AP_MODULE_DECLARE_DATA lwh_guard_module = {
    STANDARD20_MODULE_STUFF,
    NULL, NULL, create_config, NULL, commands, register_hooks
};
