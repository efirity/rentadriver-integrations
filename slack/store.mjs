import { DatabaseSync } from 'node:sqlite';
import { mkdirSync, chmodSync } from 'node:fs';
import { dirname } from 'node:path';
import { seal, unseal } from './security.mjs';
export class Store {
  constructor(path, key, now = Date.now) {
    if (key.length !== 32) throw new Error('Storage key must contain 32 bytes.');
    if (path !== ':memory:') mkdirSync(dirname(path), { recursive: true, mode: 0o700 });
    this.db = new DatabaseSync(path); this.key = key; this.now = now;
    this.db.exec('CREATE TABLE IF NOT EXISTS records (kind TEXT, id TEXT, value TEXT NOT NULL, expires INTEGER, PRIMARY KEY(kind,id));');
    if (path !== ':memory:') chmodSync(path, 0o600);
  }
  put(kind, id, value, ttl = null) {
    this.db.prepare('DELETE FROM records WHERE expires IS NOT NULL AND expires <= ?').run(this.now());
    this.db.prepare('INSERT OR REPLACE INTO records VALUES (?,?,?,?)').run(kind, id, seal(this.key, value), ttl === null ? null : this.now() + ttl);
  }
  get(kind, id) {
    const row = this.db.prepare('SELECT value,expires FROM records WHERE kind=? AND id=?').get(kind, id);
    if (!row || (row.expires !== null && row.expires <= this.now())) return null;
    return unseal(this.key, row.value);
  }
  take(kind, id) {
    const row = this.db.prepare('DELETE FROM records WHERE kind=? AND id=? RETURNING value,expires').get(kind, id);
    if (!row || (row.expires !== null && row.expires <= this.now())) return null;
    return unseal(this.key, row.value);
  }
  delete(kind, id) { this.db.prepare('DELETE FROM records WHERE kind=? AND id=?').run(kind, id); }
  close() { this.db.close(); }
}
