#define _GNU_SOURCE
#include <curl/curl.h>
#include <dlfcn.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#undef curl_easy_setopt

/* Test-only libcurl outcomes. Run exclusively in a network-none container. */
static void record_event(const char *event)
{
    const char *path = getenv("DOVEADM_TEST_CURL_LOG");
    if (!path) _Exit(91);
    FILE *file = fopen(path, "a");
    if (!file) _Exit(92);
    fprintf(file, "%s\n", event);
    fclose(file);
}

CURL *curl_easy_init(void)
{
    const char *fail = getenv("DOVEADM_TEST_CURL_FAIL_INIT");
    if (fail && strcmp(fail, "1") == 0) {
        unsetenv("DOVEADM_TEST_CURL_FAIL_INIT");
        record_event("init_false");
        return NULL;
    }
    CURL *(*real_init)(void) = dlsym(RTLD_NEXT, "curl_easy_init");
    if (!real_init) _Exit(93);
    CURL *handle = real_init();
    record_event(handle ? "init_ok" : "unexpected_init_false");
    return handle;
}

CURLcode curl_easy_setopt(CURL *handle, CURLoption option, ...)
{
    (void)handle;
    (void)option;
    record_event("setopt_blocked");
    return CURLE_UNKNOWN_OPTION;
}

CURLcode curl_easy_perform(CURL *handle)
{
    (void)handle;
    record_event("unexpected_perform_blocked");
    return CURLE_ABORTED_BY_CALLBACK;
}

CURLMcode curl_multi_perform(CURLM *multi, int *running)
{
    (void)multi;
    *running = 0;
    record_event("unexpected_multi_perform_blocked");
    return CURLM_INTERNAL_ERROR;
}

/* PHP 8.3 may deep-bind extensions, bypassing LD_PRELOAD without this shim. */
void *dlopen(const char *filename, int flags)
{
    void *(*real_dlopen)(const char *, int) = dlsym(RTLD_NEXT, "dlopen");
    if (!real_dlopen) _Exit(94);
    return real_dlopen(filename, flags & ~RTLD_DEEPBIND);
}
