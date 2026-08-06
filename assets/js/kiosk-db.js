/**
 * KioskDB — Dedicated IndexedDB for offline kiosk registration mode.
 *
 * Separate from OfflineDB (cdti_admission_offline) so that kiosk data
 * never conflicts with the standard online registration flow.
 *
 * Object stores:
 *   admitted_students  keyed by index_number — cached from admin sync
 *   kiosk_drafts       keyed by index_number — in-progress kiosk forms
 *   kiosk_queue        keyed by clientUuid   — queued kiosk submissions
 *   kiosk_meta         keyed by 'key'        — sync timestamp / count
 *
 * Exposed as the global `KioskDB`. Every method returns a Promise.
 * Fails soft — callers degrade gracefully if IndexedDB is unavailable.
 */
var KioskDB = (function () {
  'use strict';

  var DB_NAME    = 'cdti_kiosk';
  var DB_VERSION = 1;
  var dbPromise  = null;

  var BACKOFF_MS   = [5000, 15000, 45000, 120000, 300000];
  var MAX_ATTEMPTS = 10;

  function openDB() {
    if (dbPromise) return dbPromise;
    dbPromise = new Promise(function (resolve, reject) {
      if (!window.indexedDB) {
        reject(new Error('IndexedDB not supported in this browser.'));
        return;
      }
      var req = indexedDB.open(DB_NAME, DB_VERSION);
      req.onupgradeneeded = function () {
        var db = req.result;
        if (!db.objectStoreNames.contains('admitted_students')) {
          db.createObjectStore('admitted_students', { keyPath: 'idx' });
        }
        if (!db.objectStoreNames.contains('kiosk_drafts')) {
          db.createObjectStore('kiosk_drafts', { keyPath: 'indexNumber' });
        }
        if (!db.objectStoreNames.contains('kiosk_queue')) {
          var qs = db.createObjectStore('kiosk_queue', { keyPath: 'clientUuid' });
          qs.createIndex('state', 'state', { unique: false });
        }
        if (!db.objectStoreNames.contains('kiosk_meta')) {
          db.createObjectStore('kiosk_meta', { keyPath: 'key' });
        }
      };
      req.onsuccess  = function () { resolve(req.result); };
      req.onerror    = function () { reject(req.error || new Error('Failed to open kiosk database.')); };
      req.onblocked  = function () { reject(new Error('Kiosk database upgrade blocked by another open tab.')); };
    });
    return dbPromise;
  }

  function promisify(request) {
    return new Promise(function (resolve, reject) {
      request.onsuccess = function () { resolve(request.result); };
      request.onerror   = function () { reject(request.error || new Error('IndexedDB request failed.')); };
    });
  }

  function getStore(name, mode) {
    return openDB().then(function (db) {
      return db.transaction(name, mode).objectStore(name);
    });
  }

  // ── Admitted Students ──────────────────────────────────────────────────────

  /**
   * Replace ALL admitted students with the freshly downloaded records.
   * Done in a single transaction so the store is never partially empty.
   * @param {Array} records  Array of student objects (must have .idx field)
   * @returns {Promise<number>} Count of records stored
   */
  function bulkStoreStudents(records) {
    return openDB().then(function (db) {
      return new Promise(function (resolve, reject) {
        var tx    = db.transaction('admitted_students', 'readwrite');
        var store = tx.objectStore('admitted_students');
        store.clear();
        records.forEach(function (r) { store.put(r); });
        tx.oncomplete = function () { resolve(records.length); };
        tx.onerror    = function () { reject(tx.error || new Error('Bulk store failed.')); };
        tx.onabort    = function () { reject(new Error('Bulk store aborted.')); };
      });
    });
  }

  /** Look up a student by their exact index number. Returns undefined if not found. */
  function lookupStudent(indexNumber) {
    return getStore('admitted_students', 'readonly').then(function (store) {
      return promisify(store.get(indexNumber));
    });
  }

  /** How many admitted students are currently cached? */
  function getStudentCount() {
    return getStore('admitted_students', 'readonly').then(function (store) {
      return promisify(store.count());
    });
  }

  // ── Sync Metadata ─────────────────────────────────────────────────────────

  /** { key, count, syncedAt (ms), etag } */
  function getSyncMeta() {
    return getStore('kiosk_meta', 'readonly').then(function (store) {
      return promisify(store.get('sync'));
    });
  }

  function setSyncMeta(data) {
    return getStore('kiosk_meta', 'readwrite').then(function (store) {
      return promisify(store.put(Object.assign({ key: 'sync' }, data)));
    });
  }

  /** School settings cached for offline display (name, phone, etc.) */
  function getSchoolMeta() {
    return getStore('kiosk_meta', 'readonly').then(function (store) {
      return promisify(store.get('school'));
    });
  }

  function setSchoolMeta(data) {
    return getStore('kiosk_meta', 'readwrite').then(function (store) {
      return promisify(store.put(Object.assign({ key: 'school' }, data)));
    });
  }

  // ── Drafts ────────────────────────────────────────────────────────────────

  function saveDraft(indexNumber, data) {
    if (!indexNumber) return Promise.reject(new Error('saveDraft requires indexNumber.'));
    return getStore('kiosk_drafts', 'readwrite').then(function (store) {
      return promisify(store.put(Object.assign({}, data, {
        indexNumber: indexNumber,
        updatedAt: Date.now(),
      })));
    });
  }

  function getDraft(indexNumber) {
    return getStore('kiosk_drafts', 'readonly').then(function (store) {
      return promisify(store.get(indexNumber));
    });
  }

  function deleteDraft(indexNumber) {
    return getStore('kiosk_drafts', 'readwrite').then(function (store) {
      return promisify(store.delete(indexNumber));
    });
  }

  // ── Submission Queue ──────────────────────────────────────────────────────

  function enqueue(entry) {
    if (!entry || !entry.clientUuid) return Promise.reject(new Error('enqueue requires clientUuid.'));
    return getStore('kiosk_queue', 'readwrite').then(function (store) {
      var now = Date.now();
      return promisify(store.put(Object.assign({
        state: 'pending',
        attempts: 0,
        nextRetryAt: null,
        lastError: null,
        createdAt: now,
        updatedAt: now,
      }, entry)));
    });
  }

  function getSyncableItems() {
    return getStore('kiosk_queue', 'readonly').then(function (store) {
      var idx = store.index('state');
      var now = Date.now();
      return Promise.all(['pending', 'retry'].map(function (s) {
        return promisify(idx.getAll(s));
      })).then(function (results) {
        return results[0].concat(results[1])
          .filter(function (i) { return !i.nextRetryAt || i.nextRetryAt <= now; })
          .sort(function (a, b) { return a.createdAt - b.createdAt; });
      });
    });
  }

  function updateQueueItem(clientUuid, changes) {
    return getStore('kiosk_queue', 'readwrite').then(function (store) {
      return promisify(store.get(clientUuid)).then(function (existing) {
        if (!existing) return null;
        var updated = Object.assign({}, existing, changes, { updatedAt: Date.now() });
        return promisify(store.put(updated)).then(function () { return updated; });
      });
    });
  }

  function getAllQueueItems() {
    return getStore('kiosk_queue', 'readonly').then(function (store) {
      return promisify(store.getAll());
    });
  }

  function deleteQueueItem(clientUuid) {
    return getStore('kiosk_queue', 'readwrite').then(function (store) {
      return promisify(store.delete(clientUuid));
    });
  }

  return {
    BACKOFF_MS:         BACKOFF_MS,
    MAX_ATTEMPTS:       MAX_ATTEMPTS,
    openDB:             openDB,
    // students
    bulkStoreStudents:  bulkStoreStudents,
    lookupStudent:      lookupStudent,
    getStudentCount:    getStudentCount,
    // meta
    getSyncMeta:        getSyncMeta,
    setSyncMeta:        setSyncMeta,
    getSchoolMeta:      getSchoolMeta,
    setSchoolMeta:      setSchoolMeta,
    // drafts
    saveDraft:          saveDraft,
    getDraft:           getDraft,
    deleteDraft:        deleteDraft,
    // queue
    enqueue:            enqueue,
    getSyncableItems:   getSyncableItems,
    updateQueueItem:    updateQueueItem,
    getAllQueueItems:    getAllQueueItems,
    deleteQueueItem:    deleteQueueItem,
  };
})();
