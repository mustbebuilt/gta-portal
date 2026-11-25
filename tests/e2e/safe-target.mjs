export function testBaseUrl(env = process.env) {
    const url = new URL(env.BASE_URL || 'http://127.0.0.1:8000');
    if (!['http:', 'https:'].includes(url.protocol) || !['127.0.0.1', 'localhost', '[::1]'].includes(url.hostname)
        || url.username || url.password || url.search || url.hash || url.pathname !== '/') {
        throw new Error('Browser tests require a loopback origin and fictional local data. Production and remote hosts are blocked.');
    }
    return url.origin;
}
