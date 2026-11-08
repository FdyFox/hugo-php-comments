// Runs the script from the built test page against a minimal DOM stub.
const fs = require('fs');

class Node {
  constructor(tag) { this.tag = tag; this.children = []; this.dataset = {}; this.hidden = false; this.textContent = ''; }
  append(...c) { this.children.push(...c); }
  remove() {}
  addEventListener() {}
  querySelector(s) { return made[s] || (made[s] = new Node(s)); }
  querySelectorAll() { return []; }
  text() { return this.textContent + this.children.map(c => typeof c === 'string' ? c : c.text()).join(' '); }
  find(cls, out = []) { if (this.className === cls) out.push(this); this.children.forEach(c => c.find && c.find(cls, out)); return out; }
}
const made = {};
const root = new Node('section');
root.dataset = { endpoint: '/api', section: 'posts', page: 'hello', lang: 'en' };
globalThis.document = { getElementById: () => root, createElement: t => new Node(t) };

const data = { comments: [
  { name: 'Anna', website: '', comment: 'Hello\n\n<b>World</b>', date: '2026-11-05T10:00:00+01:00',
    replies: [{ name: 'Author', website: '', comment: 'Reply <b>x</b>', date: '2026-11-06T10:00:00+01:00' }] },
  { name: 'Bob', website: 'https://b.example', comment: 'Plain', date: '2026-11-05T11:00:00+01:00', replies: [] },
] };
globalThis.fetch = async () => ({ json: async () => data });

const html = fs.readFileSync(process.argv[2], 'utf8');
const i = html.indexOf('hpc-replies');
const start = html.lastIndexOf('<script', i);
eval(html.slice(html.indexOf('>', start) + 1, html.indexOf('</script>', i)));

setTimeout(() => {
  const list = made['.hpc-list'];
  const check = (name, ok) => { console.log((ok ? 'ok   ' : 'FAIL ') + name); if (!ok) process.exitCode = 1; };
  const replies = list.children[0].find('hpc-replies');
  const link = list.children[1].find('hpc-author')[0];
  check('title counts replies', made['.hpc-title'].textContent === '3 comments');
  check('two top-level comments', list.children.length === 2);
  check('list visible', list.hidden === false);
  check('reply nested', replies.length === 1 && replies[0].children.length === 1);
  check('badge only on the reply', list.children[0].find('hpc-badge').length === 1 && list.children[1].find('hpc-badge').length === 0);
  check('badge text from translations', list.children[0].find('hpc-badge')[0].textContent === 'Author');
  check('no replies block without replies', list.children[1].find('hpc-replies').length === 0);
  check('HTML stays text', replies[0].text().includes('Reply <b>x</b>'));
  check('website link opens safely in a new tab', link.tag === 'a' && link.target === '_blank' && link.rel.includes('noopener'));
}, 50);
