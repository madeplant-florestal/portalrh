const assert = require('node:assert/strict');
const { buildShareLinks, generateTrackedUrl } = require('../../public/assets/share-utils.js');

const baseUrl = 'https://example.com/vaga/10?foo=bar';

const tracked = generateTrackedUrl(baseUrl, 'linkedin');
assert.ok(tracked.includes('utm_source=linkedin'));
assert.ok(tracked.includes('utm_medium=social'));
assert.ok(tracked.includes('utm_campaign=vagas'));

const links = buildShareLinks(baseUrl, 'Vaga PHP');
assert.ok(links.facebook.includes('facebook.com'));
assert.ok(links.linkedin.includes('linkedin.com'));
assert.ok(links.twitter.includes('twitter.com'));
assert.ok(links.whatsapp.includes('wa.me'));
assert.ok(links.email.startsWith('mailto:'));
assert.ok(links.direct.includes('utm_source=link'));
assert.ok(links.native.includes('utm_source=native'));

console.log('OK unit share-utils');

