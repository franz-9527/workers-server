"use strict";
(() => {
  var __getOwnPropNames = Object.getOwnPropertyNames;
  var __commonJS = (cb, mod) => function __require() {
    return mod || (0, cb[__getOwnPropNames(cb)[0]])((mod = { exports: {} }).exports, mod), mod.exports;
  };

  // miniprogram/utils/ChatImages.js
  var require_ChatImages = __commonJS({
    "miniprogram/utils/ChatImages.js"(exports, module) {
      var terminal = (state) => ["accepted", "rejected", "canceled"].includes(state);
      var fail = (code) => Object.assign(new Error(code), { code });
      var ChatImages = class {
        constructor(manager) {
          this.manager = manager;
          this.rows = /* @__PURE__ */ new Map();
          this.tasks = /* @__PURE__ */ new Set();
          this.urls = /* @__PURE__ */ new Map();
          this.visibleViews = /* @__PURE__ */ new Map();
          this.generation = 0;
          this.originals = /* @__PURE__ */ new Set();
        }
        id() {
          return this.manager.clientID();
        }
        reset(id) {
          this.originals.clear();
          this.generation++;
          for (const task of this.tasks) try {
            task.abort();
          } catch (_) {
          }
          ;
          this.tasks.clear();
          if (id) {
            this.visibleViews.delete(id);
            for (const [key, row] of this.rows) if (row.conversation_id === id) this.rows.delete(key);
            for (const key of this.urls.keys()) if (key.startsWith(id + ":")) this.urls.delete(key);
          } else {
            this.rows.clear();
            this.urls.clear();
            this.visibleViews.clear();
          }
        }
        live(epoch, generation, id) {
          const m = this.manager;
          return epoch === m.epoch && generation === this.generation && m.enabled && m.cache.has(id);
        }
        changed(row) {
          this.manager.emit("images", { conversation_id: row.conversation_id });
        }
        list(id) {
          const now = this.manager.io.now();
          for (const [key, row] of this.rows) {
            if (row.localExpires <= now) row.items.forEach((item) => {
              delete item.path;
            });
            if (row.expires <= now && (!row.submitted || terminal(row.state))) this.rows.delete(key);
          }
          for (const [key, value] of this.urls) if (value.until <= now) this.urls.delete(key);
          return [...this.rows.values()].filter((row) => row.conversation_id === id).map((row) => ({ ...row, items: row.items.map((item) => ({ ...item })) }));
        }
        async stage(id, files) {
          const m = this.manager, epoch = m.epoch, focus = m.focusEpoch;
          m.checkFocus(id, epoch, focus);
          if (!m.cache.get(id)?.canSend) throw fail("CHAT_SEND_RESTRICTED");
          if (!Array.isArray(files) || files.length < 1 || files.length > 9 || files.some((f) => !f.path || !Number.isFinite(f.size) || f.size < 1 || f.size > 3 * 1024 * 1024)) throw fail("CHAT_IMAGE_LIMIT");
          if (this.rows.size >= 4) throw fail("CHAT_RESOURCE_BUSY");
          const client = await this.id(), uploads = await Promise.all(files.map(() => this.id()));
          m.checkFocus(id, epoch, focus);
          const row = { conversation_id: id, client_message_id: client, revision: 1, items: files.map((file, index) => ({ path: file.path, size: file.size, upload_id: uploads[index], state: "uploading", progress: 0 })), state: "editing", submitted: false, busy: false, localExpires: m.io.now() + 30 * 60 * 1e3, expires: m.io.now() + 30 * 60 * 1e3 };
          this.rows.set(row.client_message_id, row);
          this.changed(row);
          this.upload(row);
          return row.client_message_id;
        }
        async upload(row, index) {
          if (row.uploading) return;
          row.uploading = true;
          const m = this.manager, epoch = m.epoch, generation = this.generation;
          try {
            for (let i = 0; i < row.items.length; i++) {
              const item = row.items[i];
              if (index !== void 0 && i !== index || item.attachment_id && item.state !== "upload_failed") continue;
              try {
                item.state = "uploading";
                item.code = "";
                this.changed(row);
                const grant = await m.http("media.upload", { conversation_id: row.conversation_id, upload_id: item.upload_id, size_bytes: item.size }, epoch);
                if (!this.live(epoch, generation, row.conversation_id)) return;
                item.attachment_id = grant.attachment_id;
                item.object_version = grant.object_version;
                if (grant.state !== "processed" && grant.state !== "blocked") {
                  if (!m.io.upload || !/^https:\/\//.test(grant.host || "")) throw fail("CHAT_MEDIA_UNAVAILABLE");
                  await m.io.upload(grant, item.path, (progress) => {
                    if (this.live(epoch, generation, row.conversation_id)) {
                      item.progress = Math.max(0, Math.min(100, progress));
                      this.changed(row);
                    }
                  }, this.tasks);
                }
                if (!this.live(epoch, generation, row.conversation_id)) return;
                const result = await m.http("media.uploaded", { conversation_id: row.conversation_id, attachment_id: item.attachment_id }, epoch);
                if (!this.live(epoch, generation, row.conversation_id)) return;
                item.state = result.state;
                item.progress = 100;
              } catch (error) {
                if (!this.live(epoch, generation, row.conversation_id)) return;
                item.state = "upload_failed";
                item.code = error.code || "CHAT_NETWORK_ERROR";
              }
              this.changed(row);
            }
          } finally {
            if (this.live(epoch, generation, row.conversation_id)) {
              row.uploading = false;
              this.changed(row);
            }
          }
        }
        async send(id, client, source = {}) {
          const m = this.manager, row = this.rows.get(client);
          m.checkFocus(id, m.epoch, m.focusEpoch);
          if (!row || row.conversation_id !== id || row.busy || row.submitted || row.uploading || row.items.some((i) => !i.attachment_id || i.state === "upload_failed") || !row.items.length) throw fail("CHAT_IMAGE_NOT_READY");
          if (!m.cache.get(id)?.canSend) throw fail("CHAT_SEND_RESTRICTED");
          row.source = {};
          for (const key of ["reply_to_message_id", "reply_to_item_id"]) if (source[key]) row.source[key] = source[key];
          row.request = { conversation_id: id, client_message_id: client, message_type: row.items.length === 1 ? "image" : "image_album", revision: row.revision, items: row.items.map((item, ordinal) => ({ attachment_id: item.attachment_id, object_version: item.object_version, ordinal })), ...row.source };
          row.submitted = true;
          row.state = "unknown";
          row.expires = m.io.now() + 60 * 60 * 1e3;
          row.busy = true;
          this.changed(row);
          const epoch = m.epoch, generation = this.generation;
          try {
            const result = await m.action(id, "message.send", row.request);
            if (this.live(epoch, generation, id) && this.rows.get(client) === row) this.settle(row, result);
          } catch (error) {
            if (this.live(epoch, generation, id)) {
              row.code = error.code || "CHAT_NETWORK_ERROR";
              row.state = ["CHAT_CONTENT_BLOCKED", "CHAT_SEND_RESTRICTED", "CHAT_ACTION_EXPIRED", "CHAT_REFERENCE_UNAVAILABLE"].includes(row.code) ? "rejected" : "unknown";
            }
          } finally {
            if (this.live(epoch, generation, id)) {
              row.busy = false;
              this.changed(row);
            }
          }
        }
        settle(row, result) {
          if (!result || result.client_message_id !== row.client_message_id || !["accepted", "rejected", "canceled", "checking", "retryable", "review_pending"].includes(result.state)) throw fail("CHAT_INVALID_RESPONSE");
          row.state = result.state;
          row.code = result.code || "";
          row.message_id = result.message_id || "";
          if (result.items) for (const item of row.items) {
            const update = result.items.find((v) => v.attachment_id === item.attachment_id);
            if (update) {
              item.state = update.state;
              item.code = update.code || "";
            }
          }
          if (result.state === "accepted") {
            row.items.forEach((item) => {
              delete item.path;
            });
            this.manager.schedulePoll(0);
            this.manager.refreshAccount();
          }
          this.changed(row);
        }
        async refresh(id) {
          const m = this.manager, epoch = m.epoch, generation = this.generation;
          const pending = await m.http("media.pending", { conversation_id: id }, epoch);
          if (!this.live(epoch, generation, id) || !Array.isArray(pending)) return;
          for (const result of pending) {
            let row = this.rows.get(result.client_message_id);
            if (!row) {
              if (this.rows.size >= 4) continue;
              row = { conversation_id: id, client_message_id: result.client_message_id, revision: result.revision, items: (result.items || []).map((v) => ({ ...v, progress: 100 })), submitted: true, state: "unknown", expires: Date.parse(result.expires_at), recovered: true };
              this.rows.set(row.client_message_id, row);
            }
            this.settle(row, result);
          }
          for (const row of [...this.rows.values()].filter((v) => v.conversation_id === id && v.submitted && !terminal(v.state)).slice(0, 4)) await this.status(id, row.client_message_id);
        }
        async status(id, client, cancel = false) {
          const m = this.manager, epoch = m.epoch, generation = this.generation, row = this.rows.get(client);
          const result = await m.http(cancel ? "media.cancel" : "media.status", { conversation_id: id, client_message_id: client }, epoch);
          if (!this.live(epoch, generation, id)) throw fail("CHAT_CONTEXT_CHANGED");
          if (row && this.rows.get(client) === row) this.settle(row, result);
          return result;
        }
        async retry(id, client, index) {
          const row = this.rows.get(client);
          if (!row || row.busy || row.uploading) return;
          if (row.submitted) {
            const result = await this.status(id, client);
            if (terminal(result.state)) return;
          }
          const item = row.items[index];
          if (!item) return;
          if (item.state === "upload_failed" && item.path) {
            await this.upload(row, index);
            return;
          }
          if (item.state === "check_unavailable" && item.attachment_id) {
            const result = await this.manager.http("media.uploaded", { conversation_id: id, attachment_id: item.attachment_id }, this.manager.epoch);
            item.state = result.state;
            this.changed(row);
          }
        }
        async revise(id, client, change) {
          const row = this.rows.get(client);
          if (!row || row.busy || row.uploading) return null;
          if (row.submitted) {
            const result = await this.status(id, client, true);
            if (result.state === "accepted") return null;
            if (!["canceled", "rejected"].includes(result.state)) throw fail("CHAT_SEND_RESULT_UNKNOWN");
          }
          const items = row.items.map((i) => ({ ...i }));
          change(items);
          if (items.length > 9) throw fail("CHAT_IMAGE_LIMIT");
          const nextClient = await this.id();
          for (const item of items) if (!/^[1-9][0-9]{9}\.[0-9a-f]{32}$/.test(String(item.upload_id || ""))) item.upload_id = await this.id();
          if (this.rows.get(client) !== row) throw fail("CHAT_CONTEXT_CHANGED");
          const next = { ...row, client_message_id: nextClient, revision: row.revision + 1, items, submitted: false, state: "editing", busy: false, expires: this.manager.io.now() + 30 * 60 * 1e3 };
          delete next.request;
          this.rows.delete(client);
          if (items.length) this.rows.set(next.client_message_id, next);
          this.changed(next);
          return next.client_message_id;
        }
        async retrySend(id, client) {
          const row = this.rows.get(client);
          if (!row || !row.request) return;
          try {
            await this.status(id, client);
          } catch (error) {
            if (error.code !== "CHAT_SEND_RESULT_UNKNOWN") throw error;
          }
          if (!terminal(row.state) && row.state === "unknown") {
            const result = await this.manager.action(id, "message.send", row.request);
            this.settle(row, result);
          }
        }
        setVisible(id, views) {
          if (!views.length) this.visibleViews.delete(id);
          else this.visibleViews.set(id, new Map(views.map((v) => [v.message_id, v])));
        }
        view(id, message) {
          const a = this.manager.cache.get(id)?.messages.get(message), b = this.visibleViews.get(id)?.get(message);
          if (!a) return b;
          if (!b) return a;
          const x = String(a.state_version || "0"), y = String(b.state_version || "0");
          return x.length > y.length || x.length === y.length && x >= y ? a : b;
        }
        async url(id, message, item, thumbnail) {
          const m = this.manager, epoch = m.epoch, generation = this.generation, key = [id, message, item, thumbnail ? "thumb" : "main"].join(":");
          const model = m.cache.get(id), view = this.view(id, message);
          if (!view || view.state !== "published" || !view.images?.some((i) => i.item_id === item)) throw fail("CHAT_MEDIA_UNAVAILABLE");
          if (m.disk?.scope && model?.capabilities?.cache) {
            const current = () => m.live(epoch) && this.live(epoch, generation, id) && m.cache.get(id) === model && this.view(id, message)?.state === "published" && String(this.view(id, message)?.state_version) === String(view.state_version);
            return m.disk.file(
              { conversation_id: id, message_id: message, item_id: item, state_version: String(view.state_version), kind: "image", variant: thumbnail ? "thumb" : "main" },
              () => m.http("media.url", { conversation_id: id, message_id: message, item_id: item, thumbnail }, epoch),
              current
            );
          }
          const known = this.urls.get(key);
          if (known && known.until > m.io.now()) return known.url;
          const result = await m.http("media.url", { conversation_id: id, message_id: message, item_id: item, thumbnail }, epoch);
          if (!this.live(epoch, generation, id) || m.cache.get(id) !== model || this.view(id, message)?.state !== "published") throw fail("CHAT_MEDIA_UNAVAILABLE");
          if (result.cloud_available === false) throw fail("CHAT_MEDIA_CLOUD_EXPIRED");
          if (!/^https:\/\//.test(result.url || "") || Number(result.expires_at) * 1e3 <= m.io.now()) throw fail("CHAT_INVALID_RESPONSE");
          this.urls.set(key, { url: result.url, until: Math.min(Number(result.expires_at) * 1e3, m.io.now() + 5e4) });
          return result.url;
        }
        async preview(id, message, item, original = false) {
          const view = this.view(id, message), image = view?.images?.find((v) => v.item_id === item), bytes = Number(image?.main_size_bytes) || 0;
          const key = [id, message, item, view?.state_version].join(":");
          const small = Number.isSafeInteger(bytes) && bytes > 0 && bytes <= 1048576;
          if (!original && !small && !this.originals.has(key)) return { url: await this.url(id, message, item, true), thumbnail: true, showOriginal: true, mainBytes: bytes };
          try {
            const url = await this.url(id, message, item, false);
            this.originals.add(key);
            if (this.originals.size > 256) this.originals.delete(this.originals.values().next().value);
            return { url, thumbnail: false, showOriginal: false, mainBytes: bytes };
          } catch (error) {
            if (!["CHAT_MEDIA_CLOUD_EXPIRED", "CHAT_NETWORK_ERROR", "CHAT_CACHE_DOWNLOAD_FAILED"].includes(error.code)) throw error;
            return { url: await this.url(id, message, item, true), thumbnail: true, showOriginal: error.code !== "CHAT_MEDIA_CLOUD_EXPIRED", mainBytes: bytes };
          }
        }
      };
      module.exports = { ChatImages };
    }
  });

  // miniprogram/utils/ChatAV.js
  var require_ChatAV = __commonJS({
    "miniprogram/utils/ChatAV.js"(exports, module) {
      var fail = (code) => Object.assign(new Error(code), { code });
      var terminal = (s) => ["accepted", "rejected", "canceled"].includes(s);
      var limits = { voice: { kind: "audio", seconds: 60, bytes: 1048576 }, camera: { kind: "video", seconds: 30, bytes: 8388608 }, album: { kind: "video", seconds: 120, bytes: 20971520 } };
      var ChatAV = class {
        constructor(manager) {
          this.manager = manager;
          this.rows = /* @__PURE__ */ new Map();
          this.tasks = /* @__PURE__ */ new Set();
          this.generation = 0;
          this.visible = /* @__PURE__ */ new Map();
        }
        id() {
          return this.manager.clientID();
        }
        live(epoch, generation, id) {
          const m = this.manager;
          return m.live(epoch) && generation === this.generation && m.cache.has(id);
        }
        change(row) {
          this.manager.emit("av", { conversation_id: row.conversation_id });
        }
        pause() {
          this.generation++;
          for (const task of this.tasks) try {
            task.abort();
          } catch (_) {
          }
          ;
          this.tasks.clear();
          for (const row of this.rows.values()) row.busy = false;
        }
        reset(id) {
          this.pause();
          if (id) {
            this.visible.delete(id);
            for (const [key, row] of this.rows) if (row.conversation_id === id) this.rows.delete(key);
          } else {
            this.rows.clear();
            this.visible.clear();
          }
        }
        async stage(id, file) {
          const m = this.manager, epoch = m.epoch, focus = m.focusEpoch;
          m.checkFocus(id, epoch, focus);
          const model = m.cache.get(id), rule = limits[file.source];
          if (!rule || !model?.canSend || !model.capabilities?.[rule.kind]) throw fail("CHAT_SEND_RESTRICTED");
          if (!file.path || !Number.isSafeInteger(file.size) || file.size < 1 || file.size > rule.bytes || !Number.isFinite(file.duration) || file.duration <= 0 || file.duration > rule.seconds || rule.kind === "audio" && file.duration < 1) throw fail("CHAT_AV_LIMIT_EXCEEDED");
          if ([...this.rows.values()].filter((r) => !terminal(r.state)).length >= 4) throw fail("CHAT_RESOURCE_BUSY");
          const client = await this.id();
          m.checkFocus(id, epoch, focus);
          const row = { ...file, kind: rule.kind, conversation_id: id, client_message_id: client, state: "editing", submitted: false, busy: false, progress: 0 };
          this.rows.set(row.client_message_id, row);
          this.change(row);
          return row.client_message_id;
        }
        list(id) {
          const m = this.manager;
          for (const [key, row] of this.rows) if (row.state === "accepted" && m.cache.get(row.conversation_id)?.messages.has(row.message_id) || terminal(row.state) && row.expires && row.expires < m.io.now()) this.rows.delete(key);
          const rows = [...this.rows.values()].filter((r) => r.conversation_id === id);
          const terminalRows = rows.filter((r) => terminal(r.state));
          for (const row of terminalRows.slice(0, -20)) this.rows.delete(row.client_message_id);
          return rows.filter((r) => this.rows.has(r.client_message_id)).map((r) => ({ ...r }));
        }
        async send(id, client, source = {}) {
          const m = this.manager, row = this.rows.get(client);
          m.checkFocus(id, m.epoch, m.focusEpoch);
          if (!row || row.busy || row.submitted || !m.cache.get(id)?.canSend) throw fail("CHAT_SEND_RESTRICTED");
          if (source.edited_from_recalled_message_id) throw fail("CHAT_REFERENCE_UNAVAILABLE");
          row.reply = {};
          for (const key of ["reply_to_message_id", "reply_to_item_id"]) if (source[key]) row.reply[key] = source[key];
          row.submitted = true;
          row.state = "unknown";
          row.expires = m.io.now() + 24 * 60 * 60 * 1e3;
          return this.transfer(row);
        }
        settle(row, result) {
          if (!result || result.client_message_id !== row.client_message_id || !["checking", "review_pending", "accepted", "rejected", "canceled", "retryable"].includes(result.state)) throw fail("CHAT_INVALID_RESPONSE");
          row.state = result.state;
          row.code = result.code || "";
          row.message_id = result.message_id || "";
          row.phase = result.phase || row.phase;
          if (terminal(row.state)) {
            this.manager.io.av?.remove(row).catch(() => {
            });
            delete row.path;
          }
          if (row.state === "accepted") {
            this.manager.schedulePoll(0);
            this.manager.refreshAccount();
          }
          this.change(row);
          return result;
        }
        async transfer(row) {
          const m = this.manager, epoch = m.epoch, generation = this.generation, id = row.conversation_id;
          if (row.busy) return;
          row.busy = true;
          this.change(row);
          const check = () => {
            if (!this.live(epoch, generation, id) || this.rows.get(row.client_message_id) !== row || row.state === "canceled") throw fail("CHAT_CONTEXT_CHANGED");
          };
          try {
            if (!row.request) {
              const digest = await m.io.av.info(row.path);
              check();
              if (digest.size !== row.size || !/^[a-f0-9]{32}$/.test(digest.digest)) throw fail("CHAT_AV_FILE_CHANGED");
              row.md5 = digest.digest;
              const u = await m.http("av.upload", { conversation_id: id, upload_id: row.client_message_id, source: row.source, size_bytes: row.size, source_md5: row.md5 }, epoch);
              check();
              row.attachment_id = u.attachment_id;
              row.object_version = u.object_version;
              row.part_count = u.part_count;
              row.request = { conversation_id: id, client_message_id: row.client_message_id, message_type: row.kind, revision: 1, items: [{ attachment_id: u.attachment_id, object_version: u.object_version, ordinal: 0 }], ...row.clientTranscript ? { client_transcript: row.clientTranscript } : {}, ...row.recognitionProvider === "wechat_si" ? { client_asr_provider: "wechat_si" } : {}, ...row.reply || {} };
              try {
                await m.io.av.save(row, m.disk?.scope);
              } catch (_) {
                m.io.av.warn();
              }
            }
            let result;
            if (row.dispatchAttempted || row.recovered) {
              try {
                result = await m.http("av.status", { conversation_id: id, client_message_id: row.client_message_id }, epoch);
              } catch (error) {
                if (error.code !== "CHAT_SEND_RESULT_UNKNOWN") throw error;
              }
              check();
            }
            if (!result) {
              row.dispatchAttempted = true;
              result = await m.action(id, "message.send", row.request);
            }
            check();
            this.settle(row, result);
            if (terminal(row.state)) return result;
            const status = await m.http("av.status", { conversation_id: id, client_message_id: row.client_message_id }, epoch);
            check();
            this.settle(row, status);
            if (status.phase !== "uploading" || terminal(row.state)) return status;
            if (!row.path) throw fail("CHAT_AV_SOURCE_MISSING");
            const info = await m.io.av.info(row.path);
            check();
            if (info.size !== row.size || info.digest !== row.md5) throw fail("CHAT_AV_FILE_CHANGED");
            const done = new Set(status.uploaded_parts || []), count = status.part_count;
            if (!Number.isSafeInteger(count) || count < 1 || count > 10) throw fail("CHAT_INVALID_RESPONSE");
            const pending = Array.from({ length: count }, (_, i) => i + 1).filter((i) => !done.has(i));
            const concurrency = await m.io.av.network() === "wifi" ? 2 : 1;
            check();
            const piece = async (ordinal) => {
              check();
              const grant = await m.http("av.part", { conversation_id: id, attachment_id: row.attachment_id, ordinal }, epoch);
              check();
              if (grant.state !== "present") {
                const size = count === 1 ? row.size : Math.min(2097152, row.size - (ordinal - 1) * 2097152);
                const path = count === 1 ? row.path : await m.io.av.piece(row.path, (ordinal - 1) * 2097152, size);
                check();
                try {
                  await m.io.upload(grant, path, () => {
                  }, this.tasks);
                } finally {
                  if (count !== 1) await m.io.av.unlink(path);
                }
                check();
                await m.http("av.part", { conversation_id: id, attachment_id: row.attachment_id, ordinal, confirm: true }, epoch);
                check();
              }
              done.add(ordinal);
              row.progress = Math.floor(done.size / count * 100);
              this.change(row);
            };
            let next = 0;
            await Promise.all(Array.from({ length: Math.min(concurrency, pending.length) }, async () => {
              while (next < pending.length) {
                const ordinal = pending[next++];
                await piece(ordinal);
              }
            }));
            check();
            await m.http("av.complete", { conversation_id: id, attachment_id: row.attachment_id }, epoch);
            check();
            row.phase = "publish";
            row.state = "checking";
            this.change(row);
            if (row.kind === "audio") {
              for (const delay of [120, 220, 360, 600, 900, 1400]) {
                await new Promise((resolve) => setTimeout(resolve, delay));
                check();
                const fast = await m.http("av.status", { conversation_id: id, client_message_id: row.client_message_id }, epoch);
                check();
                this.settle(row, fast);
                if (terminal(row.state)) return fast;
              }
            }
          } catch (error) {
            if (this.live(epoch, generation, id)) {
              row.code = error.code || "CHAT_NETWORK_ERROR";
              this.change(row);
            }
            throw error;
          } finally {
            if (this.live(epoch, generation, id)) {
              row.busy = false;
              this.change(row);
            }
          }
        }
        async status(id, client, cancel = false) {
          const row = this.rows.get(client), m = this.manager, epoch = m.epoch, generation = this.generation;
          if (row && !row.submitted) {
            if (cancel) {
              this.rows.delete(client);
              this.change(row);
            }
            ;
            return;
          }
          const result = await m.http(cancel ? "av.cancel" : "av.status", { conversation_id: id, client_message_id: client }, epoch);
          if (!this.live(epoch, generation, id)) throw fail("CHAT_CONTEXT_CHANGED");
          if (row) this.settle(row, result);
          return result;
        }
        async retry(id, client) {
          const row = this.rows.get(client);
          if (!row || row.busy) return;
          if (row.submitted && !terminal(row.state)) {
            if (!row.request) return this.status(id, client);
            return this.transfer(row);
          }
        }
        async refresh(id) {
          const m = this.manager, epoch = m.epoch, generation = this.generation;
          const model = m.cache.get(id);
          if (!model?.capabilities?.audio && !model?.capabilities?.video) return;
          const journal = await m.io.av?.load(m.disk?.scope, id) || [];
          if (!this.live(epoch, generation, id)) return;
          for (const row of journal) if (!this.rows.has(row.client_message_id)) this.rows.set(row.client_message_id, row);
          const pending = await m.http("av.pending", { conversation_id: id }, epoch);
          if (!this.live(epoch, generation, id)) return;
          for (const result of pending) {
            let row = this.rows.get(result.client_message_id);
            if (!row) {
              row = { conversation_id: id, client_message_id: result.client_message_id, submitted: true, state: "unknown", kind: "video", recovered: true };
              this.rows.set(row.client_message_id, row);
            }
            ;
            this.settle(row, result);
          }
          for (const row of [...this.rows.values()].filter((r) => r.conversation_id === id && r.submitted && !terminal(r.state) && !r.busy)) try {
            await this.status(id, row.client_message_id);
          } catch (error) {
            if (this.live(epoch, generation, id)) {
              row.code = error.code || "CHAT_NETWORK_ERROR";
              this.change(row);
            }
          }
        }
        setVisible(id, views) {
          this.visible.set(id, new Map(views.map((v) => [v.message_id, v])));
        }
        view(id, mid) {
          const a = this.manager.cache.get(id)?.messages.get(mid), b = this.visible.get(id)?.get(mid);
          if (!a) return b;
          if (!b) return a;
          return String(a.state_version).length > String(b.state_version).length || String(a.state_version).length === String(b.state_version).length && a.state_version >= b.state_version ? a : b;
        }
        async url(id, mid, poster = false, frame = null) {
          const m = this.manager, epoch = m.epoch, generation = this.generation, view = this.view(id, mid);
          if (!view || view.state !== "published" || !["audio", "video"].includes(view.message_type)) throw fail("CHAT_MEDIA_UNAVAILABLE");
          const authorize = () => m.http("av.url", { conversation_id: id, message_id: mid, ...poster ? { thumbnail: true } : {}, ...frame !== null ? { frame_ordinal: frame } : {} }, epoch);
          const guard = () => this.live(epoch, generation, id) && this.view(id, mid)?.state === "published" && this.view(id, mid)?.state_version === view.state_version;
          if (m.disk?.scope && m.cache.get(id)?.capabilities?.cache) return m.disk.file({ conversation_id: id, message_id: mid, state_version: view.state_version, kind: view.message_type, variant: frame !== null ? "frame-" + frame : poster ? "poster" : "main" }, authorize, guard);
          const result = await authorize();
          if (!guard()) throw fail("CHAT_CONTEXT_CHANGED");
          if (result.cloud_available === false) throw fail("CHAT_MEDIA_CLOUD_EXPIRED");
          return result.url;
        }
        async heard(id, mid) {
          const result = await this.manager.http("av.listened", { conversation_id: id, message_id: mid });
          this.manager.schedulePoll(0);
          return result;
        }
      };
      module.exports = { ChatAV, limits };
    }
  });

  // miniprogram/utils/ChatCalls.js
  var require_ChatCalls = __commonJS({
    "miniprogram/utils/ChatCalls.js"(exports, module) {
      var terminal = /* @__PURE__ */ new Set(["rejected", "cancelled", "missed", "ended", "failed"]);
      var compare = (a, b) => String(a).length - String(b).length || String(a).localeCompare(String(b));
      var callAliases = { CALL_disabled: "CALL_DISABLED", CALL_busy: "CALL_BUSY", CALL_unavailable: "CALL_UNAVAILABLE", CALL_exhausted: "CALL_EXHAUSTED", CALL_safety_insufficient: "CALL_SAFETY_INSUFFICIENT", disabled: "CALL_DISABLED", busy: "CALL_BUSY", unavailable: "CALL_UNAVAILABLE", exhausted: "CALL_EXHAUSTED", safety_insufficient: "CALL_SAFETY_INSUFFICIENT", no_peer: "CALL_NO_PEER", "\u4EC5\u5355\u804A\u652F\u6301\u901A\u8BDD": "CALL_DIRECT_ONLY" };
      var callMessages = { CALL_BUSY: "\u5BF9\u65B9\u6216\u5F53\u524D\u8D26\u53F7\u6B63\u5728\u901A\u8BDD", CALL_UNAVAILABLE: "\u901A\u8BDD\u670D\u52A1\u6682\u4E0D\u53EF\u7528\uFF0C\u8BF7\u68C0\u67E5\u901A\u8BDD\u8D44\u6E90\u540E\u91CD\u8BD5", CALL_EXHAUSTED: "\u672C\u6708\u5E73\u53F0\u901A\u8BDD\u989D\u5EA6\u5DF2\u7528\u5B8C", CALL_SAFETY_INSUFFICIENT: "\u5F53\u524D\u5B89\u5168\u53EF\u7528\u989D\u5EA6\u4E0D\u8DB3\uFF0C\u53EF\u7A0D\u540E\u91CD\u8BD5", CALL_DISABLED: "\u901A\u8BDD\u5C1A\u672A\u5F00\u653E", CALL_DIRECT_ONLY: "\u4EC5\u5355\u804A\u652F\u6301\u901A\u8BDD", CALL_NO_PEER: "\u5F53\u524D\u6CA1\u6709\u53EF\u901A\u8BDD\u7684\u53E6\u4E00\u4F4D\u6210\u5458", CALL_STATE_CONFLICT: "\u901A\u8BDD\u72B6\u6001\u5DF2\u53D8\u5316\uFF0C\u8BF7\u5237\u65B0", CALL_OPERATION_PENDING: "\u6B63\u5728\u786E\u8BA4\u4E0A\u4E00\u64CD\u4F5C\u7ED3\u679C\uFF0C\u8BF7\u70B9\u51FB\u540C\u4E00\u5165\u53E3\u91CD\u8BD5", CALL_INVITATION_EXPIRED: "\u672C\u6B21\u547C\u53EB\u5DF2\u8FC7\u671F\uFF0C\u8BF7\u91CD\u65B0\u53D1\u8D77", CALL_REQUEST_INVALID: "\u901A\u8BDD\u8BF7\u6C42\u65E0\u6548\uFF0C\u8BF7\u5237\u65B0\u540E\u91CD\u8BD5", CALL_DEVICE_UNSUPPORTED: "\u5F53\u524D\u8BBE\u5907\u6682\u4E0D\u652F\u6301\u901A\u8BDD", CHAT_PERMISSION_DENIED: "\u5F53\u524D\u5DF2\u65E0\u6743\u7EE7\u7EED\u901A\u8BDD", CHAT_UNAUTHORIZED: "\u767B\u5F55\u72B6\u6001\u5DF2\u5931\u6548\uFF0C\u8BF7\u91CD\u65B0\u8FDB\u5165", CHAT_NETWORK_ERROR: "\u65E0\u6CD5\u8FDE\u63A5\u901A\u8BDD\u670D\u52A1\uFF0C\u8BF7\u68C0\u67E5\u7F51\u7EDC\u540E\u91CD\u8BD5", CHAT_TRANSPORT_UNKNOWN: "\u901A\u8BDD\u8BF7\u6C42\u7ED3\u679C\u5F85\u786E\u8BA4\uFF0C\u8BF7\u70B9\u51FB\u540C\u4E00\u5165\u53E3\u91CD\u8BD5", CHAT_DEPENDENCY_UNAVAILABLE: "\u901A\u8BDD\u670D\u52A1\u4F9D\u8D56\u6682\u4E0D\u53EF\u7528", CHAT_CONTEXT_CHANGED: "\u8D26\u53F7\u6216\u4F1A\u8BDD\u5DF2\u53D8\u5316\uFF0C\u8BF7\u91CD\u65B0\u8FDB\u5165" };
      var definitiveCallCodes = /* @__PURE__ */ new Set(["CALL_BUSY", "CALL_STATE_CONFLICT", "CALL_REQUEST_INVALID", "CALL_DISABLED", "CALL_UNAVAILABLE", "CALL_EXHAUSTED", "CALL_SAFETY_INSUFFICIENT", "CALL_INVITATION_EXPIRED", "CHAT_UNAUTHORIZED", "CHAT_PERMISSION_DENIED"]);
      function normalizeCallCode(value) {
        const raw = typeof value === "string" ? value : String(value?.code || value?.reason || "");
        return callAliases[raw] || raw;
      }
      function callMessage(value) {
        const raw = typeof value === "string" ? value : String(value?.code || value?.reason || "");
        const code = normalizeCallCode(raw);
        if (callMessages[code]) return callMessages[code];
        if (raw && !/^CALL_|^CHAT_/.test(raw)) return raw;
        return "\u901A\u8BDD\u6682\u4E0D\u53EF\u7528\uFF0C\u8BF7\u7A0D\u540E\u91CD\u8BD5";
      }
      function definitiveCallRefusal(error) {
        return error?.retryable !== true && definitiveCallCodes.has(normalizeCallCode(error?.code));
      }
      var ChatCalls = class {
        constructor(manager) {
          this.enabled = false;
          this.manager = manager;
          this.actorID = "";
          this.instance = "";
          this.serial = 0;
          this.current = null;
          this.pendingCreate = null;
          this.pending = null;
          this.timer = null;
          this.polling = null;
          this.incoming = "";
        }
        async random(bytes) {
          if (!this.manager.io.randomBytes) throw Object.assign(new Error("\u5F53\u524D\u8BBE\u5907\u6682\u4E0D\u652F\u6301\u901A\u8BDD"), { code: "CALL_DEVICE_UNSUPPORTED" });
          return this.manager.io.randomBytes(bytes);
        }
        async device() {
          if (this.instance) return this.instance;
          if (this.instanceJob) return this.instanceJob;
          const epoch = this.manager.epoch;
          const job = this.random(16).then((id) => {
            if (epoch !== this.manager.epoch) throw Object.assign(new Error("\u8D26\u53F7\u5DF2\u53D8\u5316"), { code: "CHAT_CONTEXT_CHANGED" });
            this.instance = id;
            return id;
          }).finally(() => {
            if (this.instanceJob === job) this.instanceJob = null;
          });
          this.instanceJob = job;
          return job;
        }
        call(action, payload) {
          return this.manager.command("calls." + action, payload);
        }
        apply(view) {
          if (!view || typeof view.call_id !== "string" || typeof view.revision !== "string") return;
          const previous = this.current;
          if (previous && compare(previous.call_id, view.call_id) > 0) return;
          if (previous && previous.call_id === view.call_id && (compare(previous.attempt_epoch, view.attempt_epoch) > 0 || previous.attempt_epoch === view.attempt_epoch && compare(previous.revision, view.revision) > 0)) return;
          const merged = { ...view };
          if (previous && previous.call_id === view.call_id && previous.attempt_epoch === view.attempt_epoch && previous.revision === view.revision) {
            merged.authorized_until = Math.max(previous.authorized_until || 0, view.authorized_until || 0);
            merged.prepared_count = Math.max(previous.prepared_count || 0, view.prepared_count || 0);
          }
          this.current = merged;
          this.manager.emit("calls.changed", { ...merged });
        }
        async create(conversation, peer, media) {
          const key = [conversation, peer, media].join(":");
          if (this.createJob) {
            if (this.createKey === key) return this.createJob;
            throw Object.assign(new Error("\u547C\u53EB\u7ED3\u679C\u5C1A\u672A\u786E\u8BA4"), { code: "CALL_OPERATION_PENDING" });
          }
          this.createKey = key;
          const job = this.performCreate(conversation, peer, media).finally(() => {
            if (this.createJob === job) {
              this.createJob = null;
              this.createKey = "";
            }
          });
          this.createJob = job;
          return job;
        }
        async performCreate(conversation, peer, media) {
          const epoch = this.manager.epoch;
          if (this.pendingCreate && (this.pendingCreate.conversation_id !== conversation || this.pendingCreate.peer_id !== peer || this.pendingCreate.media !== media)) throw Object.assign(new Error("\u8BF7\u5148\u786E\u8BA4\u4E0A\u4E00\u901A\u547C\u53EB\u7ED3\u679C"), { code: "CALL_OPERATION_PENDING" });
          if (!this.pendingCreate) {
            const q = { conversation_id: conversation, peer_id: peer, media, operation_id: await this.random(32), instance_id: await this.device() };
            if (epoch !== this.manager.epoch) throw Object.assign(new Error("\u8D26\u53F7\u5DF2\u53D8\u5316"), { code: "CHAT_CONTEXT_CHANGED" });
            this.pendingCreate = q;
          }
          let view;
          try {
            view = await this.call("create", this.pendingCreate);
          } catch (error) {
            if (epoch === this.manager.epoch && definitiveCallRefusal(error)) this.pendingCreate = null;
            throw error;
          }
          if (epoch !== this.manager.epoch) return;
          this.pendingCreate = null;
          this.apply(view);
          return view;
        }
        async action(name) {
          if (this.actionJob) {
            if (this.actionName === name) return this.actionJob;
            throw Object.assign(new Error("\u64CD\u4F5C\u7ED3\u679C\u5C1A\u672A\u786E\u8BA4"), { code: "CALL_OPERATION_PENDING" });
          }
          this.actionName = name;
          const job = this.performAction(name).finally(() => {
            if (this.actionJob === job) {
              this.actionJob = null;
              this.actionName = "";
            }
          });
          this.actionJob = job;
          return job;
        }
        async performAction(name) {
          if (!this.current) throw new Error("\u6CA1\u6709\u6D3B\u52A8\u901A\u8BDD");
          const epoch = this.manager.epoch;
          if (name === "end" && this.pending && this.pending.name !== "end") {
            await this.performAction(this.pending.name);
            if (epoch !== this.manager.epoch) throw Object.assign(new Error("\u8D26\u53F7\u5DF2\u53D8\u5316"), { code: "CHAT_CONTEXT_CHANGED" });
            if (terminal.has(this.current?.state)) return this.current;
          }
          if (this.pending && this.pending.name !== name) throw Object.assign(new Error("\u8BF7\u5148\u786E\u8BA4\u4E0A\u4E00\u64CD\u4F5C\u7ED3\u679C"), { code: "CALL_OPERATION_PENDING" });
          if (!this.pending) {
            const target = { ...this.current };
            const q = { call_id: target.call_id, attempt_epoch: target.attempt_epoch, revision: target.revision, instance_id: await this.device(), operation_id: await this.random(32) };
            if (epoch !== this.manager.epoch || this.current?.call_id !== target.call_id) throw Object.assign(new Error("\u8D26\u53F7\u5DF2\u53D8\u5316"), { code: "CHAT_CONTEXT_CHANGED" });
            this.pending = { name, q };
          }
          try {
            const view = await this.call(name, this.pending.q);
            if (epoch !== this.manager.epoch) return;
            this.pending = null;
            this.apply(view);
            return view;
          } catch (error) {
            if (definitiveCallRefusal(error) || !error.retryable && error.statusCode && error.statusCode < 500) this.pending = null;
            throw error;
          }
        }
        async status() {
          if (!this.current) return null;
          const epoch = this.manager.epoch, view = await this.call("status", { call_id: this.current.call_id });
          if (epoch === this.manager.epoch) this.apply(view);
          return view;
        }
        async prepare() {
          if (!this.current) throw new Error("\u6CA1\u6709\u6D3B\u52A8\u901A\u8BDD");
          const epoch = this.manager.epoch, target = { ...this.current };
          const instance = await this.device();
          if (epoch !== this.manager.epoch || this.current?.call_id !== target.call_id || this.current.attempt_epoch !== target.attempt_epoch) throw Object.assign(new Error("\u901A\u8BDD\u72B6\u6001\u5DF2\u53D8\u5316"), { code: "CHAT_CONTEXT_CHANGED" });
          const started = typeof this.manager.io.monotonicNow === "function" ? this.manager.io.monotonicNow() : typeof this.manager.io.now === "function" ? this.manager.io.now() : Date.now();
          const value = await this.call("prepare", { call_id: target.call_id, attempt_epoch: target.attempt_epoch, instance_id: instance });
          if (epoch !== this.manager.epoch) throw new Error("\u8D26\u53F7\u5DF2\u53D8\u5316");
          this.apply(value.call);
          if (this.current?.call_id !== target.call_id || this.current.attempt_epoch !== target.attempt_epoch || !["preparing", "connecting", "active"].includes(this.current.state)) throw Object.assign(new Error("\u901A\u8BDD\u72B6\u6001\u5DF2\u53D8\u5316"), { code: "CALL_STATE_CONFLICT" });
          const received = typeof this.manager.io.monotonicNow === "function" ? this.manager.io.monotonicNow() : typeof this.manager.io.now === "function" ? this.manager.io.now() : Date.now();
          const elapsed = Math.max(0, received - started);
          return { ...value.credentials, remaining_ms: Math.max(0, (value.credentials.expires_at - value.call.server_time) * 1e3 - elapsed), received_at_ms: received };
        }
        setEnabled(value) {
          const was = this.enabled;
          this.enabled = value === true;
          if (this.enabled && !was && this.manager.foreground) this.schedule(0);
          if (!this.enabled && was) this.foreground(false);
        }
        foreground(value) {
          if (this.timer) this.manager.io.clearTimeout(this.timer);
          this.timer = null;
          if (value && this.enabled) this.schedule(0);
          else this.manager.emit("calls.suspend", {});
        }
        schedule(delay) {
          if (this.timer) this.manager.io.clearTimeout(this.timer);
          this.timer = this.manager.io.setTimeout(() => {
            this.timer = null;
            this.poll().catch(() => {
            });
          }, delay);
        }
        async poll() {
          const epoch = this.manager.epoch;
          if (!this.enabled || !this.manager.live(epoch)) return;
          if (this.polling) return this.polling;
          const job = this.call("inbox", {}).then((list) => {
            if (epoch !== this.manager.epoch || !this.manager.live(epoch) || !Array.isArray(list)) return;
            for (const view of list) {
              if (!this.current || this.current.call_id === view.call_id || terminal.has(this.current.state)) this.apply(view);
              if (view.state === "ringing" && view.callee_id === this.actorID && view.call_id !== this.incoming) {
                this.incoming = view.call_id;
                if (this.manager.io.incoming) this.manager.io.incoming(view, epoch);
              }
            }
          }).catch((error) => {
            if (epoch === this.manager.epoch && ["CALL_DISABLED", "CHAT_ROUTE_NOT_FOUND"].includes(normalizeCallCode(error.code))) {
              this.setEnabled(false);
              return;
            }
            throw error;
          }).finally(() => {
            if (this.polling === job) {
              this.polling = null;
              if (this.enabled && this.manager.live(epoch)) this.schedule(5e3);
            }
          });
          this.polling = job;
          return job;
        }
        reset() {
          if (this.timer) this.manager.io.clearTimeout(this.timer);
          this.createJob = null;
          this.createKey = "";
          this.actionJob = null;
          this.actionName = "";
          this.enabled = false;
          this.timer = null;
          this.polling = null;
          this.instanceJob = null;
          this.instance = "";
          this.actorID = "";
          this.current = null;
          this.pendingCreate = null;
          this.pending = null;
          this.incoming = "";
          this.manager.emit("calls.suspend", {});
        }
      };
      module.exports = { ChatCalls, terminal, normalizeCallCode, callMessage };
    }
  });

  // miniprogram/utils/ChatManager.js
  var require_ChatManager = __commonJS({
    "miniprogram/utils/ChatManager.js"(exports, module) {
      var { ChatImages } = require_ChatImages();
      var { ChatAV } = require_ChatAV();
      var { ChatCalls } = require_ChatCalls();
      var routes = {
        "calls.availability": "calls/availability",
        "calls.create": "calls/create",
        "calls.inbox": "calls/inbox",
        "calls.status": "calls/status",
        "calls.history": "calls/history",
        "calls.prepare": "calls/prepare",
        "calls.accept": "calls/accept",
        "calls.reject": "calls/reject",
        "calls.end": "calls/end",
        "calls.pulse": "calls/pulse",
        "calls.ready": "calls/ready",
        "calls.prepared": "calls/prepared",
        "av.upload": "av/upload",
        "av.part": "av/part",
        "av.complete": "av/complete",
        "av.status": "av/status",
        "av.cancel": "av/cancel",
        "av.pending": "av/pending",
        "av.url": "av/url",
        "av.listened": "av/listened",
        "governance.status": "governance/status",
        "governance.report": "governance/report",
        "governance.appeal": "governance/appeal",
        "governance.cases": "governance/cases",
        "media.upload": "media/upload",
        "media.uploaded": "media/uploaded",
        "media.status": "media/status",
        "media.cancel": "media/cancel",
        "media.pending": "media/pending",
        "media.url": "media/url",
        "media.report": "media/report",
        "conversation.readers": "conversations/readers",
        "conversation.bootstrap": "conversations/bootstrap",
        "history.request": "messages/history",
        "sync.request": "messages/sync",
        "changes.sync": "messages/changes",
        "message.locate": "messages/locate",
        "receipt.read": "conversations/read",
        "conversation.unread": "conversations/unread",
        "conversation.preferences": "conversations/preferences",
        "conversation.preference": "conversations/preference",
        "account.digests": "conversations/digests",
        "message.recall": "messages/recall",
        "message.send": "messages/send",
        "message.status": "messages/result"
      };
      var failure = (code, retryable = false) => Object.assign(new Error(code), { code, retryable });
      var compare = (a, b) => String(a).length - String(b).length || String(a).localeCompare(String(b));
      var ChatManager = class {
        constructor(io) {
          this.av = new ChatAV(this);
          this.images = new ChatImages(this);
          this.io = io;
          this.disk = io.disk || null;
          this.ledger = io.ledger || null;
          this.epoch = 0;
          this.identityEpoch = 0;
          this.serial = 0;
          this.enabled = false;
          this.foreground = false;
          this.network = true;
          this.calls = new ChatCalls(this);
          this.listeners = /* @__PURE__ */ new Set();
          this.preferenceJobs = /* @__PURE__ */ new Map();
          this.accountGeneration = 0;
          this.pending = /* @__PURE__ */ new Map();
          this.cache = /* @__PURE__ */ new Map();
          this.drafts = /* @__PURE__ */ new Map();
          this.recalled = /* @__PURE__ */ new Map();
          this.outbox = /* @__PURE__ */ new Map();
          this.active = "";
          this.token = null;
          this.state = "disabled";
          this.attempt = 0;
          this.task = null;
          this.connecting = false;
          this.reconciling = null;
          this.pendingLedgerJob = null;
          this.pendingLedgerTimer = null;
        }
        emit(type, data) {
          this.listeners.forEach((fn) => {
            try {
              fn(type, data);
            } catch (_) {
            }
          });
        }
        subscribe(fn) {
          this.listeners.add(fn);
          return () => this.listeners.delete(fn);
        }
        live(epoch) {
          return epoch === this.epoch && this.enabled && this.foreground && this.network;
        }
        setEnabled(value) {
          this.enabled = value === true;
          this.reset();
          this.calls.foreground(this.enabled && this.foreground);
        }
        setForeground(value) {
          this.calls.foreground(value);
          this.foreground = value === true;
          if (!this.foreground) {
            this.epoch++;
            this.focusEpoch = (this.focusEpoch || 0) + 1;
            this.accountGeneration++;
            this.reconciling = null;
            this.credentialJob = null;
            this.credentialFailure = null;
            this.presenceJob = null;
            this.connecting = false;
            this.renewing = false;
            this.token = null;
            this.again = false;
            this.outbox.forEach((row) => {
              if (row.busy) {
                row.busy = false;
                if (row.state === "sending") {
                  row.state = "unknown";
                  row.code = "CHAT_TRANSPORT_UNKNOWN";
                }
              }
            });
            this.av.pause();
            if (this.disk) this.disk.pause();
            this.disconnect();
            this.stopTimers();
            return;
          }
          if (this.disk) this.disk.sweep().catch(() => {
          });
          this.start();
        }
        setNetwork(value) {
          this.network = value === true;
          this.calls.foreground(this.foreground && this.network);
          this.disconnect();
          this.stopTimers();
          if (this.network) this.start();
        }
        reset() {
          this.identityEpoch++;
          this.simulation = false;
          this.testSandbox = false;
          this.calls.reset();
          if (this.io.av && this.disk?.scope) this.io.av.reset(this.disk.scope).catch(() => {
          });
          this.av.reset();
          if (this.disk) this.disk.reset().catch(() => {
          });
          this.images.reset();
          this.epoch++;
          this.accountGeneration++;
          this.preferenceJobs.clear();
          this.disconnect();
          this.stopTimers();
          this.token = null;
          this.cache.clear();
          this.drafts.clear();
          this.recalled.clear();
          this.outbox.clear();
          this.active = "";
          this.reconciling = null;
          this.credentialJob = null;
          this.credentialFailure = null;
          this.presenceJob = null;
          this.pendingLedgerJob = null;
          if (this.pendingLedgerTimer) this.io.clearTimeout(this.pendingLedgerTimer);
          this.pendingLedgerTimer = null;
          if (this.ledger) this.ledger.unbind();
          this.lastTyping = 0;
          this.digestCursor = "";
          this.again = false;
          this.restartDigestAfterCurrent = false;
          this.renewing = false;
          this.pollFailures = 0;
          this.focusEpoch = (this.focusEpoch || 0) + 1;
          this.connecting = false;
          this.attempt = 0;
          this.state = this.enabled ? "offline" : "disabled";
          this.emit("reset");
          this.start();
        }
        stopTimers() {
          ["reconnectTimer", "pollTimer", "authTimer", "renewTimer", "presenceTimer", "presenceTimer", "pendingLedgerTimer"].forEach((key) => {
            if (this[key]) this.io.clearTimeout(this[key]);
            this[key] = null;
          });
        }
        disconnect() {
          ;
          ["authTimer", "renewTimer", "presenceTimer"].forEach((key) => {
            if (this[key]) this.io.clearTimeout(this[key]);
            this[key] = null;
          });
          this.socketReady = false;
          const task = this.task;
          this.task = null;
          if (task) {
            try {
              task.close({ code: 1e3 });
            } catch (_) {
            }
          }
          this.pending.forEach((p) => {
            this.io.clearTimeout(p.timer);
            p.reject(failure("CHAT_TRANSPORT_UNKNOWN", true));
          });
          this.pending.clear();
          this.state = this.enabled ? "offline" : "disabled";
        }
        start() {
          if (!this.live(this.epoch)) return;
          this.connect();
          this.schedulePoll(0);
        }
        retryConnection() {
          this.credentialFailure = null;
          if (this.reconnectTimer) this.io.clearTimeout(this.reconnectTimer);
          this.reconnectTimer = null;
          this.attempt = 0;
          this.start();
        }
        async credential(epoch, force = false) {
          if (this.credentialFailure && this.credentialFailure.until > this.io.now()) throw this.credentialFailure.error;
          if (!this.token || force || this.token.expires <= this.io.now() + 3e4) {
            if (!this.credentialJob) {
              const job = Promise.resolve().then(() => this.io.credential(force)).then((result) => {
                if (!this.live(epoch)) throw failure("CHAT_CONTEXT_CHANGED");
                const expires = Date.parse(result.expires_at);
                if (!result.access_token || !Number.isFinite(expires) || expires <= this.io.now()) throw failure("CHAT_UNAUTHORIZED");
                if (result.environment && this.io.environment && result.environment !== this.io.environment()) throw failure("CHAT_UNAUTHORIZED");
                if (this.disk && result.actor_id && result.environment) this.disk.bind(result.environment, String(result.actor_id));
                if (this.ledger && result.actor_id && result.environment) this.ledger.bind(result.environment, String(result.actor_id));
                this.calls.actorID = String(result.actor_id || "");
                this.calls.setEnabled(result.calls_enabled === true);
                this.token = { value: result.access_token, expires, transport: result.transport || "go" };
                this.credentialFailure = null;
                this.simulation = result.transport === "simulation";
                this.testSandbox = result.test_sandbox === true;
                this.emit("transport", { simulation: this.simulation });
                this.emit("capabilities", { calls: this.calls.enabled });
                this.schedulePendingRestore(epoch);
              }).catch((error) => {
                if (this.live(epoch)) {
                  const dependency = error.statusCode === 503 || error.statusCode === 429 || ["CHAT_DEPENDENCY_UNAVAILABLE", "CHAT_SIGNING_NOT_CONFIGURED", "CHAT_RECOVERY_NOT_READY", "CHAT_DISABLED"].includes(error.code);
                  this.credentialFailure = { error, until: this.io.now() + (dependency ? 6e4 : 1e4) };
                  this.state = "unavailable";
                  this.emit("connection", { state: "unavailable", code: error.code });
                }
                throw error;
              }).finally(() => {
                if (this.credentialJob === job) this.credentialJob = null;
              });
              this.credentialJob = job;
            }
            await this.credentialJob;
          }
          return this.token.value;
        }
        async http(type, payload, epoch = this.epoch) {
          if (!this.live(epoch) || !routes[type]) throw failure("CHAT_UNAVAILABLE");
          const token = await this.credential(epoch);
          let result;
          try {
            result = await this.io.http(routes[type], payload, token);
          } catch (error) {
            if (error.statusCode === 401 && this.live(epoch)) {
              const renewed = await this.credential(epoch, true);
              result = await this.io.http(routes[type], payload, renewed);
              if (!this.live(epoch)) throw failure("CHAT_CONTEXT_CHANGED");
              return result;
            }
            if (epoch === this.epoch && (type.startsWith("media.") || type.startsWith("av.")) && payload.conversation_id && ["CHAT_PERMISSION_DENIED", "CHAT_UNAUTHORIZED"].includes(error.code)) {
              this.forgetConversation(payload.conversation_id);
              this.emit("conversation.clear", { conversation_id: payload.conversation_id });
            }
            throw error;
          }
          if (!this.live(epoch)) throw failure("CHAT_CONTEXT_CHANGED");
          return result;
        }
        async connect() {
          if (!this.live(this.epoch) || this.task || this.connecting || this.reconnectTimer) return;
          const epoch = this.epoch;
          this.connecting = true;
          try {
            const token = await this.credential(epoch);
            if (this.token?.transport === "simulation") {
              this.state = "http";
              this.emit("connection", { state: "http" });
              return;
            }
            const ticket = await this.io.http("auth/socket-ticket", {}, token);
            if (!this.live(epoch)) return;
            const task = this.io.socket();
            this.task = task;
            const current = () => this.live(epoch) && this.task === task;
            task.onOpen(() => {
              if (current()) this.write("auth", { ticket: ticket.ticket }, "auth");
            });
            task.onMessage((event) => {
              if (!current()) return;
              let f;
              try {
                f = JSON.parse(event.data);
              } catch (_) {
                this.socketFailed(task);
                return;
              }
              if (f.protocol_version !== 1 || !f.payload || typeof f.type !== "string") return;
              if (f.type === "auth.ok") {
                this.socketReady = true;
                this.state = "socket";
                this.attempt = 0;
                if (this.authTimer) this.io.clearTimeout(this.authTimer);
                this.authTimer = null;
                if (this.renewTimer) this.io.clearTimeout(this.renewTimer);
                this.renewTimer = this.io.setTimeout(() => this.renew(task, epoch), Math.max(1e3, Number(f.payload.expires_at) * 1e3 - this.io.now() - 3e4));
                this.schedulePoll(0);
                if (this.active) this.command("conversation.focus", { conversation_id: this.active }).then(() => this.presence()).catch(() => {
                });
                this.emit("connection", { state: "socket" });
                return;
              }
              if (f.type === "calls.changed") {
                this.calls.schedule(0);
                return;
              }
              if (f.type === "receipt.changed") {
                const cid = String(f.payload.conversation_id || ""), seq = String(f.payload.read_seq || ""), model = this.cache.get(cid);
                if (model && /^(0|[1-9][0-9]{0,19})$/.test(seq) && (!f.payload.policy_version || String(f.payload.policy_version) === String(model.policy)) && (!model.peerReadSeq || compare(seq, model.peerReadSeq) >= 0)) model.peerReadSeq = seq;
                this.emit("receipt.changed", { ...f.payload, ...model && model.peerReadSeq ? { read_seq: model.peerReadSeq } : {} });
                return;
              }
              if (f.type === "auth.required") {
                this.renew(task, epoch);
                return;
              }
              if (f.type === "ping") {
                this.write("pong", { nonce: f.payload.nonce });
                return;
              }
              if (this.pending.has(f.request_id)) {
                const p = this.pending.get(f.request_id);
                this.pending.delete(f.request_id);
                this.io.clearTimeout(p.timer);
                if (f.type === "error") p.reject(failure(f.payload.code, f.payload.retryable));
                else p.resolve(f.payload);
                return;
              }
              if (f.type === "message.new" || f.type === "account.summary.changed" || f.type === "message.changed" || f.type === "policy.changed") this.schedulePoll(0);
              if (f.type === "presence.changed") this.presence().catch(() => {
              });
              if (f.type === "typing.start" || f.type === "typing.stop" || f.type === "presence.changed") this.emit(f.type, f.payload);
            });
            task.onClose(() => this.socketFailed(task));
            task.onError(() => this.socketFailed(task));
            this.authTimer = this.io.setTimeout(() => {
              if (current() && !this.socketReady) this.socketFailed(task);
            }, 6e3);
          } catch (_) {
            if (this.live(epoch)) this.retry();
          } finally {
            if (epoch === this.epoch) this.connecting = false;
          }
        }
        socketFailed(task) {
          if (this.task !== task) return;
          this.disconnect();
          this.emit("connection", { state: "http" });
          this.retry();
          this.schedulePoll(0);
        }
        retry() {
          if (!this.live(this.epoch) || this.reconnectTimer) return;
          const jitter = typeof this.io.jitter === "function" ? this.io.jitter() : 0.5;
          const backoff = Math.min(3e4, 1e3 * Math.pow(2, Math.min(this.attempt++, 5))) * (0.8 + jitter * 0.4);
          const delay = Math.max(backoff, (this.credentialFailure?.until || 0) - this.io.now());
          this.reconnectTimer = this.io.setTimeout(() => {
            this.reconnectTimer = null;
            this.connect();
          }, delay);
        }
        async renew(task, epoch) {
          if (this.renewing || !this.live(epoch) || task !== this.task) return;
          this.renewing = true;
          try {
            const token = await this.credential(epoch, true);
            if (this.live(epoch) && task === this.task) this.write("auth.refresh", { access_token: token }, "renew");
          } catch (_) {
            this.socketFailed(task);
          } finally {
            this.renewing = false;
          }
        }
        write(type, payload, id) {
          if (!this.task) throw failure("CHAT_TRANSPORT_UNKNOWN", true);
          const task = this.task;
          task.send({ data: JSON.stringify({ protocol_version: 1, request_id: id || "c" + ++this.serial, type, payload }), fail: () => this.socketFailed(task) });
        }
        command(type, payload) {
          const epoch = this.epoch;
          if (!this.socketReady) return this.http(type, payload, epoch);
          return new Promise((resolve, reject) => {
            if (this.pending.size >= 16) return reject(failure("CHAT_RESOURCE_BUSY", true));
            const id = "c" + ++this.serial;
            const timer = this.io.setTimeout(() => {
              this.pending.delete(id);
              reject(failure("CHAT_TRANSPORT_UNKNOWN", true));
            }, 5e3);
            this.pending.set(id, { resolve, reject, timer });
            try {
              this.write(type, payload, id);
            } catch (e) {
              this.io.clearTimeout(timer);
              this.pending.delete(id);
              reject(e);
            }
          }).catch((error) => {
            if (error.code !== "CHAT_TRANSPORT_UNKNOWN" || !this.live(epoch)) throw error;
            if (type === "message.send") return this.http("message.status", { conversation_id: payload.conversation_id, client_message_id: payload.client_message_id }, epoch);
            return this.http(type, payload, epoch);
          });
        }
        focus(id) {
          if (this.presenceTimer) this.io.clearTimeout(this.presenceTimer);
          this.presenceTimer = null;
          this.lastTyping = 0;
          this.active = id || "";
          this.focusEpoch = (this.focusEpoch || 0) + 1;
          if (this.socketReady) this.command("conversation.focus", { conversation_id: this.active || "0" }).then(() => this.presence()).catch(() => {
          });
          this.schedulePoll(0);
        }
        // A newly mounted inbox needs the first page, not an old partial
        // continuation left by background reconciliation.
        refreshAccount() {
          this.accountGeneration++;
          if (!this.enabled) return;
          if (this.reconciling) {
            this.restartDigestAfterCurrent = true;
            this.again = true;
            return;
          }
          this.digestCursor = "";
          this.schedulePoll(0);
        }
        schedulePoll(delay) {
          if (!this.live(this.epoch)) return;
          if (delay === 0 && this.reconciling) {
            this.again = true;
            return;
          }
          if (this.pollTimer) this.io.clearTimeout(this.pollTimer);
          this.pollTimer = this.io.setTimeout(() => {
            this.pollTimer = null;
            this.reconcile().catch(() => {
            });
          }, delay);
        }
        async reconcile() {
          if (!this.live(this.epoch)) return;
          if (this.reconciling) {
            this.again = true;
            return this.reconciling;
          }
          const epoch = this.epoch;
          const accountGeneration = this.accountGeneration;
          const focusEpoch = this.focusEpoch;
          const id = this.active;
          const job = (async () => {
            if (id && this.active === id && this.focusEpoch === focusEpoch) await this.recoverConversation(id, epoch, focusEpoch);
            let cursor = "";
            let hasMore = true;
            let pages = 0;
            cursor = this.digestCursor || "";
            while (hasMore && pages++ < 3) {
              const page = await this.http("account.digests", { limit: 20, ...cursor ? { cursor } : {} }, epoch);
              if (!this.live(epoch) || accountGeneration !== this.accountGeneration) return;
              this.emit("digests", { items: page.items, has_more: page.has_more, replace: !cursor, preference_revision: page.preference_revision });
              if (!this.live(epoch) || accountGeneration !== this.accountGeneration) return;
              if (!page.has_more && Number.isSafeInteger(page.badge_count) && page.badge_count >= 0) this.emit("account.summary", { badge_count: page.badge_count, preference_revision: page.preference_revision });
              if (!this.live(epoch) || accountGeneration !== this.accountGeneration) return;
              hasMore = page.has_more;
              cursor = page.next_cursor || "";
              if (hasMore && !cursor) throw failure("CHAT_INVALID_RESPONSE");
            }
            this.digestCursor = hasMore ? cursor : "";
            if (hasMore) this.again = true;
            if (this.live(epoch)) {
              this.state = this.socketReady ? "socket" : "http";
              this.pollFailures = 0;
            }
          })().catch((error) => {
            if (this.live(epoch) && accountGeneration === this.accountGeneration) {
              this.pollFailures = Math.min((this.pollFailures || 0) + 1, 6);
              this.state = "unavailable";
              this.emit("connection", { state: "unavailable", code: error.code });
              if (error.code === "CHAT_RESYNC_REQUIRED") this.digestCursor = "";
            }
            throw error;
          }).finally(() => {
            if (this.reconciling !== job) return;
            this.reconciling = null;
            if (this.restartDigestAfterCurrent) {
              this.restartDigestAfterCurrent = false;
              this.digestCursor = "";
              this.again = true;
            }
            if (this.live(epoch)) {
              const immediate = this.again;
              this.again = false;
              this.schedulePoll(immediate ? 0 : this.pollFailures ? Math.min(3e4, 5e3 * Math.pow(2, this.pollFailures - 1)) : this.socketReady ? 45e3 : 7500);
            }
          });
          this.reconciling = job;
          return job;
        }
        async presence() {
          if (!this.active || !this.socketReady || !this.live(this.epoch)) return;
          if (this.presenceJob) return this.presenceJob;
          if (this.presenceTimer) this.io.clearTimeout(this.presenceTimer);
          this.presenceTimer = null;
          const id = this.active;
          const epoch = this.epoch;
          const job = this.command("presence.query", { conversation_id: id }).then((snapshot) => {
            if (this.live(epoch) && this.active === id) this.emit("presence.snapshot", snapshot);
          }).finally(() => {
            if (this.presenceJob !== job) return;
            this.presenceJob = null;
            if (this.live(epoch) && this.active === id && this.socketReady) this.presenceTimer = this.io.setTimeout(() => this.presence().catch(() => {
            }), 5e3);
          });
          this.presenceJob = job;
          return job;
        }
        async recoverConversation(id, epoch, focusEpoch) {
          const current = () => this.live(epoch) && this.active === id && this.focusEpoch === focusEpoch;
          let model = this.cache.get(id);
          try {
            if (!model) {
              let page = await this.http("conversation.bootstrap", { conversation_id: id }, epoch);
              if (!current()) return;
              model = { conversationId: id, metadata: {}, viewerID: page.viewer_actor_id || "", messages: /* @__PURE__ */ new Map(), sync: page.sync_cursor, changes: page.change_cursor, policy: page.policy_version, canSend: page.can_send === true, read: page.sync_cursor ? { cursor: page.sync_cursor, seq: page.snapshot_max_seq } : null };
              this.cache.clear();
              this.cache.set(id, model);
              page = this.merge(model, page);
              this.emit("conversation.reset", { conversation_id: id, page });
            }
            for (const [type, key, next] of [["sync.request", "sync", "sync_cursor"], ["changes.sync", "changes", "change_cursor"]]) {
              for (let n = 0; n < 4; n++) {
                let page = await this.http(type, { conversation_id: id, cursor: model[key], limit: 50 }, epoch);
                if (!current()) return;
                if (page.policy_version !== model.policy) throw failure("CHAT_RESYNC_REQUIRED");
                if (!page[next]) throw failure("CHAT_INVALID_RESPONSE");
                page = this.merge(model, page);
                model[key] = page[next];
                model.canSend = page.can_send === true;
                if (key === "sync") model.read = !page.has_more ? { cursor: page.sync_cursor, seq: page.snapshot_max_seq } : null;
                this.emit("conversation.page", { conversation_id: id, stream: key, page: { ...page, window: [...model.messages.values()] } });
                if (!page.has_more) break;
                if (n === 3) this.again = true;
              }
            }
          } catch (error) {
            if (current() && ["CHAT_RESYNC_REQUIRED", "CHAT_PERMISSION_DENIED", "CHAT_UNAUTHORIZED"].includes(error.code)) {
              this.forgetConversation(id, error.code !== "CHAT_RESYNC_REQUIRED");
              this.emit("conversation.clear", { conversation_id: id });
              if (error.code === "CHAT_RESYNC_REQUIRED") this.again = true;
            }
            throw error;
          }
        }
        merge(model, page) {
          if (page.viewer_actor_id) model.viewerID = String(page.viewer_actor_id);
          if (typeof page.peer_read_seq === "string" && /^(0|[1-9][0-9]{0,19})$/.test(page.peer_read_seq)) {
            if (!model.peerReadSeq || compare(page.peer_read_seq, model.peerReadSeq) >= 0) model.peerReadSeq = page.peer_read_seq;
          }
          if (page.capabilities) model.capabilities = { ...page.capabilities };
          if (page.policy_version) model.metadata = { conversation_mode: page.conversation_mode, order_context: page.order_context, member_role: page.member_role, viewer_actor_id: page.viewer_actor_id, policy_version: page.policy_version, can_send: page.can_send };
          const messages = [...page.messages || [], ...(page.changes || []).map((c) => c.message)];
          messages.forEach((m) => {
            const prior = model.messages.get(m.message_id);
            if (prior && prior.state === "published" && m.state === "recalled" && compare(m.state_version, prior.state_version) >= 0 && String(prior.sender_id) === model.viewerID && typeof prior.text === "string" && model.conversationId) this.rememberRecall(model.conversationId, m.message_id, prior.text);
            model.messages.set(m.message_id, mergeMessage(prior, m));
            if (this.disk && m.state !== "published") this.disk.revoke(model.conversationId, m.message_id).catch(() => {
            });
            if (m.state !== "published") {
              for (const key of this.images.urls.keys()) if (key.startsWith(model.conversationId + ":" + m.message_id + ":")) this.images.urls.delete(key);
            }
          });
          for (const [key, message] of model.messages) {
            const safe2 = { ...message };
            for (const field of ["reply", "edited_from"]) {
              const ref = safe2[field], target = ref && model.messages.get(ref.message_id);
              if (target && compare(target.state_version, ref.state_version || "0") > 0) safe2[field] = { message_id: ref.message_id, sender_id: target.sender_id, state: target.state, state_version: target.state_version, ...target.state === "published" && field === "reply" && typeof target.text === "string" ? { text: Array.from(target.text).slice(0, 160).join("") } : {} };
            }
            model.messages.set(key, safe2);
          }
          if (this.disk && model.capabilities?.cache) this.disk.remember(model.conversationId, [...model.messages.values()]).catch(() => {
          });
          if (model.messages.size > 200) {
            const sorted = [...model.messages.values()].sort((a, b) => compare(a.seq, b.seq));
            sorted.slice(0, sorted.length - 200).forEach((m) => model.messages.delete(m.message_id));
          }
          const safe = (m) => model.messages.get(m.message_id) || mergeMessage(null, m);
          return { ...page, ...model.peerReadSeq !== void 0 ? { peer_read_seq: model.peerReadSeq } : {}, messages: (page.messages || []).map(safe), changes: (page.changes || []).map((c) => ({ ...c, message: safe(c.message) })) };
        }
        async updatePreference(id, snapshot, field, value) {
          if (!snapshot || !["pinned", "later"].includes(field) || typeof value !== "boolean") throw failure("CHAT_INVALID_REQUEST");
          if (this.preferenceJobs.has(id) || this.preferenceJobs.size >= 16) throw failure("CHAT_RESOURCE_BUSY");
          const epoch = this.epoch;
          const payload = { conversation_id: id, member_version: snapshot.member_version, field, value, expected_version: snapshot[field + "_version"] };
          this.preferenceJobs.set(id, true);
          this.refreshAccount();
          try {
            let result;
            try {
              result = await this.http("conversation.preference", payload, epoch);
            } catch (error) {
              if (!["CHAT_NETWORK_ERROR", "CHAT_TRANSPORT_UNKNOWN", "CHAT_DEPENDENCY_UNAVAILABLE", "CHAT_UNAVAILABLE"].includes(error.code)) throw error;
              result = await this.http("conversation.preferences", { conversation_id: id }, epoch);
              const previous = String(payload.expected_version), current = String(result[field + "_version"]);
              const next = increment(previous);
              if (result.member_version !== payload.member_version || result[field] !== value || current !== next) throw failure("CHAT_PREFERENCE_UNKNOWN");
            }
            if (!this.live(epoch)) throw failure("CHAT_CONTEXT_CHANGED");
            this.emit("preference", { conversation_id: id, preferences: result });
            return result;
          } catch (error) {
            if (this.live(epoch) && ["CHAT_PERMISSION_DENIED", "CHAT_UNAUTHORIZED"].includes(error.code)) {
              this.forgetConversation(id);
              this.emit("conversation.clear", { conversation_id: id });
            }
            throw error;
          } finally {
            if (epoch === this.epoch) {
              this.preferenceJobs.delete(id);
              this.refreshAccount();
            }
          }
        }
        // All user actions are bound to the visible focus and identity generation.
        checkFocus(id, epoch, focus) {
          if (!this.live(epoch) || this.active !== id || this.focusEpoch !== focus) throw failure("CHAT_CONTEXT_CHANGED");
        }
        forgetConversation(id, purge = true) {
          if (this.disk && purge) this.disk.forget(id).catch(() => {
          });
          if (this.ledger && purge) this.ledger.forget(id);
          this.av.reset(id);
          if (purge && this.io.av && this.disk?.scope) this.io.av.forget(this.disk.scope, id).catch(() => {
          });
          this.images.reset(id);
          this.cache.delete(id);
          this.drafts.delete(id);
          this.recalled.forEach((v, k) => {
            if (v.conversation_id === id) this.recalled.delete(k);
          });
          this.outbox.forEach((v, k) => {
            if (v.conversation_id === id) this.outbox.delete(k);
          });
        }
        async action(id, type, payload) {
          const epoch = this.epoch;
          const focus = this.focusEpoch;
          this.checkFocus(id, epoch, focus);
          try {
            const result = await this.command(type, { conversation_id: id, ...payload });
            this.checkFocus(id, epoch, focus);
            return result;
          } catch (error) {
            if (error.code === "CHAT_PERMISSION_DENIED" && type === "message.locate") {
              try {
                const snapshot = await this.http("conversation.unread", { conversation_id: id }, epoch);
                this.checkFocus(id, epoch, focus);
                const model = this.cache.get(id);
                error = failure(model && snapshot.policy_version !== model.policy ? "CHAT_RESYNC_REQUIRED" : "CHAT_MESSAGE_UNAVAILABLE");
              } catch (probe) {
                error = probe;
              }
            }
            if (epoch === this.epoch && ["CHAT_PERMISSION_DENIED", "CHAT_UNAUTHORIZED", "CHAT_RESYNC_REQUIRED"].includes(error.code)) {
              this.forgetConversation(id);
              this.emit("conversation.clear", { conversation_id: id });
              if (error.code === "CHAT_RESYNC_REQUIRED") this.schedulePoll(0);
            }
            throw error;
          }
        }
        async window(id, type, value) {
          if (!["history.request", "message.locate", "conversation.bootstrap"].includes(type)) throw failure("CHAT_INVALID_REQUEST");
          const model = this.cache.get(id);
          if (!model) throw failure("CHAT_UNAVAILABLE");
          const changes = model.changes;
          const page = await this.action(id, type, type === "history.request" ? { cursor: value, limit: 30 } : type === "message.locate" ? { message_id: value, limit: 30 } : { limit: 30 });
          if (this.cache.get(id) !== model || page.policy_version !== model.policy) throw failure("CHAT_RESYNC_REQUIRED");
          if (changes !== model.changes) throw failure("CHAT_WINDOW_CHANGED", true);
          return this.merge({ messages: new Map(model.messages) }, page);
        }
        unread(id) {
          return this.action(id, "conversation.unread", {});
        }
        readTarget(id) {
          const model = this.cache.get(id);
          return model && model.read ? { ...model.read, epoch: this.epoch, policy: model.policy } : null;
        }
        async acknowledge(id, target) {
          const model = this.cache.get(id);
          if (!target || target.epoch !== this.epoch || !model || target.policy !== model.policy) throw failure("CHAT_CONTEXT_CHANGED");
          const result = await this.action(id, "receipt.read", { cursor: target.cursor });
          this.refreshAccount();
          return result;
        }
        pruneMemory() {
          const now = this.io.now();
          this.drafts.forEach((v, k) => {
            if (v.expires <= now) this.drafts.delete(k);
          });
          this.outbox.forEach((v, k) => {
            if (v.expires <= now) this.outbox.delete(k);
          });
          this.recalled.forEach((v, k) => {
            if (v.expires <= now) this.recalled.delete(k);
          });
        }
        draft(id) {
          this.pruneMemory();
          return this.drafts.get(id) || { text: "", revision: 0 };
        }
        saveDraft(id, text, expected, source) {
          this.pruneMemory();
          const current = this.draft(id);
          if (expected !== void 0 && current.revision !== expected) return false;
          const value = Array.from(String(text || "")).slice(0, 2e3).join("");
          const metadata = source === void 0 ? current.source || {} : source;
          const clean = {};
          for (const key of ["reply_to_message_id", "reply_to_item_id", "edited_from_recalled_message_id"]) if (metadata[key] && /^[1-9][0-9]{0,19}$/.test(String(metadata[key]))) clean[key] = String(metadata[key]);
          if (!value && !Object.keys(clean).length) this.drafts.delete(id);
          else {
            this.drafts.delete(id);
            this.drafts.set(id, { text: value, source: clean, revision: ++this.serial, expires: this.io.now() + 30 * 60 * 1e3 });
            while (this.drafts.size > 5) this.drafts.delete(this.drafts.keys().next().value);
          }
          return true;
        }
        attempts(id) {
          this.pruneMemory();
          return [...this.outbox.values()].filter((v) => v.conversation_id === id).map((v) => ({ ...v, source: { ...v.source || {} } }));
        }
        emitAttempt(row) {
          this.emit("composer", { conversation_id: row.conversation_id });
        }
        async clientID() {
          if (typeof this.io.randomBytes !== "function") throw failure("CHAT_RANDOM_UNAVAILABLE");
          const raw = await this.io.randomBytes(26);
          if (typeof raw !== "string" || !/^[0-9a-f]{52}$/.test(raw)) throw failure("CHAT_RANDOM_UNAVAILABLE");
          let prefix = String(parseInt(raw.slice(0, 2), 16) % 9 + 1);
          for (let i = 1; i < 10; i++) prefix += String(parseInt(raw.slice(i * 2, i * 2 + 2), 16) % 10);
          return prefix + "." + raw.slice(20);
        }
        schedulePendingRestore(epoch = this.epoch) {
          if (!this.ledger || this.pendingLedgerJob || this.pendingLedgerTimer || !this.live(epoch) || this.simulation === true || this.testSandbox === true) return;
          this.pendingLedgerTimer = this.io.setTimeout(() => {
            this.pendingLedgerTimer = null;
            this.restorePending(epoch).catch(() => {
            });
          }, 0);
        }
        async restorePending(epoch = this.epoch) {
          if (!this.ledger || this.pendingLedgerJob || !this.live(epoch)) return this.pendingLedgerJob;
          const job = (async () => {
            const rows = this.ledger.list().slice(-20);
            for (const saved of rows) {
              if (!this.live(epoch)) throw failure("CHAT_CONTEXT_CHANGED");
              let row = this.outbox.get(saved.client_message_id);
              if (!row) {
                row = { conversation_id: saved.conversation_id, client_message_id: saved.client_message_id, text: "", source: {}, state: "unknown", busy: false, recovered: true, lastSubmitted: 0, expires: this.io.now() + 30 * 60 * 1e3 };
                this.outbox.set(row.client_message_id, row);
                this.emitAttempt(row);
              }
              row.busy = true;
              this.emitAttempt(row);
              try {
                const result = await this.http("message.status", { conversation_id: row.conversation_id, client_message_id: row.client_message_id }, epoch);
                if (!this.live(epoch) || this.outbox.get(row.client_message_id) !== row) throw failure("CHAT_CONTEXT_CHANGED");
                this.settleAttempt(row, result);
              } catch (error) {
                if (!this.live(epoch) || this.outbox.get(row.client_message_id) !== row) throw error;
                if (error.code === "CHAT_SEND_RESULT_UNKNOWN") {
                  row.state = "unknown";
                  row.code = error.code;
                } else if (error.code === "CHAT_ACTION_EXPIRED") {
                  row.state = "rejected";
                  row.code = error.code;
                  this.ledger.remove(row.client_message_id);
                } else row.code = error.code || "CHAT_NETWORK_ERROR";
              } finally {
                if (this.outbox.get(row.client_message_id) === row) {
                  row.busy = false;
                  this.emitAttempt(row);
                }
              }
            }
          })().finally(() => {
            if (this.pendingLedgerJob === job) this.pendingLedgerJob = null;
          });
          this.pendingLedgerJob = job;
          return job;
        }
        async sendText(id, text, draftRevision) {
          const model = this.cache.get(id);
          this.checkFocus(id, this.epoch, this.focusEpoch);
          if (!model || !model.canSend) throw failure("CHAT_SEND_RESTRICTED");
          if (!validText(text)) throw failure("CHAT_TEXT_INVALID");
          const draft = this.draft(id);
          if (draftRevision !== void 0 && draft.revision !== draftRevision) throw failure("CHAT_CONTEXT_CHANGED");
          this.pruneMemory();
          if (this.outbox.size >= 20) {
            for (const [key, row2] of this.outbox) if (["accepted", "rejected"].includes(row2.state)) {
              this.outbox.delete(key);
              break;
            }
            if (this.outbox.size >= 20) throw failure("CHAT_RESOURCE_BUSY");
          }
          const client = await this.clientID();
          this.checkFocus(id, this.epoch, this.focusEpoch);
          const row = { conversation_id: id, client_message_id: client, text, source: { ...draft.source || {} }, state: "sending", busy: true, lastSubmitted: this.io.now(), expires: this.io.now() + 30 * 60 * 1e3 };
          this.outbox.set(client, row);
          if (this.ledger) this.ledger.put(row);
          if (this.draft(id).text === text) this.saveDraft(id, "", draftRevision, {});
          this.emitAttempt(row);
          return this.submit(row);
        }
        async submit(row) {
          row.lastSubmitted = this.io.now();
          const epoch = this.epoch;
          try {
            const result = await this.action(row.conversation_id, "message.send", { client_message_id: row.client_message_id, message_type: "text", text: row.text, ...row.source || {} });
            this.settleAttempt(row, result);
          } catch (error) {
            if (epoch === this.epoch && this.outbox.get(row.client_message_id) === row) this.failAttempt(row, error);
          } finally {
            if (epoch === this.epoch && this.outbox.get(row.client_message_id) === row) {
              row.busy = false;
              this.emitAttempt(row);
            }
          }
        }
        settleAttempt(row, result) {
          if (!result || result.client_message_id !== row.client_message_id || !["accepted", "rejected", "retryable", "checking", "review_pending"].includes(result.state)) throw failure("CHAT_INVALID_RESPONSE");
          row.risk_event_id = result.risk_event_id || "";
          row.state = result.state;
          row.code = result.code || "";
          row.message_id = result.message_id || "";
          if (result.state === "accepted") {
            row.text = "";
            if (this.ledger) this.ledger.remove(row.client_message_id);
            this.schedulePoll(0);
            this.refreshAccount();
          } else if (result.state === "rejected" && this.ledger) this.ledger.remove(row.client_message_id);
        }
        failAttempt(row, error) {
          if (["CHAT_REFERENCE_UNAVAILABLE", "CHAT_REEDIT_LIMIT"].includes(error.code)) this.schedulePoll(0);
          row.code = error.code || "CHAT_NETWORK_ERROR";
          row.state = ["CHAT_CHECK_UNAVAILABLE", "CHAT_DEPENDENCY_UNAVAILABLE", "CHAT_RESOURCE_BUSY"].includes(row.code) ? "retryable" : ["CHAT_ACTION_EXPIRED", "CHAT_CLIENT_MESSAGE_CONFLICT", "CHAT_CONTENT_BLOCKED", "CHAT_SEND_RESTRICTED", "CHAT_INVALID_REQUEST", "CHAT_REFERENCE_UNAVAILABLE", "CHAT_REEDIT_LIMIT"].includes(row.code) ? "rejected" : "unknown";
          if (row.state === "rejected" && this.ledger) this.ledger.remove(row.client_message_id);
        }
        async retryText(id, client) {
          this.checkFocus(id, this.epoch, this.focusEpoch);
          this.pruneMemory();
          const row = this.outbox.get(client);
          if (!row || row.conversation_id !== id || row.busy || ["accepted", "rejected"].includes(row.state)) return;
          const epoch = this.epoch;
          row.busy = true;
          this.emitAttempt(row);
          try {
            let result;
            try {
              result = await this.action(id, "message.status", { client_message_id: client });
            } catch (error) {
              if (error.code !== "CHAT_SEND_RESULT_UNKNOWN") throw error;
            }
            if (result) this.settleAttempt(row, result);
            if ((!result || result.state === "retryable" || result.state === "checking" && this.io.now() - row.lastSubmitted >= 1e4) && !row.recovered) {
              if (!this.cache.get(id) || !this.cache.get(id).canSend) throw failure("CHAT_SEND_RESTRICTED");
              await this.submit(row);
            }
          } catch (error) {
            if (epoch === this.epoch && this.outbox.get(client) === row) this.failAttempt(row, error);
          } finally {
            if (epoch === this.epoch && this.outbox.get(client) === row) {
              row.busy = false;
              this.emitAttempt(row);
            }
          }
        }
        rememberRecall(id, message, text) {
          if (typeof text !== "string" || !text) return;
          this.pruneMemory();
          const key = id + ":" + message;
          if (this.recalled.has(key)) return;
          this.recalled.set(key, { conversation_id: id, message_id: message, text, expires: this.io.now() + 30 * 60 * 1e3 });
          while (this.recalled.size > 20) this.recalled.delete(this.recalled.keys().next().value);
        }
        editableRecall(id, message) {
          this.pruneMemory();
          return this.recalled.get(id + ":" + message) || null;
        }
        async recall(id, message) {
          const epoch = this.epoch, focus = this.focusEpoch, model = this.cache.get(id), original = model && model.messages.get(message);
          this.checkFocus(id, epoch, focus);
          if (!model) throw failure("CHAT_UNAVAILABLE");
          let result;
          try {
            result = await this.action(id, "message.recall", { message_id: message });
          } catch (error) {
            if (!["CHAT_NETWORK_ERROR", "CHAT_TRANSPORT_UNKNOWN", "CHAT_DEPENDENCY_UNAVAILABLE", "CHAT_UNAVAILABLE"].includes(error.code)) throw error;
            const page2 = await this.window(id, "message.locate", message);
            result = (page2.messages || []).find((m) => m.message_id === message);
            if (!result || result.state !== "recalled") throw failure("CHAT_RECALL_UNKNOWN");
          }
          this.checkFocus(id, epoch, focus);
          if (this.cache.get(id) !== model) throw failure("CHAT_CONTEXT_CHANGED");
          if (!result || result.message_id !== message || result.state !== "recalled") throw failure("CHAT_INVALID_RESPONSE");
          if (original && original.state === "published" && String(original.sender_id) === model.viewerID && typeof original.text === "string") this.rememberRecall(id, message, original.text);
          const page = this.merge(model, { messages: [result] });
          this.emit("conversation.page", { conversation_id: id, stream: "changes", page: { ...model.metadata, ...page, window: [...model.messages.values()] } });
          this.refreshAccount();
          return result;
        }
        typing(active) {
          if (!this.active || !this.socketReady) return Promise.resolve(false);
          const now = this.io.now();
          if (active && this.lastTyping && now - this.lastTyping < 2500) return Promise.resolve(false);
          this.lastTyping = active ? now : 0;
          return this.command(active ? "typing.start" : "typing.stop", { conversation_id: this.active });
        }
      };
      function validText(text) {
        if (typeof text !== "string" || !text.trim() || text.includes("\0") || Array.from(text).length > 2e3) return false;
        let bytes = 0;
        for (const char of text) {
          const n = char.codePointAt(0);
          if (n >= 55296 && n <= 57343) return false;
          bytes += n <= 127 ? 1 : n <= 2047 ? 2 : n <= 65535 ? 3 : 4;
        }
        return bytes <= 4e3;
      }
      function increment(value) {
        const digits = String(value).split("");
        for (let i = digits.length - 1; i >= 0; i--) {
          if (digits[i] !== "9") {
            digits[i] = String(Number(digits[i]) + 1);
            return digits.join("");
          }
          digits[i] = "0";
        }
        return "1" + digits.join("");
      }
      function mergeMessage(prior, next) {
        let view = { ...prior && compare(prior.state_version, next.state_version) > 0 ? prior : next };
        for (const field of ["reply", "edited_from"]) {
          const previous = prior && prior[field], incoming = view[field];
          if (previous && incoming && previous.message_id === incoming.message_id && compare(previous.state_version || "0", incoming.state_version || "0") > 0) view[field] = { ...previous };
          else if (incoming) view[field] = { ...incoming };
          if (view[field] && (view[field].state !== "published" || field === "edited_from")) delete view[field].text;
        }
        if (view.state !== "published") {
          delete view.text;
          delete view.reply;
          delete view.edited_from;
          delete view.images;
          delete view.av;
        }
        return view;
      }
      module.exports = { ChatManager, compare, validText, mergeMessage };
    }
  });

  // node_modules/spark-md5/spark-md5.js
  var require_spark_md5 = __commonJS({
    "node_modules/spark-md5/spark-md5.js"(exports, module) {
      (function(factory) {
        if (typeof exports === "object") {
          module.exports = factory();
        } else if (typeof define === "function" && define.amd) {
          define(factory);
        } else {
          var glob;
          try {
            glob = window;
          } catch (e) {
            glob = self;
          }
          glob.SparkMD5 = factory();
        }
      })(function(undefined2) {
        "use strict";
        var add32 = function(a, b) {
          return a + b & 4294967295;
        }, hex_chr = ["0", "1", "2", "3", "4", "5", "6", "7", "8", "9", "a", "b", "c", "d", "e", "f"];
        function cmn(q, a, b, x, s, t) {
          a = add32(add32(a, q), add32(x, t));
          return add32(a << s | a >>> 32 - s, b);
        }
        function md5cycle(x, k) {
          var a = x[0], b = x[1], c = x[2], d = x[3];
          a += (b & c | ~b & d) + k[0] - 680876936 | 0;
          a = (a << 7 | a >>> 25) + b | 0;
          d += (a & b | ~a & c) + k[1] - 389564586 | 0;
          d = (d << 12 | d >>> 20) + a | 0;
          c += (d & a | ~d & b) + k[2] + 606105819 | 0;
          c = (c << 17 | c >>> 15) + d | 0;
          b += (c & d | ~c & a) + k[3] - 1044525330 | 0;
          b = (b << 22 | b >>> 10) + c | 0;
          a += (b & c | ~b & d) + k[4] - 176418897 | 0;
          a = (a << 7 | a >>> 25) + b | 0;
          d += (a & b | ~a & c) + k[5] + 1200080426 | 0;
          d = (d << 12 | d >>> 20) + a | 0;
          c += (d & a | ~d & b) + k[6] - 1473231341 | 0;
          c = (c << 17 | c >>> 15) + d | 0;
          b += (c & d | ~c & a) + k[7] - 45705983 | 0;
          b = (b << 22 | b >>> 10) + c | 0;
          a += (b & c | ~b & d) + k[8] + 1770035416 | 0;
          a = (a << 7 | a >>> 25) + b | 0;
          d += (a & b | ~a & c) + k[9] - 1958414417 | 0;
          d = (d << 12 | d >>> 20) + a | 0;
          c += (d & a | ~d & b) + k[10] - 42063 | 0;
          c = (c << 17 | c >>> 15) + d | 0;
          b += (c & d | ~c & a) + k[11] - 1990404162 | 0;
          b = (b << 22 | b >>> 10) + c | 0;
          a += (b & c | ~b & d) + k[12] + 1804603682 | 0;
          a = (a << 7 | a >>> 25) + b | 0;
          d += (a & b | ~a & c) + k[13] - 40341101 | 0;
          d = (d << 12 | d >>> 20) + a | 0;
          c += (d & a | ~d & b) + k[14] - 1502002290 | 0;
          c = (c << 17 | c >>> 15) + d | 0;
          b += (c & d | ~c & a) + k[15] + 1236535329 | 0;
          b = (b << 22 | b >>> 10) + c | 0;
          a += (b & d | c & ~d) + k[1] - 165796510 | 0;
          a = (a << 5 | a >>> 27) + b | 0;
          d += (a & c | b & ~c) + k[6] - 1069501632 | 0;
          d = (d << 9 | d >>> 23) + a | 0;
          c += (d & b | a & ~b) + k[11] + 643717713 | 0;
          c = (c << 14 | c >>> 18) + d | 0;
          b += (c & a | d & ~a) + k[0] - 373897302 | 0;
          b = (b << 20 | b >>> 12) + c | 0;
          a += (b & d | c & ~d) + k[5] - 701558691 | 0;
          a = (a << 5 | a >>> 27) + b | 0;
          d += (a & c | b & ~c) + k[10] + 38016083 | 0;
          d = (d << 9 | d >>> 23) + a | 0;
          c += (d & b | a & ~b) + k[15] - 660478335 | 0;
          c = (c << 14 | c >>> 18) + d | 0;
          b += (c & a | d & ~a) + k[4] - 405537848 | 0;
          b = (b << 20 | b >>> 12) + c | 0;
          a += (b & d | c & ~d) + k[9] + 568446438 | 0;
          a = (a << 5 | a >>> 27) + b | 0;
          d += (a & c | b & ~c) + k[14] - 1019803690 | 0;
          d = (d << 9 | d >>> 23) + a | 0;
          c += (d & b | a & ~b) + k[3] - 187363961 | 0;
          c = (c << 14 | c >>> 18) + d | 0;
          b += (c & a | d & ~a) + k[8] + 1163531501 | 0;
          b = (b << 20 | b >>> 12) + c | 0;
          a += (b & d | c & ~d) + k[13] - 1444681467 | 0;
          a = (a << 5 | a >>> 27) + b | 0;
          d += (a & c | b & ~c) + k[2] - 51403784 | 0;
          d = (d << 9 | d >>> 23) + a | 0;
          c += (d & b | a & ~b) + k[7] + 1735328473 | 0;
          c = (c << 14 | c >>> 18) + d | 0;
          b += (c & a | d & ~a) + k[12] - 1926607734 | 0;
          b = (b << 20 | b >>> 12) + c | 0;
          a += (b ^ c ^ d) + k[5] - 378558 | 0;
          a = (a << 4 | a >>> 28) + b | 0;
          d += (a ^ b ^ c) + k[8] - 2022574463 | 0;
          d = (d << 11 | d >>> 21) + a | 0;
          c += (d ^ a ^ b) + k[11] + 1839030562 | 0;
          c = (c << 16 | c >>> 16) + d | 0;
          b += (c ^ d ^ a) + k[14] - 35309556 | 0;
          b = (b << 23 | b >>> 9) + c | 0;
          a += (b ^ c ^ d) + k[1] - 1530992060 | 0;
          a = (a << 4 | a >>> 28) + b | 0;
          d += (a ^ b ^ c) + k[4] + 1272893353 | 0;
          d = (d << 11 | d >>> 21) + a | 0;
          c += (d ^ a ^ b) + k[7] - 155497632 | 0;
          c = (c << 16 | c >>> 16) + d | 0;
          b += (c ^ d ^ a) + k[10] - 1094730640 | 0;
          b = (b << 23 | b >>> 9) + c | 0;
          a += (b ^ c ^ d) + k[13] + 681279174 | 0;
          a = (a << 4 | a >>> 28) + b | 0;
          d += (a ^ b ^ c) + k[0] - 358537222 | 0;
          d = (d << 11 | d >>> 21) + a | 0;
          c += (d ^ a ^ b) + k[3] - 722521979 | 0;
          c = (c << 16 | c >>> 16) + d | 0;
          b += (c ^ d ^ a) + k[6] + 76029189 | 0;
          b = (b << 23 | b >>> 9) + c | 0;
          a += (b ^ c ^ d) + k[9] - 640364487 | 0;
          a = (a << 4 | a >>> 28) + b | 0;
          d += (a ^ b ^ c) + k[12] - 421815835 | 0;
          d = (d << 11 | d >>> 21) + a | 0;
          c += (d ^ a ^ b) + k[15] + 530742520 | 0;
          c = (c << 16 | c >>> 16) + d | 0;
          b += (c ^ d ^ a) + k[2] - 995338651 | 0;
          b = (b << 23 | b >>> 9) + c | 0;
          a += (c ^ (b | ~d)) + k[0] - 198630844 | 0;
          a = (a << 6 | a >>> 26) + b | 0;
          d += (b ^ (a | ~c)) + k[7] + 1126891415 | 0;
          d = (d << 10 | d >>> 22) + a | 0;
          c += (a ^ (d | ~b)) + k[14] - 1416354905 | 0;
          c = (c << 15 | c >>> 17) + d | 0;
          b += (d ^ (c | ~a)) + k[5] - 57434055 | 0;
          b = (b << 21 | b >>> 11) + c | 0;
          a += (c ^ (b | ~d)) + k[12] + 1700485571 | 0;
          a = (a << 6 | a >>> 26) + b | 0;
          d += (b ^ (a | ~c)) + k[3] - 1894986606 | 0;
          d = (d << 10 | d >>> 22) + a | 0;
          c += (a ^ (d | ~b)) + k[10] - 1051523 | 0;
          c = (c << 15 | c >>> 17) + d | 0;
          b += (d ^ (c | ~a)) + k[1] - 2054922799 | 0;
          b = (b << 21 | b >>> 11) + c | 0;
          a += (c ^ (b | ~d)) + k[8] + 1873313359 | 0;
          a = (a << 6 | a >>> 26) + b | 0;
          d += (b ^ (a | ~c)) + k[15] - 30611744 | 0;
          d = (d << 10 | d >>> 22) + a | 0;
          c += (a ^ (d | ~b)) + k[6] - 1560198380 | 0;
          c = (c << 15 | c >>> 17) + d | 0;
          b += (d ^ (c | ~a)) + k[13] + 1309151649 | 0;
          b = (b << 21 | b >>> 11) + c | 0;
          a += (c ^ (b | ~d)) + k[4] - 145523070 | 0;
          a = (a << 6 | a >>> 26) + b | 0;
          d += (b ^ (a | ~c)) + k[11] - 1120210379 | 0;
          d = (d << 10 | d >>> 22) + a | 0;
          c += (a ^ (d | ~b)) + k[2] + 718787259 | 0;
          c = (c << 15 | c >>> 17) + d | 0;
          b += (d ^ (c | ~a)) + k[9] - 343485551 | 0;
          b = (b << 21 | b >>> 11) + c | 0;
          x[0] = a + x[0] | 0;
          x[1] = b + x[1] | 0;
          x[2] = c + x[2] | 0;
          x[3] = d + x[3] | 0;
        }
        function md5blk(s) {
          var md5blks = [], i;
          for (i = 0; i < 64; i += 4) {
            md5blks[i >> 2] = s.charCodeAt(i) + (s.charCodeAt(i + 1) << 8) + (s.charCodeAt(i + 2) << 16) + (s.charCodeAt(i + 3) << 24);
          }
          return md5blks;
        }
        function md5blk_array(a) {
          var md5blks = [], i;
          for (i = 0; i < 64; i += 4) {
            md5blks[i >> 2] = a[i] + (a[i + 1] << 8) + (a[i + 2] << 16) + (a[i + 3] << 24);
          }
          return md5blks;
        }
        function md51(s) {
          var n = s.length, state = [1732584193, -271733879, -1732584194, 271733878], i, length, tail, tmp, lo, hi;
          for (i = 64; i <= n; i += 64) {
            md5cycle(state, md5blk(s.substring(i - 64, i)));
          }
          s = s.substring(i - 64);
          length = s.length;
          tail = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
          for (i = 0; i < length; i += 1) {
            tail[i >> 2] |= s.charCodeAt(i) << (i % 4 << 3);
          }
          tail[i >> 2] |= 128 << (i % 4 << 3);
          if (i > 55) {
            md5cycle(state, tail);
            for (i = 0; i < 16; i += 1) {
              tail[i] = 0;
            }
          }
          tmp = n * 8;
          tmp = tmp.toString(16).match(/(.*?)(.{0,8})$/);
          lo = parseInt(tmp[2], 16);
          hi = parseInt(tmp[1], 16) || 0;
          tail[14] = lo;
          tail[15] = hi;
          md5cycle(state, tail);
          return state;
        }
        function md51_array(a) {
          var n = a.length, state = [1732584193, -271733879, -1732584194, 271733878], i, length, tail, tmp, lo, hi;
          for (i = 64; i <= n; i += 64) {
            md5cycle(state, md5blk_array(a.subarray(i - 64, i)));
          }
          a = i - 64 < n ? a.subarray(i - 64) : new Uint8Array(0);
          length = a.length;
          tail = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
          for (i = 0; i < length; i += 1) {
            tail[i >> 2] |= a[i] << (i % 4 << 3);
          }
          tail[i >> 2] |= 128 << (i % 4 << 3);
          if (i > 55) {
            md5cycle(state, tail);
            for (i = 0; i < 16; i += 1) {
              tail[i] = 0;
            }
          }
          tmp = n * 8;
          tmp = tmp.toString(16).match(/(.*?)(.{0,8})$/);
          lo = parseInt(tmp[2], 16);
          hi = parseInt(tmp[1], 16) || 0;
          tail[14] = lo;
          tail[15] = hi;
          md5cycle(state, tail);
          return state;
        }
        function rhex(n) {
          var s = "", j;
          for (j = 0; j < 4; j += 1) {
            s += hex_chr[n >> j * 8 + 4 & 15] + hex_chr[n >> j * 8 & 15];
          }
          return s;
        }
        function hex(x) {
          var i;
          for (i = 0; i < x.length; i += 1) {
            x[i] = rhex(x[i]);
          }
          return x.join("");
        }
        if (hex(md51("hello")) !== "5d41402abc4b2a76b9719d911017c592") {
          add32 = function(x, y) {
            var lsw = (x & 65535) + (y & 65535), msw = (x >> 16) + (y >> 16) + (lsw >> 16);
            return msw << 16 | lsw & 65535;
          };
        }
        if (typeof ArrayBuffer !== "undefined" && !ArrayBuffer.prototype.slice) {
          (function() {
            function clamp(val, length) {
              val = val | 0 || 0;
              if (val < 0) {
                return Math.max(val + length, 0);
              }
              return Math.min(val, length);
            }
            ArrayBuffer.prototype.slice = function(from, to) {
              var length = this.byteLength, begin = clamp(from, length), end = length, num, target, targetArray, sourceArray;
              if (to !== undefined2) {
                end = clamp(to, length);
              }
              if (begin > end) {
                return new ArrayBuffer(0);
              }
              num = end - begin;
              target = new ArrayBuffer(num);
              targetArray = new Uint8Array(target);
              sourceArray = new Uint8Array(this, begin, num);
              targetArray.set(sourceArray);
              return target;
            };
          })();
        }
        function toUtf8(str) {
          if (/[\u0080-\uFFFF]/.test(str)) {
            str = unescape(encodeURIComponent(str));
          }
          return str;
        }
        function utf8Str2ArrayBuffer(str, returnUInt8Array) {
          var length = str.length, buff = new ArrayBuffer(length), arr = new Uint8Array(buff), i;
          for (i = 0; i < length; i += 1) {
            arr[i] = str.charCodeAt(i);
          }
          return returnUInt8Array ? arr : buff;
        }
        function arrayBuffer2Utf8Str(buff) {
          return String.fromCharCode.apply(null, new Uint8Array(buff));
        }
        function concatenateArrayBuffers(first, second, returnUInt8Array) {
          var result = new Uint8Array(first.byteLength + second.byteLength);
          result.set(new Uint8Array(first));
          result.set(new Uint8Array(second), first.byteLength);
          return returnUInt8Array ? result : result.buffer;
        }
        function hexToBinaryString(hex2) {
          var bytes = [], length = hex2.length, x;
          for (x = 0; x < length - 1; x += 2) {
            bytes.push(parseInt(hex2.substr(x, 2), 16));
          }
          return String.fromCharCode.apply(String, bytes);
        }
        function SparkMD5() {
          this.reset();
        }
        SparkMD5.prototype.append = function(str) {
          this.appendBinary(toUtf8(str));
          return this;
        };
        SparkMD5.prototype.appendBinary = function(contents) {
          this._buff += contents;
          this._length += contents.length;
          var length = this._buff.length, i;
          for (i = 64; i <= length; i += 64) {
            md5cycle(this._hash, md5blk(this._buff.substring(i - 64, i)));
          }
          this._buff = this._buff.substring(i - 64);
          return this;
        };
        SparkMD5.prototype.end = function(raw) {
          var buff = this._buff, length = buff.length, i, tail = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], ret;
          for (i = 0; i < length; i += 1) {
            tail[i >> 2] |= buff.charCodeAt(i) << (i % 4 << 3);
          }
          this._finish(tail, length);
          ret = hex(this._hash);
          if (raw) {
            ret = hexToBinaryString(ret);
          }
          this.reset();
          return ret;
        };
        SparkMD5.prototype.reset = function() {
          this._buff = "";
          this._length = 0;
          this._hash = [1732584193, -271733879, -1732584194, 271733878];
          return this;
        };
        SparkMD5.prototype.getState = function() {
          return {
            buff: this._buff,
            length: this._length,
            hash: this._hash.slice()
          };
        };
        SparkMD5.prototype.setState = function(state) {
          this._buff = state.buff;
          this._length = state.length;
          this._hash = state.hash;
          return this;
        };
        SparkMD5.prototype.destroy = function() {
          delete this._hash;
          delete this._buff;
          delete this._length;
        };
        SparkMD5.prototype._finish = function(tail, length) {
          var i = length, tmp, lo, hi;
          tail[i >> 2] |= 128 << (i % 4 << 3);
          if (i > 55) {
            md5cycle(this._hash, tail);
            for (i = 0; i < 16; i += 1) {
              tail[i] = 0;
            }
          }
          tmp = this._length * 8;
          tmp = tmp.toString(16).match(/(.*?)(.{0,8})$/);
          lo = parseInt(tmp[2], 16);
          hi = parseInt(tmp[1], 16) || 0;
          tail[14] = lo;
          tail[15] = hi;
          md5cycle(this._hash, tail);
        };
        SparkMD5.hash = function(str, raw) {
          return SparkMD5.hashBinary(toUtf8(str), raw);
        };
        SparkMD5.hashBinary = function(content, raw) {
          var hash = md51(content), ret = hex(hash);
          return raw ? hexToBinaryString(ret) : ret;
        };
        SparkMD5.ArrayBuffer = function() {
          this.reset();
        };
        SparkMD5.ArrayBuffer.prototype.append = function(arr) {
          var buff = concatenateArrayBuffers(this._buff.buffer, arr, true), length = buff.length, i;
          this._length += arr.byteLength;
          for (i = 64; i <= length; i += 64) {
            md5cycle(this._hash, md5blk_array(buff.subarray(i - 64, i)));
          }
          this._buff = i - 64 < length ? new Uint8Array(buff.buffer.slice(i - 64)) : new Uint8Array(0);
          return this;
        };
        SparkMD5.ArrayBuffer.prototype.end = function(raw) {
          var buff = this._buff, length = buff.length, tail = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], i, ret;
          for (i = 0; i < length; i += 1) {
            tail[i >> 2] |= buff[i] << (i % 4 << 3);
          }
          this._finish(tail, length);
          ret = hex(this._hash);
          if (raw) {
            ret = hexToBinaryString(ret);
          }
          this.reset();
          return ret;
        };
        SparkMD5.ArrayBuffer.prototype.reset = function() {
          this._buff = new Uint8Array(0);
          this._length = 0;
          this._hash = [1732584193, -271733879, -1732584194, 271733878];
          return this;
        };
        SparkMD5.ArrayBuffer.prototype.getState = function() {
          var state = SparkMD5.prototype.getState.call(this);
          state.buff = arrayBuffer2Utf8Str(state.buff);
          return state;
        };
        SparkMD5.ArrayBuffer.prototype.setState = function(state) {
          state.buff = utf8Str2ArrayBuffer(state.buff, true);
          return SparkMD5.prototype.setState.call(this, state);
        };
        SparkMD5.ArrayBuffer.prototype.destroy = SparkMD5.prototype.destroy;
        SparkMD5.ArrayBuffer.prototype._finish = SparkMD5.prototype._finish;
        SparkMD5.ArrayBuffer.hash = function(arr, raw) {
          var hash = md51_array(new Uint8Array(arr)), ret = hex(hash);
          return raw ? hexToBinaryString(ret) : ret;
        };
        return SparkMD5;
      });
    }
  });

  // server/assets/chat-testing-client.js
  var require_chat_testing_client = __commonJS({
    "server/assets/chat-testing-client.js"(exports, module) {
      var { ChatManager, compare } = require_ChatManager();
      var SparkMD5 = require_spark_md5();
      var terminal = /* @__PURE__ */ new Set(["rejected", "cancelled", "missed", "ended", "failed"]);
      var labels = { ringing: "\u547C\u53EB\u4E2D", preparing: "\u51C6\u5907\u63A5\u901A", connecting: "\u6B63\u5728\u8FDE\u63A5", active: "\u901A\u8BDD\u4E2D", stopping: "\u6B63\u5728\u7ED3\u675F", ended: "\u5DF2\u7ED3\u675F", failed: "\u672A\u80FD\u63A5\u901A", missed: "\u672A\u63A5\u542C", rejected: "\u5DF2\u62D2\u7EDD", cancelled: "\u5DF2\u53D6\u6D88" };
      var progressName = (value) => ({ editing: "\u5F85\u53D1\u9001", unknown: "\u7ED3\u679C\u5F85\u6838\u9A8C", checking: "\u68C0\u67E5\u4E2D", review_pending: "\u5F85\u5BA1\u6838", accepted: "\u5DF2\u53D1\u9001", rejected: "\u672A\u901A\u8FC7", canceled: "\u5DF2\u53D6\u6D88", retryable: "\u53EF\u91CD\u8BD5", uploading: "\u4E0A\u4F20\u4E2D", processing: "\u5904\u7406\u4E2D", raw_wait: "\u7B49\u5F85\u5FAE\u4FE1\u5BA1\u6838\u56DE\u8C03", asr: "\u8BED\u97F3\u8BC6\u522B\u4E2D", raw: "\u5A92\u4F53\u5BA1\u6838\u4E2D", publish: "\u51C6\u5907\u53D1\u9001", published: "\u5DF2\u53D1\u9001", failed: "\u672A\u5B8C\u6210", created: "\u5DF2\u521B\u5EFA", inspect: "\u5904\u7406\u5A92\u4F53\u4E2D", transcribe: "\u8BED\u97F3\u8BC6\u522B\u4E2D" })[value] || "\u7B49\u5F85\u72B6\u6001\u540C\u6B65";
      var errors = { CHAT_GO_NOT_READY: "Go\u670D\u52A1\u5C1A\u672A\u8FD0\u884C\u6216\u7248\u672C\u672A\u540C\u6B65", CHAT_SIGNING_NOT_CONFIGURED: "\u804A\u5929\u7B7E\u540D\u5C1A\u672A\u914D\u7F6E", CHAT_RECOVERY_NOT_READY: "\u804A\u5929\u6062\u590D\u68C0\u67E5\u672A\u901A\u8FC7", CHAT_IMAGE_LIMIT: "\u56FE\u7247\u6700\u591A9\u5F20\uFF0C\u5355\u5F20\u4E0D\u8D85\u8FC73MB", CHAT_AV_LIMIT_EXCEEDED: "\u8BED\u97F3\u965060\u79D2/1MB\uFF0C\u89C6\u9891\u9650120\u79D2/20MB", CHAT_SEND_RESTRICTED: "\u6B64\u80FD\u529B\u5C1A\u672A\u5C31\u7EEA\u6216\u4F1A\u8BDD\u6682\u4E0D\u53EF\u53D1\u9001", CHAT_MEDIA_UNAVAILABLE: "\u56FE\u7247\u94FE\u8DEF\u6682\u4E0D\u53EF\u7528\uFF0C\u8BF7\u68C0\u67E5\u804A\u5929\u914D\u7F6E", CHAT_AV_UNAVAILABLE: "\u97F3\u89C6\u9891\u94FE\u8DEF\u6682\u4E0D\u53EF\u7528\uFF0C\u8BF7\u68C0\u67E5\u5BA1\u6838\u53CAASR\u914D\u7F6E", CHAT_CHECK_UNAVAILABLE: "\u5BA1\u6838\u670D\u52A1\u6682\u4E0D\u53EF\u7528\uFF0C\u8BF7\u68C0\u67E5\u5FAE\u4FE1\u6388\u6743\u548C\u8FD0\u884C\u914D\u7F6E", CALL_UNAVAILABLE: "\u6D4B\u8BD5\u9875\u6D4F\u89C8\u5668\u901A\u8BDD\u4EC5\u4F7F\u7528\u58F0\u7F51\uFF1B\u8BF7\u68C0\u67E5\u58F0\u7F51\u51ED\u636E\u3001\u53D1\u5E03\u6743\u9650\u548C\u5F53\u524D\u8D44\u6E90\u6C60", CALL_unavailable: "\u6D4B\u8BD5\u9875\u6D4F\u89C8\u5668\u901A\u8BDD\u4EC5\u4F7F\u7528\u58F0\u7F51\uFF1B\u8BF7\u68C0\u67E5\u58F0\u7F51\u51ED\u636E\u3001\u53D1\u5E03\u6743\u9650\u548C\u5F53\u524D\u8D44\u6E90\u6C60", CALL_exhausted: "\u5F53\u524D\u58F0\u7F51\u8D44\u6E90\u989D\u5EA6\u5DF2\u7528\u5B8C", CALL_safety_insufficient: "\u5F53\u524D\u58F0\u7F51\u5B89\u5168\u53EF\u7528\u989D\u5EA6\u4E0D\u8DB3", CALL_busy: "\u901A\u8BDD\u8D44\u6E90\u6682\u5FD9", CALL_disabled: "\u901A\u8BDD\u603B\u5F00\u5173\u6216\u5F53\u524D\u5A92\u4F53\u7C7B\u578B\u672A\u5F00\u653E", CALL_BUSY: "\u6210\u5458\u5DF2\u6709\u901A\u8BDD\u6216\u8D44\u6E90\u6682\u5FD9", CALL_DISABLED: "\u901A\u8BDD\u672A\u5F00\u653E", CHAT_UNAUTHORIZED: "\u8EAB\u4EFD\u5DF2\u5931\u6548\uFF0C\u8BF7\u91CD\u65B0\u63A5\u5165", CHAT_PERMISSION_DENIED: "\u4F1A\u8BDD\u5DF2\u6E05\u7406\u6216\u6210\u5458\u6743\u9650\u5DF2\u53D8\u5316", CHAT_SEND_RESULT_UNKNOWN: "\u53D1\u9001\u7ED3\u679C\u5F85\u6838\u9A8C\uFF0C\u8BF7\u4FDD\u7559\u539F\u64CD\u4F5C\u6807\u8BC6\u91CD\u8BD5" };
      var message = (e) => errors[e?.code] || (/^CHAT_|^CALL_/.test(e?.code || e?.message || "") ? "\u80FD\u529B\u672A\u5C31\u7EEA\u6216\u72B6\u6001\u5DF2\u53D8\u5316\uFF0C\u8BF7\u68C0\u67E5\u804A\u5929\u8BBE\u7F6E\u540E\u91CD\u8BD5" : e?.message) || "\u64CD\u4F5C\u672A\u5B8C\u6210\uFF0C\u8BF7\u68C0\u67E5\u914D\u7F6E\u540E\u91CD\u8BD5";
      var root = document.querySelector("[data-chat-live]");
      var sdkJob;
      function sdk() {
        if (window.AgoraRTC) return Promise.resolve(window.AgoraRTC);
        if (!sdkJob) sdkJob = new Promise((resolve, reject) => {
          const s = document.createElement("script");
          s.src = "https://download.agora.io/sdk/release/AgoraRTC_N-4.22.2.js";
          s.onload = () => window.AgoraRTC ? resolve(window.AgoraRTC) : reject(Error("\u58F0\u7F51SDK\u672A\u80FD\u52A0\u8F7D"));
          s.onerror = () => {
            sdkJob = null;
            reject(Error("\u58F0\u7F51SDK\u4E0B\u8F7D\u5931\u8D25\uFF0C\u8BF7\u68C0\u67E5\u7F51\u7EDC"));
          };
          document.head.append(s);
        });
        return sdkJob;
      }
      function element(tag, text, cls) {
        const e = document.createElement(tag);
        if (text !== void 0) e.textContent = text;
        if (cls) e.className = cls;
        return e;
      }
      var TestPeer = class {
        constructor(node, owner) {
          this.node = node;
          this.owner = owner;
          this.generation = 0;
          this.cid = "";
          this.files = /* @__PURE__ */ new Map();
          this.media = null;
          this.tracks = [];
          this.urls = /* @__PURE__ */ new Map();
          this.timer = null;
          this.callAvailability = null;
          this.actor = node.querySelector("[data-live-actor]");
          this.log = node.querySelector("[data-live-log]");
          this.notice = node.querySelector("[data-live-notice]");
          this.transfer = node.querySelector("[data-live-transfer]");
          this.actions = node.querySelector("[data-live-actions]");
          node.querySelector("[data-live-connect]").onclick = async (event) => {
            if (this.starting) return;
            this.starting = true;
            event.target.disabled = true;
            try {
              await this.start();
            } catch (e) {
              this.node.dataset.liveFailure = e.code || e.message;
              this.tell(message(e));
            } finally {
              this.starting = false;
              event.target.disabled = false;
            }
          };
          node.querySelector("[data-live-disconnect]").onclick = () => this.stop();
          this.actor.onchange = () => this.stop();
          node.querySelector("[data-live-text]").onsubmit = async (event) => {
            event.preventDefault();
            const input = event.target.elements.text;
            try {
              this.require();
              const value = input.value;
              this.manager.saveDraft(this.cid, value);
              await this.manager.sendText(this.cid, value, this.manager.draft(this.cid).revision);
              input.value = "";
              this.paint();
            } catch (e) {
              this.tell(message(e));
            }
          };
          for (const kind of ["images", "audio", "video"]) node.querySelector("[data-live-" + kind + "]").onchange = (e) => this.select(kind, Array.from(e.target.files || [])).catch((e2) => this.tell(message(e2))).finally(() => {
            e.target.value = "";
          });
          node.querySelector("[data-live-retry]").onclick = () => {
            this.manager?.retryConnection();
            this.paint();
          };
          for (const name of ["audio", "video"]) node.querySelector("[data-call-" + name + "]").onclick = () => this.call(name).catch((e) => this.tell(message(e)));
          node.querySelector("[data-call-accept]").onclick = () => this.manager?.calls.action("accept").then(() => this.tick()).catch((e) => this.tell(message(e)));
          node.querySelector("[data-call-reject]").onclick = () => this.manager?.calls.action("reject").then(() => this.stopMedia()).catch((e) => this.tell(message(e)));
          node.querySelector("[data-call-end]").onclick = () => this.hangup();
          node.querySelector("[data-live-older]").onclick = () => this.history().catch((e) => this.tell(message(e)));
          this.actions.disabled = true;
        }
        tell(text) {
          this.notice.textContent = text;
        }
        require() {
          if (!this.manager || !this.cid) throw Error("\u8BF7\u5148\u9009\u62E9\u6210\u5458\u5E76\u70B9\u51FB\u63A5\u5165");
        }
        async credential() {
          const selected = this.sessionActor || this.actor.value;
          const form = new FormData();
          form.set("_action", "live_credentials");
          form.set("_token", root.dataset.csrf);
          form.set("room", root.dataset.room);
          form.set("sender", selected);
          const response = await fetch(root.dataset.endpoint, { method: "POST", credentials: "same-origin", body: form, headers: { Accept: "application/json" } });
          const body = await response.json();
          if (!response.ok || !body.ok) throw Object.assign(Error(body.message || "\u6D4B\u8BD5\u8EAB\u4EFD\u6682\u4E0D\u53EF\u7528"), { code: body.code, statusCode: response.status });
          if (this.sessionActor === selected) this.identity = body.data;
          return body.data;
        }
        async request(path, payload, token) {
          const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 1e4);
          try {
            const response = await fetch("/chat/api/v1/" + path, { method: "POST", headers: { Authorization: "Bearer " + token, "Content-Type": "application/json" }, body: JSON.stringify(payload), signal: controller.signal, cache: "no-store" });
            const body = await response.json();
            if (!response.ok || body.data === void 0) throw Object.assign(Error(errors[body.code] || body.message || "\u804A\u5929\u94FE\u8DEF\u6682\u4E0D\u53EF\u7528"), { code: body.code, statusCode: response.status, retryable: body.retryable });
            return body.data;
          } finally {
            clearTimeout(timer);
          }
        }
        socket() {
          const ws = new WebSocket(location.origin.replace(/^http/, "ws") + "/chat/ws");
          return { onOpen: (fn) => ws.addEventListener("open", fn), onMessage: (fn) => ws.addEventListener("message", fn), onClose: (fn) => ws.addEventListener("close", fn), onError: (fn) => ws.addEventListener("error", fn), send: (o) => {
            try {
              ws.send(o.data);
              o.success?.();
            } catch (e) {
              o.fail?.(e);
            }
          }, close: () => ws.close() };
        }
        io() {
          const peer = this;
          return {
            environment: () => "test",
            now: () => Date.now(),
            monotonicNow: () => performance.now(),
            jitter: () => Math.random(),
            setTimeout: (fn, delay) => setTimeout(fn, delay),
            clearTimeout: (id) => clearTimeout(id),
            randomBytes: async (n) => Array.from(crypto.getRandomValues(new Uint8Array(n)), (b) => b.toString(16).padStart(2, "0")).join(""),
            credential: () => peer.credential(),
            http: (p, d, t) => peer.request(p, d, t),
            socket: () => peer.socket(),
            upload: (grant, path, progress, tasks) => new Promise((resolve, reject) => {
              const blob = peer.files.get(path);
              if (!blob) return reject(Error("\u6587\u4EF6\u5DF2\u5931\u6548\uFF0C\u8BF7\u91CD\u65B0\u9009\u62E9"));
              const xhr = new XMLHttpRequest(), task = { abort: () => xhr.abort() };
              tasks.add(task);
              xhr.open("POST", grant.host);
              xhr.timeout = 6e4;
              xhr.upload.onprogress = (e) => {
                if (e.lengthComputable) progress(Math.round(e.loaded / e.total * 100));
              };
              xhr.onload = () => {
                tasks.delete(task);
                xhr.status >= 200 && xhr.status < 300 ? resolve() : reject(Error("\u6587\u4EF6\u4E0A\u4F20\u5931\u8D25"));
              };
              xhr.ontimeout = xhr.onerror = xhr.onabort = () => {
                tasks.delete(task);
                reject(Error("\u6587\u4EF6\u4E0A\u4F20\u4E2D\u65AD"));
              };
              const form = new FormData();
              form.set("token", grant.token);
              form.set("key", grant.key);
              form.set("file", blob, "chat-test");
              xhr.send(form);
            }),
            av: {
              info: async (path) => {
                const file = peer.files.get(path);
                if (!file) throw Error("\u539F\u6587\u4EF6\u5DF2\u5931\u6548");
                return { size: file.size, digest: SparkMD5.ArrayBuffer.hash(await file.arrayBuffer()) };
              },
              network: async () => "wifi",
              warn: () => peer.tell("\u672C\u9875\u6D4B\u8BD5\u6587\u4EF6\u4EC5\u4FDD\u5B58\u5728\u5185\u5B58\uFF0C\u5237\u65B0\u540E\u8BF7\u91CD\u65B0\u9009\u62E9"),
              piece: async (path, position, length) => {
                const key = crypto.randomUUID();
                peer.files.set(key, peer.files.get(path).slice(position, position + length));
                return key;
              },
              unlink: async (path) => peer.files.delete(path),
              save: async () => {
              },
              remove: async (row) => {
                if (row.path) peer.files.delete(row.path);
              },
              load: async () => [],
              reset: async () => {
              }
            },
            incoming: () => {
              peer.tell("\u6536\u5230\u6765\u7535\uFF0C\u8BF7\u70B9\u51FB\u63A5\u542C\u6216\u62D2\u7EDD");
              peer.paintCall();
            }
          };
        }
        async start() {
          await this.stop();
          if (this.owner.peers.some((p) => p !== this && p.manager && p.actor.value === this.actor.value)) throw Error("\u53CC\u7AEF\u8BF7\u9009\u62E9\u4E0D\u540C\u6210\u5458");
          const life = ++this.generation;
          this.sessionActor = this.actor.value;
          this.tell("\u6B63\u5728\u68C0\u67E5\u771F\u5B9E\u804A\u5929\u94FE\u8DEF\u2026");
          const initial = await this.credential();
          if (life !== this.generation) return;
          const m = new ChatManager(this.io());
          this.manager = m;
          m.subscribe((type, value) => {
            if (this.manager === m) {
              if (type === "conversation.clear" && String(value.conversation_id) === this.cid || type === "connection" && ["CHAT_UNAUTHORIZED", "CHAT_PERMISSION_DENIED"].includes(value?.code)) {
                this.stop().then(() => this.tell("\u4F1A\u8BDD\u5DF2\u6E05\u7406\u6216\u8EAB\u4EFD\u5DF2\u5931\u6548\uFF0C\u8BF7\u91CD\u65B0\u63A5\u5165"));
                return;
              }
              if (type === "connection" && value.state === "unavailable") this.tell("\u8FDE\u63A5\u6682\u4E0D\u53EF\u7528\uFF0C\u53EF\u70B9\u51FB\u91CD\u8FDE\uFF1B\u6B63\u5728\u68C0\u67E5\u7684\u5A92\u4F53\u8BF7\u6838\u9A8C\u539F\u53D1\u9001\u72B6\u6001");
              if (value?.page?.history_cursor) this.historyCursor = value.page.history_cursor;
              this.paint();
              this.paintCall();
            }
          });
          m.setEnabled(true);
          m.setForeground(true);
          await m.credential(m.epoch);
          let cursor = "", found;
          for (let page = 0; page < 20; page++) {
            const data = await m.http("account.digests", { limit: 20, ...cursor ? { cursor } : {} });
            found = data.items?.find((item) => String(item.unread?.order_context?.sandbox_room_id) === String(root.dataset.room));
            if (found || !data.has_more) break;
            cursor = data.next_cursor;
            if (!cursor) break;
          }
          if (life !== this.generation || this.manager !== m) return;
          if (!found) throw Error("Go\u5C1A\u672A\u53D1\u73B0\u6B64\u6D4B\u8BD5\u4F1A\u8BDD\uFF0C\u8BF7\u786E\u8BA4Go\u7248\u672C\u4E0E\u6570\u636E\u5E93\u5DF2\u66F4\u65B0");
          this.cid = String(found.conversation_id);
          m.focus(this.cid);
          await m.reconcile();
          if (!m.cache.has(this.cid)) await m.reconcile();
          this.actions.disabled = false;
          const target = this.identity.members.find((member) => String(member.wire_id) !== String(initial.actor_id));
          if (target) this.node.querySelector("[data-call-peer]").value = String(target.wire_id);
          try {
            this.callAvailability = await m.calls.call("availability", { conversation_id: this.cid });
          } catch (e) {
            this.callAvailability = { audio: false, video: false, reason: e.code || "CALL_unavailable", peers: [] };
          }
          this.paintCall();
          this.tell(initial.moderation_ready ? "\u5DF2\u63A5\u5165\u771F\u5B9E\u94FE\u8DEF\uFF1B\u53EF\u4E0E\u53E6\u4E00\u7AEF\u6216\u5C0F\u7A0B\u5E8F\u6536\u53D1" : "\u5DF2\u63A5\u5165\uFF1B\u5C1A\u65E0\u53EF\u7528\u4E8E\u5FAE\u4FE1\u5BA1\u6838\u7684\u771F\u5B9E\u5C0F\u7A0B\u5E8F\u8EAB\u4EFD\uFF0C\u8BF7\u5148\u7528\u540E\u53F0\u914D\u7F6E\u7684\u5C0F\u7A0B\u5E8F\u7BA1\u7406\u5458\u767B\u5F55\u4E00\u6B21");
          this.callTimer = setInterval(() => this.tick().catch((e) => this.tell(message(e))), 3e3);
          this.mediaTimer = setInterval(() => this.refreshTransfers().catch((e) => this.tell(message(e))), 5e3);
          await this.refreshTransfers();
          this.paint();
        }
        async refreshTransfers() {
          if (this.refreshingTransfers || !this.manager || !this.cid || document.hidden) return;
          this.refreshingTransfers = true;
          const m = this.manager, cid = this.cid, life = this.generation;
          try {
            await m.images.refresh(cid);
            await m.av.refresh(cid);
            if (m === this.manager && life === this.generation) this.paint();
          } finally {
            this.refreshingTransfers = false;
          }
        }
        async select(kind, files) {
          this.require();
          if (!files.length) return;
          if (kind === "images") {
            const rows = files.map((file) => {
              const path = crypto.randomUUID();
              this.files.set(path, file);
              return { path, size: file.size };
            });
            await this.manager.images.stage(this.cid, rows);
          } else {
            if (files.length !== 1) throw Error("\u4E00\u6B21\u9009\u62E9\u4E00\u4E2A\u97F3\u89C6\u9891\u6587\u4EF6");
            const file = files[0];
            if (kind === "audio" && !/\.mp3$/i.test(file.name)) throw Error("\u8BED\u97F3\u6587\u4EF6\u8BF7\u4F7F\u7528MP3\uFF0C\u4E0E\u5C0F\u7A0B\u5E8F\u5F55\u97F3\u7F16\u7801\u4FDD\u6301\u4E00\u81F4");
            if (kind === "video" && !/\.mp4$/i.test(file.name)) throw Error("\u89C6\u9891\u8BF7\u4F7F\u7528H264/AAC\u7F16\u7801\u7684MP4\u6587\u4EF6");
            const path = crypto.randomUUID(), url = URL.createObjectURL(file);
            this.files.set(path, file);
            const duration = await new Promise((resolve, reject) => {
              const media = document.createElement(kind === "audio" ? "audio" : "video");
              media.preload = "metadata";
              media.onloadedmetadata = () => {
                resolve(media.duration);
                URL.revokeObjectURL(url);
              };
              media.onerror = () => {
                URL.revokeObjectURL(url);
                reject(Error("\u65E0\u6CD5\u8BFB\u53D6\u97F3\u89C6\u9891\u65F6\u957F"));
              };
              media.src = url;
            });
            const client = await this.manager.av.stage(this.cid, { path, size: file.size, duration, source: kind === "audio" ? "voice" : "album" });
            await this.manager.av.send(this.cid, client);
          }
          this.paint();
        }
        paint() {
          const m = this.manager, model = m?.cache.get(this.cid);
          if (!model) return;
          const signature = JSON.stringify([...model.messages.values()].map((row) => [row.message_id, row.state_version, row.state]));
          if (signature !== this.renderKey) {
            this.renderKey = signature;
            const atEnd = this.log.scrollHeight - this.log.clientHeight - this.log.scrollTop < 80, fragment = document.createDocumentFragment();
            const people = new Map((this.identity.members || []).map((p) => [String(p.wire_id), p.display_name]));
            for (const row of [...model.messages.values()].sort((a, b) => compare(a.seq, b.seq))) {
              const article = element("article", void 0, "chat-testing-message" + (row.sender_id === m.calls.actorID ? " is-admin" : ""));
              article.append(element("small", (people.get(row.sender_id) || "\u6D4B\u8BD5\u6210\u5458") + " \xB7 " + new Date(row.created_at).toLocaleString()));
              const bubble = element("div");
              if (row.state !== "published") bubble.textContent = row.state === "recalled" ? "\u8BE5\u6D88\u606F\u5DF2\u64A4\u56DE" : "\u6B64\u6D88\u606F\u4E0D\u53EF\u67E5\u770B";
              else if (row.message_type === "text") bubble.textContent = row.text || "";
              else if (["image", "image_album"].includes(row.message_type)) {
                for (const item of row.images || []) {
                  const image = element("img");
                  image.alt = "\u804A\u5929\u56FE\u7247";
                  image.loading = "lazy";
                  bubble.append(image);
                  const key = row.message_id + ":" + item.item_id;
                  this.imageURL(key, () => m.images.url(this.cid, row.message_id, item.item_id, true), (url) => {
                    if (image.isConnected) image.src = url;
                  }).catch(() => image.alt = "\u56FE\u7247\u6682\u4E0D\u53EF\u7528\uFF0C\u7A0D\u540E\u91CD\u8BD5");
                  image.onclick = () => m.images.url(this.cid, row.message_id, item.item_id, false).then((url) => window.open(url, "_blank", "noopener,noreferrer")).catch((e) => this.tell(message(e)));
                }
              } else if (["audio", "video"].includes(row.message_type)) {
                const media = element(row.message_type);
                media.controls = true;
                media.preload = "none";
                bubble.append(media);
                const button = element("button", "\u52A0\u8F7D" + (row.message_type === "audio" ? "\u8BED\u97F3" : "\u89C6\u9891"), "admin-btn admin-btn--sm");
                button.type = "button";
                button.onclick = () => m.av.url(this.cid, row.message_id).then((url) => {
                  media.src = url;
                  media.play().catch(() => {
                  });
                  button.remove();
                }).catch((e) => this.tell(message(e)));
                bubble.append(button);
                if (row.message_type === "audio") media.onended = () => m.av.heard(this.cid, row.message_id).catch(() => {
                });
              } else bubble.textContent = "\u6D88\u606F\u7C7B\u578B\uFF1A" + row.message_type;
              article.append(bubble);
              if (row.state === "published" && row.sender_id === m.calls.actorID) {
                const recall = element("button", "\u64A4\u56DE", "admin-btn admin-btn--sm");
                recall.type = "button";
                recall.onclick = () => m.recall(this.cid, row.message_id).catch((e) => this.tell(message(e)));
                article.append(recall);
              }
              fragment.append(article);
            }
            this.log.replaceChildren(fragment);
            if (atEnd) this.log.scrollTop = this.log.scrollHeight;
          }
          const cap = model.capabilities || {};
          for (const kind of ["images", "audio", "video"]) this.node.querySelector("[data-live-" + kind + "]").disabled = cap[kind] !== true;
          const transfers = [];
          for (const row of m.images.list(this.cid)) {
            const item = element("div", "\u56FE\u7247 \xB7 " + (row.code ? message({ code: row.code }) : row.state === "editing" ? row.uploading ? "\u4E0A\u4F20\u4E2D" : "\u5F85\u53D1\u9001" : progressName(row.state)), "chat-testing-transfer-row");
            for (let index = 0; index < row.items.length; index++) {
              const file = row.items[index];
              if (["upload_failed", "check_unavailable"].includes(file.state)) {
                const retry = element("button", "\u91CD\u8BD5\u7B2C" + (index + 1) + "\u5F20", "admin-btn admin-btn--sm");
                retry.onclick = () => m.images.retry(this.cid, row.client_message_id, index).catch((e) => this.tell(message(e)));
                item.append(retry);
              }
            }
            if (!row.submitted && !row.uploading && row.items.every((file) => file.attachment_id && !["upload_failed", "check_unavailable"].includes(file.state))) {
              const send = element("button", "\u53D1\u9001\u56FE\u7247", "admin-btn admin-btn--sm");
              send.onclick = () => m.images.send(this.cid, row.client_message_id).catch((e) => this.tell(message(e)));
              item.append(send);
            }
            if (row.submitted && !["accepted", "rejected", "canceled"].includes(row.state)) {
              const retry = element("button", "\u6838\u9A8C\u72B6\u6001", "admin-btn admin-btn--sm");
              retry.onclick = () => m.images.status(this.cid, row.client_message_id).catch((e) => this.tell(message(e)));
              item.append(retry);
            }
            if (!row.uploading && !["accepted", "rejected", "canceled"].includes(row.state)) {
              const cancel = element("button", "\u53D6\u6D88", "admin-btn admin-btn--sm");
              cancel.onclick = () => m.images.revise(this.cid, row.client_message_id, (items) => items.splice(0)).catch((e) => this.tell(message(e)));
              item.append(cancel);
            }
            transfers.push(item);
          }
          for (const row of m.attempts(this.cid)) {
            if (row.state === "accepted" && model.messages.has(row.message_id)) continue;
            const item = element("div", "\u6587\u5B57 \xB7 " + (row.code ? message({ code: row.code }) : progressName(row.state)), "chat-testing-transfer-row");
            if (row.state === "unknown" || row.state === "retryable") {
              const retry = element("button", "\u6838\u9A8C\u5E76\u91CD\u8BD5", "admin-btn admin-btn--sm");
              retry.onclick = () => m.retryText(this.cid, row.client_message_id).catch((e) => this.tell(message(e)));
              item.append(retry);
            }
            transfers.push(item);
          }
          for (const row of m.av.list(this.cid)) {
            const item = element("div", (row.kind === "audio" ? "\u8BED\u97F3" : "\u89C6\u9891") + " \xB7 " + (row.code ? message({ code: row.code }) : progressName(row.phase || row.state)) + (row.busy ? " " + row.progress + "%" : ""), "chat-testing-transfer-row");
            if (!row.busy && !["accepted", "rejected", "canceled"].includes(row.state)) {
              const retry = element("button", row.path ? "\u6838\u9A8C\u5E76\u91CD\u8BD5" : "\u6838\u9A8C\u72B6\u6001", "admin-btn admin-btn--sm");
              retry.onclick = () => m.av.retry(this.cid, row.client_message_id).catch((e) => this.tell(message(e)));
              const cancel = element("button", "\u53D6\u6D88", "admin-btn admin-btn--sm");
              cancel.onclick = () => m.av.status(this.cid, row.client_message_id, true).catch((e) => this.tell(message(e)));
              item.append(retry, cancel);
            }
            transfers.push(item);
          }
          this.transfer.replaceChildren(...transfers);
          this.node.querySelector("[data-live-capabilities]").textContent = "\u56FE\u7247" + (cap.images ? "\u53EF\u7528" : "\u672A\u5C31\u7EEA") + " \xB7 \u8BED\u97F3" + (cap.audio ? "\u53EF\u7528" : "\u672A\u5C31\u7EEA") + " \xB7 \u89C6\u9891" + (cap.video ? "\u53EF\u7528" : "\u672A\u5C31\u7EEA");
        }
        imageURL(key, load, apply) {
          let known = this.urls.get(key);
          if (!known || known.until < Date.now()) {
            known = { until: Date.now() + 4e4, promise: load() };
            this.urls.set(key, known);
          }
          return known.promise.then(apply);
        }
        async history() {
          this.require();
          const model = this.manager.cache.get(this.cid);
          if (!this.historyCursor) return this.tell("\u5DF2\u663E\u793A\u5F53\u524D\u53EF\u8BFB\u6D88\u606F");
          const page = await this.manager.window(this.cid, "history.request", { cursor: this.historyCursor, limit: 50 });
          this.historyCursor = page.history_cursor || "";
          this.manager.merge(model, page);
          this.paint();
        }
        async call(media) {
          this.require();
          const peer = this.node.querySelector("[data-call-peer]").value;
          if (!peer || peer === this.manager.calls.actorID) throw Error("\u8BF7\u9009\u62E9\u53E6\u4E00\u4F4D\u6210\u5458");
          let availability;
          try {
            availability = await this.manager.calls.call("availability", { conversation_id: this.cid });
          } catch (e) {
            availability = { audio: false, video: false, reason: e.code || "CALL_unavailable", peers: [] };
          }
          this.callAvailability = availability;
          this.paintCall();
          if (availability?.[media] !== true) {
            const code = String(availability?.reason || "CALL_unavailable");
            const reason = this.identity?.members?.length !== 2 ? "\u5F53\u524D\u6D4B\u8BD5\u4F1A\u8BDD\u4E0D\u662F\u4E24\u4EBA\u5355\u804A\uFF1B\u8BED\u97F3/\u89C6\u9891\u901A\u8BDD\u4EC5\u652F\u6301\u4E24\u4EBA\u4F1A\u8BDD" : message({ code });
            throw Object.assign(Error(reason), { code });
          }
          await sdk();
          await this.manager.calls.create(this.cid, peer, media);
          this.paintCall();
          await this.tick();
        }
        paintCall() {
          const call = this.manager?.calls.current, box = this.node.querySelector("[data-call-status]"), available = this.callAvailability || {};
          const idle = this.identity?.members?.length !== 2 ? "\u5F53\u524D\u4E3A\u591A\u4EBA\u6D4B\u8BD5\u4F1A\u8BDD\uFF1B\u8BED\u97F3/\u89C6\u9891\u901A\u8BDD\u4EC5\u652F\u6301\u4E24\u4EBA\u4F1A\u8BDD" : available.audio || available.video ? "\u5F53\u524D\u6CA1\u6709\u901A\u8BDD" : message({ code: available.reason || "CALL_unavailable" });
          box.textContent = call ? labels[call.state] || "\u901A\u8BDD\u72B6\u6001\u66F4\u65B0\u4E2D" : idle;
          this.node.querySelector("[data-call-audio]").disabled = !!call && !terminal.has(call.state) || available.audio !== true;
          this.node.querySelector("[data-call-video]").disabled = !!call && !terminal.has(call.state) || available.video !== true;
          this.node.querySelector("[data-call-accept]").hidden = !(call?.state === "ringing" && call.callee_id === this.manager.calls.actorID);
          this.node.querySelector("[data-call-reject]").hidden = this.node.querySelector("[data-call-accept]").hidden;
          this.node.querySelector("[data-call-end]").hidden = !call || terminal.has(call.state);
          if (call && (terminal.has(call.state) || call.state === "stopping")) this.stopMedia();
        }
        async tick() {
          if (this.ticking || !this.manager || document.hidden) return;
          this.ticking = true;
          const m = this.manager, life = this.generation;
          try {
            if (m.calls.current) await m.calls.status();
            else await m.calls.poll();
            if (this.manager !== m || life !== this.generation) return;
            const call = m.calls.current;
            this.paintCall();
            if (!call || terminal.has(call.state) || call.state === "ringing" || call.state === "stopping") return;
            if (!this.media) await this.join(call, life);
            if (this.manager !== m || life !== this.generation) return;
            await this.signals();
            if (!this.media) return;
            await m.calls.action("pulse");
            if (this.media && m.calls.current.authorized_until > this.until + 5) {
              const grant = await m.calls.prepare();
              await this.media.renewToken(grant.token);
              this.arm(grant, life);
            }
          } finally {
            this.ticking = false;
          }
        }
        arm(grant, life) {
          clearTimeout(this.deadline);
          this.until = grant.expires_at;
          const remaining = Math.max(0, grant.remaining_ms - 2e3);
          this.deadline = setTimeout(() => {
            if (life === this.generation) this.hangup();
          }, remaining);
        }
        async join(call, life) {
          const m = this.manager, grant = await m.calls.prepare();
          if (life !== this.generation || this.manager !== m) return;
          if (grant.provider !== "shengwang") throw Error("\u6D4F\u89C8\u5668\u901A\u8BDD\u9700\u4F7F\u7528\u58F0\u7F51\u901A\u9053\uFF0C\u8BF7\u68C0\u67E5\u901A\u8BDD\u8D44\u6E90\u8BBE\u7F6E");
          const SDK = await sdk();
          if (life !== this.generation || this.manager !== m) return;
          const client = SDK.createClient({ mode: "live", codec: "h264" });
          this.media = client;
          await client.setClientRole("host");
          const current = () => life === this.generation && this.manager === m && this.media === client;
          client.on("user-published", async (user, type) => {
            if (!current() || Number(user.uid) !== Number(grant.peer_uid)) return;
            try {
              await client.subscribe(user, type);
              if (!current()) return;
              if (type === "video") user.videoTrack.play(this.node.querySelector("[data-call-remote]"));
              if (type === "audio") user.audioTrack.play();
            } catch (e) {
              if (current()) this.tell("\u5BF9\u65B9\u5A92\u4F53\u8BA2\u9605\u5931\u8D25\uFF0C\u8BF7\u91CD\u8BD5");
            }
          });
          client.on("user-left", (user) => {
            if (current() && Number(user.uid) === Number(grant.peer_uid)) this.hangup();
          });
          client.on("token-privilege-did-expire", () => {
            if (current()) this.hangup();
          });
          try {
            this.arm(grant, life);
            await client.join(grant.app_id, grant.channel, grant.token, Number(grant.uid));
            if (!current()) return client.leave();
            const audio = await SDK.createMicrophoneAudioTrack();
            if (!current()) {
              audio.close();
              return client.leave();
            }
            this.tracks.push(audio);
            if (call.media === "video") {
              const video = await SDK.createCameraVideoTrack();
              if (!current()) {
                video.close();
                return this.stopMedia();
              }
              this.tracks.push(video);
              if (current()) video.play(this.node.querySelector("[data-call-local]"));
            }
            if (!current()) return this.stopMedia();
            await client.publish(this.tracks);
            if (!current()) return this.stopMedia();
            this.preparedSignal = false;
            this.readySignal = false;
          } catch (e) {
            await this.stopMedia();
            await m.calls.action("end").catch(() => {
            });
            throw e;
          }
        }
        async signals() {
          const m = this.manager, engine = this.media;
          if (!m || !engine) return;
          for (const name of ["prepared", "ready"]) {
            const key = name + "Signal";
            if (this[key]) continue;
            try {
              await m.calls.action(name);
              if (this.media !== engine || this.manager !== m) return;
              this[key] = true;
            } catch (e) {
              if (e.code === "CALL_STATE_CONFLICT") return;
              throw e;
            }
          }
        }
        async stopMedia() {
          clearTimeout(this.deadline);
          this.deadline = null;
          const client = this.media;
          this.media = null;
          for (const track of this.tracks) {
            try {
              track.stop();
              track.close();
            } catch (e) {
            }
          }
          this.tracks = [];
          if (client) {
            try {
              await client.leave();
            } catch (e) {
            }
            ;
            client.removeAllListeners?.();
          }
          this.node.querySelector("[data-call-local]").replaceChildren();
          this.node.querySelector("[data-call-remote]").replaceChildren();
        }
        async hangup() {
          await this.stopMedia();
          if (this.manager?.calls.current && !terminal.has(this.manager.calls.current.state)) try {
            await this.manager.calls.action("end");
          } catch (e) {
            this.tell(message(e));
          }
          this.paintCall();
        }
        async stop() {
          this.generation++;
          clearInterval(this.callTimer);
          clearInterval(this.mediaTimer);
          clearTimeout(this.timer);
          const m = this.manager;
          this.manager = null;
          this.actions.disabled = true;
          this.cid = "";
          this.renderKey = null;
          this.historyCursor = "";
          await this.stopMedia();
          if (m?.calls.current && !terminal.has(m.calls.current.state)) await m.calls.action("end").catch(() => {
          });
          m?.setForeground(false);
          m?.setEnabled(false);
          this.files.clear();
          this.urls.clear();
          this.sessionActor = "";
          this.identity = null;
          this.callAvailability = null;
          this.log.replaceChildren();
          this.transfer.replaceChildren();
          this.node.querySelector("[data-call-status]").textContent = "\u5F53\u524D\u6CA1\u6709\u901A\u8BDD";
          this.tell("\u5C1A\u672A\u63A5\u5165");
        }
      };
      if (root) {
        const owner = { peers: [] };
        root.querySelectorAll("[data-live-peer]").forEach((node) => owner.peers.push(new TestPeer(node, owner)));
        window.addEventListener("pagehide", () => owner.peers.forEach((peer) => peer.stop()), { once: true });
        document.addEventListener("visibilitychange", () => {
          if (document.hidden) owner.peers.forEach((peer) => peer.hangup());
        });
      }
      module.exports = { TestPeer, errors };
    }
  });
  require_chat_testing_client();
})();
