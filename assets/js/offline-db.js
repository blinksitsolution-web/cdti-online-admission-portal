/**
 * OfflineDB — IndexedDB layer for the offline-first admission registration flow.
 *
 * Two object stores, per the approved offline-admission design:
 *   drafts           one row per student, keyed by indexNumber — current
 *                    in-progress form state (see autosave).
 *   submission_queue one row per queued/attempted submission, keyed by
 *                    clientUuid — serialized fields + photo Blob + queue
 *                    state/retry metadata. State machine: Pending ->
 *                    Uploading -> Retry -> Completed -> Archived, with a
 *                    Failed state reached from Retry after the attempt cap
 *                    or immediately on a non-recoverable server response.
 *
 * Exposed as the global `OfflineDB`. Every method returns a Promise and
 * rejects (rather than throwing synchronously) so callers can degrade to
 * online-only behaviour when IndexedDB is unavailable (e.g. Safari private
 * browsing) instead of breaking the page.
 */
var OfflineDB = (function () {
  'use strict';

  var DB_NAME = 'cdti_admission_offline';
  var DB_VERSION = 1;
  var STORE_DRAFTS = 'drafts';
  var STORE_QUEUE = 'submission_queue';
  var DRAFT_MAX_AGE_MS = 30 * 24 * 60 * 60 * 1000; // 30 days

  var QUEUE_STATES = ['pending', 'uploading', 'retry', 'completed', 'archived', 'failed'];

  var dbPromise = null;

  function openDB() {
    if (dbPromise) return dbPromise;
    dbPromise = new Promise(function (resolve, reject) {
      if (!window.indexedDB) {
        reject(new Error('IndexedDB is not supported in this browser.'));
        return;
      }
      var req = indexedDB.open(DB_NAME, DB_VERSION);
      req.onupgradeneeded = function () {
        var db = req.result;
        if (!db.objectStoreNames.contains(STORE_DRAFTS)) {
          db.createObjectStore(STORE_DRAFTS, { keyPath: 'indexNumber' });
        }
        if (!db.objectStoreNames.contains(STORE_QUEUE)) {
          var qStore = db.createObjectStore(STORE_QUEUE, { keyPath: 'clientUuid' });
          qStore.createIndex('state', 'state', { unique: false });
          qStore.createIndex('indexNumber', 'indexNumber', { unique: false });
        }
      };
      req.onsuccess = function () { resolve(req.result); };
      req.onerror = function () { reject(req.error || new Error('Failed to open offline database.')); };
      req.onblocked = function () { reject(new Error('Offline database upgrade blocked by another open tab.')); };
    });
    return dbPromise;
  }

  function promisifyRequest(request) {
    return new Promise(function (resolve, reject) {
      request.onsuccess = function () { resolve(request.result); };
      request.onerror = function () { reject(request.error || new Error('IndexedDB request failed.')); };
    });
  }

  function getStore(storeName, mode) {
    return openDB().then(function (db) {
      return db.transaction(storeName, mode).objectStore(storeName);
    });
  }

  // ── Drafts ──────────────────────────────────────────────────────────────

  function saveDraft(indexNumber, data) {
    if (!indexNumber) return Promise.reject(new Error('saveDraft requires an indexNumber.'));
    return getStore(STORE_DRAFTS, 'readwrite').then(function (store) {
      var record = Object.assign({}, data, { indexNumber: indexNumber, updatedAt: Date.now() });
      return promisifyRequest(store.put(record));
    });
  }

  function getDraft(indexNumber) {
    return getStore(STORE_DRAFTS, 'readonly').then(function (store) {
      return promisifyRequest(store.get(indexNumber));
    });
  }

  function deleteDraft(indexNumber) {
    return getStore(STORE_DRAFTS, 'readwrite').then(function (store) {
      return promisifyRequest(store.delete(indexNumber));
    });
  }

  // Abandoned drafts older than 30 days are purged — a practical bound on
  // PII exposure on a shared/family device, since the draft is never
  // encrypted client-side (see Phase 2 security review).
  function purgeStaleDrafts() {
    return getStore(STORE_DRAFTS, 'readwrite').then(function (store) {
      return promisifyRequest(store.getAll()).then(function (all) {
        var cutoff = Date.now() - DRAFT_MAX_AGE_MS;
        var stale = all.filter(function (d) { return (d.updatedAt || 0) < cutoff; });
        stale.forEach(function (d) { store.delete(d.indexNumber); });
        return stale.length;
      });
    });
  }

  // ── Submission queue ───────────────────────────────────────────────────

  function enqueueSubmission(entry) {
    if (!entry || !entry.clientUuid) return Promise.reject(new Error('enqueueSubmission requires a clientUuid.'));
    return getStore(STORE_QUEUE, 'readwrite').then(function (store) {
      var now = Date.now();
      var record = Object.assign({
        state: 'pending',
        attempts: 0,
        nextRetryAt: null,
        lastError: null,
        createdAt: now,
      }, entry, { updatedAt: now });
      return promisifyRequest(store.put(record));
    });
  }

  function getQueueItem(clientUuid) {
    return getStore(STORE_QUEUE, 'readonly').then(function (store) {
      return promisifyRequest(store.get(clientUuid));
    });
  }

  function updateQueueItem(clientUuid, changes) {
    return getStore(STORE_QUEUE, 'readwrite').then(function (store) {
      return promisifyRequest(store.get(clientUuid)).then(function (existing) {
        if (!existing) return null;
        var updated = Object.assign({}, existing, changes, { updatedAt: Date.now() });
        return promisifyRequest(store.put(updated)).then(function () { return updated; });
      });
    });
  }

  function deleteQueueItem(clientUuid) {
    return getStore(STORE_QUEUE, 'readwrite').then(function (store) {
      return promisifyRequest(store.delete(clientUuid));
    });
  }

  // Items the sync engine should attempt now (Pending or Retry whose
  // backoff window has elapsed), oldest first.
  function getSyncableSubmissions() {
    return getStore(STORE_QUEUE, 'readonly').then(function (store) {
      var index = store.index('state');
      var now = Date.now();
      return Promise.all(['pending', 'retry'].map(function (state) {
        return promisifyRequest(index.getAll(state));
      })).then(function (results) {
        var items = results[0].concat(results[1]);
        return items
          .filter(function (item) { return !item.nextRetryAt || item.nextRetryAt <= now; })
          .sort(function (a, b) { return a.createdAt - b.createdAt; });
      });
    });
  }

  function getAllQueueItems() {
    return getStore(STORE_QUEUE, 'readonly').then(function (store) {
      return promisifyRequest(store.getAll());
    });
  }

  // Cleared on explicit logout (see Phase 2 security review) — wired up
  // from the logout control, not called automatically.
  function clearAll() {
    return Promise.all([
      getStore(STORE_DRAFTS, 'readwrite').then(function (store) { return promisifyRequest(store.clear()); }),
      getStore(STORE_QUEUE, 'readwrite').then(function (store) { return promisifyRequest(store.clear()); }),
    ]);
  }

  return {
    QUEUE_STATES: QUEUE_STATES,
    openDB: openDB,
    saveDraft: saveDraft,
    getDraft: getDraft,
    deleteDraft: deleteDraft,
    purgeStaleDrafts: purgeStaleDrafts,
    enqueueSubmission: enqueueSubmission,
    getQueueItem: getQueueItem,
    updateQueueItem: updateQueueItem,
    deleteQueueItem: deleteQueueItem,
    getSyncableSubmissions: getSyncableSubmissions,
    getAllQueueItems: getAllQueueItems,
    clearAll: clearAll,
  };
})();
