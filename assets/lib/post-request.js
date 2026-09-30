/**
 * State-changing requests of the back-office: always a POST whose body carries
 * the back-office token, never a URL with the token in its query string (a URL
 * ends up in the browser history, in server logs and in proxies).
 *
 * The token is read from the <meta name="bo-token"> tag rendered by the layout.
 */
export function backOfficeToken() {
    return document.querySelector('meta[name="bo-token"]')?.getAttribute('content') ?? '';
}

/**
 * Body of a fetch() POST: the given fields plus the token.
 *
 * @param {Record<string, string|number>} fields
 * @returns {URLSearchParams}
 */
export function tokenBody(fields = {}) {
    const body = new URLSearchParams();
    Object.entries(fields).forEach(([name, value]) => body.set(name, String(value)));
    body.set('_token', backOfficeToken());

    return body;
}

/**
 * Navigates to the given URL through a POST form carrying the token, the way a
 * link would navigate with a GET.
 *
 * @param {string} url
 * @param {Record<string, string|number>} fields
 */
export function submitAsPost(url, fields = {}) {
    const form = document.createElement('form');
    form.method = 'post';
    form.action = url;
    form.hidden = true;

    tokenBody(fields).forEach((value, name) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.append(input);
    });

    document.body.append(form);
    form.submit();
}
